<?php

namespace Modules\Masters\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Models\Permission;
use Modules\Core\Services\PermissionService;

/**
 * The site and forecourt masters' write-back permissions (T022; CLAUDE.md
 * rule 1, PM decision 01a1cecf-8e08): masters.{resource}.edit for each
 * master that writes PumpIT after the first three. Its own one-shot, because
 * MasterWritePermissionSeeder has already run where the first three were
 * seeded and a seeder in agora.SeedMaster is never run again.
 *
 * Edit covers the form, its save and Put back; there is no delete, because no
 * PumpIT row is removed. CREATED, NOT GRANTED BY NAME: an administrator's
 * `*.*.*` covers these; anyone else is ticked on Setup → Users and access.
 * Upserts on the code. Writes agora.Permission only.
 */
class SiteMasterWritePermissionSeeder extends Seeder
{
    /** After MasterWritePermissionSeeder (66). */
    public int $seedOrder = 67;

    /** @var array<string, string> resource => what editing it writes */
    public const PERMISSIONS = [
        'expenses' => 'Change an expense code\'s description, GL code, VAT and active flag in PumpIT (BRN_Expenses)',
        'tills' => 'Rename a till in PumpIT (BRN_Tills)',
        'shifts' => 'Rename a shift or switch it on or off in PumpIT (BRN_Shifts)',
        'transactiontypes' => 'Rename a transaction type in PumpIT (BRN_TransactionType)',
        'fueltypes' => 'Rename a fuel type in PumpIT (BRN_FuelType)',
        'tanks' => 'Rename a tank in PumpIT (BRN_Tank)',
        'pumps' => 'Change a pump\'s capture order or switch it on or off in PumpIT (BRN_Pump)',
        'meters' => 'Change a utility meter\'s description in PumpIT (BRN_UtilityMeter)',
        'terminals' => 'Change a merchant or terminal number\'s description, or switch it on or off, in PumpIT (SS_Branch_*_MerchantNo / _TerminalNo)',
    ];

    public function run(): void
    {
        $branchId = (int) config('agora.group_branch_id', 2);
        $sort = 1440;

        foreach (self::PERMISSIONS as $resource => $name) {
            Permission::query()->withoutGlobalScopes()->updateOrCreate(
                ['BranchId' => $branchId, 'Code' => Permission::code('masters', $resource, 'edit')],
                ['Module' => 'masters', 'Resource' => $resource, 'Action' => 'edit', 'Name' => $name,
                    'SortOrder' => $sort += 10, 'UpdatedAt' => now()]
            );
        }

        app(PermissionService::class)->forgetAll();

        $this->command?->info('  Masters: '.count(self::PERMISSIONS).' site write-back permission(s) created.');
    }
}
