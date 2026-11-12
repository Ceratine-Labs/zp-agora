<?php

namespace Modules\StockRecon\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Models\Permission;

class BalancingProfilePermissionSeeder extends Seeder
{
    public int $seedOrder = 73;

    public function run(): void
    {
        foreach (['view' => 'View stock balancing profiles', 'save' => 'Change stock balancing profiles and item mappings'] as $action => $name) {
            Permission::query()->acrossBranches()->updateOrCreate([
                'BranchId' => config('agora.group_branch_id'), 'Code' => 'stockrecon.profiles.'.$action,
            ], ['Module' => 'stockrecon', 'Resource' => 'profiles', 'Action' => $action, 'Name' => $name, 'SortOrder' => 1125]);
        }
    }
}
