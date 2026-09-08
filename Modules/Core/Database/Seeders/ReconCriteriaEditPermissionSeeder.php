<?php

namespace Modules\Core\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Models\Permission;
use Modules\Core\Models\Role;
use Modules\Core\Models\RolePermission;
use Modules\Core\Services\PermissionService;

/**
 * `recon.criteria.edit` — changing what the previews resolve against.
 *
 * Its own seeder rather than a line in PermissionSeeder, which has already run
 * and which the ledger will not run again. A seeder is a one-shot, so changing
 * what was seeded means writing another one — the same rule that governs
 * migrations.
 *
 * GRANTED TO ADMIN AND FINANCE, and deliberately not to Operations. An
 * extraction rule decides which bank lines reconcile against which deposits
 * across a whole site; changing one is closer to executing a reconciliation
 * than to looking at one. Operations keeps `recon.criteria.view` and can see
 * every rule without being able to move one.
 *
 * BOTH ROLES NEED A ROW, and the first cut of this seeder got that wrong.
 * RolePermissionSeeder's `*.*.*` and `recon.*.*` are patterns it MATERIALISES
 * into agora.RolePermission rows at seed time — they are not evaluated when a
 * permission is checked. So a permission created after that seeder ran is held
 * by nobody until it is granted explicitly, wildcard or no wildcard, and the
 * administrator was refused their own screen until this was fixed.
 */
class ReconCriteriaEditPermissionSeeder extends Seeder
{
    /** After PermissionSeeder (15) and RolePermissionSeeder, which built the matrix. */
    public int $seedOrder = 20;

    public function run(): void
    {
        $branchId = (int) config('agora.group_branch_id', 2);

        $permission = Permission::query()->withoutGlobalScopes()->updateOrCreate(
            ['BranchId' => $branchId, 'Code' => Permission::code('recon', 'criteria', 'edit')],
            [
                'Module' => 'recon',
                'Resource' => 'criteria',
                'Action' => 'edit',
                'Name' => 'Change the extraction configuration — an Agora override, never the customer\'s table',
                'SortOrder' => 999,
                'UpdatedAt' => now(),
            ]
        );

        $granted = [];

        foreach (['admin', 'finance'] as $code) {
            $role = Role::query()->withoutGlobalScopes()->where('Code', $code)->first();

            if ($role === null) {
                $this->command?->warn("Role {$code} is not seeded — recon.criteria.edit not granted to it.");

                continue;
            }

            // updateOrCreate rather than a relation, matching
            // RolePermissionSeeder: the pivot carries BranchId like every other
            // row in the estate.
            RolePermission::query()->withoutGlobalScopes()->updateOrCreate(
                ['BranchId' => $branchId, 'RoleId' => $role->Id, 'PermissionId' => $permission->Id],
                ['UpdatedAt' => now()]
            );

            $granted[] = $code;
        }

        // Anyone signed in while this ran is holding a cached grant set that
        // does not know about the new permission.
        app(PermissionService::class)->forgetAll();

        $this->command?->info('recon.criteria.edit granted to '.(implode(', ', $granted) ?: 'nobody').'.');
    }
}
