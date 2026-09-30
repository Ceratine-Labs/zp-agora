<?php

namespace Modules\Cash\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Permission;
use Modules\Core\Services\PermissionService;

/**
 * Daily banking's four permissions (T037), and a first set of holders.
 *
 *   cash.cashup.view     Daily Banking, Cashups, Z-reads with no cashup, the
 *                        phone list
 *   cash.cashup.save     Save card amounts, Add from PumpIT — writes PumpIT (the
 *                        header, the leg, the pool row's banked mark) while the
 *                        write switch is live
 *   cash.cashup.confirm  agree to a cashup's figures (Agora only)
 *   cash.cashup.close    a manager's sign-off, and reopening a closed cashup
 *                        with a reason (Agora only)
 *
 * Save, confirm and close are separate grants because capturing, agreeing and
 * signing off are three people's acts (feature-rules §4). There is no delete:
 * a cashup is a transactional entry.
 *
 * WHO HOLDS THEM ON DAY ONE. There are no roles (retired 22 Sep 2026), so a
 * new permission is held by nobody until it is granted to a person. The grant
 * copies an existing one, exactly as ZreadPermissionSeeder does:
 *
 *   view     to everyone who can see a stock recon run
 *            (stockrecon.runs.view — the operational readers)
 *   save     to everyone who can run a stock recon preview
 *   confirm  (stockrecon.runs.create — branch managers, operations, finance,
 *            admin as the roles left them)
 *   close    to everyone who can reverse a stock recon commit
 *            (stockrecon.runs.reverse — finance and admin): the manager's
 *            view is a head-office screen, and the sign-off belongs there
 *
 * Each person's grants are editable on their user screen.
 *
 * ONLY EVER ADDS: every insert is guarded by NOT EXISTS, so a rerun after
 * --forget puts back only grants somebody removed by hand.
 */
class CashupPermissionSeeder extends Seeder
{
    /** After ZreadPermissionSeeder (61). */
    public int $seedOrder = 62;

    /** @var array<int, array{0: string, 1: string, 2: string}> action, name, copied from */
    private const CATALOGUE = [
        ['view', 'See the daily banking: cashups, their legs and variances, and the Z-reads with no cashup', 'stockrecon.runs.view'],
        ['save', 'Save a cashup\'s legs — writes them, the header and the pool\'s banked mark to PumpIT', 'stockrecon.runs.create'],
        ['confirm', 'Confirm a cashup\'s figures', 'stockrecon.runs.create'],
        ['close', 'Close a confirmed cashup (the manager\'s sign-off), and reopen a closed one', 'stockrecon.runs.reverse'],
    ];

    public function run(): void
    {
        $schema = config('agora.schema');
        $branchId = (int) config('agora.group_branch_id', 2);
        $connection = config('agora.connections.app');
        $sort = 1250;

        foreach (self::CATALOGUE as [$action, $name, $from]) {
            $permission = Permission::query()->withoutGlobalScopes()->updateOrCreate(
                ['BranchId' => $branchId, 'Code' => Permission::code('cash', 'cashup', $action)],
                [
                    'Module' => 'cash',
                    'Resource' => 'cashup',
                    'Action' => $action,
                    'Name' => $name,
                    'SortOrder' => $sort += 10,
                    'UpdatedAt' => now(),
                ]
            );

            $source = Permission::query()->withoutGlobalScopes()->where('Code', $from)->value('Id');

            if ($source === null) {
                $this->command?->warn("  {$from} does not exist here, so cash.cashup.{$action} was granted to nobody.");

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

            $this->command?->info("  cash.cashup.{$action} granted to {$granted} person(s) holding {$from}.");
        }

        app(PermissionService::class)->forgetAll();
    }
}
