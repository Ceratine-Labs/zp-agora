<?php

namespace Tests\Feature\Core;

use App\Support\ProcedureService;
use Illuminate\Support\Facades\DB;
use Modules\Core\Database\Seeders\ManagedRolesSeeder;
use Modules\Core\Database\Seeders\RetireRolesSeeder;
use Modules\Core\Models\MenuItem;
use Modules\Core\Models\Permission;
use Modules\Core\Models\Role;
use Modules\Core\Models\RoleMenuItem;
use Modules\Core\Models\RolePermission;
use Modules\Core\Models\User;
use Modules\Core\Models\UserPermission;
use Modules\Core\Models\UserRole;
use Modules\Core\Services\MenuAccessService;
use Modules\Core\Services\PermissionService;
use Modules\Core\Services\RoleAccessService;
use Tests\Fixtures\GrantsAccess;
use Tests\TestCase;

class ManagedRolesTest extends TestCase
{
    use GrantsAccess;

    private bool $transactionStarted = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->skipUnlessLocalStub();
        DB::connection(config('agora.connections.app'))->beginTransaction();
        $this->transactionStarted = true;
    }

    protected function tearDown(): void
    {
        if ($this->transactionStarted) {
            DB::connection(config('agora.connections.app'))->rollBack();
        }
        parent::tearDown();
    }

    private function person(?string $profile = null): User
    {
        $user = User::query()->acrossBranches()->create([
            'BranchId' => config('agora.group_branch_id'), 'UserName' => 'TEST-role-user',
            'EmailAddress' => 'TEST-role-'.uniqid().'@agora.invalid', 'IsActive' => true,
            'IsLocked' => false, 'Workspace' => 'ho', 'PasswordHash' => 'TEST-password-only',
        ]);
        if ($profile !== null) {
            $this->grantProfile($user, $profile);
        }

        return $user;
    }

    private function permission(string $code): int
    {
        if (str_contains($code, '*')) {
            [$module, $resource, $action] = explode('.', $code);

            return (int) Permission::query()->acrossBranches()->firstOrCreate([
                'BranchId' => config('agora.group_branch_id'), 'Code' => $code,
            ], ['Module' => $module, 'Resource' => $resource, 'Action' => $action, 'Name' => 'TEST-wildcard'])->Id;
        }

        return (int) Permission::query()->acrossBranches()->where('Code', $code)->firstOrFail()->Id;
    }

    /**
     * @param  array<int, string>  $codes
     * @param  array<int, int>  $menu
     */
    private function role(array $codes = [], array $menu = []): Role
    {
        return app(RoleAccessService::class)->save(null, [
            'Name' => 'TEST-role-'.uniqid(), 'Code' => 'TEST-'.uniqid(),
            'permissions' => array_map(fn ($code) => $this->permission($code), $codes), 'menu' => $menu,
        ]);
    }

    public function test_migration_preserves_historical_roles_and_existing_version_audit(): void
    {
        $db = DB::connection(config('agora.connections.app'));
        $originalSchema = config('agora.schema');
        $scratch = 'TEST_roles_'.uniqid();
        $db->statement("CREATE SCHEMA [{$scratch}]");
        $db->statement("SELECT Id, BranchId, Code, Name INTO [{$scratch}].[Role] FROM [{$originalSchema}].[Role] WHERE IsManaged = 0");
        $db->statement("SELECT * INTO [{$scratch}].[SchemaVersion] FROM [{$originalSchema}].[SchemaVersion] WHERE Version <> '1.10-core-roles'");
        $before = $db->table($scratch.'.Role')->orderBy('Id')->get()->toJson();
        $audit = $db->table($scratch.'.SchemaVersion')->where('Version', '1.10')->first();
        try {
            config(['agora.schema' => $scratch]);
            $migration = require base_path('Modules/Core/Database/Migrations/v1__01h_core_managed_roles.php');
            $migration->up();
            $this->assertSame($before, $db->table($scratch.'.Role')->select('Id', 'BranchId', 'Code', 'Name')->orderBy('Id')->get()->toJson());
            $this->assertSame(0, $db->table($scratch.'.Role')->where('IsManaged', 1)->count());
            $this->assertEquals($audit, $db->table($scratch.'.SchemaVersion')->where('Version', '1.10')->first());
            $this->assertSame(1, $db->table($scratch.'.SchemaVersion')->where('Version', '1.10-core-roles')->count());
        } finally {
            config(['agora.schema' => $originalSchema]);
        }
    }

    public function test_rollout_grants_role_actions_only_to_existing_direct_role_administrators(): void
    {
        $admin = $this->person();
        $reader = $this->person();
        foreach ([[$admin, 'setup.roles.edit'], [$reader, 'setup.roles.view']] as [$person, $code]) {
            UserPermission::query()->acrossBranches()->create([
                'BranchId' => config('agora.group_branch_id'), 'UserId' => $person->Id, 'PermissionId' => $this->permission($code),
            ]);
        }
        $usersMenu = MenuItem::query()->acrossBranches()->where('RouteName', 'app.setup.users.index')->firstOrFail();
        app(MenuAccessService::class)->setForUser($admin, [$usersMenu->Id]);
        $derived = $this->person();
        $role = $this->role(['setup.roles.edit']);
        app(RoleAccessService::class)->assign($derived, [$role->Id]);
        app(ManagedRolesSeeder::class)->run();
        app(ManagedRolesSeeder::class)->run();
        $permissions = app(PermissionService::class);
        $this->assertTrue($permissions->userHas($admin, 'setup.roles.create'));
        $this->assertTrue($permissions->userHas($admin, 'setup.roles.delete'));
        $this->assertFalse($permissions->userHas($reader, 'setup.roles.create'));
        $this->assertFalse($permissions->userHas($derived, 'setup.roles.create'));
        $this->assertCount(3, $permissions->directPatternsFor($admin));
        $this->assertCount(2, app(MenuAccessService::class)->userItemIdsFor($admin));
    }

    public function test_rerunning_the_historical_cutover_does_not_flatten_managed_roles(): void
    {
        $person = $this->person();
        $usersMenu = MenuItem::query()->acrossBranches()->where('RouteName', 'app.setup.users.index')->firstOrFail();
        $role = $this->role(['setup.users.view'], [$usersMenu->Id]);
        app(RoleAccessService::class)->assign($person, [$role->Id]);
        app(RetireRolesSeeder::class)->run();
        $this->assertSame([], app(PermissionService::class)->directPatternsFor($person));
        $this->assertSame([], app(MenuAccessService::class)->userItemIdsFor($person));
    }

    public function test_roles_combine_and_edits_invalidate_all_members_without_copying_direct_grants(): void
    {
        $roles = app(RoleAccessService::class);
        $permissions = app(PermissionService::class);
        $first = $this->role(['setup.users.view']);
        $second = $this->role(['setup.roles.view']);
        $a = $this->person();
        $b = $this->person();
        $roles->assign($a, [$first->Id, $second->Id]);
        $roles->assign($b, [$first->Id]);
        foreach ([$a, $b] as $person) {
            $this->assertTrue($permissions->userHas($person, 'setup.users.view'));
            $this->assertSame([], $permissions->directPatternsFor($person));
        }
        $this->assertTrue($permissions->userHas($a, 'setup.roles.view'));
        $roles->save($first->Id, ['Code' => $first->Code, 'Name' => $first->Name, 'permissions' => []]);
        foreach ([$a, $b] as $person) {
            $this->assertFalse($permissions->userHas($person, 'setup.users.view'));
            $this->assertFalse((new PermissionService)->userHas($person, 'setup.users.view'));
        }
        $this->assertTrue($permissions->userHas($a, 'setup.roles.view'));
    }

    public function test_removing_roles_preserves_direct_grants_sites_and_landing(): void
    {
        $person = $this->person();
        $person->forceFill(['LandingRoute' => 'app.exco'])->save();
        UserPermission::query()->acrossBranches()->create(['BranchId' => config('agora.group_branch_id'),
            'UserId' => $person->Id, 'PermissionId' => $this->permission('setup.users.view')]);
        $role = $this->role(['setup.roles.view']);
        $roles = app(RoleAccessService::class);
        $roles->assign($person, [$role->Id]);
        $this->assertTrue(app(PermissionService::class)->userHas($person, 'setup.roles.view'));
        $roles->assign($person, []);
        $this->assertTrue(app(PermissionService::class)->userHas($person, 'setup.users.view'));
        $this->assertFalse(app(PermissionService::class)->userHas($person, 'setup.roles.view'));
        $this->assertSame('app.exco', $person->fresh()->LandingRoute);
        $this->assertSame([], $person->fresh()->allowedBranchIds());
    }

    public function test_historical_roles_and_assignments_are_inert_and_cannot_be_assigned(): void
    {
        $role = $this->role(['*.*.*']);
        $role->forceFill(['IsManaged' => false])->save();
        $person = $this->person();
        UserRole::query()->acrossBranches()->create(['BranchId' => config('agora.group_branch_id'), 'UserId' => $person->Id, 'RoleId' => $role->Id]);
        $this->assertFalse(app(PermissionService::class)->userHas($person, 'setup.users.edit'));
        $this->assertSame([], app(RoleAccessService::class)->roleIdsFor($person));
        $this->actingAs($this->person('admin'))->put(route('app.setup.users.roles.update', $person->Id), ['roles' => [$role->Id]])
            ->assertSessionHasErrors('roles');
        $this->get(route('app.setup.roles.edit', $role->Id))->assertNotFound();
    }

    public function test_menu_role_changes_reach_the_route_guard_and_keep_direct_ticks_separate(): void
    {
        $person = $this->person();
        $menu = app(MenuAccessService::class);
        $users = MenuItem::query()->acrossBranches()->where('RouteName', 'app.setup.users.index')->firstOrFail();
        $roleMenu = MenuItem::query()->acrossBranches()->where('RouteName', 'app.setup.roles.index')->firstOrFail();
        $role = $this->role(['*.*.view'], [$roleMenu->Id]);
        $menu->setForUser($person, [$users->Id]);
        app(RoleAccessService::class)->assign($person, [$role->Id]);
        $this->assertTrue($menu->canReachRoute($person, 'app.setup.roles.index'));
        $this->assertSame([$users->Id], $menu->userItemIdsFor($person));
        app(RoleAccessService::class)->save($role->Id, ['Code' => $role->Code, 'Name' => $role->Name,
            'permissions' => [$this->permission('*.*.view')], 'menu' => []]);
        $this->assertFalse($menu->canReachRoute($person, 'app.setup.roles.index'));
        $this->assertTrue($menu->canReachRoute($person, 'app.setup.users.index'));
        $this->actingAs($person)->get(route('app.setup.roles.index'))->assertForbidden();
    }

    public function test_role_admin_cannot_remove_their_own_last_access_and_rollback_clears_cache(): void
    {
        $actor = $this->person();
        $role = $this->role(['*.*.*']);
        app(RoleAccessService::class)->assign($actor, [$role->Id]);
        $this->actingAs($actor)->from(route('app.setup.roles.edit', $role->Id))
            ->put(route('app.setup.roles.update', $role->Id), ['Code' => $role->Code, 'Name' => $role->Name])
            ->assertSessionHasErrors('role');
        $this->assertTrue(app(PermissionService::class)->userHas($actor, 'setup.roles.edit'));
        $this->put(route('app.setup.users.roles.update', $actor->Id), ['roles' => []])->assertSessionHasErrors('roles');
        $this->assertSame([$role->Id], app(RoleAccessService::class)->roleIdsFor($actor));
        $this->assertTrue((new PermissionService)->userHas($actor, 'setup.users.edit'));
    }

    public function test_role_crud_assignment_screens_and_validation(): void
    {
        $admin = $this->person('admin');
        $this->actingAs($admin)->get(route('app.setup.roles.index'))->assertOk()->assertSee('Create role');
        $this->get(route('app.setup.roles.create'))->assertOk()->assertSee('Save role');
        $code = 'TEST-http-'.uniqid();
        $this->post(route('app.setup.roles.store'), ['Code' => $code, 'Name' => 'TEST Cash viewers',
            'permissions' => [$this->permission('setup.users.view')]])->assertSessionHasNoErrors()->assertRedirect();
        $role = Role::query()->acrossBranches()->where('Code', $code)->firstOrFail();
        $this->get(route('app.setup.roles.show', $role->Id))->assertOk()->assertSee('setup.users.view');
        $this->get(route('app.setup.roles.edit', $role->Id))->assertOk();
        $subject = $this->person();
        $this->put(route('app.setup.users.roles.update', $subject->Id), ['roles' => [$role->Id]])->assertSessionHasNoErrors();
        $this->get(route('app.setup.users.show', $subject->Id))->assertOk()->assertSee('From role')->assertSee('TEST Cash viewers');
        $this->get(route('app.setup.users.edit', $subject->Id))->assertOk()->assertSee('Granted by role');
        $this->delete(route('app.setup.roles.destroy', $role->Id))->assertSessionHasErrors('role');
        $this->post(route('app.setup.roles.store'), ['Code' => strtoupper($code), 'Name' => 'Duplicate'])->assertSessionHasErrors('Code');
        $this->put(route('app.setup.roles.update', $role->Id), ['Code' => $code, 'Name' => 'TEST', 'permissions' => [2147483647]])->assertSessionHasErrors('permissions');
        $this->put(route('app.setup.users.roles.update', $subject->Id), ['roles' => []])->assertRedirect();
        $this->delete(route('app.setup.roles.destroy', $role->Id))->assertRedirect(route('app.setup.roles.index'));
        $this->assertFalse(Role::query()->acrossBranches()->whereKey($role->Id)->exists());
        $this->assertSame(0, RolePermission::query()->acrossBranches()->where('RoleId', $role->Id)->count());
        $this->assertSame(0, RoleMenuItem::query()->acrossBranches()->where('RoleId', $role->Id)->count());
    }

    public function test_readers_cannot_create_edit_delete_or_assign_and_exports_require_view(): void
    {
        $role = $this->role();
        $reader = $this->person('auditor');
        $this->actingAs($reader)->get(route('app.setup.roles.index'))->assertOk()->assertDontSee('Create role');
        $this->get(route('app.setup.roles.create'))->assertForbidden();
        $this->get(route('app.setup.roles.edit', $role->Id))->assertForbidden();
        $this->post(route('app.setup.roles.store'), ['Code' => 'TEST-denied', 'Name' => 'Denied'])->assertForbidden();
        $this->put(route('app.setup.roles.update', $role->Id), ['Code' => $role->Code, 'Name' => 'Denied'])->assertForbidden();
        $this->delete(route('app.setup.roles.destroy', $role->Id))->assertForbidden();
        $this->put(route('app.setup.users.roles.update', $reader->Id), ['roles' => [$role->Id]])->assertForbidden();
        $this->get(route('app.grids.extract', ['grid' => 'app.setup.roles']))->assertOk();
        $this->actingAs($this->person())->get(route('app.grids.extract', ['grid' => 'app.setup.roles']))->assertForbidden();
        $this->get(route('app.grids.extract', ['grid' => 'app.setup.users']))->assertForbidden();
    }

    public function test_role_grid_filters_counts_and_users_grid_include_inherited_permissions(): void
    {
        $role = $this->role(['setup.users.view', 'setup.roles.view']);
        $person = $this->person();
        app(RoleAccessService::class)->assign($person, [$role->Id]);
        $procs = app(ProcedureService::class);
        $sets = $procs->callSets('agora.usp_Core_GridRoles', ['Search' => $role->Name, 'FiltersJson' => json_encode([
            ['column' => 'PermissionCount', 'op' => 'gte', 'value' => '2'],
            ['column' => 'UserCount', 'op' => 'eq', 'value' => '1'],
        ])]);
        $this->assertCount(1, $sets[0]);
        $this->assertSame(1, (int) $sets[1][0]->TotalRows);
        $this->assertSame(2, (int) $sets[0][0]->PermissionCount);
        $empty = $procs->callSets('agora.usp_Core_GridRoles', ['Search' => $role->Name, 'FiltersJson' => json_encode([
            ['column' => 'UserCount', 'op' => 'gt', 'value' => '1'],
        ])]);
        $this->assertCount(0, $empty[0]);
        $this->assertSame(0, (int) $empty[1][0]->TotalRows);
        $people = $procs->call('agora.usp_Core_GridUsers', ['Search' => $person->EmailAddress]);
        $this->assertCount(1, $people);
        $this->assertSame(2, (int) $people[0]->PermissionCount);
        $this->assertSame($role->Name, $people[0]->RoleNames);
    }
}
