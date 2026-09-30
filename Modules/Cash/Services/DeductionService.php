<?php

namespace Modules\Cash\Services;

use App\Exceptions\AgoraProcException;
use App\Support\ProcedureService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Deductions and the payroll file (T041).
 *
 * The five head-office tabs are grids and need nothing here. This is the part
 * that writes: building a site's schedule for a pay period
 * (usp_Cash_BuildDeductions → agora.DeductionRun + agora.Deduction) and taking
 * the payroll file from it (usp_Cash_ExportPayroll, which marks the runs
 * exported). Every figure comes out of a procedure; nothing is recomputed here.
 *
 * Both procedures manage their own transaction, so they are called through
 * callSets() rather than write() — write() would open a Laravel transaction
 * around them, and a refusal inside a nested one strands the connection.
 */
class DeductionService
{
    public function __construct(protected ProcedureService $procedures) {}

    public function leniency(): float
    {
        return (float) config('cash.deductions.cash_leniency', 3.00);
    }

    /**
     * The period a Deductions screen opens on when none is chosen: the last
     * full calendar month — a pay period. The procedures default the same way.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function defaultPeriod(): array
    {
        $last = Carbon::today()->subMonthNoOverflow();

        return [$last->copy()->startOfMonth(), $last->copy()->endOfMonth()];
    }

    /**
     * Approved staff shorts in the period that are on no cashup, and so on no
     * deduction (T042): {Shorts, Amount, Oldest}.
     *
     * @param  array<int, int>  $branchIds  the sites chosen; [] = every site allowed
     * @param  array<int, int>  $allowed  the sites the person may see; [] = every site
     */
    public function unposted(array $branchIds, array $allowed, Carbon $from, Carbon $to): object
    {
        return ($this->procedures->callSets('usp_Cash_GetUnpostedStaffShorts', [
            'BranchIds' => $branchIds === [] ? null : implode(',', $branchIds),
            'DateFrom' => $from->toDateString(),
            'DateTo' => $to->toDateString(),
            'AllowedBranchIds' => $allowed === [] ? null : implode(',', $allowed),
        ])[0] ?? collect())->first() ?? (object) ['Shorts' => 0, 'Amount' => 0, 'Oldest' => null];
    }

    /**
     * Build each site's schedule for the period. One run per site; a site the
     * procedure refuses (a period already exported, say) is reported and the
     * others still build.
     *
     * @param  array<int, int>  $branchIds
     * @return array{built: array<int, object>, refused: array<int, string>}
     */
    public function build(array $branchIds, Carbon $from, Carbon $to, int $userId, ?string $note = null): array
    {
        $built = [];
        $refused = [];

        foreach ($branchIds as $branchId) {
            try {
                $status = ($this->procedures->callSets('usp_Cash_BuildDeductions', [
                    'BranchId' => $branchId,
                    'FromDate' => $from->toDateString(),
                    'ToDate' => $to->toDateString(),
                    'Leniency' => $this->leniency(),
                    'UserId' => $userId,
                    'Note' => $note,
                ])[0] ?? collect())->first();

                if ($status === null || ! (bool) $status->Ok) {
                    throw new AgoraProcException($status->Code ?? 'REFUSED',
                        $status->Message ?? 'The build was declined without saying why.', 'usp_Cash_BuildDeductions');
                }

                $built[$branchId] = $status;
            } catch (AgoraProcException $e) {
                $refused[$branchId] = $e->getMessage();
            }
        }

        return ['built' => $built, 'refused' => $refused];
    }

    /**
     * Take the payroll file: the lines, and the runs marked exported.
     *
     * @param  array<int, int>  $branchIds
     * @return array{status: object, lines: Collection<int, object>}
     */
    public function exportPayroll(array $branchIds, Carbon $from, Carbon $to, int $userId): array
    {
        $sets = $this->procedures->callSets('usp_Cash_ExportPayroll', [
            'BranchIds' => implode(',', $branchIds),
            'FromDate' => $from->toDateString(),
            'ToDate' => $to->toDateString(),
            'UserId' => $userId,
        ]);

        $status = ($sets[0] ?? collect())->first();

        if ($status === null || ! (bool) $status->Ok) {
            throw new AgoraProcException($status->Code ?? 'REFUSED',
                $status->Message ?? 'The export was declined without saying why.', 'usp_Cash_ExportPayroll');
        }

        return ['status' => $status, 'lines' => $sets[1] ?? collect()];
    }

    /**
     * The payroll file as CSV — the layout usp_Cash_ExportPayroll returns,
     * column for column, which is provisional until ZP confirms their payroll
     * system's. Amounts are what to deduct, as positive rand.
     *
     * @param  Collection<int, object>  $lines
     */
    public function csv(Collection $lines, Carbon $from, Carbon $to): StreamedResponse
    {
        $columns = ['BranchId', 'BranchName', 'EmployeeCode', 'EmployeeName', 'PostDescription',
            'FromDate', 'ToDate', 'CashShort', 'StockLoss', 'NonIntegrated', 'Total'];

        $name = sprintf('agora-payroll-deductions_%s_%s.csv', $from->format('Ymd'), $to->format('Ymd'));

        return response()->streamDownload(function () use ($lines, $columns) {
            $out = fopen('php://output', 'w');

            if ($out === false) {
                return;
            }

            fputcsv($out, $columns, escape: '');

            foreach ($lines as $line) {
                $row = [];

                foreach ($columns as $column) {
                    $value = $line->{$column} ?? '';
                    $row[] = in_array($column, ['FromDate', 'ToDate'], true) ? substr((string) $value, 0, 10) : $value;
                }

                fputcsv($out, $row, escape: '');
            }

            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
