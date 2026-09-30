<?php

namespace Modules\Cash\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Permission;
use Modules\Core\Services\PermissionService;
use Throwable;

/**
 * The staff shorts' four permissions (T040), and a first set of holders.
 *
 *   cash.staffshort.view     Staff shorts, its phone screen and the list
 *   cash.staffshort.raise    Raise a short — writes a BRN_StaffShorts row (and
 *                            the section 2 counter) to PumpIT while the write
 *                            switch is live
 *   cash.staffshort.approve  Approve, or decline with a reason — writes that
 *                            row's approval or decline columns
 *   cash.staffshort.withdraw Withdraw an approved short not yet on a cashup —
 *                            writes the approval back and the short declined
 *                            (Ryan's named go-ahead, PM decision 01a0e5b5)
 *   cash.staffshort.post     Post an approved short to a cashup — writes the
 *                            cashup's pump-shortages line, the short's flag and
 *                            the header's totals
 *
 * Raise, approve and post are separate grants (feature-rules §4): the person
 * who raises a short, the approver who passes it and the cashier who puts it
 * on a cashup are not the same act.
 *
 * WHO HOLDS THEM ON DAY ONE. There are no roles (retired 22 Sep 2026), so a new
 * permission is held by nobody until it is granted to a person:
 *
 *   view     to everyone holding cash.cashup.view
 *   raise    to everyone holding cash.cashup.save — the people who cash up
 *   post     to everyone holding cash.cashup.save — posting IS a cashup save
 *   approve  to the people PumpIT itself lets approve: every agora.User whose
 *            LegacyUserId is in [PumpIT].dbo.BRN_ApproverUserlevel, the band
 *            dbo.sp_SelectUnApprovedStaffShortsByApproverUser reads (5 users on
 *            27 Sep 2026: 2, 4, 9, 115, 139). Read, never written. Where the
 *            table cannot be read, nobody is granted and the seeder says so.
 *   withdraw to everyone holding cash.staffshort.approve — whoever may pass a
 *            short may take the approval back (granted after approve, in the
 *            same run)
 *
 * Each person's grants are editable on their user screen. An approver granted
 * the permission here (or by cash.staffshort.*, cash.*.*) is also who is
 * emailed when a short is raised; an administrator's *.*.* approves without
 * being mailed about every short in the estate.
 *
 * ONLY EVER ADDS: every insert is guarded by NOT EXISTS, so a rerun after
 * --forget puts back only grants somebody removed by hand.
 */
class StaffShortPermissionSeeder extends Seeder
{
    /** After DropSafePermissionSeeder (65) and CashupPermissionSeeder (62), whose grants these copy. */
    public int $seedOrder = 66;

    /** @var array<int, array{0: string, 1: string, 2: ?string}> action, name, copied from (null: the legacy band) */
    private const CATALOGUE = [
        ['view', 'See staff shorts: raised, waiting, approved, posted and declined, at a site and across sites', 'cash.cashup.view'],
        ['raise', 'Raise a staff short against a person — writes it to PumpIT for approval', 'cash.cashup.save'],
        ['approve', 'Approve a staff short, or decline it with a reason — writes the decision to PumpIT', null],
        ['withdraw', 'Withdraw an approved staff short not yet on a cashup — writes it back to declined in PumpIT', 'cash.staffshort.approve'],
        ['post', 'Post an approved staff short to a cashup\'s pump shortages — writes the line to PumpIT', 'cash.cashup.save'],
    ];

    public function run(): void
    {
        $schema = config('agora.schema');
        $erp = (string) config('agora.source_databases.erp');
        $branchId = (int) config('agora.group_branch_id', 2);
        $connection = config('agora.connections.app');
        $sort = 1450;

        foreach (self::CATALOGUE as [$action, $name, $from]) {
            $code = Permission::code('cash', 'staffshort', $action);

            $permission = Permission::query()->withoutGlobalScopes()->updateOrCreate(
                ['BranchId' => $branchId, 'Code' => $code],
                [
                    'Module' => 'cash',
                    'Resource' => 'staffshort',
                    'Action' => $action,
                    'Name' => $name,
                    'SortOrder' => $sort += 10,
                    'UpdatedAt' => now(),
                ]
            );

            if ($from === null) {
                try {
                    $granted = DB::connection($connection)->affectingStatement("
                        INSERT INTO [{$schema}].[UserPermission] ([BranchId], [UserId], [PermissionId], [CreatedAt])
                        SELECT DISTINCT ?, u.[Id], ?, SYSDATETIME()
                        FROM [{$schema}].[User] u
                        WHERE u.[DeletedAt] IS NULL
                          AND u.[LegacyUserId] IN (SELECT a.[ApproverUserlevelId] FROM [{$erp}].dbo.BRN_ApproverUserlevel a)
                          AND NOT EXISTS (
                              SELECT 1 FROM [{$schema}].[UserPermission] x
                              WHERE x.[UserId] = u.[Id] AND x.[PermissionId] = ?
                          );
                    ", [$branchId, $permission->Id, $permission->Id]);

                    $this->command?->info("  {$code} granted to {$granted} person(s) in PumpIT's approver band.");
                } catch (Throwable $e) {
                    $this->command?->warn("  PumpIT's approver band could not be read ({$e->getMessage()}), so {$code} was granted to nobody.");
                }

                continue;
            }

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
