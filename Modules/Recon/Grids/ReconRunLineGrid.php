<?php

namespace Modules\Recon\Grids;

use App\Grid\GridColumn;
use App\Grid\GridDefinition;
use App\Grid\Sources\EloquentSource;
use App\Grid\Sources\GridSource;
use Modules\Recon\Models\ReconRunLine;

/**
 * A reconciliation run's proposals, as a grid — for the EXTRACT.
 *
 * WHY THIS EXISTS BESIDE THE SCREEN'S OWN TABLE RATHER THAN REPLACING IT.
 * The run screen renders a bespoke `<x-table>`, and it has to: every row
 * carries a tick box that decides what a commit will stamp, the whole table is
 * inside the execute form, and clicking a row opens the two sides behind the
 * total. `<x-data-grid>` has no vocabulary for any of that, and rewriting a
 * screen the customer reconciles against in order to gain a download button
 * would be trading a working thing for a feature.
 *
 * But "how do I get this into Excel" should not have a different answer
 * depending on which component a screen happens to use. So the rows get a real
 * GridDefinition, the standard extract endpoint serves them, and the screen
 * includes the same drawer every other grid has. Nothing about the table
 * changes.
 *
 * SCOPED BY THE RUN IN THE REQUEST. A grid definition is registered once under
 * one key, and these rows only mean anything inside one run — so the source
 * closure reads `run` off the request. EloquentSource takes a closure exactly
 * so a fresh builder is made per call, which is what makes that safe.
 *
 * An absent or unknown run yields NO ROWS rather than every line ever
 * proposed. The extract endpoint is reachable by anyone who may see the recon
 * screen, and a missing parameter must not turn into the whole table.
 */
class ReconRunLineGrid extends GridDefinition
{
    public function key(): string
    {
        return 'app.recon.run';
    }

    public function title(): string
    {
        return 'Reconciliation proposals';
    }

    public function blurb(): ?string
    {
        return 'Every proposal on one run, with both sides and the difference between them.';
    }

    /**
     * The columns the screen shows, in its order.
     *
     * The area-specific ones — the CC and DD legs, the population — are
     * declared for every area and simply come out empty where the area does
     * not use them. A definition cannot know which run it is being asked
     * about at the moment its catalogue is read, and a column that is
     * sometimes absent would make a saved layout mean different things on
     * different areas.
     */
    public function columns(): array
    {
        return [
            new GridColumn(key: 'Outcome', label: 'Outcome', sort: 'Outcome', format: 'chip'),
            new GridColumn(key: 'CommitState', label: 'State', sort: 'CommitState'),
            new GridColumn(key: 'ReconBatchNo', label: 'Batch', sort: 'ReconBatchNo', mono: true),
            new GridColumn(key: 'KeyRef', label: 'Reference', sort: 'KeyRef', mono: true),
            new GridColumn(key: 'KeyRef2', label: 'Second reference', sort: 'KeyRef2', mono: true, visible: false),
            new GridColumn(key: 'MopsKeyRef', label: 'Deposit reference', mono: true, visible: false),
            new GridColumn(key: 'Population', label: 'Population', sort: 'Population', visible: false),
            new GridColumn(key: 'BankDate', label: 'Bank date', format: 'date', sort: 'BankDate'),
            new GridColumn(key: 'BankLines', label: 'Bank lines', format: 'number', sort: 'BankLines'),
            new GridColumn(key: 'BankTotal', label: 'Bank', format: 'money', sort: 'BankTotal', total: true),
            new GridColumn(key: 'BankCC', label: 'CC', format: 'money', visible: false),
            new GridColumn(key: 'BankDD', label: 'DD', format: 'money', visible: false),
            new GridColumn(key: 'MopsTxns', label: 'Deposits', format: 'number', sort: 'MopsTxns'),
            new GridColumn(key: 'MopsTotal', label: 'Deposit', format: 'money', sort: 'MopsTotal', total: true),
            new GridColumn(key: 'DiffAmount', label: 'Difference', format: 'money', sort: 'DiffAmount', total: true),
            new GridColumn(key: 'BankNarrative', label: 'Narrative', wide: true, visible: false),
            new GridColumn(key: 'BlockReason', label: 'Why it was skipped', wide: true, visible: false),
            new GridColumn(key: 'NearRefNote', label: 'Pairing note', wide: true, visible: false),
            new GridColumn(key: 'UsedProcessOrder', label: 'Rule', format: 'number', visible: false),
        ];
    }

    public function source(): GridSource
    {
        $runId = $this->runId();

        return new EloquentSource(
            // A closure, not a builder: a shared one accumulates the last
            // request's where clauses.
            query: fn () => ReconRunLine::query()
                ->acrossBranches()
                // 0 matches nothing. An extract asked for without a run must
                // come back empty, never as every proposal in the estate.
                ->where('RunId', $runId ?? 0),
            searchable: ['KeyRef', 'KeyRef2', 'Outcome', 'BankNarrative'],
            sortable: [
                'Outcome' => 'Outcome',
                'CommitState' => 'CommitState',
                'ReconBatchNo' => 'ReconBatchNo',
                'KeyRef' => 'KeyRef',
                'KeyRef2' => 'KeyRef2',
                'Population' => 'Population',
                'BankDate' => 'BankDate',
                'BankLines' => 'BankLines',
                'BankTotal' => 'BankTotal',
                'MopsTxns' => 'MopsTxns',
                'MopsTotal' => 'MopsTotal',
                'DiffAmount' => 'DiffAmount',
            ],
        );
    }

    /** The screen's own order: as the procedure proposed them. */
    public function defaultSort(): ?string
    {
        return null;
    }

    /** One run belongs to one branch already; a site selector would say nothing. */
    public function branchSelector(): bool
    {
        return false;
    }

    /**
     * The run these rows belong to, off the request.
     *
     * `null` rather than a guess when it is absent or not a number — the
     * source turns that into an empty result, which is the safe reading.
     */
    private function runId(): ?int
    {
        $run = request()->query('run');

        return is_numeric($run) ? (int) $run : null;
    }
}
