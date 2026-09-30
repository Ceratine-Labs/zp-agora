<?php

namespace Modules\Cash\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Permission;
use Modules\Core\Services\PermissionService;

/**
 * Z-read allocation's three permissions (T036), and a first set of holders.
 *
 *   cash.zread.view      the screen, a row's panel, the phone's list
 *   cash.zread.allocate  stage: Auto-allocate, accept, set by hand, clear
 *   cash.zread.commit    save: Update ZREAD, and the phone's Confirm — which,
 *                        in 'live' stamp mode, writes three columns to PumpIT
 *   cash.zread.reverse   undo one Update ZREAD press, PumpIT included
 *
 * Staging and saving are separate grants because proposing and deciding are
 * separate acts (feature-rules §4). There is no delete: an allocation is a
 * transactional entry.
 *
 * WHO HOLDS THEM ON DAY ONE. There are no roles (retired 22 Sep 2026), so a
 * new permission is held by nobody until it is granted to a person — and
 * granted it has to be, or the screen ships behind a 403 for everyone. The
 * grant copies an existing one, exactly as ReconRunClosePermissionSeeder does:
 *
 *   view      to everyone who can see a stock recon run
 *             (stockrecon.runs.view — the operational readers)
 *   allocate  to everyone who can run a stock recon preview
 *   commit    (stockrecon.runs.create — branch managers, operations,
 *             finance and admin as the roles left them)
 *
 *   reverse   to everyone who can reverse a stock recon commit
 *             (stockrecon.runs.reverse — admin and finance)
 *
 * Allocate and commit go together because the legacy ZREAD Update screen is
 * one person's job: whoever allocates presses Update (Ryan kept that default,
 * 27 Sep 2026). Reverse is narrower because it rewrites what PumpIT holds for
 * a whole press at once — the same line the stock recon reversal draws. Each
 * person's grants are editable on their user screen.
 *
 * ONLY EVER ADDS: every insert is guarded by NOT EXISTS, so a rerun after
 * --forget puts back only grants somebody removed by hand.
 */
class ZreadPermissionSeeder extends Seeder
{
    /** After RetireRolesSeeder (49), which put the stock recon grants on people. */
    public int $seedOrder = 61;

    /** @var array<int, array{0: string, 1: string, 2: string}> action, name, copied from */
    private const CATALOGUE = [
        ['view', 'See the Z-reads and what the system proposes for them', 'stockrecon.runs.view'],
        ['allocate', 'Stage Z-read allocations — auto-allocate, accept, set by hand, clear', 'stockrecon.runs.create'],
        ['commit', 'Save staged Z-read allocations (Update ZREAD) — also writes till, shift and person to PumpIT while the stamp is live', 'stockrecon.runs.create'],
        ['reverse', 'Reverse an Update ZREAD press — puts PumpIT\'s prior till, shift and person back', 'stockrecon.runs.reverse'],
    ];

    public function run(): void
    {
        $schema = config('agora.schema');
        $branchId = (int) config('agora.group_branch_id', 2);
        $connection = config('agora.connections.app');
        $sort = 1200;

        foreach (self::CATALOGUE as [$action, $name, $from]) {
            $permission = Permission::query()->withoutGlobalScopes()->updateOrCreate(
                ['BranchId' => $branchId, 'Code' => Permission::code('cash', 'zread', $action)],
                [
                    'Module' => 'cash',
                    'Resource' => 'zread',
                    'Action' => $action,
                    'Name' => $name,
                    'SortOrder' => $sort += 10,
                    'UpdatedAt' => now(),
                ]
            );

            $source = Permission::query()->withoutGlobalScopes()->where('Code', $from)->value('Id');

            if ($source === null) {
                $this->command?->warn("  {$from} does not exist here, so cash.zread.{$action} was granted to nobody.");

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

            $this->command?->info("  cash.zread.{$action} granted to {$granted} person(s) holding {$from}.");
        }

        // Anyone signed in while this ran holds a cached grant set that does
        // not know about the new permissions.
        app(PermissionService::class)->forgetAll();
    }
}
