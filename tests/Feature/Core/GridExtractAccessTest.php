<?php

namespace Tests\Feature\Core;

use App\Grid\GridRegistry;
use App\Support\BranchContext;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Modules\Core\Models\User;
use Modules\Core\Services\PermissionService;
use Tests\TestCase;

/**
 * A grid's extract and saved layout need the access its screen needs
 * (Core's AuthorizeAccessGrid). Until 12 Nov 2026 only the users, roles and
 * stock recon grids were checked, so anybody signed in could download the
 * branch master — or most other grids — by typing its key.
 *
 * Writes TEST- users with their grants, removed in tearDown. Reads the local
 * container only.
 */
class GridExtractAccessTest extends TestCase
{
    /** @var array<int, User> */
    private array $users = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->skipUnlessLocalStub();
    }

    protected function tearDown(): void
    {
        foreach ($this->users as $user) {
            foreach (['UserPermission', 'UserBranch', 'UserGridColumn', 'UserMenuItem'] as $table) {
                $this->db()->table('agora.'.$table)->where('UserId', $user->Id)->delete();
            }
            User::query()->acrossBranches()->where('Id', $user->Id)->forceDelete();
        }
        parent::tearDown();
    }

    public function test_the_branch_extract_needs_the_branch_view_permission(): void
    {
        $extract = route('app.grids.extract', ['grid' => 'app.setup.branches']);

        // Nobody with no access at all gets past EnforceMenuAccess; somebody
        // with other access is the case that was open.
        $this->actingAs($this->person([]))->get($extract)->assertRedirect(route('app.access-required'));
        $this->actingAs($this->person(['setup.settings.view']))->get($extract)->assertForbidden();
        $this->actingAs($this->person(['setup.settings.view']))
            ->postJson(route('app.grids.columns.store', ['grid' => 'app.setup.branches']), ['text_size' => 'large'])
            ->assertForbidden();

        $csv = $this->actingAs($this->person(['masters.branches.view']))->get($extract)->assertOk()->streamedContent();
        $this->assertStringContainsString('Total Hluhluwe', $csv, 'a viewer still gets the CSV');
    }

    public function test_the_settings_extract_needs_the_settings_view_permission(): void
    {
        $extract = route('app.grids.extract', ['grid' => 'app.setup.settings']);

        $this->actingAs($this->person(['masters.branches.view']))->get($extract)->assertForbidden();
        $csv = $this->actingAs($this->person(['setup.settings.view']))->get($extract)->assertOk()->streamedContent();
        $this->assertStringContainsString('cash.dropsafe.reason_threshold', $csv);
    }

    /** Every registered grid answers to a screen route, so none is left open or shut by accident. */
    public function test_every_registered_grid_has_a_screen_route(): void
    {
        $registry = app(GridRegistry::class);
        foreach (array_keys($registry->all()) as $key) {
            $screen = $registry->findOrFail($key)->screenRoute();
            $this->assertNotNull($screen, "{$key} names no screen route, so its extract is refused");
            $this->assertTrue(Route::has($screen), "{$key} → {$screen}");
        }
    }

    /** @param  array<int, string>  $codes  permission codes */
    private function person(array $codes): User
    {
        $user = User::query()->acrossBranches()->create([
            'BranchId' => 2, 'EmailAddress' => 'TEST-grid-access-'.Str::uuid().'@agora.invalid', 'UserName' => 'TEST-Grid access',
            'IsActive' => true, 'IsLocked' => false, 'Workspace' => 'ho', 'PasswordHash' => 'TEST-only-'.Str::random(24),
        ]);
        $this->users[] = $user;
        foreach ($codes as $code) {
            $permission = $this->db()->table('agora.Permission')->where('Code', $code)->value('Id');
            $this->assertNotNull($permission, "{$code} is seeded");
            $this->db()->table('agora.UserPermission')->insert(['BranchId' => 2, 'UserId' => $user->Id, 'PermissionId' => $permission]);
        }
        app(PermissionService::class)->forget($user);
        app(BranchContext::class)->setWorkspace('ho')->setAllowed([]);

        return $user;
    }

    private function db(): Connection
    {
        return DB::connection(config('agora.connections.app'));
    }
}
