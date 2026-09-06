<?php

namespace Tests\Feature\Core;

use Modules\Core\Models\Role;
use Modules\Core\Models\User;
use Modules\Core\Models\UserRole;
use Modules\Core\Services\PermissionService;
use Tests\TestCase;

/**
 * Setup → Users and access (T028).
 *
 * The screens exist to answer two questions — who holds what, and what that
 * lets them do — so the tests are about the permission boundary rather than
 * about markup. The grid itself is Lane C's and already tested.
 */
class UserAdminScreenTest extends TestCase
{
    private int $branchId;

    /** @var array<int, User> */
    private array $made = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->branchId = (int) config('agora.group_branch_id', 2);
    }

    protected function tearDown(): void
    {
        foreach ($this->made as $user) {
            UserRole::query()->acrossBranches()->where('UserId', $user->Id)->delete();
            User::query()->acrossBranches()->where('Id', $user->Id)->delete();
        }

        parent::tearDown();
    }

    private function person(string $roleCode): User
    {
        $role = Role::query()->acrossBranches()->where('Code', $roleCode)->firstOrFail();

        $user = User::query()->acrossBranches()->create([
            'BranchId' => $this->branchId,
            'EmailAddress' => 'TEST-t028-'.uniqid().'@agora.invalid',
            'UserName' => 'TEST-'.$roleCode,
            'RoleId' => $role->Id,
            'IsActive' => true,
            'IsLocked' => false,
            'PasswordHash' => User::UNUSABLE_PASSWORD,
        ]);

        UserRole::query()->acrossBranches()->create([
            'BranchId' => $this->branchId,
            'UserId' => $user->Id,
            'RoleId' => $role->Id,
            'IsPrimary' => true,
        ]);

        app(PermissionService::class)->forget($user);
        $this->made[] = $user;

        return $user;
    }

    public function test_an_admin_sees_the_user_list(): void
    {
        $this->actingAs($this->person('admin'))
            ->get(route('app.setup.users.index'))
            ->assertOk()
            ->assertSee('Users and access');
    }

    public function test_a_branch_manager_is_refused_the_user_list(): void
    {
        $this->actingAs($this->person('branch-manager'))
            ->get(route('app.setup.users.index'))
            ->assertForbidden();
    }

    public function test_the_auditor_may_look_but_not_save(): void
    {
        $auditor = $this->person('auditor');
        $subject = $this->person('operations');

        $this->actingAs($auditor)
            ->get(route('app.setup.users.show', ['user' => $subject->Id]))
            ->assertOk();

        // *.*.view covers setup.users.view but never setup.users.edit.
        $this->actingAs($auditor)
            ->put(route('app.setup.users.update', ['user' => $subject->Id]), ['roles' => []])
            ->assertForbidden();
    }

    public function test_saving_roles_replaces_the_set_rather_than_adding_to_it(): void
    {
        $admin = $this->person('admin');
        $subject = $this->person('operations');

        $finance = Role::query()->acrossBranches()->where('Code', 'finance')->firstOrFail();

        $this->actingAs($admin)
            ->put(route('app.setup.users.update', ['user' => $subject->Id]), [
                'roles' => [$finance->Id],
                'primary' => $finance->Id,
            ])
            ->assertRedirect(route('app.setup.users.show', ['user' => $subject->Id]));

        $held = UserRole::query()->acrossBranches()->where('UserId', $subject->Id)->get();

        $this->assertCount(1, $held, 'The old Operations grant must be gone, not kept alongside.');
        $this->assertSame($finance->Id, (int) $held->first()->RoleId);
        $this->assertTrue((bool) $held->first()->IsPrimary);

        // RoleId is the denormalised pointer the landing route reads; it must
        // follow the primary grant or the two disagree silently.
        $this->assertSame($finance->Id, (int) $subject->fresh()->RoleId);

        // And the change must be visible immediately, not after a cache TTL.
        $this->assertTrue(app(PermissionService::class)->userHas($subject->fresh(), 'recon.runs.execute'));
    }

    public function test_the_role_matrix_renders_for_someone_who_may_see_it(): void
    {
        $this->actingAs($this->person('admin'))
            ->get(route('app.setup.roles.index'))
            ->assertOk()
            ->assertSee('recon.runs.execute');
    }
}
