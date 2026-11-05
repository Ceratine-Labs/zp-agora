<?php

namespace Tests\Feature\Core;

use App\Support\ProcedureService;
use Illuminate\Support\Facades\DB;
use Modules\Core\Database\Seeders\AllPermissionsSeeder;
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
use Modules\Reports\Database\Seeders\MyQueuePermissionsSeeder;
use Modules\Reports\Database\Seeders\ReportViewPermissionsSeeder;
use Modules\Reports\Services\MyQueueAccess;
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

    public function test_my_queue_event_visibility_follows_role_permissions(): void
    {
        app(MyQueuePermissionsSeeder::class)->run();
        $person = $this->person();
        $role = $this->role(['reports.queue-day-close.view', 'reports.queue-staff-shorts.view']);
        app(RoleAccessService::class)->assign($person, [$role->Id]);
        $this->assertSame(['day-close', 'staff-shorts'], app(MyQueueAccess::class)->allowed($person));
        $this->assertSame([], app(MyQueueAccess::class)->allowed($this->person()));
    }

    public function test_my_queue_page_omits_events_without_role_access(): void
    {
        app(ReportViewPermissionsSeeder::class)->run();
        app(MyQueuePermissionsSeeder::class)->run();
        $person = $this->person();
        $role = $this->role(['reports.my-queue.view', 'reports.queue-day-close.view']);
        app(RoleAccessService::class)->assign($person, [$role->Id]);
        $this->actingAs($person)->get(route('app.reports.show', ['report' => 'my-queue']))
            ->assertOk()->assertSee('data-queue-total="day-close"', false)
            ->assertDontSee('data-queue-total="staff-shorts"', false)
            ->assertDontSee('data-queue-total="open-cashups"', false);
    }

    public function test_user_without_permissions_is_directed_to_access_required(): void
    {
        $person = $this->person();
        $this->actingAs($person)->get(route('app.dashboard'))->assertRedirect(route('app.access-required'));
        $this->get(route('app.setup.users.index'))->assertRedirect(route('app.access-required'));
        $this->get(route('app.access-required'))->assertOk()->assertSee('You have no permissions in Agora')->assertSee('contact your administrator');
        $this->getJson(route('app.dashboard'))->assertForbidden()->assertJsonPath('message', 'You have no permissions. Contact your administrator.');
        $this->get(route('app.password.change'))->assertOk();
    }

    public function test_permissions_control_menu_entries_and_report_urls_without_menu_grants(): void
    {
        $person = $this->person();
        $role = $this->role(['recon.runs.view', 'stockrecon.runs.view']);
        app(RoleAccessService::class)->assign($person, [$role->Id]);
        $recon = MenuItem::query()->acrossBranches()->where('RouteName', 'app.recon.index')->firstOrFail();
        $users = MenuItem::query()->acrossBranches()->where('RouteName', 'app.setup.users.index')->firstOrFail();
        $stock = MenuItem::query()->acrossBranches()->where('RouteName', 'app.stockrecon.index')->firstOrFail();
        $access = app(MenuAccessService::class);
        $access->setForUser($person, [(int) $users->Id]);

        $this->assertTrue($access->mayView($person, $recon), 'Role permission grants its menu even with a different legacy tick.');
        $this->assertTrue($access->mayView($person, $stock), 'Stock reconciliation permission grants its menu.');
        $this->assertFalse($access->mayView($person, $users), 'Legacy menu tick must not grant a missing permission.');
        $other = $this->person();
        $this->assertFalse($access->mayView($other, $stock), 'No permission means no stock reconciliation menu.');
        $this->actingAs($person)->get(route('app.reports.index'))->assertForbidden();
        $this->get(route('app.reports.show', ['report' => 'stock-count']))->assertForbidden();
    }

    public function test_role_editor_has_independent_controls_for_each_permission_module(): void
    {
        $admin = $this->person('admin');
        $html = $this->actingAs($admin)->get(route('app.setup.roles.create'))->assertOk()->getContent();
        $doc = new \DOMDocument;
        @$doc->loadHTML($html);
        $xpath = new \DOMXPath($doc);
        $groups = $xpath->query('//*[@data-role-permission-group]');
        $this->assertGreaterThan(1, $groups->length);
        foreach ($groups as $group) {
            $this->assertSame(1, $xpath->query('.//button[@data-select-permissions]', $group)->length);
            $this->assertSame(1, $xpath->query('.//button[@data-clear-permissions]', $group)->length);
        }
        $this->assertSame(0, $xpath->query('//input[@name="menu[]"]')->length);
    }

    public function test_all_permissions_grant_is_explicit_and_covers_new_permissions(): void
    {
        app(AllPermissionsSeeder::class)->run();
        app(AllPermissionsSeeder::class)->run();
        $this->assertSame(1, Permission::query()->acrossBranches()->where('Code', '*.*.*')->count());
        $person = $this->person();
        $this->assertSame([], app(PermissionService::class)->patternsFor($person));
        $role = $this->role(['*.*.*']);
        app(RoleAccessService::class)->assign($person, [$role->Id]);
        $this->assertTrue(app(PermissionService::class)->userHas($person, 'stockrecon.runs.view'));
        $this->assertTrue(app(PermissionService::class)->userHas($person, 'future.feature.view'));
    }

    public function test_each_report_has_its_own_menu_and_direct_url_permission(): void
    {
        app(ReportViewPermissionsSeeder::class)->run();
        $person = $this->person();
        $role = $this->role(['reports.stock-count.view']);
        app(RoleAccessService::class)->assign($person, [$role->Id]);
        $stock = MenuItem::query()->acrossBranches()->where('Url', '/app/reports/stock-count')->firstOrFail();
        $cash = MenuItem::query()->acrossBranches()->where('Url', '/app/reports/drop-safe')->firstOrFail();
        $this->assertTrue(app(MenuAccessService::class)->mayView($person, $stock));
        $this->assertFalse(app(MenuAccessService::class)->mayView($person, $cash));
        $this->actingAs($person)->get(route('app.reports.show', ['report' => 'drop-safe']))->assertForbidden();
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
        $this->get(route('app.setup.users.show', $subject->Id))->assertOk()->assertSee('Granted by role: TEST Cash viewers');
        $this->get(route('app.setup.users.edit', $subject->Id))->assertOk()->assertSee('Granted by role: TEST Cash viewers')->assertSee('is-inherited');
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
