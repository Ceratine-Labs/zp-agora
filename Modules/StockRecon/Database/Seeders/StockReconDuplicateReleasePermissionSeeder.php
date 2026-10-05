<?php

namespace Modules\StockRecon\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Permission;
use Modules\Core\Services\PermissionService;

/**
 * Releasing a possible duplicate issue (D2) from holding its chain back, and
 * withdrawing a release (Ryan, 5 Oct 2026, PM decision 01a10b21).
 *
 *   stockrecon.duplicates.release   Release a D2 with a reason, so the next
 *                                   preview lets its chain commit — the chain
 *                                   is then balanced, and a short somebody hid
 *                                   by moving counts can go back on the shift
 *   stockrecon.duplicates.withdraw  Withdraw a release, so the D2 holds its
 *                                   chain back again
 *
 * Its own seeder: StockReconPermissionSeeder has run and is in the ledger, and a
 * seeder is a one-shot.
 *
 * WHO HOLDS THEM ON DAY ONE. There are no roles (retired 22 Sep 2026), so a new
 * permission is held by nobody until it is granted to a person:
 *
 *   release   to everyone holding stockrecon.runs.execute. Releasing a D2 is
 *             what lets its chain be committed, so it belongs to the people
 *             who may commit — and not to the branch whose short it is.
 *   withdraw  to everyone holding stockrecon.duplicates.release (granted after
 *             it, in the same run): whoever may let a chain through may take
 *             that back.
 *
 * Each person's grants are editable on their user screen. ONLY EVER ADDS:
 * every insert is guarded by NOT EXISTS, so a rerun after --forget puts back
 * only grants somebody removed by hand.
 */
class StockReconDuplicateReleasePermissionSeeder extends Seeder
{
    /** After StockReconPermissionSeeder (27) and RetireRolesSeeder (49), whose grants these copy. */
    public int $seedOrder = 67;

    /** @var array<int, array{0: string, 1: string, 2: string}> action, name, copied from */
    private const CATALOGUE = [
        ['release', 'Release a possible duplicate issue (D2) with a reason, so its chain may commit', 'stockrecon.runs.execute'],
        ['withdraw', 'Withdraw a release, so the possible duplicate holds its chain back again', 'stockrecon.duplicates.release'],
    ];

    public function run(): void
    {
        $schema = config('agora.schema');
        $branchId = (int) config('agora.group_branch_id', 2);
        $connection = config('agora.connections.app');
        $sort = 1070;

        foreach (self::CATALOGUE as [$action, $name, $from]) {
            $code = Permission::code('stockrecon', 'duplicates', $action);

            $permission = Permission::query()->withoutGlobalScopes()->updateOrCreate(
                ['BranchId' => $branchId, 'Code' => $code],
                [
                    'Module' => 'stockrecon',
                    'Resource' => 'duplicates',
                    'Action' => $action,
                    'Name' => $name,
                    'SortOrder' => $sort += 10,
                    'UpdatedAt' => now(),
                ]
            );

            $source = Permission::query()->withoutGlobalScopes()->where('Code', $from)->value('Id');

            if ($source === null) {
                $this->command?->warn("  {$from} does not exist here, so {$code} was granted to nobody.");

                continue;
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
            ", [$branchId, $permission->Id, $source, $permission->Id]);

            $this->command?->info("  {$code} granted to {$granted} person(s) holding {$from}.");
        }

        app(PermissionService::class)->forgetAll();
    }
}
