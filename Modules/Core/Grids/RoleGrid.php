<?php

namespace Modules\Core\Grids;

use App\Grid\GridColumn;
use App\Grid\GridDefinition;
use App\Grid\GridFilter;
use App\Grid\Sources\GridSource;
use App\Grid\Sources\ProcedureSource;
use Illuminate\Support\Facades\Gate;

class RoleGrid extends GridDefinition
{
    public function key(): string
    {
        return 'app.setup.roles';
    }

    public function title(): string
    {
        return 'Roles';
    }

    public function defaultSort(): ?string
    {
        return 'Name';
    }

    public function branchSelector(): bool
    {
        return false;
    }

    public function columns(): array
    {
        return [
            new GridColumn(key: 'Code', label: 'Code', sort: 'Code', mono: true),
            new GridColumn(key: 'Name', label: 'Name', sort: 'Name', link: true, wide: true),
            new GridColumn(key: 'PermissionCount', label: 'Permissions', sort: 'PermissionCount', format: 'number'),
            new GridColumn(key: 'MenuCount', label: 'Menu entries', sort: 'MenuCount', format: 'number'),
            new GridColumn(key: 'UserCount', label: 'Users', sort: 'UserCount', format: 'number'),
        ];
    }

    public function filters(): array
    {
        return [
            'Code' => new GridFilter(column: 'Code', type: 'text'),
            'Name' => new GridFilter(column: 'Name', type: 'text'),
            'PermissionCount' => new GridFilter(column: 'PermissionCount', type: 'number'),
            'MenuCount' => new GridFilter(column: 'MenuCount', type: 'number'),
            'UserCount' => new GridFilter(column: 'UserCount', type: 'number'),
        ];
    }

    public function source(): GridSource
    {
        return new ProcedureSource('agora.usp_Core_GridRoles', acceptsFilters: true);
    }

    public function rowUrl(object $row): ?string
    {
        return route('app.setup.roles.show', ['role' => $row->Id]);
    }

    public function rowActions(object $row): array
    {
        $actions = [['label' => 'Open', 'url' => $this->rowUrl($row)]];
        if (Gate::allows('setup.roles.edit')) {
            $actions[] = ['label' => 'Edit', 'url' => route('app.setup.roles.edit', ['role' => $row->Id]), 'primary' => true];
        }

        return $actions;
    }
}
