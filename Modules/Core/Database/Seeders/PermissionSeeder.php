<?php

namespace Modules\Core\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Models\Permission;

/**
 * The permission catalogue (T008).
 *
 * Slugs are {module}.{resource}.{action} per feature-rules §D. A permission is
 * a fact about the software, so this is a plain list rather than something
 * derived from routes: a route can disappear in a refactor and the grant that
 * referenced it should not silently start meaning nothing.
 *
 * Only what exists today is listed. A module that has not been built yet does
 * not get speculative permissions — an unused slug in a role matrix is a
 * promise the screen has to keep later.
 */
class PermissionSeeder extends Seeder
{
    public int $seedOrder = 15;

    /** @var array<string, array<string, array<int, array{0:string,1:string}>>> */
    private const CATALOGUE = [
        'core' => [
            'dashboard' => [['view', 'See the dashboard']],
            'profile' => [['view', 'See your own profile'], ['edit', 'Change your own password']],
            'styleguide' => [['view', 'Open the component gallery']],
        ],
        'setup' => [
            'users' => [['view', 'See the user list'], ['edit', 'Create and change users'], ['delete', 'Deactivate a user']],
            'roles' => [['view', 'See roles and their permissions'], ['edit', 'Change what a role may do']],
            'branches' => [['view', 'See the branch list'], ['edit', 'Change branch master data']],
        ],
        'recon' => [
            // The bank reconciliation module. `execute` and `reverse` are
            // separate from `create` on purpose: previewing a run is safe and
            // stamping one writes to the customer's estate, so the person who
            // may look is not automatically the person who may commit.
            'runs' => [
                ['view', 'See reconciliation runs and their lines'],
                ['create', 'Preview a reconciliation'],
                ['execute', 'Commit a reconciliation — writes the stamp'],
                ['reverse', 'Reverse a committed reconciliation'],
                ['delete', 'Discard a preview run'],
            ],
            'criteria' => [['view', 'See the extraction configuration a run used']],
        ],
        'reports' => [
            'catalogue' => [['view', 'See the report library']],
            'report' => [['view', 'Run a report'], ['export', 'Extract a report to CSV or XLSX']],
        ],
        'audit' => [
            // The Auditor role is *.*.view plus audit.*.*, so this module is
            // the half that is theirs alone.
            'activity' => [['view', 'See who signed in and when']],
            'changes' => [['view', 'See the master-data change log']],
        ],
    ];

    public function run(): void
    {
        $branchId = (int) config('agora.group_branch_id', 2);
        $sort = 0;
        $written = 0;

        foreach (self::CATALOGUE as $module => $resources) {
            foreach ($resources as $resource => $actions) {
                foreach ($actions as [$action, $name]) {
                    $code = Permission::code($module, $resource, $action);

                    Permission::query()->withoutGlobalScopes()->updateOrCreate(
                        ['BranchId' => $branchId, 'Code' => $code],
                        [
                            'Module' => $module,
                            'Resource' => $resource,
                            'Action' => $action,
                            'Name' => $name,
                            'SortOrder' => $sort += 10,
                            'UpdatedAt' => now(),
                        ]
                    );
                    $written++;
                }
            }
        }

        $this->command?->info("Permissions: {$written} across ".count(self::CATALOGUE).' modules.');
    }
}
