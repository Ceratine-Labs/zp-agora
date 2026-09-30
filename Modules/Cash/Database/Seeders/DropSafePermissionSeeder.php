<?php

namespace Modules\Cash\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Permission;
use Modules\Core\Services\PermissionService;

/**
 * The drop safe's five permissions (T039), and a first set of holders.
 *
 *   cash.dropsafe.view     Drop safe, its phone screen and the Drop safe bags
 *                          grid
 *   cash.dropsafe.save     Drop a bag — writes a BRN_DropSafe row to PumpIT
 *                          while the write switch is live
 *   cash.dropsafe.edit     Correct a bag still in the safe and on no cashup —
 *                          writes that row
 *   cash.dropsafe.void     Void a bag captured in error — DELETES its
 *                          BRN_DropSafe row (Ryan's named go-ahead, 27 Sep 2026),
 *                          the whole row kept in agora.DropSafeEvent first
 *   cash.dropsafe.collect  Collect bags for the security company — writes a
 *                          BRN_DropSafe_Collection row and each bag's
 *                          CollectionId
 *
 * Save, edit, void and collect are separate grants (feature-rules §4): the
 * cashier who drops a bag, the manager who corrects or voids one and the person
 * who hands the safe over are not the same act.
 *
 * WHO HOLDS THEM ON DAY ONE. There are no roles (retired 22 Sep 2026), so a
 * new permission is held by nobody until it is granted to a person. The grant
 * copies the daily banking ones, because a bag is dropped and collected by the
 * people who cash up, and corrected by the one who signs a cashup off:
 *
 *   cash.dropsafe.view     to everyone holding cash.cashup.view
 *   cash.dropsafe.save     to everyone holding cash.cashup.save
 *   cash.dropsafe.edit     to everyone holding cash.cashup.close
 *   cash.dropsafe.void     to everyone holding cash.cashup.close
 *   cash.dropsafe.collect  to everyone holding cash.cashup.save
 *
 * Each person's grants are editable on their user screen.
 *
 * ONLY EVER ADDS: every insert is guarded by NOT EXISTS, so a rerun after
 * --forget puts back only grants somebody removed by hand.
 */
class DropSafePermissionSeeder extends Seeder
{
    /** After TillBalancePermissionSeeder (63), and CashupPermissionSeeder (62), whose grants these copy. */
    public int $seedOrder = 65;

    /** @var array<int, array{0: string, 1: string, 2: string}> action, name, copied from */
    private const CATALOGUE = [
        ['view', 'See the drop safe: the bags in it, the collections, and every bag across sites', 'cash.cashup.view'],
        ['save', 'Drop a bag into the safe — writes it to PumpIT', 'cash.cashup.save'],
        ['edit', 'Correct a bag still in the safe and on no cashup — writes the change to PumpIT', 'cash.cashup.close'],
        ['void', 'Void a bag captured in error — deletes it from PumpIT, kept whole in Agora', 'cash.cashup.close'],
        ['collect', 'Hand bags to the security company — writes the collection to PumpIT', 'cash.cashup.save'],
    ];

    public function run(): void
    {
        $schema = config('agora.schema');
        $branchId = (int) config('agora.group_branch_id', 2);
        $connection = config('agora.connections.app');
        $sort = 1400;

        foreach (self::CATALOGUE as [$action, $name, $from]) {
            $code = Permission::code('cash', 'dropsafe', $action);

            $permission = Permission::query()->withoutGlobalScopes()->updateOrCreate(
                ['BranchId' => $branchId, 'Code' => $code],
                [
                    'Module' => 'cash',
                    'Resource' => 'dropsafe',
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
