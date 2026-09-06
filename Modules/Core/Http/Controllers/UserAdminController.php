<?php

namespace Modules\Core\Http\Controllers;

use App\Grid\GridRegistry;
use App\Grid\GridService;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Permission;
use Modules\Core\Models\Role;
use Modules\Core\Models\User;
use Modules\Core\Models\UserRole;
use Modules\Core\Services\PermissionService;

/**
 * Setup → People and assets → Users and access (T028).
 *
 * The list is a grid over agora.usp_Core_GridUsers, like every other
 * user-facing result set. The detail screen is a form, because assigning roles
 * is a decision rather than a query.
 *
 * WHAT THIS DELIBERATELY DOES NOT DO YET. Branch assignment is T009's — the
 * grants live in agora.UserBranch and the screen that edits them needs
 * BranchContext to exist first, so this shows the count and links nowhere.
 * Creating a user is not here either: the 88 people come from PumpIT through
 * usp_Core_MigrateUsers, and a hand-made 89th before the migration has run
 * would collide with it on email address.
 */
class UserAdminController extends Controller
{
    public function __construct(
        private GridRegistry $registry,
        private GridService $grids,
        private PermissionService $permissions,
    ) {}

    /** The user list. */
    public function index(Request $request): View
    {
        return view('core::setup.users.index', [
            'grid' => $this->grids->build(
                $this->registry->findOrFail('app.setup.users'),
                $request,
                $request->user()?->Id,
            ),
        ]);
    }

    /** One person: their roles, and what those roles let them do. */
    public function show(Request $request, int $user): View
    {
        $person = User::query()->acrossBranches()->findOrFail($user);

        $held = UserRole::query()->acrossBranches()
            ->where('UserId', $person->Id)
            ->get()
            ->keyBy('RoleId');

        return view('core::setup.users.show', [
            'person' => $person,
            'roles' => Role::query()->acrossBranches()->orderBy('SortOrder')->get(),
            'held' => $held,
            'branchCount' => DB::connection(config('agora.connections.app'))
                ->table(config('agora.schema').'.UserBranch')
                ->where('UserId', $person->Id)->count(),
            'effective' => $this->effectivePermissions($person),
        ]);
    }

    /**
     * Change who someone is.
     *
     * The whole grant set is replaced rather than diffed, because a role
     * REMOVED is the change that matters and a diff that only adds is the
     * classic way access accumulates until everybody is an administrator.
     */
    public function update(Request $request, int $user): RedirectResponse
    {
        $person = User::query()->acrossBranches()->findOrFail($user);

        $data = $request->validate([
            'roles' => ['array'],
            'roles.*' => ['integer'],
            'primary' => ['nullable', 'integer'],
        ]);

        $roleIds = array_values(array_unique(array_map('intval', $data['roles'] ?? [])));
        $valid = Role::query()->acrossBranches()->whereIn('Id', $roleIds)->pluck('Id')->all();
        $primary = in_array((int) ($data['primary'] ?? 0), $valid, true)
            ? (int) $data['primary']
            : ($valid[0] ?? null);

        $branchId = (int) config('agora.group_branch_id');

        DB::connection(config('agora.connections.app'))->transaction(function () use ($person, $valid, $primary, $branchId) {
            UserRole::query()->acrossBranches()->where('UserId', $person->Id)->delete();

            foreach ($valid as $roleId) {
                UserRole::query()->acrossBranches()->create([
                    'BranchId' => $branchId,
                    'UserId' => $person->Id,
                    'RoleId' => $roleId,
                    'IsPrimary' => $roleId === $primary,
                    'CreatedAt' => now(),
                ]);
            }

            // RoleId is the denormalised pointer the landing route reads. It
            // follows the primary grant so the two cannot disagree.
            $person->forceFill(['RoleId' => $primary])->saveQuietly();
        });

        $this->permissions->forget($person);

        return redirect()
            ->route('app.setup.users.show', ['user' => $person->Id])
            ->with('status', $valid === []
                ? $person->UserName.' now holds no roles and can sign in but see nothing.'
                : $person->UserName.' now holds '.count($valid).' role'.(count($valid) === 1 ? '' : 's').'.');
    }

    /** The role matrix: every role, and every permission it carries. */
    public function roles(): View
    {
        $roles = Role::query()->acrossBranches()->orderBy('SortOrder')->get();
        $permissions = Permission::query()->acrossBranches()->orderBy('SortOrder')->get();

        $granted = DB::connection(config('agora.connections.app'))
            ->table(config('agora.schema').'.RolePermission')
            ->get()
            ->groupBy('RoleId')
            ->map(fn ($rows) => $rows->pluck('PermissionId')->flip());

        return view('core::setup.roles.index', [
            'roles' => $roles,
            'permissions' => $permissions->groupBy('Module'),
            'granted' => $granted,
        ]);
    }

    /**
     * Every permission this person actually holds, resolved through their
     * roles — the answer to "why can they see that", which a role list alone
     * does not give.
     *
     * @return array<string, array<int, string>>
     */
    private function effectivePermissions(User $person): array
    {
        $out = [];

        foreach (Permission::query()->acrossBranches()->orderBy('SortOrder')->get() as $permission) {
            if ($this->permissions->userHas($person, $permission->Code)) {
                $out[$permission->Module][] = $permission->Code;
            }
        }

        return $out;
    }
}
