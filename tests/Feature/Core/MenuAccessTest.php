<?php

namespace Tests\Feature\Core;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Models\MenuItem;
use Modules\Core\Models\Permission;
use Modules\Core\Models\User;
use Modules\Core\Models\UserPermission;
use Modules\Core\Services\MenuAccessService;
use Modules\Core\Services\PermissionService;
use Tests\TestCase;

class MenuAccessTest extends TestCase
{
    private bool $started = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->skipUnlessLocalStub();
        DB::connection(config('agora.connections.app'))->beginTransaction();
        $this->started = true;
    }

    protected function tearDown(): void
    {
        if ($this->started) {
            DB::connection(config('agora.connections.app'))->rollBack();
        }
        parent::tearDown();
    }

    public function test_direct_permission_controls_menu_and_ignores_legacy_ticks(): void
    {
        $person = User::query()->acrossBranches()->create([
            'BranchId' => config('agora.group_branch_id'), 'UserName' => 'TEST-menu',
            'EmailAddress' => 'TEST-menu-'.uniqid().'@agora.invalid', 'IsActive' => true,
            'IsLocked' => false, 'Workspace' => 'ho', 'PasswordHash' => 'TEST-'.Str::random(24),
        ]);
        $id = Permission::query()->acrossBranches()->where('Code', 'stockrecon.runs.view')->value('Id');
        $this->assertNotNull($id);
        UserPermission::query()->acrossBranches()->create([
            'BranchId' => config('agora.group_branch_id'), 'UserId' => $person->Id, 'PermissionId' => $id,
        ]);
        app(PermissionService::class)->forget($person);
        $stock = MenuItem::query()->acrossBranches()->where('RouteName', 'app.stockrecon.index')->firstOrFail();
        $users = MenuItem::query()->acrossBranches()->where('RouteName', 'app.setup.users.index')->firstOrFail();
        $access = app(MenuAccessService::class);
        $access->setForUser($person, [(int) $users->Id]);
        $this->assertTrue($access->mayView($person, $stock));
        $this->assertFalse($access->mayView($person, $users));
        $this->actingAs($person)->get(route('app.setup.users.index'))->assertForbidden();
        $this->assertTrue($access->canReachRoute($person, 'app.stockrecon.index'));
    }

    public function test_unmapped_menu_entry_is_hidden_even_when_user_has_other_permissions(): void
    {
        $person = User::query()->acrossBranches()->create([
            'BranchId' => config('agora.group_branch_id'), 'UserName' => 'TEST-menu',
            'EmailAddress' => 'TEST-menu-'.uniqid().'@agora.invalid', 'IsActive' => true,
            'IsLocked' => false, 'Workspace' => 'ho', 'PasswordHash' => 'TEST-'.Str::random(24),
        ]);
        $permission = Permission::query()->acrossBranches()->where('Code', 'stockrecon.runs.view')->firstOrFail();
        UserPermission::query()->acrossBranches()->create([
            'BranchId' => config('agora.group_branch_id'), 'UserId' => $person->Id, 'PermissionId' => $permission->Id,
        ]);
        app(PermissionService::class)->forget($person);
        $item = new MenuItem(['Label' => 'Unmapped', 'RouteName' => null, 'Url' => null]);
        $this->assertFalse(app(MenuAccessService::class)->mayView($person, $item));
    }
}
