<?php

namespace Modules\StockRecon\Grids;

use App\Grid\GridColumn;
use App\Grid\GridDefinition;
use App\Grid\Sources\EloquentSource;
use App\Grid\Sources\GridSource;
use Modules\StockRecon\Models\StockReconRunLine;

/**
 * A run's proposals, as a grid — for the EXTRACT.
 *
 * WHY THIS EXISTS BESIDE THE SCREEN'S OWN TABLE RATHER THAN REPLACING IT. The
 * run screen renders a bespoke `<x-table>`, and it has to: every row carries a
 * tick box that decides what a commit will write, the whole table is inside the
 * commit form, and clicking a row opens the chain behind it. `<x-data-grid>`
 * has no vocabulary for any of that.
 *
 * But "how do I get this into Excel" should not have a different answer
 * depending on which component a screen happens to use — and on this screen it
 * matters more than most, because in journal mode the extract IS the deliverable:
 * it is the worklist an admin applies by hand.
 *
 * SCOPED BY THE RUN IN THE REQUEST. An absent or unknown run yields NO ROWS
 * rather than every line ever proposed: the extract endpoint is reachable by
 * anyone who may see the screen, and a missing parameter must not turn into the
 * whole table.
 */
class StockReconRunLineGrid extends GridDefinition
{
    public function key(): string
    {
        return 'app.stockrecon.run';
    }

    public function title(): string
    {
        return 'Balancing proposals';
    }

    public function blurb(): ?string
    {
        return 'Every shift on one run: what was counted, what the method proposes, and why a line '
            .'was reported rather than amended.';
    }

    public function columns(): array
    {
        return [
            new GridColumn(key: 'Outcome', label: 'Outcome', sort: 'Outcome', wide: true),
            new GridColumn(key: 'ExceptionCode', label: 'Class', sort: 'ExceptionCode'),
            new GridColumn(key: 'CommitState', label: 'State', sort: 'CommitState'),
            new GridColumn(key: 'AreaNo', label: 'Area', format: 'number', sort: 'AreaNo'),
            new GridColumn(key: 'StockItemNo', label: 'Item no', sort: 'StockItemNo', mono: true),
            new GridColumn(key: 'TransactionDate', label: 'Date', format: 'date', sort: 'TransactionDate'),
            new GridColumn(key: 'ShiftNo', label: 'Shift', format: 'number', sort: 'ShiftNo'),
            new GridColumn(key: 'QtyOpen', label: 'Open', format: 'number', sort: 'QtyOpen'),
            new GridColumn(key: 'QtyIssued', label: 'Issued', format: 'number', sort: 'QtyIssued'),
            new GridColumn(key: 'QtyClose', label: 'Close', format: 'number', sort: 'QtyClose'),
            new GridColumn(key: 'QtyPOS', label: 'POS', format: 'number', sort: 'QtyPOS'),
            new GridColumn(key: 'QtyVar', label: 'Variance', format: 'number', sort: 'QtyVar'),
            // The two an admin working the journal actually retypes.
            new GridColumn(key: 'QtyOpenNew', label: 'Open (balanced)', format: 'number'),
            new GridColumn(key: 'QtyCloseNew', label: 'Close (balanced)', format: 'number'),
            new GridColumn(key: 'AmendClose', label: 'Amendment', format: 'number', sort: 'AmendClose'),
            new GridColumn(key: 'QtyVarNew', label: 'Variance after', format: 'number', sort: 'QtyVarNew'),
            new GridColumn(key: 'ChainNetVar', label: 'Chain total', format: 'number', visible: false),
            new GridColumn(key: 'IsDormant', label: 'Dormant', format: 'bool', visible: false),
            new GridColumn(key: 'ChainBlocked', label: 'Chain blocked', format: 'bool', visible: false),
            new GridColumn(key: 'BlockReason', label: 'Why it was skipped', wide: true, visible: false),
        ];
    }

    public function source(): GridSource
    {
        $runId = $this->runId();

        return new EloquentSource(
            // A closure, not a builder: a shared one accumulates the last
            // request's where clauses.
            query: fn () => StockReconRunLine::query()
                ->acrossBranches()
                // 0 matches nothing. An extract asked for without a run must
                // come back empty, never as every proposal in the estate.
                ->where('RunId', $runId ?? 0),
            searchable: ['StockItemNo', 'Outcome', 'BlockReason'],
            sortable: [
                'Outcome' => 'Outcome',
                'ExceptionCode' => 'ExceptionCode',
                'CommitState' => 'CommitState',
                'AreaNo' => 'AreaNo',
                'StockItemNo' => 'StockItemNo',
                'TransactionDate' => 'TransactionDate',
                'ShiftNo' => 'ShiftNo',
                'QtyOpen' => 'QtyOpen',
                'QtyIssued' => 'QtyIssued',
                'QtyClose' => 'QtyClose',
                'QtyPOS' => 'QtyPOS',
                'QtyVar' => 'QtyVar',
                'AmendClose' => 'AmendClose',
                'QtyVarNew' => 'QtyVarNew',
            ],
        );
    }

    /** The screen's own order: the chain, as the procedure laid it out. */
    public function defaultSort(): ?string
    {
        return null;
    }

    /** One run belongs to one branch already; a site selector would say nothing. */
    public function branchSelector(): bool
    {
        return false;
    }

    private function runId(): ?int
    {
        $run = request()->query('run');

        return is_numeric($run) ? (int) $run : null;
    }
}
