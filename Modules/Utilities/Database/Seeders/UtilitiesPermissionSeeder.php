<?php

namespace Modules\Utilities\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Models\Permission;
use Modules\Core\Services\PermissionService;

/**
 * The utility meter permissions (T056), one per action — feature-rules §4.
 *
 *   utilities.readings.view   open the screen: the month per meter, the stuck
 *                             meters, every reading
 *   utilities.readings.save   record a reading for a day that has none. This
 *                             WRITES the customer's PumpIT.dbo.BRN_UtilityTransaction
 *   utilities.readings.edit   change a reading somebody already captured — a
 *                             correction, which needs a reason and is recorded
 *                             with the value it replaced
 *
 * There is no delete. A reading is a record of something that was seen.
 *
 * CREATED, NOT GRANTED BY NAME. Roles were retired on 22 Sep 2026 and a
 * permission is now ticked per person on Setup → Users and access. Anybody
 * holding a wildcard already covers these at the check (`*.*.*` for an
 * administrator, `*.*.view` for an auditor), because PermissionService
 * expands the pattern when it asks rather than when it grants. Who else
 * captures readings — site managers, the forecourt supervisor — is Ryan's to
 * tick; there is no existing permission these follow from, unlike
 * recon.runs.close following recon.runs.delete.
 *
 * Its own seeder because PermissionSeeder has already run and the ledger will
 * not run it again. Upserts on the code, so a rerun after `--forget` changes
 * nothing but the wording.
 */
class UtilitiesPermissionSeeder extends Seeder
{
    /** After RetireRolesSeeder (49), like every permission created since. */
    public int $seedOrder = 62;

    /** @var array<string, string> */
    private const ACTIONS = [
        'view' => 'See utility meter readings, usage and cost, and the meters that have stopped moving',
        'save' => 'Record a meter reading for a day that has none — writes PumpIT.dbo.BRN_UtilityTransaction',
        'edit' => 'Change a meter reading somebody already captured — needs a reason, and keeps the old value',
    ];

    public function run(): void
    {
        $branchId = (int) config('agora.group_branch_id', 2);
        $sort = 1200;

        foreach (self::ACTIONS as $action => $name) {
            Permission::query()->withoutGlobalScopes()->updateOrCreate(
                ['BranchId' => $branchId, 'Code' => Permission::code('utilities', 'readings', $action)],
                [
                    'Module' => 'utilities',
                    'Resource' => 'readings',
                    'Action' => $action,
                    'Name' => $name,
                    'SortOrder' => $sort += 10,
                    'UpdatedAt' => now(),
                ]
            );
        }

        // Anyone signed in while this ran holds a cached grant set that does
        // not know these codes exist.
        app(PermissionService::class)->forgetAll();

        $this->command?->info('  Utilities: '.count(self::ACTIONS).' permissions created. Tick them per person on Setup → Users and access.');
    }
}
