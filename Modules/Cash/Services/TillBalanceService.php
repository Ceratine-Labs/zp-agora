<?php

namespace Modules\Cash\Services;

use App\Support\ProcedureService;
use Illuminate\Support\Collection;
use Modules\Cash\Support\TillBalanceScope;

/**
 * Till balance (T038): the seam between the screen and the procedures.
 *
 * Every figure is T-SQL — a cashup's variance is agora.vw_CashupTotals', what a
 * till balance will move it to is agora.usp_Cash_GetTillBalance's. What this
 * class adds is only what a procedure cannot know: where the settings are, who
 * the person is, and whether the literal write switch is on.
 *
 * WHAT REACHES THE CUSTOMER'S DATABASE, and only through a procedure:
 * usp_Cash_SaveTillBalance INSERTs the pair of BRN_TillBalance rows and takes
 * the number from SS_UniqueNumber section 3 — and is only called when
 * writeMode() is 'live'. Everything else reads.
 */
class TillBalanceService
{
    public function __construct(protected ProcedureService $procedures) {}

    /**
     * 'live' when a capture may write PumpIT; 'off' otherwise.
     *
     * Read from the LITERAL config('cash.tillbalance.write_mode') and nothing
     * else, and anything that is not exactly 'live' is 'off', so a typo fails
     * safe.
     */
    public function writeMode(): string
    {
        return config('cash.tillbalance.write_mode') === 'live' ? 'live' : 'off';
    }

    public function varianceFloor(): float
    {
        return (float) config('cash.tillbalance.variance_floor', 1.00);
    }

    /**
     * The site's cashups worth balancing in the period, and the till balances
     * already on them.
     *
     * @return array{cashups: Collection<int, object>, balances: Collection<int, object>}
     */
    public function period(TillBalanceScope $scope): array
    {
        $sets = $this->procedures->callSets('usp_Cash_GetTillBalance', [
            'BranchId' => $scope->branchId,
            'DateFrom' => $scope->from,
            'DateTo' => $scope->to,
            'EmployeeCode' => $scope->employeeCode,
            'VarianceFloor' => $this->varianceFloor(),
        ]);

        return [
            'cashups' => $sets[0] ?? collect(),
            'balances' => $sets[1] ?? collect(),
        ];
    }

    /**
     * Move $amount of one cashup's variance onto another — PumpIT is written:
     * the pair of BRN_TillBalance rows, and the counter.
     *
     * @param  array{date: string, till: int, shift: int}  $from  the cashup giving the amount up
     * @param  array{date: string, till: int, shift: int}  $to  the cashup taking it
     */
    public function capture(int $branchId, array $from, array $to, float|string $amount, int $userId, ?string $note = null): object
    {
        return $this->procedures->write('usp_Cash_SaveTillBalance', [
            'BranchId' => $branchId,
            'FromDate' => $from['date'],
            'FromTillNo' => $from['till'],
            'FromShiftNo' => $from['shift'],
            'ToDate' => $to['date'],
            'ToTillNo' => $to['till'],
            'ToShiftNo' => $to['shift'],
            'Amount' => $amount,
            'Note' => $note,
            'WriteMode' => $this->writeMode(),
            'UserId' => $userId,
        ]);
    }

    /**
     * The figures above the screen, counted from the procedure's own rows so
     * the strip and the lists cannot disagree.
     *
     * @param  Collection<int, object>  $cashups
     * @param  Collection<int, object>  $balances
     * @return array<string, int|float>
     */
    public function summary(Collection $cashups, Collection $balances): array
    {
        return [
            'shorts' => $cashups->filter(fn (object $c) => (float) $c->Variance < 0)->count(),
            'shortTotal' => (float) $cashups->filter(fn (object $c) => (float) $c->Variance < 0)->sum(fn (object $c) => (float) $c->Variance),
            'overs' => $cashups->filter(fn (object $c) => (float) $c->Variance > 0)->count(),
            'overTotal' => (float) $cashups->filter(fn (object $c) => (float) $c->Variance > 0)->sum(fn (object $c) => (float) $c->Variance),
            'pending' => $balances->where('Status', 'pending')->count(),
            'pendingAmount' => (float) $balances->where('Status', 'pending')->sum(fn (object $b) => (float) $b->Amount),
        ];
    }

    public static function statusTone(?string $status): string
    {
        return match ($status) {
            'approved' => 'good',
            'declined' => 'serious',
            'pending' => 'warn',
            default => 'neutral',
        };
    }

    public static function statusLabel(?string $status): string
    {
        return match ($status) {
            'approved' => 'Approved',
            'declined' => 'Declined',
            'pending' => 'Waiting for approval',
            default => (string) $status,
        };
    }
}
