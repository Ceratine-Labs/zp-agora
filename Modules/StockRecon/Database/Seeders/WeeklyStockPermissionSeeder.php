<?php

namespace Modules\StockRecon\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Models\Permission;

class WeeklyStockPermissionSeeder extends Seeder
{
    public int $seedOrder = 73;

    public function run(): void
    {
        foreach (['view' => 'Read weekly stock investigations', 'create' => 'Build weekly stock source snapshots',
            'edit' => 'Open a weekly investigation action', 'save' => 'Save weekly investigation owners and findings'] as $action => $name) {
            Permission::query()->acrossBranches()->updateOrCreate(['BranchId' => config('agora.group_branch_id'), 'Code' => 'stockrecon.weekly.'.$action],
                ['Module' => 'stockrecon', 'Resource' => 'weekly', 'Action' => $action, 'Name' => $name, 'SortOrder' => 1130]);
        }
    }
}
