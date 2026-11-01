<?php

namespace Modules\StockRecon\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Models\Permission;

/** Catalogue only. Customers assign the new grants through users or managed roles. */
class CountingAreaPermissionSeeder extends Seeder
{
    public int $seedOrder = 71;

    public function run(): void
    {
        foreach (['view' => 'Read counting areas and confirmations', 'edit' => 'Open the counting-area classification editor', 'save' => 'Confirm the prepared-food classification of a counting area'] as $action => $name) {
            Permission::query()->acrossBranches()->updateOrCreate([
                'BranchId' => config('agora.group_branch_id'), 'Code' => 'stockrecon.countingareas.'.$action,
            ], ['Module' => 'stockrecon', 'Resource' => 'countingareas', 'Action' => $action, 'Name' => $name, 'SortOrder' => 1120]);
        }
    }
}
