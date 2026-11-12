<?php

namespace Modules\Core\Grids;

use App\Grid\GridColumn;
use App\Grid\GridDefinition;
use App\Grid\Sources\GridSource;
use App\Grid\Sources\ProcedureSource;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * Setup → The business → System defaults and thresholds (T026).
 *
 * One row per setting: what it is, the group value, how many sites carry their
 * own, and what reads it. Opening a row shows the sites and the history.
 */
class SettingGrid extends GridDefinition
{
    public const TYPES = ['int', 'decimal', 'money', 'litres', 'share', 'bool', 'string', 'choice'];

    public function key(): string
    {
        return 'app.setup.settings';
    }

    public function title(): string
    {
        return 'System defaults and thresholds';
    }

    public function blurb(): ?string
    {
        return 'Each threshold\'s group value, and the sites that carry their own. Copies of PumpIT\'s settings are listed after Agora\'s, read-only.';
    }

    public function defaultSort(): ?string
    {
        return 'SettingKey';
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
            new GridColumn('SettingKey', 'Setting', sort: 'SettingKey', link: true, mono: true),
            new GridColumn('Description', 'What it is', sort: 'Description', wide: true),
            new GridColumn('GroupValue', 'Group value', filter: 'text'),
            new GridColumn('Overrides', 'Sites with their own', format: 'number', sort: 'Overrides'),
            new GridColumn('Module', 'Module', sort: 'Module', options: ['cash', 'exceptions', 'fuel', 'pumpit', 'purchasing', 'recon', 'utilities']),
            new GridColumn('Type', 'Type', sort: 'Type', options: self::TYPES, visible: false),
            new GridColumn('AllowsBranch', 'Per site', format: 'bool', options: ['Yes', 'No']),
            new GridColumn('InUse', 'In use', format: 'bool', options: ['Yes', 'No']),
            new GridColumn('UsedBy', 'Read by', sort: 'UsedBy', wide: true, visible: false),
            new GridColumn('Source', 'Source', format: 'chip', sort: 'Source', options: ['seed', 'pumpit', 'edit']),
            new GridColumn('LegacySource', 'PumpIT column', visible: false),
            new GridColumn('UpdatedAt', 'Changed', format: 'instant', sort: 'UpdatedAt', filter: 'none', visible: false),
        ];
    }

    public function source(): GridSource
    {
        return new ProcedureSource('agora.usp_Core_GridSettings', acceptsFilters: true, extra: [
            'UserId' => Auth::user()?->Id,
            'Authorized' => (int) Gate::allows('setup.settings.view'),
            'GroupBranchId' => (int) config('agora.group_branch_id', 2),
        ]);
    }

    public function cellsPartial(): string
    {
        return 'core::setup.settings._cells';
    }

    public function rowUrl(object $row): ?string
    {
        return route('app.setup.settings.show', ['key' => $row->SettingKey]);
    }

    public function detailUrl(object $row): ?string
    {
        return $this->rowUrl($row);
    }

    public function rowActions(object $row): array
    {
        return [['label' => 'Open', 'url' => $this->rowUrl($row)]];
    }
}
