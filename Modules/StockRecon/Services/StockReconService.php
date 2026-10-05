<?php

namespace Modules\StockRecon\Services;

use App\Exceptions\AgoraProcException;
use App\Support\ProcedureService;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\StockRecon\Models\StockReconRun;
use Modules\StockRecon\Models\StockReconRunLine;

/**
 * The seam between the balancing procedures and the screen.
 *
 * It does four things and deliberately no more:
 *
 *  1. **Creates the run header, then hands its id to the procedure.** Every
 *     other preview in Agora returns rows for PHP to record; a branch-month
 *     here is fifteen thousand shift lines, so the arithmetic and the write
 *     happen in one set-based pass and PHP only opens the row it will fill.
 *  2. **Resolves and validates the caps.** Config declares them; this turns
 *     what a form sent into the arguments the procedure declares, and refuses
 *     to send one it does not.
 *
 *  3. **Replays a run's own arguments when committing.** @UseOriginalCounts
 *     decides which pair of columns the commit re-checks against, so it comes
 *     off the stored run rather than off today's config.
 *  4. **Answers the drill**, and the duplicate issues a run found.
 *
 * What it does NOT do is decide anything. Every figure on the screen comes out
 * of a procedure; nothing is recomputed here. If a rule ever exists in both
 * places the procedure is right and this is the bug.
 */
class StockReconService
{
    public function __construct(protected ProcedureService $procedures) {}

    /** @return array<string, array<string, mixed>> */
    public function options(): array
    {
        /** @var array<string, array<string, mixed>> $declared */
        $declared = config('stockrecon.options');

        $options = [];

        foreach ($declared as $name => $option) {
            $options[$name] = $option + ['name' => $name];
        }

        return $options;
    }

    /** @return array<string, array<string, mixed>> */
    public function exceptionClasses(): array
    {
        return config('stockrecon.exceptions');
    }

    /**
     * The client's audit of hand balancing already in PumpIT (S7, S8, S11,
     * S12, S13, S4 …), in the order a line is given one.
     *
     * @return array<string, array<string, mixed>>
     */
    public function auditChecks(): array
    {
        return config('stockrecon.audit');
    }

