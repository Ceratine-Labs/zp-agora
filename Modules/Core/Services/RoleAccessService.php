<?php

namespace Modules\Core\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Models\MenuItem;
use Modules\Core\Models\Permission;
use Modules\Core\Models\Role;
use Modules\Core\Models\RoleMenuItem;
use Modules\Core\Models\RolePermission;
use Modules\Core\Models\User;
use Modules\Core\Models\UserRole;

/** Customer-defined roles add grants; historical role rows never participate. */
class RoleAccessService
{
    public function __construct(private PermissionService $permissions, private MenuAccessService $menus) {}

    /** @return Builder<Role> */
    public function roles(): Builder
    {
        return Role::query()->acrossBranches()
            ->where('BranchId', config('agora.group_branch_id'))->where('IsManaged', true);
    }

    /** @return array<int, int> */
    public function roleIdsFor(User $person): array
    {
        return $this->roles()->whereIn('Id', UserRole::query()->acrossBranches()
            ->where('UserId', $person->Id)->select('RoleId'))
            ->pluck('Id')->map(fn ($id) => (int) $id)->all();
    }

    /** @return array<int, string> */
    public function patternsFor(User $person): array
    {
        return Permission::query()->acrossBranches()->whereIn('Id', RolePermission::query()->acrossBranches()
            ->whereIn('RoleId', $this->roleIdsFor($person))->select('PermissionId'))
            ->pluck('Code')->map(fn ($code) => strtolower((string) $code))->all();
    }

