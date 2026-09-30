<?php

namespace Modules\Cash\Services;

use App\Support\ProcedureService;
use Illuminate\Support\Collection;
use Modules\Cash\Support\DropSafeScope;

/**
 * The drop safe (T039): the seam between the screens and the procedures.
 *
 * Every figure is T-SQL — what is in the safe is agora.usp_Cash_DropSafeInSafe
 * and agora.usp_Cash_GetDropSafe, the rules on a bag are
 * agora.usp_Cash_SaveDropSafe's. What this class adds is only what a procedure
 * cannot know: where the settings are, who the person is, and whether the
 * literal write switch is on.
 *
 * WHAT REACHES THE CUSTOMER'S DATABASE, and only through a procedure, only
 * while writeMode() is 'live':
 *   drop() / edit()  usp_Cash_SaveDropSafe      BRN_DropSafe (INSERT / UPDATE)
 *   void()           usp_Cash_VoidDropSafe      BRN_DropSafe (DELETE, the whole
 *                                               row kept in agora.DropSafeEvent)
 *   collect()        usp_Cash_CollectDropSafe   BRN_DropSafe_Collection
 *                                               (INSERT), BRN_DropSafe
 *                                               (CollectionId UPDATE)
 * Everything else reads.
 */
class DropSafeService
{
    /** The badge a console reads for the drop safe step. */
    public const BADGE = 'cash.dropsafe.insafe';

    public function __construct(protected ProcedureService $procedures) {}

    /**
     * 'live' when a press may write PumpIT; 'off' otherwise.
     *
     * Read from the LITERAL config('cash.dropsafe.write_mode') and nothing
     * else, and anything that is not exactly 'live' is 'off', so a typo fails
     * safe.
     */
    public function writeMode(): string
    {
        return config('cash.dropsafe.write_mode') === 'live' ? 'live' : 'off';
    }

    /** Above this a bag needs a reason — cash.dropsafe.reason_threshold (setting). */
    public function threshold(): float
    {
        return (float) config('cash.dropsafe.reason_threshold', 2000.00);
    }

    /**
     * One site: the bags in the safe, the collections in the period, the lists
     * a capture chooses from, and the totals.
     *
     * @return array{bags: Collection<int, object>, collections: Collection<int, object>, reasons: Collection<int, object>,
     *               limitReasons: Collection<int, object>, employees: Collection<int, object>, summary: ?object}
     */
    public function site(DropSafeScope $scope): array
    {
        $sets = $this->procedures->callSets('usp_Cash_GetDropSafe', [
            'BranchId' => $scope->branchId,
            'DateFrom' => $scope->from,
            'DateTo' => $scope->to,
            'Threshold' => $this->threshold(),
        ]);

        return [
            'bags' => $sets[0] ?? collect(),
            'collections' => $sets[1] ?? collect(),
            'reasons' => $sets[2] ?? collect(),
            'limitReasons' => $sets[3] ?? collect(),
            'employees' => $sets[4] ?? collect(),
            'summary' => ($sets[5] ?? collect())->first(),
        ];
    }

    /**
     * What is in the safe, per site. Empty `$branchIds` means every site.
     *
     * @param  array<int, int>  $branchIds
     * @return Collection<int, object>
     */
    public function inSafe(array $branchIds = []): Collection
    {
        return $this->procedures->call('usp_Cash_DropSafeInSafe', [
            'BranchIds' => $branchIds === [] ? null : implode(',', $branchIds),
        ]);
    }

    /**
     * The branch console's drop safe step (T029 draws it; the day-close spine
     * does not exist yet). Done when nothing is left in the site's safe — a
     * collection that empties it clears the step.
     *
     * @return array{title: string, detail: string, done: bool, href: ?string, bags: int, amount: float}
     */
    public function consoleStep(int $branchId): array
    {
        $row = $this->inSafe([$branchId])->first();
        $bags = (int) ($row->Bags ?? 0);

        return [
            'title' => 'Drop safe',
            'detail' => self::stepText($bags),
            'done' => $bags === 0,
            'href' => route('app.cash.dropsafe', ['branch_id' => $branchId]),
            'bags' => $bags,
            'amount' => (float) ($row->Amount ?? 0),
        ];
    }

