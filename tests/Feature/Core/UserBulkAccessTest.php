<?php

namespace Tests\Feature\Core;

use Illuminate\Support\Facades\DB;
use Modules\Core\Grids\UserGrid;
use Modules\Core\Models\Permission;
use Modules\Core\Models\Role;
use Modules\Core\Models\User;
use Modules\Core\Models\UserPermission;
use Modules\Core\Models\UserRole;
use Modules\Core\Services\PermissionService;
use Modules\Core\Services\RoleAccessService;
use Tests\Fixtures\GrantsAccess;
use Tests\TestCase;

class UserBulkAccessTest extends TestCase
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
        $person = User::query()->acrossBranches()->create([
            'BranchId' => config('agora.group_branch_id'),
            'UserName' => 'TEST-bulk-user',
            'EmailAddress' => 'TEST-bulk-'.uniqid().'@agora.invalid',
            'IsActive' => true,
            'IsLocked' => false,
            'Workspace' => 'ho',
            'PasswordHash' => 'TEST-password-only',
        ]);
        if ($profile !== null) {
            $this->grantProfile($person, $profile);
        }

        return $person;
    }

    private function permission(string $code): int
    {
        return (int) Permission::query()->acrossBranches()->where('Code', $code)->firstOrFail()->Id;
    }

    private function role(string $permission): Role
    {
        return app(RoleAccessService::class)->save(null, [
            'Code' => 'TEST-'.uniqid(),
            'Name' => 'TEST-bulk-role-'.uniqid(),
            'permissions' => [$this->permission($permission)],
        ]);
    }

    public function test_only_editors_can_select_and_apply_a_batch(): void
    {
        $subject = $this->person();
        $payload = ['items' => [$subject->Id], 'action' => 'deactivate'];
        $this->post(route('app.setup.users.bulk.update'), $payload)->assertRedirect(route('login'));

        $this->actingAs($this->person('auditor'));
        $this->assertFalse(app(UserGrid::class)->selectable());
        $this->post(route('app.setup.users.bulk.update'), $payload)->assertForbidden();
        $this->assertTrue($subject->fresh()->IsActive);

        $this->actingAs($this->person('admin'));
        $this->assertTrue(app(UserGrid::class)->selectable());
        $this->assertSame($subject->Id, app(UserGrid::class)->rowKey($subject));
        $this->post(route('app.setup.users.bulk.update'), $payload)
            ->assertSessionHasNoErrors()->assertRedirect(route('app.setup.users.index'));
        $this->assertFalse($subject->fresh()->IsActive);
    }

    public function test_adding_roles_preserves_existing_roles_and_direct_grants_and_refreshes_permissions(): void
    {
        $first = $this->person();
        $second = $this->person();
        $existing = $this->role('setup.users.view');
        $added = $this->role('setup.roles.view');
        $roles = app(RoleAccessService::class);
        $roles->assign($first, [$existing->Id]);
        $directPermission = $this->permission('setup.users.edit');
        UserPermission::query()->acrossBranches()->create([
            'BranchId' => config('agora.group_branch_id'), 'UserId' => $first->Id,
            'PermissionId' => $directPermission,
        ]);
        $permissions = app(PermissionService::class);
        $this->assertFalse($permissions->userHas($first, 'setup.roles.view'));
        $this->assertFalse($permissions->userHas($second, 'setup.roles.view'));

        $this->actingAs($this->person('admin'));
        $payload = ['items' => [$first->Id, $second->Id], 'action' => 'add_roles', 'roles' => [$added->Id]];
        $this->post(route('app.setup.users.bulk.update'), $payload)->assertSessionHasNoErrors();
        $this->post(route('app.setup.users.bulk.update'), $payload)->assertSessionHasNoErrors();

        $this->assertEqualsCanonicalizing([$existing->Id, $added->Id], $roles->roleIdsFor($first));
        $this->assertSame([$added->Id], $roles->roleIdsFor($second));
        $this->assertSame(3, UserRole::query()->acrossBranches()->whereIn('UserId', [$first->Id, $second->Id])->count());
        $this->assertSame([$directPermission], UserPermission::query()->acrossBranches()
            ->where('UserId', $first->Id)->pluck('PermissionId')->map(fn ($id) => (int) $id)->all());
        foreach ([$first, $second] as $person) {
            $this->assertTrue($permissions->userHas($person, 'setup.roles.view'));
            $this->assertTrue((new PermissionService)->userHas($person, 'setup.roles.view'));
        }
    }

    public function test_activation_and_unlock_change_only_the_requested_account_flag(): void
    {
        $first = $this->person();
        $second = $this->person();
        foreach ([$first, $second] as $person) {
            $person->forceFill(['IsActive' => false, 'IsLocked' => true])->save();
        }
        $this->actingAs($this->person('admin'));
        $items = [$first->Id, $second->Id];
        $this->post(route('app.setup.users.bulk.update'), ['items' => $items, 'action' => 'unlock'])
            ->assertSessionHasNoErrors();
        foreach ([$first, $second] as $person) {
            $this->assertFalse($person->fresh()->IsActive);
            $this->assertFalse($person->fresh()->IsLocked);
        }
        $this->post(route('app.setup.users.bulk.update'), ['items' => $items, 'action' => 'activate'])
            ->assertSessionHasNoErrors();
        foreach ([$first, $second] as $person) {
            $this->assertTrue($person->fresh()->IsActive);
            $this->assertFalse($person->fresh()->IsLocked);
            $this->assertSame('TEST-bulk-user', $person->fresh()->UserName);
        }
    }

    public function test_invalid_selections_and_actions_never_change_any_user(): void
    {
        $subject = $this->person();
        $deleted = $this->person();
        $deleted->delete();
        $this->actingAs($this->person('admin'));

        $invalid = [
            [],
            ['items' => []],
            ['items' => [$subject->Id, $subject->Id]],
            ['items' => [$subject->Id, 2147483647]],
            ['items' => [$subject->Id, $deleted->Id]],
            ['items' => [$subject->Id, 'invalid']],
            ['items' => range(1, 201)],
            ['items' => [$subject->Id], 'action' => 'remove_roles'],
            ['items' => [$subject->Id], 'action' => 'add_roles'],
        ];
        foreach ($invalid as $payload) {
            $this->post(route('app.setup.users.bulk.update'), $payload + ['action' => 'deactivate'])
                ->assertSessionHasErrors('bulk')->assertRedirect(route('app.setup.users.index'));
            $this->assertTrue($subject->fresh()->IsActive);
        }
    }

    public function test_unknown_or_historical_roles_refuse_the_complete_batch(): void
    {
        $first = $this->person();
        $second = $this->person();
        $managed = $this->role('setup.users.view');
        $historical = $this->role('setup.roles.view');
        $historical->forceFill(['IsManaged' => false])->save();
        $this->actingAs($this->person('admin'));

        foreach ([2147483647, $historical->Id] as $invalidRole) {
            $this->post(route('app.setup.users.bulk.update'), [
                'items' => [$first->Id, $second->Id], 'action' => 'add_roles',
                'roles' => [$managed->Id, $invalidRole],
            ])->assertSessionHasErrors('bulk');
            $this->assertSame([], app(RoleAccessService::class)->roleIdsFor($first));
            $this->assertSame([], app(RoleAccessService::class)->roleIdsFor($second));
        }
    }

    public function test_self_deactivation_refuses_and_rolls_back_the_entire_selection(): void
    {
        // The other user sorts first, so this proves a write before the
        // administrator's refusal is rolled back as well.
        $subject = $this->person();
        $actor = $this->person('admin');
        $this->assertLessThan($actor->Id, $subject->Id);
        $this->actingAs($actor)->post(route('app.setup.users.bulk.update'), [
            'items' => [$subject->Id, $actor->Id], 'action' => 'deactivate',
        ])->assertSessionHasErrors(['bulk' => 'You cannot deactivate your own account. Ask another administrator.']);
        $this->assertTrue($subject->fresh()->IsActive);
        $this->assertTrue($actor->fresh()->IsActive);
    }
}
