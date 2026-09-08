<?php

namespace Modules\Recon\Grids;

use App\Grid\GridPage;
use App\Grid\GridQuery;
use App\Grid\Sources\GridSource;
use Illuminate\Support\Collection;
use Modules\Recon\Models\ReconRun;
use Modules\Recon\Models\ReconRunLine;
use Modules\Recon\Services\ReconService;

/**
 * The rows BEHIND a run's proposals — one side of them — as a grid source.
 *
 * A proposal is a total. The two sides are what it is made of, and until now
 * they existed only inside the expand panel, one proposal at a time. This is
 * the same rows, for a whole run or for one proposal, so either can be
 * extracted.
 *
 * WHY IT CANNOT BE A QUERY. There is no table of these. A COMMITTED line's
 * rows are in agora.ReconMatch, but a PREVIEW's are derived: the extraction
 * rules live in usp_Recon_DrillBank and usp_Recon_DrillMops, and they are
 * asked per line, by key and window. ReconService::sides() already knows which
 * of the two to read — that is the method the expand panel uses — so this
 * calls it per line and concatenates. Re-deriving that choice here would be a
 * second copy of a rule that has already been wrong once this week.
 *
 * THE COST IS REAL AND IS WHY `line` EXISTS. On the customer's instance a
 * drill runs in about 190 ms, so one proposal is instant and a 160-line
 * preview is around thirty seconds. A committed run costs nothing like that —
 * ReconMatch is one query per line and no procedure runs at all. The screen
 * offers the whole-run export where it is cheap and says so where it is not;
 * the per-proposal export is always immediate.
 *
 * PAGED IN MEMORY, WHICH THE INTERFACE OTHERWISE FORBIDS. GridSource exists to
 * stop a source returning everything and letting the caller slice, and that
 * rule is right for a table with ten thousand rows in it. Here the whole set
 * has to be assembled before it can be counted at all — there is nothing to
 * count without resolving every line — so the slice happens after. The bound
 * is the run's line count, which is the same bound the screen already renders.
 */
final class ReconSideSource implements GridSource
{
    /** @param 'bank'|'mops' $side */
    public function __construct(
        private string $side,
        private ReconService $service,
    ) {}

    public function page(GridQuery $query): GridPage
    {
        $started = microtime(true);

        $rows = $this->rows();
        $total = $rows->count();

        $slice = $rows
            ->slice(max(0, ($query->page - 1) * $query->pageSize), $query->pageSize)
            ->values();

        return new GridPage(
            rows: $slice,
            total: $total,
            ms: (microtime(true) - $started) * 1000,
        );
    }

    /**
     * Every row on this side, stamped with the proposal it belongs to.
     *
     * The proposal's own reference travels on each row, because that is what
     * makes the file a working document rather than a list: "which bank lines
     * settled batch 204" is the question somebody opens this to answer, and a
     * flat list of statement lines cannot answer it.
     *
     * @return Collection<int, object>
     */
    private function rows(): Collection
    {
        [$run, $lines] = $this->scope();

        if ($run === null) {
            return collect();
        }

        $out = collect();

        foreach ($lines as $line) {
            $sides = $this->service->sides($run, $line);

            foreach ($sides[$this->side] as $row) {
                $out->push($this->stamp($run, $line, $row));
            }
        }

        return $out;
    }

    /**
     * What was asked for: one proposal, or a whole run.
     *
     * `line` wins over `run` when both are present — it is the narrower and
     * the cheaper, and a caller that named a line meant that line.
     *
     * Neither present means NOTHING. This endpoint is reachable by anyone who
     * may open the recon screen, and a missing parameter must not widen into
     * every proposal in the estate.
     *
     * @return array{0: ReconRun|null, 1: Collection<int, ReconRunLine>}
     */
    private function scope(): array
    {
        $lineId = request()->query('line');
        $runId = request()->query('run');

        if (is_numeric($lineId)) {
            $line = ReconRunLine::query()->acrossBranches()->find((int) $lineId);

            if ($line === null) {
                return [null, collect()];
            }

            $run = ReconRun::query()->acrossBranches()->find($line->RunId);

            return [$run, collect([$line])];
        }

        if (! is_numeric($runId)) {
            return [null, collect()];
        }

        $run = ReconRun::query()->acrossBranches()->find((int) $runId);

        if ($run === null) {
            return [null, collect()];
        }

        return [$run, ReconRunLine::query()->acrossBranches()->where('RunId', $run->Id)->get()];
    }

    /**
     * One source row, plus the proposal it sits under.
     *
     * The two drills return different shapes — they are describing different
     * things — so this normalises into one row shape per side rather than
     * asking the column catalogue to cope with both.
     */
    private function stamp(ReconRun $run, ReconRunLine $line, object $row): object
    {
        $common = [
            'RunId' => $run->Id,
            'LineId' => $line->Id,
            'KeyRef' => $line->KeyRef,
            'KeyRef2' => $line->KeyRef2,
            'Outcome' => $line->Outcome,
            'CommitState' => $line->CommitState,
            'ReconBatchNo' => $line->ReconBatchNo,
        ];

        if ($this->side === 'bank') {
            return (object) ($common + [
                'BankStatementLineID' => $row->BankStatementLineID ?? null,
                'LineDate' => $row->LineDate ?? null,
                'Description' => $row->Description ?? null,
                'Leg' => $row->Leg ?? null,
                'Amount' => $row->Amount ?? null,
                'ReconState' => $row->ReconState ?? null,
                'SourceReconBatchNo' => $row->ReconBatchNo ?? null,
            ]);
        }

        return (object) ($common + [
            'SourceRef' => $row->SourceRef ?? null,
            'SourceDate' => $row->SourceDate ?? null,
            'Detail' => $row->Detail ?? null,
            'SourceId' => $row->SourceId ?? null,
            'Amount' => $row->Amount ?? null,
        ]);
    }

    /**
     * Named honestly: on a committed line these rows are read back out of
     * agora.ReconMatch, and on a preview they come from the drill. The screen
     * shows whichever is true.
     */
    public function name(): string
    {
        return $this->side === 'bank'
            ? 'agora.usp_Recon_DrillBank · agora.ReconMatch'
            : 'agora.usp_Recon_DrillMops · agora.ReconMatch';
    }

    /** Assembled in PHP, so a per-column header filter has nothing to push down to. */
    public function supportsFilters(): bool
    {
        return false;
    }
}
