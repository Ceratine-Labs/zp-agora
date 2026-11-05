<?php

namespace Modules\Core\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Models\User;

/** Apply one explicit change to the complete selection, or change nothing. */
class UserBulkAccessService
{
    public function __construct(
        private UserAccessService $access,
        private RoleAccessService $roles,
        private PermissionService $permissions,
        private MenuAccessService $menus,
    ) {}

    /**
     * @param  array<int, int>  $userIds
     * @param  array<int, int>  $roleIds
     */
    public function apply(array $userIds, string $action, array $roleIds, User $actor): int
    {
        if ($userIds === [] || count($userIds) > 200 || count($userIds) !== count(array_unique($userIds))) {
            throw ValidationException::withMessages(['bulk' => 'Select between 1 and 200 different users.']);
        }

        if (! in_array($action, ['add_roles', 'replace_roles', 'activate', 'deactivate', 'unlock'], true)) {
            throw ValidationException::withMessages(['bulk' => 'Choose one of the available actions.']);
        }

        try {
            return DB::connection(config('agora.connections.app'))->transaction(function () use ($userIds, $action, $roleIds, $actor): int {
                if (in_array($action, ['add_roles', 'replace_roles'], true)) {
                    $valid = $this->roles->roles()->whereIn('Id', $roleIds)
                        ->orderBy('Id')->lockForUpdate()->pluck('Id');
                    if ($roleIds === [] || $valid->count() !== count(array_unique($roleIds))) {
                        throw ValidationException::withMessages(['bulk' => 'Choose available roles. A role may have been removed; reload and try again.']);
                    }
                }

                $people = User::query()->acrossBranches()->whereIn('Id', $userIds)
                    ->orderBy('Id')->lockForUpdate()->get();
                if ($people->count() !== count($userIds)) {
                    throw ValidationException::withMessages(['bulk' => 'One of the selected users is no longer available. Reload and select the users again.']);
                }

                foreach ($people as $person) {
                    if (in_array($action, ['add_roles', 'replace_roles'], true)) {
                        $selected = $action === 'add_roles'
                            ? array_values(array_unique([...$this->roles->roleIdsFor($person), ...$roleIds]))
                            : $roleIds;
                        $this->roles->assign($person, $selected, $actor);
                    } else {
                        $fields = match ($action) {
                            'activate' => ['IsActive' => true],
                            'deactivate' => ['IsActive' => false],
                            default => ['IsLocked' => false],
                        };
                        $this->access->updateDetails($person, $fields, $actor);
                    }
                }

                return $people->count();
            });
        } finally {
            // A later refusal also rolls back earlier users. Clear anything
            // warmed by the access guards while those changes were visible.
            foreach ($userIds as $userId) {
                $this->permissions->forget($userId);
                $this->menus->forget($userId);
            }
        }
    }
}
