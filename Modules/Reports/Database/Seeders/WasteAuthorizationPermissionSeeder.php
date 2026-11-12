<?php

namespace Modules\Reports\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Models\Permission;

final class WasteAuthorizationPermissionSeeder extends Seeder
{
    public int $seedOrder = 74;

    public function run(): void
    {
        Permission::query()->acrossBranches()->updateOrCreate([
            'BranchId' => config('agora.group_branch_id'), 'Code' => 'reports.waste.approve',
        ], ['Module' => 'reports', 'Resource' => 'waste', 'Action' => 'approve',
            'Name' => 'Authorize good waste', 'SortOrder' => 941]);
    }
}
