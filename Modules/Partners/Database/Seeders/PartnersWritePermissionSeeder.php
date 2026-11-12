<?php

namespace Modules\Partners\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Models\Permission;
use Modules\Core\Services\PermissionService;

/**
 * The trading partners' write permissions (T024), one per action per
 * resource — feature-rules §4. Each is a NAMED WRITE to PumpIT (CLAUDE.md
 * rule 1, PM decision 01a1cecf-8e08), switched by partners.write_mode.
 *
 *   partners.suppliers.edit     change a supplier, or put a change back
 *   partners.suppliers.create   add a new supplier at every site
 *   partners.customers.edit     change an account customer, or put it back
 *   partners.customers.create   add an account customer at a site
 *
 * No delete: nothing removes a partner from PumpIT.
 *
 * Its own seeder rather than an edit to PartnersPermissionSeeder, because a
 * seeder already in the ledger is never run again.
 *
 * CREATED, NOT GRANTED BY NAME. An administrator's `*.*.*` covers these; an
 * auditor's `*.*.view` does not. Who else writes partners is ticked per
 * person or per role on Setup → Users and access.
 */
class PartnersWritePermissionSeeder extends Seeder
{
    public int $seedOrder = 67;

    /** @var array<string, array<string, string>> */
    private const PERMISSIONS = [
        'suppliers' => [
            'edit' => 'Change a supplier in PumpIT — name, contact, department, active, cash and stock flags',
            'create' => 'Add a new supplier to PumpIT, at every site carrying the supplier list',
        ],
        'customers' => [
            'edit' => 'Change an account customer in PumpIT — name and contact details',
            'create' => 'Add an account customer to PumpIT at a site',
        ],
    ];

    public function run(): void
    {
        $branchId = (int) config('agora.group_branch_id', 2);
        $sort = 1330;
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

        $this->command?->info("  Partners: {$count} write permissions created. Tick them per person on Setup → Users and access.");
    }
}
