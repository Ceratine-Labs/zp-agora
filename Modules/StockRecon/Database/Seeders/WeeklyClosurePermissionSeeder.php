<?php

namespace Modules\StockRecon\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Models\Permission;

class WeeklyClosurePermissionSeeder extends Seeder
{
    public int $seedOrder = 74;

    public function run(): void
    {
        foreach (['approve' => 'Approve weekly investigation closure at head office', 'reopen' => 'Reopen a signed weekly investigation'] as $action => $name) {
            Permission::query()->acrossBranches()->updateOrCreate(['BranchId' => config('agora.group_branch_id'), 'Code' => 'stockrecon.weekly.'.$action],
                ['Module' => 'stockrecon', 'Resource' => 'weekly', 'Action' => $action, 'Name' => $name, 'SortOrder' => 1131]);
        }
    }
}
