<?php

namespace Modules\Core\Http\Controllers;

use App\Grid\GridRegistry;
use App\Grid\GridService;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Core\Models\Permission;
use Modules\Core\Models\Role;
use Modules\Core\Models\RolePermission;
use Modules\Core\Models\User;
use Modules\Core\Models\UserRole;
use Modules\Core\Services\RoleAccessService;

class RoleAdminController extends Controller
{
    public function __construct(private RoleAccessService $roles) {}

    public function index(Request $request, GridRegistry $registry, GridService $grids): View
    {
        return view('core::setup.roles.index', [
            'grid' => $grids->build($registry->findOrFail('app.setup.roles'), $request, $request->user()?->Id),
        ]);
    }

    public function create(): View
    {
        return view('core::setup.roles.edit', $this->state(new Role));
    }

    public function show(int $role): View
    {
        return view('core::setup.roles.show', $this->state($this->roles->roles()->findOrFail($role)));
    }

    public function edit(int $role): View
    {
        return view('core::setup.roles.edit', $this->state($this->roles->roles()->findOrFail($role)));
    }

    public function store(Request $request): RedirectResponse
    {
        return $this->save($request);
    }

    public function update(Request $request, int $role): RedirectResponse
    {
        return $this->save($request, $role);
    }

    public function destroy(int $role): RedirectResponse
    {
        $this->roles->delete($role);

        return redirect()->route('app.setup.roles.index')->with('status', 'Role deleted.');
    }

    private function save(Request $request, ?int $id = null): RedirectResponse
    {
        $data = $request->validate([
            'Code' => ['required', 'string', 'max:40', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9_-]*$/'],
            'Name' => ['required', 'string', 'max:80'],
            'permissions' => ['sometimes', 'array'], 'permissions.*' => ['required', 'integer', 'distinct'],
        ]);
        $role = $this->roles->save($id, $data, $request->user());

        return redirect()->route('app.setup.roles.show', ['role' => $role->Id])->with('status', 'Role saved. Assigned users receive the updated access immediately.');
    }

    /** @return array<string, mixed> */
    private function state(Role $role): array
    {
        return [
            'role' => $role,
            'permissionGroups' => Permission::query()->acrossBranches()->orderBy('SortOrder')->get()->groupBy('Module'),
            'permissionIds' => RolePermission::query()->acrossBranches()->where('RoleId', $role->Id ?? 0)
                ->pluck('PermissionId')->map(fn ($id) => (int) $id)->all(),
            'members' => User::query()->acrossBranches()->whereIn('Id', UserRole::query()->acrossBranches()
                ->where('RoleId', $role->Id ?? 0)->select('UserId'))->orderBy('UserName')->get(),
        ];
    }
}
