<?php

namespace Modules\Product\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Models\Permission;
use Modules\Core\Models\Role;
use Modules\Core\Models\RolePermission;
use Modules\Core\Services\PermissionService;

/**
 * `master.stock.edit` widened to finance, operations and branch manager.
 *
 * StockMasterPermissionSeeder granted it to admin alone and said why: the
 * build plan's owner map puts Masters under "IT & Masters" and names a
 * Trading owner for pricing, and no trading role is seeded — so rather than
 * invent a role or hand master data to Finance on a guess, it shipped narrow
 * and the question went to Ryan.
 *
 * He answered on 20 September 2026: finance, operations and branch manager,
 * all three. Which is the reading that makes sense once you say it out loud —
 * operations own the counts and the counting behaviour flags live on this
 * record; finance argue from cost and GP; a branch manager is scoped to their
 * own sites by their branch grants anyway, so "may edit the stock master"
 * means "may edit MY site's stock master" for them. Executive and auditor
 * keep view only.
 *
 * Its own seeder because a seeder is a one-shot and the first one has already
 * run — the same rule that governs migrations. And by row, not by wildcard:
 * RolePermissionSeeder MATERIALISES its patterns at seed time and they are not
 * re-evaluated when a permission is checked, so a grant that is not a row is
 * not a grant.
 */
class StockMasterEditWidenedSeeder extends Seeder
{
    /** After StockMasterPermissionSeeder (21), which created the permission. */
    public int $seedOrder = 22;

    private const ROLES = ['finance', 'operations', 'branch-manager'];

    public function run(): void
    {
        $branchId = (int) config('agora.group_branch_id', 2);

        $permission = Permission::query()->withoutGlobalScopes()
            ->where('BranchId', $branchId)
            ->where('Code', Permission::code('master', 'stock', 'edit'))
            ->first();

        if ($permission === null) {
            $this->command?->warn(
                'master.stock.edit does not exist — StockMasterPermissionSeeder has not run. Nothing granted.'
            );

            return;
        }

        $granted = [];

        foreach (self::ROLES as $code) {
            $role = Role::query()->withoutGlobalScopes()->where('Code', $code)->first();

            if ($role === null) {
                $this->command?->warn("Role {$code} is not seeded — master.stock.edit not granted to it.");

                continue;
            }

            RolePermission::query()->withoutGlobalScopes()->updateOrCreate(
                ['BranchId' => $branchId, 'RoleId' => $role->Id, 'PermissionId' => $permission->Id],
                ['UpdatedAt' => now()]
            );

            $granted[] = $code;
        }

        // Anyone signed in while this ran is holding a cached grant set that
        // still says they may not.
        app(PermissionService::class)->forgetAll();

        $this->command?->info('master.stock.edit widened to '.(implode(', ', $granted) ?: 'nobody').'.');
    }
}
