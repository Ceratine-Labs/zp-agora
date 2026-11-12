<?php

namespace Modules\StockRecon\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Models\Permission;

/** Registration only. Assignment and existing access controls stay unchanged. */
class StockReconGuidePermissionSeeder extends Seeder
{
    public int $seedOrder = 74;

    public function run(): void
    {
        Permission::query()->acrossBranches()->updateOrCreate([
            'BranchId' => config('agora.group_branch_id'),
            'Code' => 'stockrecon.guide.view',
        ], [
            'Module' => 'stockrecon',
            'Resource' => 'guide',
            'Action' => 'view',
            'Name' => 'View stock reconciliation logic guide',
            'SortOrder' => 1126,
        ]);
    }
}
