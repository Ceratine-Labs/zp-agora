<?php

namespace Modules\Reports\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Models\Permission;

/** Each report needs its own assignable permission and direct URL guard. */
class ReportViewPermissionsSeeder extends Seeder
{
    public int $seedOrder = 72;

    public function run(): void
    {
        foreach ((array) config('reports.reports', []) as $key => $report) {
            Permission::query()->acrossBranches()->updateOrCreate([
                'BranchId' => config('agora.group_branch_id'),
                'Code' => 'reports.'.$key.'.view',
            ], [
                'Module' => 'reports',
                'Resource' => (string) $key,
                'Action' => 'view',
                'Name' => 'View '.($report['label'] ?? $key),
                'SortOrder' => 930,
            ]);
        }
    }
}
