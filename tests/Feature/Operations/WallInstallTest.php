<?php

namespace Tests\Feature\Operations;

use Illuminate\Support\Facades\DB;
use Modules\Operations\Database\Seeders\OperationsWallSeeder;
use Tests\TestCase;

class WallInstallTest extends TestCase
{
    public function test_install_registers_the_wall_without_assigning_access_to_any_user(): void
    {
        $this->skipUnlessLocalStub();
        $db = DB::connection();
        $permissions = $db->table('agora.UserPermission')->count();
        $menus = $db->table('agora.UserMenuItem')->count();
        $db->beginTransaction();
        try {
            app(OperationsWallSeeder::class)->run();
            $this->assertSame($permissions, $db->table('agora.UserPermission')->count());
            $this->assertSame($menus, $db->table('agora.UserMenuItem')->count());
            $this->assertSame(1, $db->table('agora.Permission')->where('Code', 'operations.wall.view')->count());
            $this->assertSame(2, $db->table('agora.MenuItem')->where('RouteName', 'app.operations.wall')->count());
        } finally {
            $db->rollBack();
        }
    }
}