    /** The mockup's wording for the step, and for the badge. */
    public static function stepText(int $bags): string
    {
        return $bags > 0
            ? $bags.($bags === 1 ? ' bag' : ' bags').' not collected'
            : 'All bags collected';
    }

    /**
     * A new bag into the safe — PumpIT is written (one BRN_DropSafe row).
     *
     * @param  array{date: string, time: ?string, amount: string, reason: int, ref: ?string, limitReason: ?string, cashier: string, manager: ?string}  $bag
     */
    public function drop(int $branchId, string $bagNo, array $bag, int $userId, ?string $note = null): object
    {
        return $this->save('new', $branchId, $bagNo, null, $bag, $userId, $note);
    }

    /**
     * Correct a bag still in the safe and on no cashup — PumpIT is written
     * (the one BRN_DropSafe row), with what it replaced kept in Agora.
     *
     * @param  array{date: string, time: ?string, amount: string, reason: int, ref: ?string, limitReason: ?string, cashier: string, manager: ?string}  $bag
     */
    public function edit(int $branchId, string $bagNo, ?string $newBagNo, array $bag, int $userId, ?string $note = null): object
    {
        return $this->save('edit', $branchId, $bagNo, $newBagNo, $bag, $userId, $note);
    }

    /**
     * Void a bag captured in error — PumpIT is written (its BRN_DropSafe row
     * is DELETEd, after the whole row is kept in agora.DropSafeEvent). Only a
     * bag still in the safe and on no cashup; the reason is required.
     */
    public function void(int $branchId, string $bagNo, ?string $reason, int $userId): object
    {
        return $this->procedures->write('usp_Cash_VoidDropSafe', [
            'BranchId' => $branchId,
            'BagNo' => $bagNo,
            'Reason' => $reason,
            'WriteMode' => $this->writeMode(),
            'UserId' => $userId,
        ]);
    }

    /**
     * Hand bags to the security company — PumpIT is written (one
     * BRN_DropSafe_Collection row, and CollectionId on each bag).
     *
     * @param  array<int, string>  $bagNos
     * @param  array{dbag: string, manager: string, security: string, date: string, time: ?string}  $collection
     */
    public function collect(int $branchId, array $bagNos, array $collection, int $userId, ?string $note = null): object
    {
        return $this->procedures->write('usp_Cash_CollectDropSafe', [
            'BranchId' => $branchId,
            'BagsJson' => json_encode(array_values($bagNos), JSON_THROW_ON_ERROR),
            'DBagNo' => $collection['dbag'],
            'ManagerCode' => $collection['manager'],
            'SecurityName' => $collection['security'],
            'CollectionDate' => $collection['date'],
            'CollectionTime' => $collection['time'],
            'Note' => $note,
            'WriteMode' => $this->writeMode(),
            'UserId' => $userId,
        ]);
    }

    /**
     * @param  array{date: string, time: ?string, amount: string, reason: int, ref: ?string, limitReason: ?string, cashier: string, manager: ?string}  $bag
     */
    private function save(string $mode, int $branchId, string $bagNo, ?string $newBagNo, array $bag, int $userId, ?string $note): object
    {
        return $this->procedures->write('usp_Cash_SaveDropSafe', [
            'BranchId' => $branchId,
            'Mode' => $mode,
            'BagNo' => $bagNo,
            'NewBagNo' => $newBagNo,
            'DropDate' => $bag['date'],
            'DropTime' => $bag['time'],
            'Amount' => $bag['amount'],
            'ReasonForManualBagId' => $bag['reason'],
            'RefNoFaultReported' => $bag['ref'],
            'LimitReason' => $bag['limitReason'],
            'CashierCode' => $bag['cashier'],
            'ManagerCode' => $bag['manager'],
            'Threshold' => $this->threshold(),
            'Note' => $note,
            'WriteMode' => $this->writeMode(),
            'UserId' => $userId,
        ]);
    }

    /** "In the safe" is a thing to act on, never an alarm: warn while bags wait. */
    public static function inSafeTone(int $bags): string
    {
        return $bags > 0 ? 'warn' : 'good';
    }
}
