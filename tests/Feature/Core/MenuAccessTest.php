<?php

namespace Tests\Feature\Core;

use Illuminate\Support\Str;
use Modules\Core\Models\MenuItem;
use Modules\Core\Models\Role;
use Modules\Core\Models\RoleMenuItem;
use Modules\Core\Models\User;
use Modules\Core\Models\UserMenuItem;
use Modules\Core\Models\UserRole;
use Modules\Core\Services\MenuAccessService;
use Modules\Core\Services\MenuService;
use Modules\Core\Services\PermissionService;
use Tests\TestCase;

/**
 * Per-entry menu access (customer request, 22 Sep 2026).
 *
 * The customer asked for a tick per menu entry meaning view, and no tick
 * meaning no access. Two halves have to hold or the feature is a decoration:
 * the entry disappears from the mega panel, AND the URL behind it stops
 * answering. Both are tested here, along with the convention that makes the
 * first day survivable — nobody holds a tick, so an empty set is the whole
 * menu rather than an empty one.
 */
class MenuAccessTest extends TestCase
{
    private int $branchId;

    /** @var array<int, User> */
    private array $made = [];

    /** @var array<int, int> */
    private array $touchedRoles = [];

    /** @var array<int, int> */
    private array $madeItems = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->branchId = (int) config('agora.group_branch_id', 2);
    }

    protected function tearDown(): void
    {
        foreach ($this->made as $user) {
            UserMenuItem::query()->acrossBranches()->where('UserId', $user->Id)->delete();
            UserRole::query()->acrossBranches()->where('UserId', $user->Id)->delete();
            User::query()->acrossBranches()->where('Id', $user->Id)->forceDelete();
        }

        foreach ($this->touchedRoles as $roleId) {
            RoleMenuItem::query()->acrossBranches()->where('RoleId', $roleId)->delete();
        }

        foreach ($this->madeItems as $itemId) {
            UserMenuItem::query()->acrossBranches()->where('MenuItemId', $itemId)->delete();
            RoleMenuItem::query()->acrossBranches()->where('MenuItemId', $itemId)->delete();
            MenuItem::query()->acrossBranches()->where('Id', $itemId)->delete();
        }

        parent::tearDown();
    }

    private function person(string $roleCode): User
    {
        $role = Role::query()->acrossBranches()->where('Code', $roleCode)->firstOrFail();

        $user = User::query()->acrossBranches()->create([
            'BranchId' => $this->branchId,
            'EmailAddress' => 'TEST-menu-'.uniqid().'@agora.invalid',
            'UserName' => 'TEST-'.$roleCode,
            'RoleId' => $role->Id,
            'IsActive' => true,
            'IsLocked' => false,
            'PasswordHash' => 'TEST-only-'.Str::random(24),
        ]);

        UserRole::query()->acrossBranches()->create([
            'BranchId' => $this->branchId,
            'UserId' => $user->Id,
            'RoleId' => $role->Id,
            'IsPrimary' => true,
        ]);

        app(PermissionService::class)->forget($user);
        app(MenuAccessService::class)->forget($user);
        $this->made[] = $user;

        return $user;
    }

    private function item(string $path): MenuItem
    {
        return MenuItem::query()->acrossBranches()->where('Path', $path)->firstOrFail();
    }

    /**
     * Every label in one workspace's tree, flattened.
     *
     * @return array<int, string>
     */
    private function labels(User $user, string $workspace = 'ho'): array
    {
        $labels = [];

        $walk = function ($nodes) use (&$walk, &$labels): void {
            foreach ($nodes as $node) {
                $labels[] = $node->Label;
                $walk($node->childItems ?? collect());
            }
        };

        // A fresh service per call: it memoises a built tree for the life of
        // the request, which is right in a request and wrong in a test that
        // changes the grants and asks again.
        foreach ($this->freshMenus()->tree($workspace, $user) as $section) {
            $walk($section->items);
        }

        return $labels;
    }

    private function freshMenus(): MenuService
    {
        return new MenuService(app(MenuAccessService::class));
    }

    public function test_nobody_ticked_for_anything_sees_the_whole_menu(): void
    {
        $person = $this->person('admin');

        $labels = $this->labels($person);

        $this->assertContains('Users and access', $labels);
        $this->assertContains('Pump readings', $labels);
        $this->assertGreaterThan(50, count($labels), 'An unrestricted person should get the whole menu.');
    }

    public function test_one_tick_becomes_the_whole_of_what_they_see(): void
    {
        $person = $this->person('admin');
        $access = app(MenuAccessService::class);

        $access->setForUser($person, [(int) $this->item('people-assets/users')->Id]);

        $labels = $this->labels($person);

        $this->assertContains('Users and access', $labels);
        // The heading it hangs under survives — the set is closed upwards, or
        // the tick would hide itself by hiding its own column.
        $this->assertContains('People and assets', $labels);
        $this->assertNotContains('Pump readings', $labels);
    }

    public function test_a_heading_with_nothing_left_under_it_is_dropped(): void
    {
        $person = $this->person('admin');

        app(MenuAccessService::class)->setForUser($person, [(int) $this->item('people-assets/users')->Id]);

        $labels = $this->labels($person);

        // "Trading rules" is a sibling column of "People and assets" in Setup
        // and nothing under it is ticked, so it must not render as an empty
        // column with a heading and no links.
        $this->assertNotContains('Trading rules', $labels);
    }

    public function test_an_entry_naming_a_permission_they_do_not_hold_is_hidden_even_unrestricted(): void
    {
        /*
         * The second of the two things that can hide an entry, and it is NOT
         * the tick: an item naming a PermissionCode the person does not hold
         * is hidden although nobody is restricted, because the route behind it
         * already refuses them through `can:` and a link that 403s is worse
         * than no link.
         *
         * `recon.runs.execute` is the slug for it. The four menu entries that
         * name a PermissionCode today all name one every role holds, so none
         * of them can show a refusal; this one is granted to Finance and Admin
         * alone, deliberately and for a reason RolePermissionSeeder writes out
         * — committing a reconciliation stamps rows in the customer's estate.
         *
         * Not an invented slug either: a role's grants are stored as the
         * CONCRETE permissions its patterns matched when the seeder ran, so
         * even `*.*.*` does not cover a code no Permission row defines, and a
         * test written against an imaginary one proves the opposite of what it
         * looks like.
         */
        $item = MenuService::item('ho', 'setup', [
            'path' => 'people-assets/TEST-locked',
            'parent' => 'people-assets',
            'label' => 'TEST-locked entry',
            'permission' => 'recon.runs.execute',
            'sort' => 900,
        ]);
        $this->madeItems[] = (int) $item->Id;

        $this->assertContains('TEST-locked entry', $this->labels($this->person('admin')));
        $this->assertNotContains('TEST-locked entry', $this->labels($this->person('operations')));
    }

    public function test_a_role_tick_and_a_personal_tick_are_unioned(): void
    {
        $person = $this->person('operations');
        $role = Role::query()->acrossBranches()->where('Code', 'operations')->firstOrFail();
        $this->touchedRoles[] = (int) $role->Id;

        $access = app(MenuAccessService::class);
        $access->setForRole($role, [(int) $this->item('the-day/my-queue')->Id]);
        $access->setForUser($person, [(int) $this->item('decisions/waste')->Id]);

        $labels = $this->labels($person);

        $this->assertContains('My queue', $labels);
        $this->assertContains('Waste to approve', $labels);
        $this->assertNotContains('Overnight loads', $labels);
    }

    public function test_an_unticked_screen_is_refused_by_its_url_too(): void
    {
        $admin = $this->person('admin');

        // Ticked for one entry that is NOT the users screen. The service is
        // used directly on purpose: the screen itself refuses this save, and
        // that refusal is its own test below.
        app(MenuAccessService::class)->setForUser($admin, [(int) $this->item('the-day/my-queue')->Id]);

        $this->actingAs($admin)
            ->get(route('app.setup.users.index'))
            ->assertForbidden();
    }

    public function test_the_dashboard_stays_reachable_however_the_ticks_fall(): void
    {
        $admin = $this->person('admin');

        app(MenuAccessService::class)->setForUser($admin, [(int) $this->item('the-day/my-queue')->Id]);

        // A correct sign-in that ends in 403 with nowhere to go is a lockout,
        // not a restriction.
        $this->actingAs($admin)->get(route('app.dashboard'))->assertOk();
    }

    public function test_the_card_replaces_the_set_rather_than_adding_to_it(): void
    {
        $admin = $this->person('admin');
        $subject = $this->person('operations');

        $queue = (int) $this->item('the-day/my-queue')->Id;
        $waste = (int) $this->item('decisions/waste')->Id;

        $this->actingAs($admin)
            ->put(route('app.setup.users.menu.update', ['user' => $subject->Id]), ['menu' => [$queue, $waste]])
            ->assertRedirect(route('app.setup.users.edit', ['user' => $subject->Id]).'#menu');

        $this->actingAs($admin)
            ->put(route('app.setup.users.menu.update', ['user' => $subject->Id]), ['menu' => [$waste]])
            ->assertRedirect(route('app.setup.users.edit', ['user' => $subject->Id]).'#menu');

        $held = UserMenuItem::query()->acrossBranches()->where('UserId', $subject->Id)
            ->pluck('MenuItemId')->map(fn ($id) => (int) $id)->all();

        $this->assertSame([$waste], $held);
    }

    public function test_an_empty_save_is_a_real_answer_and_means_the_whole_menu(): void
    {
        $admin = $this->person('admin');
        $subject = $this->person('operations');

        app(MenuAccessService::class)->setForUser($subject, [(int) $this->item('the-day/my-queue')->Id]);

        $this->actingAs($admin)
            ->put(route('app.setup.users.menu.update', ['user' => $subject->Id]), ['menu' => []])
            ->assertRedirect(route('app.setup.users.edit', ['user' => $subject->Id]).'#menu');

        $this->assertSame([], app(MenuAccessService::class)->grantedItemIds($subject->fresh()));
        $this->assertContains('Overnight loads', $this->labels($subject));
    }

    public function test_an_administrator_cannot_tick_themselves_off_their_own_screen(): void
    {
        $admin = $this->person('admin');

        $this->actingAs($admin)
            ->put(route('app.setup.users.menu.update', ['user' => $admin->Id]), [
                'menu' => [(int) $this->item('the-day/my-queue')->Id],
            ])
            ->assertSessionHasErrors('menu');

        // And the refusal rolled the write back rather than leaving them
        // restricted with a message about it.
        $this->assertSame([], app(MenuAccessService::class)->grantedItemIds($admin->fresh()));
    }

    public function test_both_screens_render_the_ticks(): void
    {
        $admin = $this->person('admin');
        $subject = $this->person('operations');

        $this->actingAs($admin)
            ->get(route('app.setup.users.edit', ['user' => $subject->Id]))
            ->assertOk()
            ->assertSee('Menu access')
            ->assertSee('name="menu[]"', false);

        $this->actingAs($admin)
            ->get(route('app.setup.roles.index'))
            ->assertOk()
            ->assertSee('Menu access')
            ->assertSee('Save menu access');

        $this->actingAs($admin)
            ->get(route('app.setup.users.show', ['user' => $subject->Id]))
            ->assertOk()
            ->assertSee('Menu access');
    }

    public function test_the_role_matrix_saves_and_refuses_the_same_lockout(): void
    {
        $admin = $this->person('admin');
        $operations = Role::query()->acrossBranches()->where('Code', 'operations')->firstOrFail();
        $adminRole = Role::query()->acrossBranches()->where('Code', 'admin')->firstOrFail();
        $this->touchedRoles[] = (int) $operations->Id;
        $this->touchedRoles[] = (int) $adminRole->Id;

        $queue = (int) $this->item('the-day/my-queue')->Id;

        $this->actingAs($admin)
            ->put(route('app.setup.roles.menu.update'), ['menu' => [(int) $operations->Id => [$queue]]])
            ->assertRedirect(route('app.setup.roles.index').'#menu');

        $this->assertSame([$queue], app(MenuAccessService::class)->roleItemIds($operations));

        // Ticking the admin's own role for something that is not the users
        // screen would take the last administrator off it.
        $this->actingAs($admin)
            ->put(route('app.setup.roles.menu.update'), ['menu' => [(int) $adminRole->Id => [$queue]]])
            ->assertSessionHasErrors('menu');

        $this->assertSame([], app(MenuAccessService::class)->roleItemIds($adminRole));
    }
}
