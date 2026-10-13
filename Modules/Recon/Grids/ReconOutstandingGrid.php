<?php

namespace Modules\Recon\Grids;

use App\Grid\GridColumn;
use App\Grid\GridDefinition;
use App\Grid\GridFilter;
use App\Grid\Sources\GridSource;
use App\Grid\Sources\ProcedureSource;
use App\Support\BranchContext;
use App\Support\BusinessDate;

/**
 * The outstanding rows behind the bank recon dashboard — the Items tab.
 *
 * Ryan, 13 Oct 2026: "i think it will be a lot, excel struggles with it". So
 * the rows stay on the server and come a page at a time out of
 * agora.usp_Recon_GridOutstanding, filtered, sorted and exported there; the
 * dashboard above it adds the same rows up with agora.usp_Recon_DashboardOutstanding.
 *
 * SITES AND DATES ARE THE DASHBOARD'S. The key has no `:qualifier`, so this
 * grid reads the same `branches[]`, `from` and `to` the dashboard's scope form
 * sends, and the two can never be looking at different sites. The grid does
 * not draw its own site picker (the page passes no branches) and its scope
 * form carries the selection through when somebody searches.
 *
 * The area and side come off the request as `area` and `side`, set by the
 * links on the summary and per-site tables.
 */
class ReconOutstandingGrid extends GridDefinition
{
    /** The values `side` may take; anything else is both sides. */
    public const SIDES = ['Bank', 'PumpIT'];

    public function key(): string
    {
        return 'app.recon.outstanding';
    }

    public function title(): string
    {
        return 'Outstanding items';
    }

    public function blurb(): ?string
    {
        return 'Every bank line and PumpIT deposit still unreconciled, oldest first. '
            .'Bank lines show their statement line number as the reference.';
    }

    public function columns(): array
    {
        return [
            new GridColumn(key: 'BranchName', label: 'Site', sort: 'BranchName'),
            new GridColumn(key: 'ReconArea', label: 'Area', sort: 'ReconArea'),
            new GridColumn(key: 'Side', label: 'Side', sort: 'Side'),
            new GridColumn(key: 'TxnDate', label: 'Date', format: 'date', sort: 'TxnDate'),
            new GridColumn(key: 'AgeDays', label: 'Age (days)', format: 'number', sort: 'AgeDays'),
            new GridColumn(key: 'Reference', label: 'Reference', sort: 'Reference', mono: true),
            new GridColumn(key: 'Detail', label: 'Detail', sort: 'Detail', wide: true),
            new GridColumn(key: 'Amount', label: 'Amount', format: 'money', sort: 'Amount', total: true),
        ];
    }

    /** @return array<string, GridFilter> */
    public function filters(): array
    {
        return [
            'BranchName' => new GridFilter(column: 'BranchName', type: 'text'),
            'ReconArea' => new GridFilter(column: 'ReconArea', type: 'set',
                options: ['ABSA', 'FNB', 'CashMachine', 'CashBags', 'SmartATM', 'Unclassified']),
            'Side' => new GridFilter(column: 'Side', type: 'set', options: self::SIDES),
            'Reference' => new GridFilter(column: 'Reference', type: 'text'),
            'Detail' => new GridFilter(column: 'Detail', type: 'text'),
        ];
    }

    public function source(): GridSource
    {
        return new ProcedureSource(
            'agora.usp_Recon_GridOutstanding',
            acceptsFilters: true,
            extra: [
                'ReconArea' => self::area(),
                'Side' => self::side(),
                // The grant, not the selection — see ReconRunGrid.
                'AllowedBranchIds' => ($allowed = app(BranchContext::class)->allowed()) === [] ? null : implode(',', $allowed),
                'AsAt' => BusinessDate::today()->toDateString(),
            ],
        );
    }

    public function defaultSort(): ?string
    {
        return 'TxnDate';
    }

    public function branchSelector(): bool
    {
        return true;
    }

    /** The area off the request: one of the recon areas, Unclassified, or null for all. */
    public static function area(): ?string
    {
        $area = request()->query('area');

        return is_string($area) && ($area === 'Unclassified' || array_key_exists($area, (array) config('recon.areas')))
            ? $area
            : null;
    }

    /** The side off the request, or null for both. */
    public static function side(): ?string
    {
        $side = request()->query('side');

        return is_string($side) && in_array($side, self::SIDES, true) ? $side : null;
    }
}
