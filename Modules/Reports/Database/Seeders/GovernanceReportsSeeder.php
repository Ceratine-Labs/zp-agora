<?php

namespace Modules\Reports\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Models\Permission;
use Modules\Core\Services\MenuService;
use Modules\Core\Services\PermissionService;
use Modules\Reports\Grids\MasterChangeGrid;
use Modules\Reports\Grids\UserActivityGrid;

/**
 * Setup → Governance (T027): the master data change log and user activity.
 *
 *   reports.master-changes.view   Setup → Governance → Master data changes
 *   reports.user-activity.view    Setup → Governance → User activity
 *
 * CREATED, NOT GRANTED BY NAME, like every permission since the managed roles:
 * an administrator's `*.*.*` and an auditor's `*.*.view` already cover them,
 * and anybody else is ticked per person or per role.
 *
 * The menu: a Governance column under Setup and the two links, each carrying
 * its route's permission so it is hidden from anybody who could not open it.
 * MenuService::item() upserts on (workspace, section, path), so re-running
 * changes nothing. Writes agora.Permission and agora.MenuItem only.
 */
class GovernanceReportsSeeder extends Seeder
{
    /** After Core's menu (30) and RetireRolesSeeder (49). */
    public int $seedOrder = 74;

    /** @var array<string, array{0: string, 1: string}> resource => [permission name, menu label] */
    private const REPORTS = [
        'master-changes' => ['See every change to a master — Agora\'s own and PumpIT\'s written back — with before, after, who and why', 'Master data changes'],
        'user-activity' => ['See sign-ins, sign-outs, refused sign-ins and password resets, by person', 'User activity'],
    ];

    public function run(): void
    {
        $branchId = (int) config('agora.group_branch_id', 2);
        $sort = 935;

        foreach (self::REPORTS as $resource => [$name]) {
            Permission::query()->withoutGlobalScopes()->updateOrCreate(
                ['BranchId' => $branchId, 'Code' => Permission::code('reports', $resource, 'view')],
                ['Module' => 'reports', 'Resource' => $resource, 'Action' => 'view', 'Name' => $name,
                    'SortOrder' => $sort += 1, 'UpdatedAt' => now()],
            );
        }
        app(PermissionService::class)->forgetAll();

        MenuService::item('ho', 'setup', ['path' => 'governance', 'label' => 'Governance', 'sort' => 40]);
        MenuService::item('ho', 'setup', [
            'path' => 'governance/master-changes', 'parent' => 'governance',
            'label' => self::REPORTS['master-changes'][1], 'route' => 'app.setup.master-changes.index',
            'permission' => MasterChangeGrid::PERMISSION, 'hint' => 'Live', 'sort' => 10,
        ]);
        MenuService::item('ho', 'setup', [
            'path' => 'governance/user-activity', 'parent' => 'governance',
            'label' => self::REPORTS['user-activity'][1], 'route' => 'app.setup.user-activity.index',
            'permission' => UserActivityGrid::PERMISSION, 'hint' => 'Live', 'sort' => 20,
        ]);

        $this->command?->info('  Reports: 2 permissions and Setup → Governance → Master data changes, User activity.');
    }
}
