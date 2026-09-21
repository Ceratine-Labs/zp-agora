<?php

namespace Modules\Product\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Models\Permission;
use Modules\Core\Models\Role;
use Modules\Core\Models\RolePermission;
use Modules\Core\Services\PermissionService;

/**
 * `master.stock.view` and `master.stock.edit` (T025).
 *
 * Its own seeder rather than a line in Core's PermissionSeeder, which has
 * already run and which the ledger will not run again. A seeder is a one-shot,
 * so adding to what was seeded means writing another one — the same rule that
 * governs migrations.
 *
 * WHO GETS WHAT, and the split is not cosmetic:
 *
 *   · `view` goes to every role. The stock master is the thing all of them
 *     argue from — a count, a GP query and a price review all start here —
 *     and the auditor sees everything read-only by definition.
 *   · `edit` goes to ADMIN ALONE, for now. An override changes what a count is
 *     valued at and what a line is called on a counting sheet, at one site,
 *     for everybody. The build plan's owner map puts Masters under "IT &
 *     Masters", which is admin here; it also names a Trading owner for pricing
 *     and there is no trading role seeded. Rather than invent one or hand
 *     master data to Finance on a guess, this grants the narrow version and
 *     the question goes to Ryan. Widening a grant later is a seeder; unwinding
 *     one is an incident.
 *
 * A PERMISSION CREATED AFTER RolePermissionSeeder RAN IS HELD BY NOBODY until
 * it is granted explicitly. That seeder MATERIALISES its `*.*.*` and
 * `recon.*.*` patterns into agora.RolePermission rows at seed time; they are
 * not re-evaluated when a permission is checked. The administrator was
 * refused their own screen the last time this was forgotten (see
 * ReconCriteriaEditPermissionSeeder), so both actions are granted by row here,
 * wildcard or no wildcard.
 */
class StockMasterPermissionSeeder extends Seeder
{
    /** After PermissionSeeder (15) and RolePermissionSeeder, which built the matrix. */
    public int $seedOrder = 21;

    /** @var array<string, array{0:string, 1:array<int, string>}> */
    private const GRANTS = [
        'view' => [
            'See the stock master — every line a site carries, with its cost, GP and last count',
            ['admin', 'executive', 'finance', 'operations', 'branch-manager', 'auditor'],
        ],
        'edit' => [
            'Change a stock line — an Agora override, never the customer\'s table',
            ['admin'],
        ],
    ];

    public function run(): void
    {
        $branchId = (int) config('agora.group_branch_id', 2);
        $sort = 1000;

        foreach (self::GRANTS as $action => [$name, $roles]) {
            $permission = Permission::query()->withoutGlobalScopes()->updateOrCreate(
                ['BranchId' => $branchId, 'Code' => Permission::code('master', 'stock', $action)],
                [
                    'Module' => 'master',
                    'Resource' => 'stock',
                    'Action' => $action,
                    'Name' => $name,
                    'SortOrder' => $sort++,
                    'UpdatedAt' => now(),
                ]
            );

            $granted = [];

            foreach ($roles as $code) {
                $role = Role::query()->withoutGlobalScopes()->where('Code', $code)->first();

                if ($role === null) {
                    $this->command?->warn("Role {$code} is not seeded — master.stock.{$action} not granted to it.");

                    continue;
                }

                RolePermission::query()->withoutGlobalScopes()->updateOrCreate(
                    ['BranchId' => $branchId, 'RoleId' => $role->Id, 'PermissionId' => $permission->Id],
                    ['UpdatedAt' => now()]
                );

                $granted[] = $code;
            }

            $this->command?->info("master.stock.{$action} granted to ".(implode(', ', $granted) ?: 'nobody').'.');
        }

        // Anyone signed in while this ran is holding a cached grant set that
        // does not know about the new permissions.
        app(PermissionService::class)->forgetAll();
    }
}
