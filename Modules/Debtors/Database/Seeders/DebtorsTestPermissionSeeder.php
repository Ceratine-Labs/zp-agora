<?php

namespace Modules\Debtors\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Permission;
use Modules\Core\Services\PermissionService;

/**
 * debtors.statements.test (Ryan, 13 Nov 2026: "a separate window where a test
 * to a given address can be fired, 'your address' or a custom input" … "ensure
 * view and send are behind permissions and test").
 *
 *   debtors.statements.test   mail one statement, marked TEST, to your own
 *                             address or one you type — in every send mode,
 *                             'off' included; never to the debtor
 *
 * A seeder of its own because DebtorsPermissionSeeder has already run on live.
 * Granted to everyone already holding debtors.statements.send: whoever may mail
 * a statement may first mail one to themselves. An administrator's `*.*.*`
 * covers it too. ONLY EVER ADDS (NOT EXISTS), like DebtorsPermissionSeeder.
 * Nothing here writes the customer's databases.
 */
class DebtorsTestPermissionSeeder extends Seeder
{
    /** After DebtorsPermissionSeeder (64), whose send grant this copies. */
    public int $seedOrder = 65;

    public function run(): void
    {
        $schema = config('agora.schema');
        $branchId = (int) config('agora.group_branch_id', 2);
        $from = 'debtors.statements.send';

        $permission = Permission::query()->withoutGlobalScopes()->updateOrCreate(
            ['BranchId' => $branchId, 'Code' => Permission::code('debtors', 'statements', 'test')],
            [
                'Module' => 'debtors',
                'Resource' => 'statements',
                'Action' => 'test',
                'Name' => 'Mail a TEST statement to yourself or an address you type — never reaches the debtor',
                'SortOrder' => 1425,
                'UpdatedAt' => now(),
            ]
        );

        $source = Permission::query()->withoutGlobalScopes()->where('Code', $from)->value('Id');
        $granted = 0;

        if ($source !== null) {
            $granted = DB::connection(config('agora.connections.app'))->affectingStatement("
                INSERT INTO [{$schema}].[UserPermission] ([BranchId], [UserId], [PermissionId], [CreatedAt])
                SELECT DISTINCT ?, up.[UserId], ?, SYSDATETIME()
                FROM [{$schema}].[UserPermission] up
                WHERE up.[PermissionId] = ?
                  AND NOT EXISTS (
                      SELECT 1 FROM [{$schema}].[UserPermission] x
                      WHERE x.[UserId] = up.[UserId] AND x.[PermissionId] = ?
                  );
            ", [$branchId, $permission->Id, $source, $permission->Id]);
        }

        app(PermissionService::class)->forgetAll();

        $this->command?->info("  debtors.statements.test granted to {$granted} person(s) holding {$from}.");
    }
}
