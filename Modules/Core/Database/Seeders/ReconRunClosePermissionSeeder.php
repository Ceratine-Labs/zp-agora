<?php

namespace Modules\Core\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Permission;
use Modules\Core\Services\PermissionService;

/**
 * `recon.runs.close` — marking a reconciliation run complete, and reopening it
 * (Ryan, 23 Sep 2026, Bank Recon Close-out report).
 *
 * Its own permission because feature-rules §4 gives every action on a
 * resource its own grant, and closing is not discarding: it keeps the record
 * that discarding destroys. Its own seeder because PermissionSeeder has
 * already run and the ledger will not run it again.
 *
 * GRANTED TO EVERYONE WHO CAN ALREADY DISCARD A RUN, and to nobody else. The
 * rule it serves is Ryan's own, 9 Sep 2026: "if anything is processed against
 * a run we can't close it, else the ladies can remove it". The people who may
 * remove a run are exactly the people who may set it aside, and closing is the
 * gentler of the two. Anybody granted a wildcard that covers it already holds
 * it; the grant below is for the people who hold `recon.runs.delete` by name.
 *
 * IT ONLY EVER ADDS, like RetireRolesSeeder: guarded by NOT EXISTS, so a rerun
 * after `--forget` changes nothing, and a grant somebody removed by hand is
 * the only thing a rerun could put back.
 */
class ReconRunClosePermissionSeeder extends Seeder
{
    /** After RetireRolesSeeder (49), which put recon.runs.delete on the people who hold it. */
    public int $seedOrder = 60;

    public function run(): void
    {
        $schema = config('agora.schema');
        $branchId = (int) config('agora.group_branch_id', 2);
        $connection = config('agora.connections.app');

        $close = Permission::query()->withoutGlobalScopes()->updateOrCreate(
            ['BranchId' => $branchId, 'Code' => Permission::code('recon', 'runs', 'close')],
            [
                'Module' => 'recon',
                'Resource' => 'runs',
                'Action' => 'close',
                'Name' => 'Mark a reconciliation run complete, or reopen one — nothing in PumpIT moves',
                'SortOrder' => 999,
                'UpdatedAt' => now(),
            ]
        );

        $delete = Permission::query()->withoutGlobalScopes()
            ->where('Code', Permission::code('recon', 'runs', 'delete'))
            ->value('Id');

        if ($delete === null) {
            $this->command?->warn('recon.runs.delete does not exist here, so recon.runs.close was granted to nobody.');

            return;
        }

        $granted = DB::connection($connection)->affectingStatement("
            INSERT INTO [{$schema}].[UserPermission] ([BranchId], [UserId], [PermissionId], [CreatedAt])
            SELECT DISTINCT ?, up.[UserId], ?, SYSDATETIME()
            FROM [{$schema}].[UserPermission] up
            WHERE up.[PermissionId] = ?
              AND NOT EXISTS (
                  SELECT 1 FROM [{$schema}].[UserPermission] x
                  WHERE x.[UserId] = up.[UserId] AND x.[PermissionId] = ?
              );
        ", [$branchId, $close->Id, $delete, $close->Id]);

        // Anyone signed in while this ran is holding a cached grant set that
        // does not know about the new permission.
        app(PermissionService::class)->forgetAll();

        $this->command?->info("  recon.runs.close granted to {$granted} person(s) who can discard a run.");
    }
}
