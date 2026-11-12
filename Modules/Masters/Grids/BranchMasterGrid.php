<?php

namespace Modules\Masters\Grids;

use App\Grid\GridColumn;
use App\Grid\GridDefinition;
use App\Grid\Sources\GridSource;
use App\Grid\Sources\ProcedureSource;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * Setup → The business → Branches (T021).
 *
 * The six administrative entities are left out unless the request carries
 * `admin=1` — the listing's first job is the sites. The flag travels in the
 * query string, so the extract and a pasted link show the same rows.
 */
class BranchMasterGrid extends GridDefinition
{
    public function key(): string
    {
        return 'app.setup.branches';
    }

    public function title(): string
    {
        return 'Branches';
    }

    public function blurb(): ?string
    {
        return self::includesAdmin()
            ? 'Every branch, including the six administrative entities.'
            : 'The sites. The six administrative entities are hidden; show them from the page head.';
    }

    public function defaultSort(): ?string
    {
        return 'BranchId';
    }

    public function branchSelector(): bool
    {
        return false;
    }

    public function detailMode(): string
    {
        return 'expand';
    }

    public function columns(): array
    {
        return [
            new GridColumn('BranchId', 'Id', format: 'number', sort: 'BranchId'),
            new GridColumn('Name', 'Branch', sort: 'Name', link: true, wide: true),
            new GridColumn('RegionName', 'Region', sort: 'RegionName'),
            new GridColumn('BrandName', 'Brand', sort: 'BrandName'),
            new GridColumn('FormatName', 'Format', sort: 'FormatName'),
            new GridColumn('Status', 'Status', format: 'chip', sort: 'Status', options: ['Trading', 'Mothballed', 'Non-trading']),
            new GridColumn('POSType', 'POS type', format: 'chip', sort: 'POSType', options: ['BRANCH', 'NAMOS']),
            new GridColumn('IsAdminEntity', 'Administrative', format: 'bool', options: ['Yes', 'No']),
            new GridColumn('ProfitCentreCount', 'Profit centres', format: 'number', sort: 'ProfitCentreCount'),
            new GridColumn('ProfitCentres', 'Profit centre list', wide: true, visible: false),
            new GridColumn('LegacyName', 'Name in PumpIT', sort: 'LegacyName', visible: false),
            new GridColumn('IsActive', 'Active', format: 'bool', options: ['Yes', 'No'], visible: false),
            new GridColumn('UpdatedAt', 'Changed', format: 'instant', sort: 'UpdatedAt', filter: 'none', visible: false),
        ];
    }

    public function source(): GridSource
    {
        return new ProcedureSource('agora.usp_Masters_GridBranches', acceptsFilters: true, extra: [
            'IncludeAdmin' => (int) self::includesAdmin(),
            'UserId' => Auth::user()?->Id,
        ]);
    }

    public function rowUrl(object $row): ?string
    {
        return route('app.setup.branches.show', ['branch' => $row->BranchId]);
    }

    public function detailUrl(object $row): ?string
    {
        return $this->rowUrl($row);
    }

    public function rowActions(object $row): array
    {
        $actions = [['label' => 'Open', 'url' => $this->rowUrl($row)]];

        if (Gate::allows('masters.branches.edit')) {
            $actions[] = ['label' => 'Edit', 'url' => route('app.setup.branches.edit', ['branch' => $row->BranchId]), 'primary' => true];
        }

        return $actions;
    }

    public static function includesAdmin(): bool
    {
        return request()->boolean('admin');
    }
}
