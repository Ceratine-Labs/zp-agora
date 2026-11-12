<?php

namespace Modules\Fuel\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Models\Permission;
use Modules\Core\Services\PermissionService;

/** Create assignable permissions; never grant access to people or historical roles. */
class FuelPermissionSeeder extends Seeder
{
    public int $seedOrder = 130;

    public function run(): void
    {
        foreach ([
            ['fuel', 'prices', 'View the fuel matrix, POS observations and source history'],
            ['reports', 'fuel-history', 'View the fuel price history report'],
        ] as [$module, $resource, $name]) {
            Permission::query()->acrossBranches()->updateOrCreate([
                'BranchId' => config('agora.group_branch_id'), 'Code' => "$module.$resource.view",
            ], ['Module' => $module, 'Resource' => $resource, 'Action' => 'view', 'Name' => $name, 'SortOrder' => 1450]);
        }
        app(PermissionService::class)->forgetAll();
    }
}
