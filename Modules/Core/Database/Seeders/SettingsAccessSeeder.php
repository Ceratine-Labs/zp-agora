<?php

namespace Modules\Core\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Models\Permission;
use Modules\Core\Services\MenuService;
use Modules\Core\Services\PermissionService;

/**
 * The Setup screen for system defaults and thresholds (T026): its
 * permissions, one per action (feature-rules §4), and its menu entry.
 *
 *   setup.settings.view    the listing and one setting's page
 *   setup.settings.edit    change a value — the group's, or a site's own
 *   setup.settings.save    give a site its own value
 *   setup.settings.delete  take a site's own value away (it follows the group again)
 *
 * The group value itself is never created or deleted on the screen.
 *
 * CREATED, NOT GRANTED BY NAME, like every permission since the managed roles:
 * an administrator's `*.*.*` and an auditor's `*.*.view` already cover these at
 * the check, and anybody else is ticked per person or per role.
 *
 * The menu entry: Setup → The business → System defaults and thresholds (plan
 * §5, `#/master/sysdef`, Core). Core's MenuSeeder is in the seed ledger
 * everywhere, so the entry is added here through MenuService::item(), which
 * upserts on (workspace, section, path) — re-running changes nothing. It
 * carries its route's permission, so it is hidden from anybody who could not
 * open it.
 */
class SettingsAccessSeeder extends Seeder
{
    /** After Core's menu (30) and RetireRolesSeeder (49), like every permission created since. */
    public int $seedOrder = 65;

    /** @var array<string, string> */
    private const ACTIONS = [
        'view' => 'See the system defaults and thresholds, the group value and each site\'s own',
        'edit' => 'Change a system default or threshold — the group value or a site\'s own; needs a reason',
        'save' => 'Give a site its own value for a threshold that allows one; needs a reason',
        'delete' => 'Take a site\'s own value away, so it follows the group value again; needs a reason',
    ];

    public function run(): void
    {
        $branchId = (int) config('agora.group_branch_id', 2);
        $sort = 940;

        foreach (self::ACTIONS as $action => $name) {
            Permission::query()->withoutGlobalScopes()->updateOrCreate(
                ['BranchId' => $branchId, 'Code' => Permission::code('setup', 'settings', $action)],
                ['Module' => 'setup', 'Resource' => 'settings', 'Action' => $action, 'Name' => $name,
                    'SortOrder' => $sort += 1, 'UpdatedAt' => now()],
            );
        }
        app(PermissionService::class)->forgetAll();

        MenuService::item('ho', 'setup', [
            'path' => 'business/settings',
            'parent' => 'business',
            'label' => 'System defaults and thresholds',
            'route' => 'app.setup.settings.index',
            'permission' => 'setup.settings.view',
            'sort' => 60,
        ]);

        $this->command?->info('  Settings: 4 permissions and Setup → The business → System defaults and thresholds.');
    }
}
