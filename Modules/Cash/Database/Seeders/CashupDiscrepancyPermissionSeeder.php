<?php

namespace Modules\Cash\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Models\Permission;

/** Register the alert permission; customer-managed roles/direct grants opt in. */
final class CashupDiscrepancyPermissionSeeder extends Seeder
{
    public int $seedOrder = 74;

    public function run(): void
    {
        Permission::query()->acrossBranches()->updateOrCreate([
            'BranchId' => config('agora.group_branch_id'),
            'Code' => 'cash.discrepancy.view',
        ], [
            'Module' => 'cash', 'Resource' => 'discrepancy', 'Action' => 'view',
            'Name' => 'View cashup discrepancy alerts', 'SortOrder' => 475,
        ]);
    }
}
