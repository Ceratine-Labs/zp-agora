<?php

namespace Modules\Partners\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Models\Permission;
use Modules\Core\Services\PermissionService;

/**
 * The trading partners' permissions (T024), one per action per resource —
 * feature-rules §4.
 *
 *   partners.suppliers.view   the supplier list and one supplier's page
 *   partners.customers.view   the account-customer list and one customer's page
 *
 * VIEW ONLY, because nothing else exists yet: the masters are PumpIT's and
 * whether an edit lands in Agora or back in PumpIT is waiting on Ryan. The
 * edit / save permissions arrive with whichever save path he chooses.
 *
 * CREATED, NOT GRANTED BY NAME. An administrator's `*.*.*` and an auditor's
 * `*.*.view` already cover these at the check; who else reads the partner
 * lists is ticked per person or per role on Setup → Users and access.
 *
 * Upserts on the code, so a rerun after `--forget` changes nothing but the
 * wording. Nothing here writes the customer's databases.
 */
class PartnersPermissionSeeder extends Seeder
{
    /** After RetireRolesSeeder (49), like every permission created since. */
    public int $seedOrder = 66;

    /** @var array<string, array<string, string>> */
    private const PERMISSIONS = [
        'suppliers' => [
            'view' => 'See the supplier (creditor) master: each site\'s suppliers, POS accounts and cash / stock flags',
        ],
        'customers' => [
            'view' => 'See the account customer (debtor) master: each site\'s accounts, till limits, statement address',
        ],
    ];

    public function run(): void
    {
        $branchId = (int) config('agora.group_branch_id', 2);
        $sort = 1300;
        $count = 0;

        foreach (self::PERMISSIONS as $resource => $actions) {
            foreach ($actions as $action => $name) {
                Permission::query()->withoutGlobalScopes()->updateOrCreate(
                    ['BranchId' => $branchId, 'Code' => Permission::code('partners', $resource, $action)],
                    [
                        'Module' => 'partners',
                        'Resource' => $resource,
                        'Action' => $action,
                        'Name' => $name,
                        'SortOrder' => $sort += 10,
                        'UpdatedAt' => now(),
                    ]
                );
                $count++;
            }
        }

        app(PermissionService::class)->forgetAll();

        $this->command?->info("  Partners: {$count} permissions created. Tick them per person on Setup → Users and access.");
    }
}
