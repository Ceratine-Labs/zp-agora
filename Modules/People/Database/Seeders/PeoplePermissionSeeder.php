<?php

namespace Modules\People\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Models\Permission;
use Modules\Core\Services\PermissionService;

/**
 * The people master permissions (T023), one per action per resource —
 * feature-rules §4.
 *
 *   people.employees.view   the employee list and one employee's page,
 *                           with the shifts they worked
 *   people.posts.view       the posts at each branch
 *   people.areas.view       which counting areas each person signs on to
 *
 * VIEW ONLY, deliberately. The employees are PumpIT's BRN_Employee and
 * PumpIT trades on them; whether an edit in Agora writes back to PumpIT (a
 * new named write), lands in an Agora-owned copy, or is not offered yet is
 * Ryan's decision. The edit and save permissions arrive with that path.
 *
 * CREATED, NOT GRANTED BY NAME. An administrator's `*.*.*` and an auditor's
 * `*.*.view` already cover these at the check; anybody else is ticked per
 * person or per role on Setup → Users and access.
 *
 * Upserts on the code, so a rerun after `--forget` changes nothing but the
 * wording. Nothing here writes the customer's databases.
 */
class PeoplePermissionSeeder extends Seeder
{
    /** After RetireRolesSeeder (49), like every permission created since. */
    public int $seedOrder = 65;

    /** @var array<string, array<string, string>> */
    private const PERMISSIONS = [
        'employees' => [
            'view' => 'See the employee master: each person\'s branch, post, status, counting areas and the shifts they worked',
        ],
        'posts' => [
            'view' => 'See the employee posts at each branch and how many people hold each',
        ],
        'areas' => [
            'view' => 'See which counting areas each employee may be signed on to',
        ],
    ];

    public function run(): void
    {
        $branchId = (int) config('agora.group_branch_id', 2);
        $sort = 1200;
        $count = 0;

        foreach (self::PERMISSIONS as $resource => $actions) {
            foreach ($actions as $action => $name) {
                Permission::query()->withoutGlobalScopes()->updateOrCreate(
                    ['BranchId' => $branchId, 'Code' => Permission::code('people', $resource, $action)],
                    [
                        'Module' => 'people',
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

        $this->command?->info("  People: {$count} permissions created. Tick them per person on Setup → Users and access.");
    }
}
