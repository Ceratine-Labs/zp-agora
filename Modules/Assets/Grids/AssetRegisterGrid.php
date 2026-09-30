<?php

namespace Modules\Assets\Grids;

use App\Grid\GridColumn;
use App\Grid\GridDefinition;
use App\Grid\GridFilter;
use App\Grid\Sources\GridSource;
use App\Grid\Sources\ProcedureSource;
use Modules\Assets\Services\AssetsService;

/**
 * The asset register (T057): every asset by site and group, what it cost,
 * what it has depreciated by and what it is worth now.
 *
 * NBV comes out of agora.fn_Assets_Depreciation through the procedure, at the
 * PROVISIONAL SARS wear-and-tear periods in Modules/Assets/Config/config.php
 * (Ryan, 27 Sep 2026) — the screen says so above the grid for as long as the
 * config says `provisional`.
 *
 * `as_at` rides on the query string beside the grid's own parameters; the scope
 * form carries it through as a hidden input, so it survives a filter.
 *
 * The Status and Check chips take their tone from the procedure (StatusTone,
 * CheckTone) through this module's cells partial: "Away from its site" is the
 * row the check exists for, and the shared keyword table has no word for it.
 */
class AssetRegisterGrid extends GridDefinition
{
    public function key(): string
    {
        return 'app.assets.register';
    }

    public function title(): string
    {
        return 'Asset register';
    }

    public function blurb(): ?string
    {
        return 'Every asset by site and group: what it cost, what it has depreciated by, what it is worth, '
            .'and whether it is where its own site thinks it is.';
    }

    public function columns(): array
    {
        return [
            new GridColumn(key: 'AssetNo', label: 'Asset no', sort: 'AssetNo', mono: true, link: true,
                title: '{group}{sequence}-{site}: the group code, a sequence per site and group, and the site it belongs to'),
            // The tick list is the groups the config gives a period to — the
            // customer's 21 — rather than a second copy of them here.
            new GridColumn(key: 'AssetGroupCode', label: 'Group', sort: 'AssetGroupCode', filter: 'set',
                options: array_map('strval', array_keys((array) config('assets.depreciation.groups', [])))),
            new GridColumn(key: 'AssetGroupDescription', label: 'Group description', visible: false),
            new GridColumn(key: 'AssetTypeDescription', label: 'Type', sort: 'AssetTypeDescription'),
            new GridColumn(key: 'Make', label: 'Make', sort: 'Make'),
            new GridColumn(key: 'Model', label: 'Model', sort: 'Model'),
            new GridColumn(key: 'SerialNo', label: 'Serial no', mono: true),
            new GridColumn(key: 'LocatedAt', label: 'Located at', sort: 'LocatedAt'),
            new GridColumn(key: 'BranchName', label: 'Belongs to', sort: 'BranchName',
                title: 'The site whose register the asset is on. It does not change when the asset is moved.'),
            new GridColumn(key: 'AssetOwner', label: 'Owner', sort: 'AssetOwner', visible: false,
                title: 'The named owner from the customer\'s owner list — a brewery\'s fridge, an oil company\'s pump'),
            new GridColumn(key: 'DatePurchased', label: 'Purchased', format: 'date', sort: 'DatePurchased'),
            new GridColumn(key: 'DateAllocated', label: 'Allocated', format: 'date', visible: false),
            new GridColumn(key: 'Cost', label: 'Cost', format: 'money', sort: 'Cost', total: true),
            new GridColumn(key: 'Accumulated', label: 'Depreciation', format: 'money', sort: 'Accumulated', total: true,
                title: 'Accumulated to the last month end on or before the as-at date, at the provisional SARS period'),
            new GridColumn(key: 'Nbv', label: 'NBV', format: 'money', sort: 'Nbv', total: true,
                title: 'Cost less depreciation. Nil once disposed of; a dash where the group has no period'),
            new GridColumn(key: 'ChargeForMonth', label: 'Month\'s charge', format: 'money', total: true, visible: false,
                title: 'The charge for the last month charged — the one ending on or before the as-at date (as at 27 Sep, August)'),
            new GridColumn(key: 'LifeMonths', label: 'Life (months)', format: 'number', visible: false),
            new GridColumn(key: 'MonthsCharged', label: 'Months charged', format: 'number', visible: false),
            new GridColumn(key: 'Depreciation', label: 'Depreciation state', format: 'chip', visible: false, filter: 'set',
                options: ['Depreciating', 'Fully depreciated', 'Not started', 'No rate', 'Off the register']),
            new GridColumn(key: 'Condition', label: 'Condition', sort: 'Condition', filter: 'set',
                options: ['New', 'Good', 'Fair', 'Poor', 'Unserviceable']),
            new GridColumn(key: 'Status', label: 'Status', format: 'chip', sort: 'Status', filter: 'set',
                options: ['In service', 'At repair', 'Disposed of', 'Written off']),
            new GridColumn(key: 'Check', label: 'Check', format: 'chip', sort: 'Check', filter: 'set',
                options: ['In place', 'Away from its site', 'Away at repair', 'Off the register']),
            new GridColumn(key: 'JobCards', label: 'Job cards', format: 'number', visible: false),
            new GridColumn(key: 'Comebacks', label: 'Comebacks', format: 'number', sort: 'Comebacks', visible: false,
                title: 'Repair calls on this asset within 30 days of the call before'),
            new GridColumn(key: 'SupplierName', label: 'Supplier', visible: false),
            new GridColumn(key: 'SupplierCode', label: 'Supplier code', mono: true, visible: false, filter: 'none'),
            new GridColumn(key: 'DisposedOn', label: 'Disposed on', format: 'date', visible: false, filter: 'none'),
            new GridColumn(key: 'ProfitOnDisposal', label: 'Profit on disposal', format: 'money', visible: false, filter: 'none'),
        ];
    }

    /**
     * Keyed by COLUMN. Derived from the catalogue, except the ones the
     * procedure does not answer — which are dropped rather than sent to be
     * silently ignored.
     *
     * @return array<string, GridFilter>
     */
    public function filters(): array
    {
        $filters = parent::filters();

        unset($filters['LifeMonths'], $filters['MonthsCharged'], $filters['ChargeForMonth']);

        return $filters;
    }

    public function source(): GridSource
    {
        $service = app(AssetsService::class);

        return new ProcedureSource(
            'agora.usp_Assets_GridRegister',
            acceptsFilters: true,
            extra: [
                'RatesJson' => $service->ratesJson(),
                'AsAt' => self::asAt(),
                'AllowedBranchIds' => $service->allowedBranchIds(),
            ],
        );
    }

    public function cellsPartial(): string
    {
        return 'assets::grid._cells';
    }

    public function branchSelector(): bool
    {
        return true;
    }

    public function rowUrl(object $row): ?string
    {
        return route('app.assets.register.show', ['branch' => $row->BranchId, 'asset' => $row->AssetId]);
    }

    /**
     * The date the register is valued at: `as_at` from the query string when it
     * is a real date, today otherwise. A date nobody can parse is no date.
     */
    public static function asAt(): string
    {
        $asked = request()->query('as_at');

        if (is_string($asked) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $asked) === 1 && strtotime($asked) !== false) {
            return $asked;
        }

        return now()->toDateString();
    }
}
