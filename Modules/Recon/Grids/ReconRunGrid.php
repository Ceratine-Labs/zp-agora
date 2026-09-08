<?php

namespace Modules\Recon\Grids;

use App\Grid\GridColumn;
use App\Grid\GridDefinition;
use App\Grid\GridFilter;
use App\Grid\Sources\GridSource;
use App\Grid\Sources\ProcedureSource;
use App\Support\BranchContext;

/**
 * The runs made in one reconciliation area — the third tab of the workbench.
 *
 * WHAT THIS REPLACES. Two hand-written `<x-table>` blocks: five recent runs at
 * the foot of the area screen and ten on the hub. Neither could be filtered,
 * sorted, extracted or scoped to a person, and between them they were the only
 * way to find a run made yesterday. The grid is the component rule applied to
 * a list that had quietly grown into a screen.
 *
 * SCOPED TO THE PERSON BY DEFAULT, which is the ask ZP made. `mine` is read off
 * the request so the Mine / All switch is a link like every other scope in
 * Agora — but WHO "mine" is comes from the session, never from the query
 * string: a user id that arrives in a URL is a user id somebody can change.
 *
 * The area comes off the request too, because one definition is registered
 * once under one key and these rows only mean anything inside one area. That
 * is the same shape as ReconRunLineGrid, which reads its run the same way.
 */
class ReconRunGrid extends GridDefinition
{
    public function key(): string
    {
        return 'app.recon.runs';
    }

    public function title(): string
    {
        return 'Reconciliation runs';
    }

    public function blurb(): ?string
    {
        return 'Every preview made in this area, newest first. Yours unless you ask for everyone\'s. '
            .'The dates are the PERIOD a run covered, not when it was run — a run of 25 July to '
            .'5 August answers a search for either month.';
    }

    public function columns(): array
    {
        return [
            // link: the run's name IS the run, so it opens it. Unnamed runs
            // come back from the procedure described by their period rather
            // than as a dash, because a column of dashes cannot be navigated.
            new GridColumn(key: 'Note', label: 'Run', sort: 'Note', wide: true, link: true),
            new GridColumn(key: 'Status', label: 'Status', sort: 'Status', format: 'chip'),
            new GridColumn(key: 'BranchName', label: 'Site', sort: 'BranchName'),
            new GridColumn(key: 'FromDate', label: 'From', format: 'date', sort: 'FromDate'),
            new GridColumn(key: 'ToDate', label: 'To', format: 'date', sort: 'ToDate'),
            new GridColumn(key: 'TotalRows', label: 'Proposals', format: 'number', sort: 'TotalRows'),
            new GridColumn(key: 'MatchedRows', label: 'Would reconcile', format: 'number', sort: 'MatchedRows'),
            new GridColumn(key: 'BankOnlyRows', label: 'Bank only', format: 'number', visible: false),
            new GridColumn(key: 'DepositOnlyRows', label: 'Deposit only', format: 'number', visible: false),
            new GridColumn(key: 'MismatchRows', label: 'Mismatch', format: 'number', visible: false),
            new GridColumn(key: 'MatchedTotal', label: 'Value', format: 'money', sort: 'MatchedTotal', total: true),
            new GridColumn(key: 'CommittedRows', label: 'Stamped', format: 'number', visible: false),
            new GridColumn(key: 'CommittedTotal', label: 'Stamped value', format: 'money', visible: false, total: true),
            new GridColumn(key: 'RunBy', label: 'Run by', sort: 'RunBy'),
            new GridColumn(key: 'CreatedAt', label: 'Run at', format: 'datetime', sort: 'CreatedAt'),
            new GridColumn(key: 'PreviewMs', label: 'Took (ms)', format: 'number', visible: false),
            new GridColumn(key: 'StampMode', label: 'Stamp mode', visible: false),
        ];
    }

    /**
     * Keyed by COLUMN — the contract the base class states, and what the
     * header row looks each column up by. A plain list renders no filters at
     * all and fails silently.
     *
     * @return array<string, GridFilter>
     */
    public function filters(): array
    {
        return [
            'Note' => new GridFilter(column: 'Note', type: 'text'),
            'BranchName' => new GridFilter(column: 'BranchName', type: 'text'),
            'Status' => new GridFilter(column: 'Status', type: 'set',
                options: ['previewing', 'previewed', 'committed', 'reversed', 'failed']),
            'RunBy' => new GridFilter(column: 'RunBy', type: 'text'),
        ];
    }

    public function source(): GridSource
    {
        return new ProcedureSource(
            'agora.usp_Recon_GridRuns',
            acceptsFilters: true,
            extra: [
                'ReconArea' => $this->area(),
                'MineOnly' => (int) self::wantsOwnRunsOnly(),
                'UserId' => request()->user()?->Id,
                // The sites this person may see AT ALL, which is not the same
                // question as the sites they have selected. @BranchIds is
                // empty for a head-office user who has selected none, and
                // without this the grid would answer that with the estate.
                'AllowedBranchIds' => self::allowedBranchIds(),
            ],
        );
    }

    public function defaultSort(): ?string
    {
        return 'CreatedAt';
    }

    /** Newest first: the run a person wants is nearly always the last one. */
    public function defaultDirection(): string
    {
        return 'desc';
    }

    /** A run is about one site, so head office picks which ones it is reading. */
    public function branchSelector(): bool
    {
        return true;
    }

    public function rowUrl(object $row): ?string
    {
        return route('app.recon.run', ['run' => $row->Id]);
    }

    /**
     * @return array<int, array{label: string, url: string, primary?: bool}>
     */
    public function rowActions(object $row): array
    {
        return [[
            'label' => (bool) ($row->IsOpen ?? false) ? 'Resume' : 'Open',
            'url' => route('app.recon.run', ['run' => $row->Id]),
            'primary' => (bool) ($row->IsOpen ?? false),
        ]];
    }

    /**
     * Mine, or everyone's.
     *
     * Static because the pane renders the switch and has to agree with the
     * grid about which way it is currently set; two readings of the same
     * query string is how a toggle ends up lying about its own state.
     *
     * Default MINE. ZP's clerks asked to stop seeing each other's previews,
     * so the burden is on asking for everyone's, not on escaping them.
     */
    public static function wantsOwnRunsOnly(): bool
    {
        return request()->query('scope', 'mine') !== 'all';
    }

    /**
     * The area off the request, or null for every area.
     *
     * Null is the honest answer when the tab is not in play — the extract
     * endpoint is reachable on its own, and an absent area there means "the
     * runs this person may see", not "no runs".
     */
    private function area(): ?string
    {
        $area = request()->query('area');

        return is_string($area) && array_key_exists($area, (array) config('recon.areas'))
            ? $area
            : null;
    }

    /** The branch grant as a CSV, or null where the grant is the whole estate. */
    private static function allowedBranchIds(): ?string
    {
        $allowed = app(BranchContext::class)->allowed();

        return $allowed === [] ? null : implode(',', $allowed);
    }
}
