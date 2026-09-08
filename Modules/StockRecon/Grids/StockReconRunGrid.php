<?php

namespace Modules\StockRecon\Grids;

use App\Grid\GridColumn;
use App\Grid\GridDefinition;
use App\Grid\GridFilter;
use App\Grid\Sources\GridSource;
use App\Grid\Sources\ProcedureSource;
use App\Support\BranchContext;

/**
 * The balancing runs made at a site — the centre's Runs tab, and its hub.
 *
 * SCOPED TO THE PERSON BY DEFAULT. `mine` is read off the request so the
 * Mine / All switch is a link like every other scope in Agora — but WHO "mine"
 * is comes from the session, never from the query string: a user id that
 * arrives in a URL is a user id somebody can change.
 *
 * The area comes off the request too, because one definition is registered once
 * under one key and a run only means anything inside the area it was run for.
 */
class StockReconRunGrid extends GridDefinition
{
    public function key(): string
    {
        return 'app.stockrecon.runs';
    }

    public function title(): string
    {
        return 'Balancing runs';
    }

    public function blurb(): ?string
    {
        return 'Every balancing preview made at this site, newest first. Yours unless you ask for '
            .'everyone\'s. The dates are the PERIOD a run covered, not when it was run — and because '
            .'both ends of that period are anchors, the window is what fixes how much loss the run '
            .'can possibly report.';
    }

    public function columns(): array
    {
        return [
            // link: the run's name IS the run, so it opens it. An unnamed run
            // comes back described by its period rather than as a dash,
            // because a column of dashes cannot be navigated.
            new GridColumn(key: 'Note', label: 'Run', sort: 'Note', wide: true, link: true),
            new GridColumn(key: 'Status', label: 'Status', sort: 'Status', format: 'chip'),
            new GridColumn(key: 'BranchName', label: 'Site', sort: 'BranchName'),
            new GridColumn(key: 'AreaName', label: 'Area', sort: 'AreaName'),
            new GridColumn(key: 'FromDate', label: 'From', format: 'date', sort: 'FromDate'),
            new GridColumn(key: 'ToDate', label: 'To', format: 'date', sort: 'ToDate'),
            new GridColumn(key: 'TotalRows', label: 'Shifts', format: 'number', sort: 'TotalRows'),
            new GridColumn(key: 'ChainCount', label: 'Chains', format: 'number'),
            new GridColumn(key: 'BlockedChains', label: 'Blocked', format: 'number', sort: 'BlockedChains',
                title: 'Chains reported rather than balanced — a net over, a broken chain, or a cap'),
            new GridColumn(key: 'AmendedRows', label: 'Shifts to amend', format: 'number', sort: 'AmendedRows'),
            new GridColumn(key: 'UnitsAmended', label: 'Units moved', format: 'number', visible: false),
            // The number the whole module exists to surface. Balancing cannot
            // touch it, so it leads the money columns.
            new GridColumn(key: 'NetOverValue', label: 'Unrecorded issue', format: 'money',
                sort: 'NetOverValue', total: true,
                title: 'Stock the window sold but never received. No amendment can remove it.'),
            new GridColumn(key: 'ShortValueAfter', label: 'Short after', format: 'money',
                sort: 'ShortValueAfter', total: true,
                title: 'The residual short once every over is zeroed — the floor, not a target'),
            new GridColumn(key: 'DormantRows', label: 'Dormant shifts', format: 'number', visible: false),
            new GridColumn(key: 'OverRowsBefore', label: 'Over before', format: 'number', visible: false),
            new GridColumn(key: 'OverRowsAfter', label: 'Over after', format: 'number', visible: false),
            new GridColumn(key: 'ShortRowsBefore', label: 'Short before', format: 'number', visible: false),
            new GridColumn(key: 'ShortRowsAfter', label: 'Short after', format: 'number', visible: false),
            new GridColumn(key: 'CommittedRows', label: 'Amended', format: 'number', visible: false),
            new GridColumn(key: 'RunBy', label: 'Run by', sort: 'RunBy'),
            new GridColumn(key: 'CreatedAt', label: 'Run at', format: 'datetime', sort: 'CreatedAt'),
            new GridColumn(key: 'PreviewMs', label: 'Took (ms)', format: 'number', visible: false),
            new GridColumn(key: 'StampMode', label: 'Stamp mode', visible: false),
        ];
    }

    /**
     * Keyed by COLUMN — the contract the base class states, and what the header
     * row looks each column up by. A plain list renders no filters at all and
     * fails silently.
     *
     * @return array<string, GridFilter>
     */
    public function filters(): array
    {
        return [
            'Note' => new GridFilter(column: 'Note', type: 'text'),
            'BranchName' => new GridFilter(column: 'BranchName', type: 'text'),
            'AreaName' => new GridFilter(column: 'AreaName', type: 'text'),
            'Status' => new GridFilter(column: 'Status', type: 'set',
                options: ['previewing', 'previewed', 'committed', 'reversed', 'failed']),
            'RunBy' => new GridFilter(column: 'RunBy', type: 'text'),
        ];
    }

    public function source(): GridSource
    {
        return new ProcedureSource(
            'agora.usp_StockRecon_GridRuns',
            acceptsFilters: true,
            extra: [
                'AreaNo' => $this->areaNo(),
                'MineOnly' => (int) self::wantsOwnRunsOnly(),
                'UserId' => request()->user()?->Id,
                // The sites this person may see AT ALL, which is not the same
                // question as the sites they have selected. @BranchIds is empty
                // for a head-office user who has selected none, and without
                // this the grid would answer that with the whole estate.
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
        return route('app.stockrecon.run', ['run' => $row->Id]);
    }

    /**
     * @return array<int, array{label: string, url: string, primary?: bool}>
     */
    public function rowActions(object $row): array
    {
        return [[
            'label' => (bool) ($row->IsOpen ?? false) ? 'Resume' : 'Open',
            'url' => route('app.stockrecon.run', ['run' => $row->Id]),
            'primary' => (bool) ($row->IsOpen ?? false),
        ]];
    }

    /**
     * Mine, or everyone's.
     *
     * Static because the pane renders the switch and has to agree with the grid
     * about which way it is currently set; two readings of the same query
     * string is how a toggle ends up lying about its own state.
     */
    public static function wantsOwnRunsOnly(): bool
    {
        return request()->query('scope', 'mine') !== 'all';
    }

    /**
     * The area off the request, or null for every area.
     *
     * Null is the honest answer when nothing was asked for — the extract
     * endpoint is reachable on its own, and an absent area there means "the
     * runs this person may see", not "no runs".
     */
    private function areaNo(): ?int
    {
        $area = request()->query('area_no');

        return is_numeric($area) ? (int) $area : null;
    }

    /** The branch grant as a CSV, or null where the grant is the whole estate. */
    private static function allowedBranchIds(): ?string
    {
        $allowed = app(BranchContext::class)->allowed();

        return $allowed === [] ? null : implode(',', $allowed);
    }
}