    /**
     * The counting areas, for the picker.
     *
     * EVERY SITE'S AREAS AT ONCE, tagged with the branch they belong to, and
     * `linked-select.js` narrows the list in the browser as the site changes.
     * The alternative — reloading the hub whenever somebody picks a different
     * site — throws away the dates and the run name they have already typed,
     * and the ask for this screen was the least interaction that is still
     * safe. It is 250 rows across the whole estate.
     *
     * The filtering is a CONVENIENCE, not the guard: with no JavaScript every
     * area is offered, and the procedure refuses one that is not configured at
     * the chosen site by name (AGORA:NO_SUCH_AREA).
     *
     * The excluded groups are dropped rather than shown greyed — an area Agora
     * will never balance is not a choice, and offering it is offering a run
     * that can only come back empty. The screen says which groups are out and
     * why, once, above the form.
     *
     * @param  array<int, int>  $branchIds
     * @return Collection<int, object>
     */
    public function areas(array $branchIds): Collection
    {
        $excluded = (array) config('stockrecon.excluded_area_groups');
        $ids = array_map('intval', $branchIds);

        if ($ids === []) {
            return collect();
        }

        /*
         * ONLY THE AREAS THE SITE ACTUALLY COUNTS.
         *
         * STK_Area holds every area a site has ever had, and the three shift
         * bits are what say whether it is still counted — the same columns the
         * legacy 'missing count' report uses to decide what SHOULD have been
         * counted. An area with none of them ticked is history: it has no
         * shifts, so a preview scoped to it can only come back empty.
         *
         * It is most of the list. Esikhawini Convenience carries 18 areas and
         * counts 9 of them; estate-wide it is 198 of 250. Ryan, 17 Sep 2026:
         * "Please limit this list based on the site selected."
         *
         * FILTER ON THE FLAGS, NEVER ON THE GROUP NAME. 'NOT USED' is an
         * AreaGroup the customer types, and it does not mean what it says —
         * 27 of its 31 areas estate-wide are actively counted, including
         * Esikhawini's own High Shrinkage Items OK on days and nights. Rejecting
         * by that name would hide areas people are counting today.
         */
        // Interpolated because they are ints this method cast itself, and a
        // bound IN list would need a placeholder per site — 25 of them, and a
        // different statement every time the grant changes.
        return collect(DB::connection(config('agora.connections.app'))->select('
            SELECT BranchId, AreaNo, AreaDescription, AreaGroup
            FROM ['.config('agora.schema').'].[vw_StockArea]
            WHERE BranchId IN ('.implode(',', $ids).')
              AND (DayShift = 1 OR AfternoonShift = 1 OR NightShift = 1)
            ORDER BY AreaDescription
        '))
            ->reject(fn (object $area) => in_array((string) $area->AreaGroup, $excluded, true))
            ->values();
    }

    /**
     * Run a preview and record it.
     *
     * The header is created FIRST, in the transaction, so the procedure has a
     * row to fill and a failure leaves nothing half-written. The run is stored
     * whether or not anybody acts on it: a preview nobody commits is still the
     * answer to "what did this branch's counts look like in August, under these
     * caps" — which is a question the legacy procedure cannot answer at all,
     * because it keeps nothing.
     *
     * @param  array<string, scalar|null>  $options
     * @param  string|null  $note  what the person called this run, so they can
     *                             find it again — "August Hot Foods, tight caps"
     */
    public function preview(
        int $branchId,
        ?int $areaNo,
        Carbon $from,
        Carbon $to,
        array $options = [],
        ?string $groupRef = null,
        ?string $note = null,
    ): StockReconRun {
        $params = $this->parameters($options) + $this->duplicateSwitches();
        $procedure = config('agora.schema').'.usp_StockRecon_PreviewBalancing';

        $run = StockReconRun::create([
            'BranchId' => $branchId,
            'GroupRef' => $groupRef ?? (string) Str::uuid(),
            'AreaNo' => $areaNo,
            'FromDate' => $from->toDateString(),
            'ToDate' => $to->toDateString(),
            'Status' => 'previewing',
            'StampMode' => config('stockrecon.stamp_mode'),
            'ProcedureName' => $procedure,
            'ParamsJson' => json_encode($params),
            'Note' => $note,
            'CreatedBy' => auth()->id(),
            'CreatedAt' => now(),
        ]);

        $started = microtime(true);

        /*
         * write(), not call(): the procedure inserts the run's lines and
         * rewrites its header counts, and a refusal — a window the wrong way
         * round, an area that is not configured at this site — has to arrive
         * as an AgoraProcException with its code intact rather than as an
         * empty grid the reader would take for "this branch is clean".
         */
        $this->procedures->write('usp_StockRecon_PreviewBalancing', [
            'RunId' => $run->Id,
            'BranchId' => $branchId,
            'FromDate' => $from->toDateString(),
            'ToDate' => $to->toDateString(),
            'AreaNo' => $areaNo,
            'ExcludedAreaGroups' => implode(',', (array) config('stockrecon.excluded_area_groups')),
            'UserId' => auth()->id(),
            ...$params,
        ]);

        $elapsed = (int) round((microtime(true) - $started) * 1000);

        /*
         * fresh(), not a save of $elapsed onto the model in hand.
         *
         * The procedure rewrote nineteen columns of this row. Saving the model
         * as PHP last saw it would put every count back to the zero it was
         * created with — the header would then say "0 proposals" over a table
         * of fourteen thousand.
         */
        StockReconRun::query()
            ->where('BranchId', $branchId)
            ->where('Id', $run->Id)
            ->update(['PreviewMs' => $elapsed]);

        return $run->fresh();
    }

    /**
     * Commit a run: write the amendments the operator ticked.
     *
     * The stamp mode is read from config and PASSED IN rather than looked up by
     * the procedure, so there is exactly one place in the codebase that decides
     * whether Agora writes to the customer's estate.
     *
     * @return array{status: object, lines: Collection<int, object>}
     */
    public function commit(StockReconRun $run): array
    {
        $sets = $this->procedures->callSets('usp_StockRecon_Commit', [
            'RunId' => $run->Id,
            'BranchId' => $run->BranchId,
            'StampMode' => (string) config('stockrecon.stamp_mode'),
            // Read here, like the stamp mode: whether a clear duplicate's issue
            // may be written. The procedure refuses a run that removed one if
            // this has been switched off since.
            'IssueMode' => (string) config('stockrecon.issue_mode', 'off'),
            // And whether a possible duplicate (D2) still holds its chain back:
            // the procedure re-checks every D2 against the release ledger at
            // the press, so a release withdrawn since the preview is caught.
            'BlockPossibleDuplicates' => $this->duplicateSwitches()['BlockPossibleDuplicates'],
            // Replayed off the run, never defaulted here — see the model.
            'UseOriginalCounts' => (int) $run->usedOriginalCounts(),
            'UserId' => auth()->id(),
        ]);

        $status = ($sets[0] ?? collect())->first();

        /*
         * callSets() rather than write(): the procedure returns its status row
         * AND a row per candidate saying what happened to it, and a caller that
         * could not see the second set would have no way to tell the operator
         * why four of forty were skipped. The refusal contract still holds — a
         * THROW inside the procedure arrives as an AgoraProcException before
         * this line runs.
         */
        if ($status === null || ! (bool) $status->Ok) {
            throw new AgoraProcException(
                $status->Code ?? 'REFUSED',
                $status->Message ?? 'The balancing was declined without saying why.',
                'usp_StockRecon_Commit',
            );
        }

        return ['status' => $status, 'lines' => $sets[1] ?? collect()];
    }

    /**
     * Release possible duplicate issues (D2) from holding their chains back,
     * with one reason for the lot.
     *
     * The rules — a D2 on this run, the issue PumpIT still holds, a reason that
     * says something — are the procedure's. A release is keyed on the shift and
     * the issue, not on this run, so it takes effect on the NEXT preview; the
     * run it was pressed on still holds the chain back.
     *
     * @param  array<int, int>  $lineIds  run line ids on this run
     */
    public function releaseDuplicates(StockReconRun $run, array $lineIds, string $reason): object
    {
        return $this->procedures->write('usp_StockRecon_ReleaseDuplicate', [
            'BranchId' => $run->BranchId,
            'RunId' => $run->Id,
            'LineIds' => implode(',', array_map('intval', $lineIds)),
            'Reason' => $reason,
            'UserId' => auth()->id(),
        ]);
    }

    /**
     * Withdraw a release: from the next preview the possible duplicate holds
     * its chain back again. Never a delete — the release and its withdrawal
     * both stay on the ledger.
     */
    public function withdrawRelease(StockReconRun $run, int $releaseId, ?string $note = null): object
    {
        return $this->procedures->write('usp_StockRecon_WithdrawRelease', [
            'BranchId' => $run->BranchId,
            'ReleaseId' => $releaseId,
            'Note' => $note,
            'UserId' => auth()->id(),
        ]);
    }

    /** Undo a commit — exactly what it wrote, and nothing else. */
    public function reverse(StockReconRun $run, string $reason): object
    {
        return $this->procedures->write('usp_StockRecon_Reverse', [
            'RunId' => $run->Id,
            'BranchId' => $run->BranchId,
            'Reason' => $reason,
            'UserId' => auth()->id(),
        ]);
    }

    /**
     * Throw away previews, and say how many.
     *
     * The rule that a committed run is never discarded lives in the procedure,
     * not here: a preview is a record of a read and clearing it destroys
     * nothing, but a committed run is the only record of what was amended.
     */
    public function discard(int $branchId, ?int $areaNo = null, ?int $runId = null): int
    {
        $status = $this->procedures->write('usp_StockRecon_DiscardRuns', [
            'BranchId' => $branchId,
            'AreaNo' => $areaNo,
            'RunId' => $runId,
            'UserId' => auth()->id(),
            'MineOnly' => 1,
        ]);

        return (int) $status->Id;
    }

    /**
     * Tick or untick proposals on a run.
     *
     * Eloquent rather than a procedure: this is the operator's working state,
     * not a business rule. Nothing about a tick is true or false — what it
     * MEANS is decided at commit, where the procedure re-checks every row.
     *
     * @param  array<int, int>  $lineIds
     */
    public function select(StockReconRun $run, array $lineIds): int
    {
        /*
         * THE TABLE, NAMED, not the model.
         *
         * StockReconRunLine reads agora.vw_StockReconRunLine so that a run made
         * before v1__14a still shows an item name and the shift's employee —
         * see the note on that model. The view joins the stock master, the area
         * master and the shift-employee aggregate, and SQL Server will not
         * update through a joined view. This is the only Eloquent write to a
         * run line in the module; everything else goes through a procedure.
         *
         * BranchId is passed explicitly, so nothing is lost by stepping outside
         * BranchScope here — the scope would have applied the same predicate.
         */
        $table = config('agora.schema').'.StockReconRunLine';

        $lines = fn () => DB::connection((new StockReconRunLine)->getConnectionName())
            ->table($table)
            ->where('BranchId', $run->BranchId)
            ->where('RunId', $run->Id);

        $lines()->update(['Selected' => false]);

        if ($lineIds === []) {
            return 0;
        }

        return $lines()
            // Only a row the commit could actually honour may be ticked. A tick
            // on anything else would be a promise the commit has to break.
            // A blocked chain qualifies only where it is being put back to the
            // staff counts, or where a clear duplicate issue is taken out on
            // its own (StockReconRunLine::isWritable()).
            ->where('WouldAmend', true)
            ->where(fn ($q) => $this->writableOnBlockedChain($q))
            ->where('CommitState', 'pending')
            ->whereIn('Id', $lineIds)
            ->update(['Selected' => true]);
    }

    /**
     * Tick everything the commit could still honour.
     *
     * The default state of the screen: the preview sets Selected = WouldAmend
     * "because the ask is the least interaction". After a SINGLE-row amend the
     * selection has been narrowed to that one row, and leaving it there would
     * hand the operator a screen where nothing is ticked and no explanation of
     * why — so the default is put back, minus whatever just committed.
     *
     * Same predicate as select(), deliberately: two definitions of "a row the
     * commit could honour" is how one of them ends up offering a tick the
     * commit has to break.
     */
    public function selectAllAmendable(StockReconRun $run): int
    {
        $table = config('agora.schema').'.StockReconRunLine';

        return DB::connection((new StockReconRunLine)->getConnectionName())
            ->table($table)
            ->where('BranchId', $run->BranchId)
            ->where('RunId', $run->Id)
            ->where('WouldAmend', true)
            ->where(fn ($q) => $this->writableOnBlockedChain($q))
            ->where('CommitState', 'pending')
            ->update(['Selected' => true]);
    }

    /**
     * The chain half of "a row the commit could honour" — the same predicate
     * usp_StockRecon_Commit and StockReconRunLine::isWritable() apply: a clear
     * chain; a blocked one being put back to the staff counts; or a clear
     * duplicate issue taken out on its own (QtyIssuedNew is set). Never a
     * chain a possible duplicate holds back (DupHold).
     */
    private function writableOnBlockedChain(Builder $query): Builder
    {
        return $query->where('DupHold', false)
            ->where(fn (Builder $q) => $q->where('ChainBlocked', false)
                ->orWhere('RestoresHand', true)
                ->orWhereNotNull('QtyIssuedNew'));
    }

    /**
     * The two config switches the preview takes beyond the run's options.
     * Recorded with them on the run (ParamsJson), so what a run did can be read
     * back off it.
     *
     *   RemoveDuplicates         config('stockrecon.issue_mode') is 'live': a
     *                            clear duplicate (D1) is taken out of the basis
     *                            and proposed for the commit.
     *   BlockPossibleDuplicates  config('stockrecon.exceptions.D2.blocks'): a
     *                            possible one (D2) with no release standing
     *                            holds its chain back — nothing on it is
     *                            written. On since 5 Oct 2026 (PM decision
     *                            01a10b21). The commit takes it too.
     *
     * @return array{RemoveDuplicates: int, BlockPossibleDuplicates: int}
     */
    protected function duplicateSwitches(): array
    {
        return [
            'RemoveDuplicates' => (int) (config('stockrecon.issue_mode') === 'live'),
            'BlockPossibleDuplicates' => (int) (bool) config('stockrecon.exceptions.D2.blocks', false),
        ];
    }

    /**
     * The chain behind one shift — the row detail panel.
     *
     * A shift on its own cannot be judged: its variance came from the counts on
     * either side of it, its amendment was decided by the whole item's running
     * total, and whether it is blocked was decided by a DIFFERENT shift
     * somewhere else in the chain.
     *
     * @return array{item: ?object, shifts: Collection<int, object>}
     */
    public function chain(StockReconRun $run, StockReconRunLine $line): array
    {
        $sets = $this->procedures->callSets('usp_StockRecon_DrillChain', [
            'RunId' => $run->Id,
            'BranchId' => $run->BranchId,
            'LineId' => $line->Id,
        ]);

        return [
            'item' => ($sets[0] ?? collect())->first(),
            'shifts' => $sets[1] ?? collect(),
        ];
    }

    /**
     * The duplicate issues a run found — the "Duplicate issues" panel on the
     * run and on its exceptions (Ryan, 1 Oct 2026).
     *
     * One call, three result sets, and nothing recomputed here: each candidate
     * with its state, the shifts that prove it (the twin, the shift as keyed,
     * and for a clear one the shift corrected), and the totals. The shifts are
     * hung on their candidate so the view loops once.
     *
     * @return array{procedure: string, cases: Collection<int, object>, totals: ?object}
     */
    public function duplicates(StockReconRun $run): array
    {
        $sets = $this->procedures->callSets('usp_StockRecon_GetDuplicates', [
            'BranchId' => $run->BranchId,
            'RunId' => $run->Id,
        ]);

        $shifts = ($sets[1] ?? collect())->groupBy(fn (object $row) => (int) $row->LineId);

        return [
            'procedure' => config('agora.schema').'.usp_StockRecon_GetDuplicates',
            'cases' => ($sets[0] ?? collect())->map(function (object $case) use ($shifts) {
                $case->shifts = $shifts->get((int) $case->LineId, collect())->values();

                return $case;
            })->values(),
            'totals' => ($sets[2] ?? collect())->first(),
        ];
    }

    /**
     * Turn what the form sent into the arguments the procedure declares.
     *
     * Only declared options are sent. A value outside its declared range is
     * clamped rather than refused: these are plausibility caps, and a cap that
     * throws a 422 at somebody who typed 1.5 has stopped them working over a
     * setting that only ever blocks more or fewer chains.
     *
     * @param  array<string, scalar|null>  $options
     * @return array<string, scalar|null>
     */
    protected function parameters(array $options): array
    {
        $params = [];

        foreach ($this->options() as $name => $declaration) {
            $value = $options[$name] ?? $declaration['default'];

            if ($value === null || $value === '') {
                $value = $declaration['default'];
            }

            $params[$name] = match ($declaration['type'] ?? 'text') {
                'int' => (int) min(max((int) $value, $declaration['min'] ?? PHP_INT_MIN), $declaration['max'] ?? PHP_INT_MAX),
                'decimal' => (float) min(max((float) $value, $declaration['min'] ?? -INF), $declaration['max'] ?? INF),
                'bool' => (int) (bool) $value,
                default => (string) $value,
            };
        }

        return $params;
    }
}
