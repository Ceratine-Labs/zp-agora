<?php

namespace Modules\Core\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Models\MenuItem;
use Modules\Core\Models\Permission;
use Modules\Core\Models\User;
use Modules\Core\Models\UserMenuItem;
use Modules\Core\Models\UserPermission;
use Modules\Core\Services\MenuAccessService;
use Modules\Core\Services\MenuService;
use Modules\Core\Services\PermissionService;

/** Register the catalogue and navigation, without assigning anybody a role. */
class ManagedRolesSeeder extends Seeder
{
    public int $seedOrder = 70;

    public function run(): void
    {
        foreach (['view', 'create', 'edit', 'delete'] as $action) {
            Permission::query()->acrossBranches()->updateOrCreate([
                'BranchId' => config('agora.group_branch_id'), 'Code' => 'setup.roles.'.$action,
            ], ['Module' => 'setup', 'Resource' => 'roles', 'Action' => $action,
                'Name' => ucfirst($action).' roles', 'SortOrder' => 920]);
        }
        $menu = MenuService::item('ho', 'setup', [
            'path' => 'people-assets/roles', 'parent' => 'people-assets', 'label' => 'Roles',
            'route' => 'app.setup.roles.index', 'permission' => 'setup.roles.view', 'sort' => 15,
        ]);
        // The retired catalogue already gave role administrators view/edit.
        // Carry that management responsibility onto the new action split.
        // Read DIRECT edit grants only: never flatten managed roles on a rerun.
        $edit = Permission::query()->acrossBranches()->where('Code', 'setup.roles.edit')->value('Id');
        $actions = Permission::query()->acrossBranches()->whereIn('Code', ['setup.roles.create', 'setup.roles.delete'])->pluck('Id');
        $administrators = User::query()->acrossBranches()->whereIn('Id', UserPermission::query()->acrossBranches()
            ->where('PermissionId', $edit)->select('UserId'))->get();
        $userMenuIds = MenuItem::query()->acrossBranches()->where('RouteName', 'app.setup.users.index')->pluck('Id');
        foreach ($administrators as $person) {
            foreach ($actions as $permissionId) {
                UserPermission::query()->acrossBranches()->firstOrCreate([
                    'BranchId' => config('agora.group_branch_id'), 'UserId' => $person->Id, 'PermissionId' => $permissionId,
                ]);
            }
            if (UserMenuItem::query()->acrossBranches()->where('UserId', $person->Id)->whereIn('MenuItemId', $userMenuIds)->exists()) {
                UserMenuItem::query()->acrossBranches()->firstOrCreate([
                    'BranchId' => config('agora.group_branch_id'), 'UserId' => $person->Id, 'MenuItemId' => $menu->Id,
                ]);
                app(MenuAccessService::class)->forget($person);
            }
        }
        app(PermissionService::class)->forgetAll();
    }
}
