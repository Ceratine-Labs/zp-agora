<?php

namespace Tests\Feature\StockRecon;

use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Permission;
use Modules\StockRecon\Database\Seeders\StockReconGuidePermissionSeeder;
use Tests\TestCase;

class GuidePermissionTest extends TestCase
{
    public function test_registration_is_idempotent_and_creates_no_grants_or_menu_rules(): void
    {
        $this->skipUnlessLocalStub('The guide permission seeder is tested inside a rolled-back local transaction.');
        config(['agora.group_branch_id' => 999]);
        $db = DB::connection(config('agora.connections.app'));
        $db->beginTransaction();
        try {
            $tables = ['RolePermission', 'UserPermission', 'MenuItem'];
            $before = array_map(fn ($table) => $db->table('agora.'.$table)->count(), $tables);
            $query = Permission::query()->acrossBranches()->where('BranchId', 999)->where('Code', 'stockrecon.guide.view');
            $seeder = new StockReconGuidePermissionSeeder;
            $seeder->run();
            $first = $query->firstOrFail();
            $seeder->run();

            $this->assertSame(1, $query->count());
            $this->assertSame($first->Id, $query->firstOrFail()->Id);
            $this->assertSame('guide', $first->Resource);
            $this->assertSame('view', $first->Action);
            $this->assertSame($before, array_map(fn ($table) => $db->table('agora.'.$table)->count(), $tables));
        } finally {
            $db->rollBack();
        }
    }
}
