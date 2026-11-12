<?php

namespace Modules\Fuel\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Models\MenuItem;
use Modules\Core\Services\MenuService;

/** The canonical Fuel module replaces the old unbuilt Setup placeholder. */
class FuelMenuSeeder extends Seeder
{
    public int $seedOrder = 131;

    public function run(): void
    {
        foreach (['ho', 'branch'] as $workspace) {
            MenuService::section($workspace, 'fuel', 'Fuel', 25);
            MenuService::item($workspace, 'fuel', [
                'path' => 'fuel-matrix', 'label' => 'Fuel matrix', 'route' => 'app.fuel.matrix',
                'permission' => 'fuel.prices.view', 'sort' => 10,
            ]);
            MenuService::item($workspace, 'fuel', [
                'path' => 'fuel-history', 'label' => 'Fuel price history', 'route' => 'app.fuel.history',
                'permission' => 'fuel.prices.view', 'sort' => 20,
            ]);
            MenuService::item($workspace, 'fuel', [
                'path' => 'fuel-history-report', 'label' => 'Fuel history report', 'url' => '/app/reports/fuel-history',
                'permission' => 'reports.fuel-history.view', 'sort' => 30,
            ]);
        }
        MenuItem::query()->acrossBranches()->where('BranchId', config('agora.group_branch_id'))
            ->where('Path', 'trading-rules/fuel-matrix')->update(['IsActive' => false]);
    }
}
