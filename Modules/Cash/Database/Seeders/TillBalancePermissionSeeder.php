<?php

namespace Modules\Cash\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Permission;
use Modules\Core\Services\PermissionService;

/**
 * Till balance and pump shortages' three permissions (T038), and a first set
 * of holders.
 *
 *   cash.tillbalance.view     the Till balance screen
 *   cash.tillbalance.save     Capture — writes PumpIT (the BRN_TillBalance pair
 *                             and the SS_UniqueNumber section 3 counter) while
 *                             the write switch is live
 *   cash.pumpshortages.view   the Pump shortages grid
 *
 * No edit and no delete: a till balance is a transactional entry, declined in
 * PumpIT and captured again when it is wrong (feature-rules §4).
 *
 * WHO HOLDS THEM ON DAY ONE. There are no roles (retired 22 Sep 2026), so a
 * new permission is held by nobody until it is granted to a person. The grant
 * copies the daily banking ones, because a till balance is captured by whoever
 * cashes up and read by whoever reads cashups:
 *
 *   cash.tillbalance.view    to everyone holding cash.cashup.view
 *   cash.tillbalance.save    to everyone holding cash.cashup.save
 *   cash.pumpshortages.view  to everyone holding cash.cashup.view
 *
 * Each person's grants are editable on their user screen.
 *
 * ONLY EVER ADDS: every insert is guarded by NOT EXISTS, so a rerun after
 * --forget puts back only grants somebody removed by hand.
 */
class TillBalancePermissionSeeder extends Seeder
{
    /** After CashupPermissionSeeder (62), whose grants these copy. */
    public int $seedOrder = 63;

    /** @var array<int, array{0: string, 1: string, 2: string, 3: string}> resource, action, name, copied from */
    private const CATALOGUE = [
        ['tillbalance', 'view', 'See till balances and the cashups they move money between', 'cash.cashup.view'],
        ['tillbalance', 'save', 'Capture a till balance — writes it to PumpIT for approval there', 'cash.cashup.save'],
        ['pumpshortages', 'view', 'See pump shortages by attendant and shift', 'cash.cashup.view'],
    ];

    public function run(): void
    {
        $schema = config('agora.schema');
        $branchId = (int) config('agora.group_branch_id', 2);
        $connection = config('agora.connections.app');
        $sort = 1300;

        foreach (self::CATALOGUE as [$resource, $action, $name, $from]) {
            $code = Permission::code('cash', $resource, $action);

            $permission = Permission::query()->withoutGlobalScopes()->updateOrCreate(
                ['BranchId' => $branchId, 'Code' => $code],
                [
                    'Module' => 'cash',
                    'Resource' => $resource,
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
