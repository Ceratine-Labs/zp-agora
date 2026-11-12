<?php

namespace Modules\People\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Models\Permission;
use Modules\Core\Services\PermissionService;

/**
 * The people master's write permissions (T023), one per action per resource
 * — feature-rules §4. They switch on a named write to PumpIT (CLAUDE.md
 * rule 1; PM decision 01a1ceba-4d74):
 *
 *   people.employees.edit   change a person's status, post or known-as name
 *                           in BRN_Employee, and put such a change back
 *   people.areas.edit       change which counting areas a person signs on
 *                           to (BRN_EmployeeArea), and put that back
 *   people.posts.save       add a post at a branch (BRN_EmployeePost)
 *
 * No delete: nothing here removes an employee or a post.
 *
 * A seeder of its own because PeoplePermissionSeeder has already run
 * wherever the read side has. CREATED, NOT GRANTED BY NAME: an
 * administrator's `*.*.*` covers them; an auditor's `*.*.view` does not.
 * Upserts on the code. Nothing here writes the customer's databases.
 */
class PeopleWritePermissionSeeder extends Seeder
{
    public int $seedOrder = 66;

    /** @var array<string, array<string, string>> */
    private const PERMISSIONS = [
        'employees' => [
            'edit' => 'Change an employee\'s status, post or known-as name — written to PumpIT, needs a reason',
        ],
        'areas' => [
            'edit' => 'Change the counting areas an employee may be signed on to — written to PumpIT, needs a reason',
        ],
        'posts' => [
            'save' => 'Add a post at a branch — written to PumpIT, needs a reason',
        ],
    ];

    public function run(): void
    {
        $branchId = (int) config('agora.group_branch_id', 2);
        $sort = 1240;
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

        $this->command?->info("  People: {$count} write permissions created. Tick them per person on Setup → Users and access.");
    }
}
