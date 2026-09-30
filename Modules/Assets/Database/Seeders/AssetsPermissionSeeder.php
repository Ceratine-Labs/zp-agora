<?php

namespace Modules\Assets\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Models\Permission;
use Modules\Core\Services\PermissionService;

/**
 * The asset permissions (T057), one per action per resource — feature-rules §4.
 *
 *   assets.register.view    the register, an asset's page, the masters
 *   assets.register.save    register a new asset
 *   assets.register.edit    change a registered asset (every change needs a reason)
 *   assets.register.delete  take an asset registered in error off the register;
 *                           refused once it has any movement or job card
 *   assets.movement.view    the movement list
 *   assets.movement.save    record a transfer, repair trip, sale, trade-in or write-off
 *   assets.movement.edit    reverse an asset's latest movement
 *   assets.repair.view      job cards, comebacks and the supplier tally
 *   assets.repair.save      raise a job card
 *   assets.repair.edit      update a job card (return dates, purchase request) or cancel it
 *
 * No delete for a movement or a job card: they are transactions.
 *
 * CREATED, NOT GRANTED BY NAME. Roles were retired on 22 Sep 2026; a
 * permission is ticked per person on Setup → Users and access. Anybody holding
 * a wildcard already covers these at the check (`*.*.*` for an administrator,
 * `*.*.view` for an auditor), because PermissionService expands the pattern
 * when it asks. Who else registers and moves assets is Ryan's to tick.
 *
 * Its own seeder because PermissionSeeder has already run and the ledger will
 * not run it again. Upserts on the code, so a rerun after `--forget` changes
 * nothing but the wording. Nothing here writes the customer's databases.
 */
class AssetsPermissionSeeder extends Seeder
{
    /** After RetireRolesSeeder (49), like every permission created since. */
    public int $seedOrder = 64;

    /** @var array<string, array<string, string>> */
    private const PERMISSIONS = [
        'register' => [
            'view' => 'See the asset register — cost, depreciation, NBV, condition — each asset\'s page, and the asset masters',
            'save' => 'Register a new asset',
            'edit' => 'Change a registered asset — needs a reason',
            'delete' => 'Delete an asset registered in error — only while it has no movements or job cards',
        ],
        'movement' => [
            'view' => 'See asset movements: transfers, repair trips, sales, trade-ins and write-offs',
            'save' => 'Record an asset movement',
            'edit' => 'Reverse an asset\'s latest movement — needs a reason, and the movement stays on record',
        ],
        'repair' => [
            'view' => 'See repair job cards, comebacks and the comebacks-by-supplier tally',
            'save' => 'Raise a repair job card',
            'edit' => 'Update or cancel a repair job card — return dates, the purchase request that pays for it',
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
                    ['BranchId' => $branchId, 'Code' => Permission::code('assets', $resource, $action)],
                    [
                        'Module' => 'assets',
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

        // Anyone signed in while this ran holds a cached grant set that does
        // not know these codes exist.
        app(PermissionService::class)->forgetAll();

        $this->command?->info("  Assets: {$count} permissions created. Tick them per person on Setup → Users and access.");
    }
}
