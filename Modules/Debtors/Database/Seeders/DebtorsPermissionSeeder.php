<?php

namespace Modules\Debtors\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Permission;
use Modules\Core\Services\PermissionService;

/**
 * Debtors statements' three permissions, and a first set of holders.
 *
 *   debtors.statements.view   the list, a statement, its PDF
 *   debtors.statements.send   mail a statement now — to the debtor only when
 *                             debtors.send_mode is 'live'
 *   debtors.contacts.edit     a debtor's email address, copies, and whether
 *                             the account is on the monthly run
 *
 * There is no delete: a statement is a reading of the ledger, and a send is a
 * record of what a debtor was told.
 *
 * WHO HOLDS THEM ON DAY ONE. There are no roles (retired 22 Sep 2026), so a
 * new permission is held by nobody until it is granted to a person. The grant
 * copies daily banking, because debtors are a cashup leg and the people who
 * read cashups are the people who chase accounts:
 *
 *   debtors.statements.view   to everyone holding cash.cashup.view
 *   debtors.statements.send   to everyone holding cash.cashup.save
 *   debtors.contacts.edit     to everyone holding cash.cashup.save
 *
 * Each person's grants are editable on their user screen.
 *
 * ONLY EVER ADDS: every insert is guarded by NOT EXISTS, so a rerun after
 * --forget puts back only grants somebody removed by hand.
 */
class DebtorsPermissionSeeder extends Seeder
{
    /** After CashupPermissionSeeder (62), whose grants these copy. */
    public int $seedOrder = 64;

    /** @var array<int, array{0: string, 1: string, 2: string, 3: string}> resource, action, name, copied from */
    private const CATALOGUE = [
        ['statements', 'view', 'See debtors\' balances and statements of account, and download them', 'cash.cashup.view'],
        ['statements', 'send', 'Mail a debtor their statement — reaches the debtor only once sending is live', 'cash.cashup.save'],
        ['contacts', 'edit', 'Set where a debtor\'s statement is mailed, and put the account on the monthly run', 'cash.cashup.save'],
    ];

    public function run(): void
    {
        $schema = config('agora.schema');
        $branchId = (int) config('agora.group_branch_id', 2);
        $connection = config('agora.connections.app');
        $sort = 1400;

        foreach (self::CATALOGUE as [$resource, $action, $name, $from]) {
            $code = Permission::code('debtors', $resource, $action);

            $permission = Permission::query()->withoutGlobalScopes()->updateOrCreate(
                ['BranchId' => $branchId, 'Code' => $code],
                [
                    'Module' => 'debtors',
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
