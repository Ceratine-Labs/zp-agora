<?php

namespace Modules\StockRecon\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Models\Permission;
use Modules\Core\Models\Role;
use Modules\Core\Models\RolePermission;
use Modules\Core\Services\PermissionService;

/**
 * The stock recon centre's permissions, and who holds them.
 *
 * ITS OWN SEEDER rather than lines in Core's PermissionSeeder, which has
 * already run and which the ledger will not run again. A seeder is a one-shot,
 * so adding a permission means writing another one — the same rule that governs
 * migrations.
 *
 * AND IT MUST GRANT THEM EXPLICITLY. RolePermissionSeeder's `*.*.*` and
 * `recon.*.*` are patterns it MATERIALISED into agora.RolePermission rows at
 * seed time — they are not evaluated when a permission is checked. A permission
 * created after that seeder ran is held by NOBODY until it is granted here,
 * wildcard or no wildcard. That caught the recon criteria permission out and
 * refused the administrator their own screen.
 *
 * WHO GETS WHAT, and the split is the one feature-rules §4 asks for:
 *
 *   view/create/delete   Admin, Finance, Operations, and a Branch manager for
 *                        their own site. Previewing reads and writes nothing,
 *                        and the person who counts the stock is the person best
 *                        placed to look at what the counts imply.
 *   execute/reverse      Admin and Finance only. Committing amends counts — in
 *                        Agora's ledger today and in the customer's ERP the day
 *                        the stamp mode changes — and a shift's balanced short
 *                        is what a charge is written off. The person who may
 *                        look is deliberately not the person who may commit,
 *                        and NOT the branch whose figure it is.
 *   exceptions.view      Everyone who may see a run, plus the Auditor and the
 *                        Executive: the unrecorded-issue figure is a group
 *                        finding, not an operational one.
 */
class StockReconPermissionSeeder extends Seeder
{
    /** After PermissionSeeder (15) and RolePermissionSeeder (25). */
    public int $seedOrder = 27;

    /** @var array<int, array{0:string,1:string,2:string,3:array<int,string>}> */
    private const CATALOGUE = [
        ['runs', 'view', 'See balancing runs and the shifts on them',
            ['admin', 'finance', 'operations', 'branch-manager', 'auditor', 'executive']],
        ['runs', 'create', 'Preview a balancing — reads the counts, writes nothing',
            ['admin', 'finance', 'operations', 'branch-manager']],
        ['runs', 'execute', 'Commit a balancing — writes the amendments',
            ['admin', 'finance']],
        ['runs', 'reverse', 'Reverse a committed balancing',
            ['admin', 'finance']],
        ['runs', 'delete', 'Discard a balancing preview',
            ['admin', 'finance', 'operations', 'branch-manager']],
        ['exceptions', 'view', 'See the unrecorded issues and count exceptions a run found',
            ['admin', 'finance', 'operations', 'branch-manager', 'auditor', 'executive']],
    ];

    public function run(): void
    {
        $branchId = (int) config('agora.group_branch_id', 2);
        $sort = 1000;
        $granted = 0;

        foreach (self::CATALOGUE as [$resource, $action, $name, $roles]) {
            $permission = Permission::query()->withoutGlobalScopes()->updateOrCreate(
                ['BranchId' => $branchId, 'Code' => Permission::code('stockrecon', $resource, $action)],
                [
                    'Module' => 'stockrecon',
                    'Resource' => $resource,
                    'Action' => $action,
                    'Name' => $name,
                    'SortOrder' => $sort += 10,
                    'UpdatedAt' => now(),
                ]
            );

            foreach ($roles as $code) {
                $role = Role::query()->withoutGlobalScopes()->where('Code', $code)->first();

                if ($role === null) {
                    $this->command?->warn("Role {$code} is not seeded — stockrecon.{$resource}.{$action} not granted to it.");

                    continue;
                }

                // updateOrCreate rather than a relation, matching
                // RolePermissionSeeder: the pivot carries BranchId like every
                // other row in the estate.
                RolePermission::query()->withoutGlobalScopes()->updateOrCreate(
                    ['BranchId' => $branchId, 'RoleId' => $role->Id, 'PermissionId' => $permission->Id],
                    ['UpdatedAt' => now()]
                );

                $granted++;
            }
        }

        // Anyone signed in while this ran is holding a cached grant set that
        // does not know about the new permissions.
        app(PermissionService::class)->forgetAll();

        $this->command?->info('Stock recon: '.count(self::CATALOGUE)." permissions, {$granted} grants.");
    }
}
