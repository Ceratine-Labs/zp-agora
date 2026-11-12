<?php

namespace Modules\Masters\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Models\Permission;
use Modules\Core\Services\PermissionService;

/**
 * The branch master permissions (T021), one per action per resource —
 * feature-rules §4.
 *
 *   masters.branches.view    the branch listing and one branch's page
 *   masters.branches.edit    change a branch — every change needs a reason
 *   masters.groupings.view   the regions, brands and formats
 *   masters.groupings.save   add a region, brand or format
 *   masters.groupings.edit   rename one, or switch it off
 *
 * No create or delete for a branch (its id is PumpIT's) and no delete for a
 * grouping (it is switched off).
 *
 * CREATED, NOT GRANTED BY NAME. An administrator's `*.*.*` and an auditor's
 * `*.*.view` already cover these at the check; who else maintains branches is
 * ticked per person or per role on Setup → Users and access.
 *
 * Upserts on the code, so a rerun after `--forget` changes nothing but the
 * wording. Nothing here writes the customer's databases.
 */
class MastersPermissionSeeder extends Seeder
{
    /** After RetireRolesSeeder (49), like every permission created since. */
    public int $seedOrder = 64;

    /** @var array<string, array<string, string>> */
    private const PERMISSIONS = [
        'branches' => [
            'view' => 'See the branch master: every branch\'s region, brand, format, status, POS type and profit centres',
            'edit' => 'Change a branch — status, groupings, POS type, administrative flag, profit centres; needs a reason',
        ],
        'groupings' => [
            'view' => 'See the regions, brands and formats branches are grouped by',
            'save' => 'Add a region, brand or format',
            'edit' => 'Rename a region, brand or format, or switch one off — needs a reason',
        ],
    ];

    public function run(): void
    {
        $branchId = (int) config('agora.group_branch_id', 2);
        $sort = 1150;
        $count = 0;

        foreach (self::PERMISSIONS as $resource => $actions) {
            foreach ($actions as $action => $name) {
                Permission::query()->withoutGlobalScopes()->updateOrCreate(
                    ['BranchId' => $branchId, 'Code' => Permission::code('masters', $resource, $action)],
                    [
                        'Module' => 'masters',
                        'Resource' => $resource,
                        'Action' => $action,
                        'Name' => $name,
                        'SortOrder' => $sort += 10,
                        'UpdatedAt' => now(),
                    ]
                );
                $count++;
            }
        }

        app(PermissionService::class)->forgetAll();

        $this->command?->info("  Masters: {$count} permissions created. Tick them per person on Setup → Users and access.");
    }
}
