<?php

namespace Modules\Core\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Models\Role;

/**
 * Points the roles at the pages they land on.
 *
 * A SECOND seeder rather than an edit to RoleSeeder, for the same reason a
 * schema change is a new migration: RoleSeeder is in `agora.SeedMaster` on
 * every instance that exists and will never be invoked again. There is no
 * version on a seeder — changing what was seeded means writing another one.
 *
 * RoleSeeder left every LandingRoute null because none of these routes
 * existed. Two of them do now (T007 ships the stubs the branch console and the
 * Exco pack will replace), so the two roles the acceptance names get theirs.
 * The other four stay on the dashboard until their epic lands: a role pointed
 * at a route nobody has built falls back anyway, but a null says so honestly
 * rather than looking like an oversight.
 */
class RoleLandingSeeder extends Seeder
{
    /** After RoleSeeder, which creates the rows this one edits. */
    public int $seedOrder = 25;

    /** Role code => the route name that role lands on. */
    private const LANDINGS = [
        'branch-manager' => 'app.console',
        'executive' => 'app.exco',
    ];

    public function run(): void
    {
        $touched = 0;

        foreach (self::LANDINGS as $code => $route) {
            $role = Role::query()->acrossBranches()->where('Code', $code)->first();

            if (! $role) {
                $this->command?->warn("  Role landing: no role [{$code}] — skipped.");

                continue;
            }

            $role->forceFill(['LandingRoute' => $route])->save();
            $touched++;

            $this->command?->info("  Role landing: {$code} -> {$route}");
        }

        if ($touched === 0) {
            $this->command?->warn('  Role landing: nothing to set. Run RoleSeeder first.');
        }
    }
}
