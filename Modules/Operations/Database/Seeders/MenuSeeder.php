<?php

namespace Modules\Operations\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Models\Permission;
use Modules\Core\Services\MenuService;

/** Register the wall. Existing user permission and menu assignments are unchanged. */
class MenuSeeder extends Seeder
{
    public int $seedOrder = 80;

    public function run(): void
    {
        $branchId = (int) config('agora.group_branch_id');
        Permission::query()->withoutGlobalScopes()->updateOrCreate(
            ['BranchId' => $branchId, 'Code' => 'operations.wall.view'],
            ['Module' => 'operations', 'Resource' => 'wall', 'Action' => 'view',
                'Name' => 'Read the live Operations Wall', 'SortOrder' => 1200]
        );
        foreach (['ho', 'branch'] as $workspace) {
            MenuService::item($workspace, 'today', [
                'path' => 'operations-wall', 'label' => 'Operations Wall',
                'route' => 'app.operations.wall', 'permission' => 'operations.wall.view',
                'hint' => 'live', 'sort' => 1,
            ]);
        }
    }
}
