<?php

namespace Tests\Fixtures;

use Modules\Core\Models\Permission;
use Modules\Core\Models\User;
use Modules\Core\Models\UserPermission;
use Modules\Core\Services\PermissionService;

/**
 * Making a test person who may do something, after roles were retired
 * (Ryan, 22 September 2026).
 *
 * Every feature test used to build its fixture the same way — find a Role by
 * code, create a User, write a UserRole — in five copies. With roles gone that
 * would have become five copies of a permission-granting loop instead, so it
 * is one copy here.
 *
 * THE PROFILE NAMES ARE THE OLD ROLE NAMES ON PURPOSE. A test asserting that
 * "a read-only person must not reach a page made of Save buttons" is about
 * that boundary, not about a role, and keeping the name keeps the test
 * readable against the assertion it makes. What changed is that the grants are
 * WRITTEN DOWN HERE rather than looked up: the old fixtures would have gone on
 * passing if somebody had quietly widened a role, which is the failure mode a
 * permission test exists to catch.
 */
trait GrantsAccess
{
    /**
     * @var array<string, array<int, string>>
     *
     * The union of what RolePermissionSeeder, StockMasterPermissionSeeder,
     * StockMasterEditWidenedSeeder, StockReconPermissionSeeder and
     * ReconCriteriaEditPermissionSeeder granted each role — because a role was
     * never defined in one place, which is one of the things that made it hard
     * to answer "what may this person do". Written out rather than read back
     * out of agora.RolePermission on purpose: nothing maintains that table any
     * more, so a fixture reading it would quietly go stale the first time a
     * module adds a permission.
     */
    private const ACCESS_PROFILES = [
        'admin' => ['*.*.*'],

        'auditor' => [
            '*.*.view', 'audit.*.*',
        ],

        'finance' => [
            'core.*.*', 'recon.*.*', 'reports.*.*', 'audit.*.view', 'setup.*.view',
            'master.stock.view', 'master.stock.edit',
            'stockrecon.runs.view', 'stockrecon.runs.create', 'stockrecon.runs.execute',
            'stockrecon.runs.reverse', 'stockrecon.runs.delete', 'stockrecon.exceptions.view',
        ],

        'operations' => [
            'core.*.*', 'recon.runs.view', 'recon.runs.create', 'recon.runs.delete',
            'recon.criteria.view', 'reports.*.*', 'setup.*.view',
            'master.stock.view', 'master.stock.edit',
            'stockrecon.runs.view', 'stockrecon.runs.create', 'stockrecon.runs.delete',
            'stockrecon.exceptions.view',
        ],

        'branch-manager' => [
            'core.*.*', 'reports.catalogue.view', 'reports.report.view',
            'master.stock.view', 'master.stock.edit',
            'stockrecon.runs.view', 'stockrecon.runs.create', 'stockrecon.runs.delete',
            'stockrecon.exceptions.view',
        ],

        'executive' => [
            'core.*.*', 'reports.*.*', 'recon.runs.view', 'audit.*.view',
            'master.stock.view',
            'stockrecon.runs.view', 'stockrecon.exceptions.view',
        ],
    ];

    /**
     * The workspace a profile works in.
     *
     * agora.User.Workspace since the cutover — it used to be on the role, and
     * a fixture created from nothing has to say it or ResolveBranchContext
     * puts a branch manager in the head-office shell.
     *
     * @var array<string, string>
     */
    private const ACCESS_WORKSPACES = ['branch-manager' => 'branch'];

    /**
     * Where a profile lands after signing in.
     *
     * agora.User.LandingRoute since the cutover, from RoleLandingSeeder's map.
     * A fixture that does not set it lands on the dashboard, which is the
     * correct fallback and the wrong thing to assert a landing test against.
     *
     * @var array<string, string>
     */
    private const ACCESS_LANDINGS = [
        'branch-manager' => 'app.console',
        'executive' => 'app.exco',
    ];

    /** Where a profile lands, or null for the dashboard. */
    protected function profileLanding(string $profile): ?string
    {
        return self::ACCESS_LANDINGS[$profile] ?? null;
    }

    /**
     * Grant a person everything one profile stands for.
     *
     * Patterns are expanded against agora.Permission, because a grant is
     * always a concrete permission id — the wildcard is a convenience for
     * writing the profile, exactly as it was for writing a role.
     */
    protected function grantProfile(User $user, string $profile): User
    {
        $permissions = app(PermissionService::class);
        $patterns = self::ACCESS_PROFILES[$profile]
            ?? throw new \InvalidArgumentException("No access profile named [{$profile}].");

        foreach (Permission::query()->acrossBranches()->get() as $permission) {
            if (! $permissions->anyMatches($patterns, $permission->Code)) {
                continue;
            }

            UserPermission::query()->acrossBranches()->firstOrCreate([
                'BranchId' => (int) $user->BranchId,
                'UserId' => (int) $user->Id,
                'PermissionId' => (int) $permission->Id,
            ]);
        }

        $permissions->forget($user);

        return $user;
    }

    /** Which workspace a profile belongs in. */
    protected function profileWorkspace(string $profile): string
    {
        return self::ACCESS_WORKSPACES[$profile] ?? 'ho';
    }
}