    /** @return array<int, int> */
    public function menuIdsFor(User $person): array
    {
        return RoleMenuItem::query()->acrossBranches()->whereIn('RoleId', $this->roleIdsFor($person))
            ->pluck('MenuItemId')->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    /** @param array<int, int> $roleIds */
    public function assign(User $person, array $roleIds, ?User $actor = null): void
    {
        $before = $this->adminAccess($actor);
        try {
            DB::connection(config('agora.connections.app'))->transaction(function () use ($person, $roleIds, $actor, $before) {
                $valid = $this->roles()->whereIn('Id', $roleIds)->orderBy('Id')->lockForUpdate()->pluck('Id')->map(fn ($id) => (int) $id)->all();
                if (count($valid) !== count(array_unique($roleIds))) {
                    throw ValidationException::withMessages(['roles' => 'One of these roles is no longer available. Reload and try again.']);
                }
                User::query()->acrossBranches()->whereKey($person->Id)->lockForUpdate()->firstOrFail();
                UserRole::query()->acrossBranches()->where('UserId', $person->Id)
                    ->whereIn('RoleId', $this->roles()->select('Id'))->delete();
                foreach ($valid as $id) {
                    UserRole::query()->acrossBranches()->create([
                        'BranchId' => config('agora.group_branch_id'), 'UserId' => $person->Id,
                        'RoleId' => $id, 'IsPrimary' => false,
                    ]);
                }
                $this->forget([(int) $person->Id]);
                $this->protectAdmin($actor, $before, 'roles');
            });
        } finally {
            $this->forget([(int) $person->Id]);
        }
    }

    /**
     * @param  array{Code: string, Name: string, permissions?: array<int, int>, menu?: array<int, int>}  $data
     */
    public function save(?int $id, array $data, ?User $actor = null): Role
    {
        $before = $this->adminAccess($actor);
        $affected = [];
        try {
            return DB::connection(config('agora.connections.app'))->transaction(function () use ($id, $data, $actor, $before, &$affected) {
                $role = $id === null ? new Role : $this->roles()->lockForUpdate()->findOrFail($id);
                $code = strtolower(trim($data['Code']));
                if (Role::query()->acrossBranches()->where('BranchId', config('agora.group_branch_id'))
                    ->where('Code', $code)->when($id !== null, fn ($query) => $query->where('Id', '!=', $id))->exists()) {
                    throw ValidationException::withMessages(['Code' => 'That role code is already in use. Choose another.']);
                }
                $permissionIds = $this->validatedIds(Permission::query()->acrossBranches(), $data['permissions'] ?? [], 'permissions');
                $menuIds = $this->validatedIds(MenuItem::query()->acrossBranches()->where('IsActive', true), $data['menu'] ?? [], 'menu');
                $role->fill(['BranchId' => config('agora.group_branch_id'), 'Code' => $code,
                    'Name' => trim($data['Name']), 'IsManaged' => true])->save();
                $affected = UserRole::query()->acrossBranches()->where('RoleId', $role->Id)
                    ->pluck('UserId')->map(fn ($value) => (int) $value)->all();
                RolePermission::query()->acrossBranches()->where('RoleId', $role->Id)->delete();
                RoleMenuItem::query()->acrossBranches()->where('RoleId', $role->Id)->delete();
                foreach ($permissionIds as $permissionId) {
                    RolePermission::query()->acrossBranches()->create([
                        'BranchId' => config('agora.group_branch_id'), 'RoleId' => $role->Id, 'PermissionId' => $permissionId,
                    ]);
                }
                foreach ($menuIds as $menuId) {
                    RoleMenuItem::query()->acrossBranches()->create([
                        'BranchId' => config('agora.group_branch_id'), 'RoleId' => $role->Id, 'MenuItemId' => $menuId,
                    ]);
                }
                $this->forget($affected);
                $this->protectAdmin($actor, $before);

                return $role;
            });
        } finally {
            // Also clear entries warmed while a rejected change was inside its transaction.
            $this->forget($affected);
        }
    }

    public function delete(int $id): void
    {
        DB::connection(config('agora.connections.app'))->transaction(function () use ($id) {
            $role = $this->roles()->lockForUpdate()->findOrFail($id);
            if (UserRole::query()->acrossBranches()->where('RoleId', $role->Id)->exists()) {
                throw ValidationException::withMessages(['role' => 'This role is assigned to users. Remove those assignments before deleting it.']);
            }
            RolePermission::query()->acrossBranches()->where('RoleId', $role->Id)->delete();
            RoleMenuItem::query()->acrossBranches()->where('RoleId', $role->Id)->delete();
            $role->delete();
        });
    }

    /**
     * @param  Builder<covariant \App\Models\BaseModel>  $query
     * @param  array<int, int>  $ids
     * @return array<int, int>
     */
    private function validatedIds(Builder $query, array $ids, string $field): array
    {
        $valid = $query->whereIn('Id', $ids)->pluck('Id')->map(fn ($id) => (int) $id)->all();
        if (count($valid) !== count(array_unique($ids))) {
            throw ValidationException::withMessages([$field => 'An entry is no longer available. Reload and try again.']);
        }

        return $valid;
    }

    /** @param array<int, int> $ids */
    private function forget(array $ids): void
    {
        foreach ($ids as $id) {
            $this->permissions->forget($id);
            $this->menus->forget($id);
        }
    }

    /** @return array<string, bool> */
    private function adminAccess(?User $actor): array
    {
        if ($actor === null) {
            return [];
        }
        $state = [];
        foreach (['users', 'roles'] as $resource) {
            $state[$resource] = $this->permissions->userHasAll($actor, ['setup.'.$resource.'.edit', 'setup.'.$resource.'.view'])
                && $this->menus->canReachRoute($actor, 'app.setup.'.$resource.'.index');
        }

        return $state;
    }

    /** @param array<string, bool> $before */
    private function protectAdmin(?User $actor, array $before, string $field = 'role'): void
    {
        $after = $this->adminAccess($actor);
        foreach ($before as $resource => $allowed) {
            if ($allowed && ! ($after[$resource] ?? false)) {
                throw ValidationException::withMessages([$field => 'That change would remove your own administration access. Ask another administrator.']);
            }
        }
    }
}
