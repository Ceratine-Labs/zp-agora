<?php

namespace Modules\Core\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Models\Permission;

/** Makes the existing wildcard matcher available for deliberate admin grants. */
class AllPermissionsSeeder extends Seeder
{
    public int $seedOrder = 71;

    public function run(): void
    {
        Permission::query()->acrossBranches()->updateOrCreate([
            'BranchId' => config('agora.group_branch_id'),
            'Code' => '*.*.*',
        ], [
            'Module' => 'all',
            'Resource' => '*',
            'Action' => '*',
            'Name' => 'All permissions (administrator)',
            'SortOrder' => 0,
        ]);
    }
}
