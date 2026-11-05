<?php

namespace Modules\Reports\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Models\Permission;
use Modules\Reports\Services\MyQueueAccess;

final class MyQueuePermissionsSeeder extends Seeder
{
    public int $seedOrder = 73;

    public function run(): void
    {
        foreach (MyQueueAccess::TYPES as $key => $label) {
            Permission::query()->acrossBranches()->updateOrCreate([
                'BranchId' => config('agora.group_branch_id'),
                'Code' => 'reports.queue-'.$key.'.view',
            ], [
                'Module' => 'reports', 'Resource' => 'queue-'.$key, 'Action' => 'view',
                'Name' => 'View My Queue: '.$label, 'SortOrder' => 940,
            ]);
        }
    }
}
