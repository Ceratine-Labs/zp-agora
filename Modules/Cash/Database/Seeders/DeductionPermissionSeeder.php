<?php

namespace Modules\Cash\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Permission;
use Modules\Core\Services\PermissionService;

/**
 * The deductions' three permissions (T041), and a first set of holders.
 *
 *   cash.deductions.view    the five head-office tabs and the built schedule
 *   cash.deductions.build   build a site's schedule for a pay period — Agora's
 *                           own tables only
 *   cash.deductions.export  take the payroll file, which marks the runs it came
 *                           from exported
 *
 * Held first by whoever may approve a staff short (cash.staffshort.approve) —
 * the people who decide what is charged are the people who read what is
 * deducted. Each person's grants are editable on their user screen.
 *
 * ONLY EVER ADDS: every insert is guarded by NOT EXISTS, so a rerun after
 * --forget puts back only grants somebody removed by hand.
 */
class DeductionPermissionSeeder extends Seeder
{
    /** After StaffShortPermissionSeeder (66), whose approvers these copy. */
    public int $seedOrder = 67;

    /** @var array<int, array{0: string, 1: string, 2: string}> action, name, copied from */
    private const CATALOGUE = [
        ['view', 'See the deduction tabs — cash shorts, stock loss and non-integrated POS by employee — and the built schedule', 'cash.staffshort.approve'],
        ['build', 'Build a site\'s deduction schedule for a pay period — Agora\'s own record, nothing in PumpIT', 'cash.staffshort.approve'],
        ['export', 'Take the payroll deduction file — marks the pay period exported, so it is not rebuilt', 'cash.staffshort.approve'],
    ];

    public function run(): void
    {
        $schema = config('agora.schema');
        $branchId = (int) config('agora.group_branch_id', 2);
        $connection = config('agora.connections.app');
        $sort = 1500;

        foreach (self::CATALOGUE as [$action, $name, $from]) {
            $code = Permission::code('cash', 'deductions', $action);

            $permission = Permission::query()->withoutGlobalScopes()->updateOrCreate(
                ['BranchId' => $branchId, 'Code' => $code],
                [
                    'Module' => 'cash',
                    'Resource' => 'deductions',
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
