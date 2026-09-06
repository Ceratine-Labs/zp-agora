<?php

namespace Modules\Core\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Permission;
use Modules\Core\Models\Role;
use Modules\Core\Models\RolePermission;
use Modules\Core\Services\PermissionService;

/**
 * What each of the six roles may do (T008).
 *
 * Grants are written as PATTERNS and stored as the concrete permissions they
 * match today. The pattern is the intent and lives here; the rows are what the
 * database can join on. Re-running this after a module adds permissions is what
 * brings a role up to date, which is why it is idempotent rather than one-shot.
 *
 * THE ONE THAT MATTERS FOR BANK RECON. `recon.runs.execute` and
 * `recon.runs.reverse` are granted to Finance and Admin ONLY. Not Operations,
 * not Branch manager, and explicitly not Auditor. Previewing a reconciliation
 * reads; committing one stamps rows in the customer's estate, and the rulebook
 * says every write of that kind sits behind its own permission per resource.
 * The person who may look at a run is deliberately not the person who may
 * commit it.
 */
class RolePermissionSeeder extends Seeder
{
    public int $seedOrder = 25;

    /** @var array<string, array<int, string>> */
    private const GRANTS = [
        // Everything, including execute and reverse.
        'admin' => ['*.*.*'],

        // Reads the whole system, commits reconciliations, cannot administer
        // users or roles.
        'finance' => [
            'core.*.*',
            'recon.*.*',
            'reports.*.*',
            'audit.*.view',
            'setup.*.view',
        ],

        // Runs the day-to-day. May preview and discard a reconciliation so a
        // problem can be seen and cleared, but not commit or reverse one.
        'operations' => [
            'core.*.*',
            'recon.runs.view',
            'recon.runs.create',
            'recon.runs.delete',
            'recon.criteria.view',
            'reports.*.*',
            'setup.*.view',
        ],

        // A site. Sees its own numbers; reconciliation is head office work.
        'branch-manager' => [
            'core.*.*',
            'reports.catalogue.view',
            'reports.report.view',
        ],

        // Reads everything, changes nothing, plus the audit module which is
        // theirs alone. This is the plan's own definition of the role.
        'auditor' => [
            '*.*.view',
            'audit.*.*',
        ],

        // Sees the group position. Read-only across the business, no setup.
        'executive' => [
            'core.*.*',
            'reports.*.*',
            'recon.runs.view',
            'audit.*.view',
        ],
    ];

    public function run(): void
    {
        $branchId = (int) config('agora.group_branch_id', 2);

        /** @var Collection<int, Permission> $permissions */
        $permissions = Permission::query()->withoutGlobalScopes()->get();
        $matcher = app(PermissionService::class);

        $summary = [];

        foreach (self::GRANTS as $roleCode => $patterns) {
            $role = Role::query()->withoutGlobalScopes()->where('Code', $roleCode)->first();

            if (! $role) {
                $this->command?->warn("Role {$roleCode} is not seeded — skipped.");

                continue;
            }

            $matched = $permissions
                ->filter(function (Permission $permission) use ($patterns, $matcher): bool {
                    foreach ($patterns as $pattern) {
                        if ($matcher->matches($pattern, $permission->Code)) {
                            return true;
                        }
                    }

                    return false;
                })
                ->pluck('Id')
                ->all();

            foreach ($matched as $permissionId) {
                RolePermission::query()->withoutGlobalScopes()->updateOrCreate(
                    ['BranchId' => $branchId, 'RoleId' => $role->Id, 'PermissionId' => $permissionId],
                    ['UpdatedAt' => now()]
                );
            }

            // A grant removed from the list above is removed from the role.
            // Without this the seeder can only ever widen access, and a
            // mistake would be permanent.
            DB::connection(config('agora.connections.app'))
                ->table(config('agora.schema').'.RolePermission')
                ->where('RoleId', $role->Id)
                ->when($matched !== [], fn ($q) => $q->whereNotIn('PermissionId', $matched))
                ->delete();

            $summary[] = "{$roleCode} ".count($matched);
        }

        $this->command?->info('Role permissions: '.implode(', ', $summary).'.');
    }
}
