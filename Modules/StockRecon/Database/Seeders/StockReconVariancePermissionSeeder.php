<?php

namespace Modules\StockRecon\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Permission;
use Modules\Core\Services\PermissionService;

/**
 * Original vs amended variance (ZRP, 19 Oct 2026).
 *
 *   stockrecon.variance.view   Read every count line's variance as counted
 *                              beside its variance after balancing, over any
 *                              period
 *
 * Its own seeder: StockReconPermissionSeeder has run and is in the ledger, and a
 * seeder is a one-shot.
 *
 * WHO HOLDS IT ON DAY ONE. There are no roles (retired 22 Sep 2026), so a new
 * permission is held by nobody until it is granted to a person. It goes to
 * everyone holding stockrecon.runs.view: whoever may read a balancing run may
 * read what balancing did. Read-only, so there is nothing to hold back.
 *
 * Editable per person on their user screen. ONLY EVER ADDS: the insert is
 * guarded by NOT EXISTS, so a rerun after --forget puts back only grants
 * somebody removed by hand.
 */
class StockReconVariancePermissionSeeder extends Seeder
{
    /** After StockReconPermissionSeeder (27) and RetireRolesSeeder (49), whose grants this copies. */
    public int $seedOrder = 68;

    public function run(): void
    {
        $schema = config('agora.schema');
        $branchId = (int) config('agora.group_branch_id', 2);
        $connection = config('agora.connections.app');
        $from = 'stockrecon.runs.view';
        $code = Permission::code('stockrecon', 'variance', 'view');

        $permission = Permission::query()->withoutGlobalScopes()->updateOrCreate(
            ['BranchId' => $branchId, 'Code' => $code],
            [
                'Module' => 'stockrecon',
                'Resource' => 'variance',
                'Action' => 'view',
                'Name' => 'Read the original vs amended variance over any period',
                'SortOrder' => 1100,
                'UpdatedAt' => now(),
            ]
        );

        $source = Permission::query()->withoutGlobalScopes()->where('Code', $from)->value('Id');

        if ($source === null) {
            $this->command?->warn("  {$from} does not exist here, so {$code} was granted to nobody.");

            return;
        }

        $granted = DB::connection($connection)->affectingStatement("
            INSERT INTO [{$schema}].[UserPermission] ([BranchId], [UserId], [PermissionId], [CreatedAt])
            SELECT DISTINCT ?, up.[UserId], ?, SYSUTCDATETIME()
            FROM [{$schema}].[UserPermission] up
            WHERE up.[PermissionId] = ?
              AND NOT EXISTS (
                  SELECT 1 FROM [{$schema}].[UserPermission] x
                  WHERE x.[UserId] = up.[UserId] AND x.[PermissionId] = ?
              );
        ", [$branchId, $permission->Id, $source, $permission->Id]);

        $this->command?->info("  {$code} granted to {$granted} person(s) holding {$from}.");

        app(PermissionService::class)->forgetAll();
    }
}
