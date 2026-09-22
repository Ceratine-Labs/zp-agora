<?php

namespace Modules\Core\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Copy everything a role was carrying onto the people who held it
 * (Ryan, 22 Sep 2026).
 *
 * THIS SEEDER IS THE CUTOVER. From the release it ships in, nothing reads
 * agora.UserRole or agora.RolePermission — so if this has not run, the 88
 * people in agora.User hold whatever was granted to them BY NAME, which for
 * most of them is nothing at all. It is not a tidy-up that can follow later.
 *
 * It is written as four set-based statements rather than a loop over users
 * because it runs against the customer's instance across the internet, and
 * 88 people times thirty-odd permissions is 2,600 round trips otherwise.
 *
 * IT ONLY EVER ADDS. Every insert is guarded by NOT EXISTS and every update
 * by IS NULL, so running it twice changes nothing the second time and a
 * permission somebody was granted by name after the cutover is never
 * overwritten by the role they used to hold. That matters more than it
 * sounds: seed:master records a class and skips it, but --forget reopens the
 * gate, and a seeder that reset access when somebody reran it is a seeder
 * nobody can safely rerun.
 *
 * THE SOURCE TABLES ARE LEFT ALONE. v1__01g says why — SIMPLE recovery, no
 * point-in-time restore, so the flatten stays checkable against what it came
 * from.
 */
class RetireRolesSeeder extends Seeder
{
    /** After every permission seeder, because it copies what they granted. */
    public int $seedOrder = 49;

    public function run(): void
    {
        $schema = config('agora.schema');
        $branchId = (int) config('agora.group_branch_id', 2);
        $connection = config('agora.connections.app');

        /*
         * 1. Every permission a person's roles carried becomes a permission
         *    granted to them by name. DISTINCT because two roles overlap far
         *    more than they differ — the six roles share core.*.* almost
         *    entirely — and the natural key would refuse the second copy.
         */
        $permissions = DB::connection($connection)->affectingStatement("
            INSERT INTO [{$schema}].[UserPermission] ([BranchId], [UserId], [PermissionId], [CreatedAt])
            SELECT DISTINCT ?, ur.[UserId], rp.[PermissionId], SYSDATETIME()
            FROM [{$schema}].[UserRole] ur
            JOIN [{$schema}].[RolePermission] rp ON rp.[RoleId] = ur.[RoleId]
            WHERE NOT EXISTS (
                SELECT 1 FROM [{$schema}].[UserPermission] up
                WHERE up.[UserId] = ur.[UserId] AND up.[PermissionId] = rp.[PermissionId]
            );
        ", [$branchId]);

        /*
         * 2. The same for the menu. Empty on the day this ships — the role
         *    matrix went live this morning and nobody has ticked anything —
         *    but a cutover that only worked because a table happened to be
         *    empty is one that breaks if the customer ticks something in the
         *    hour before the deploy.
         */
        $menu = DB::connection($connection)->affectingStatement("
            INSERT INTO [{$schema}].[UserMenuItem] ([BranchId], [UserId], [MenuItemId], [CreatedAt])
            SELECT DISTINCT ?, ur.[UserId], rmi.[MenuItemId], SYSDATETIME()
            FROM [{$schema}].[UserRole] ur
            JOIN [{$schema}].[RoleMenuItem] rmi ON rmi.[RoleId] = ur.[RoleId]
            WHERE NOT EXISTS (
                SELECT 1 FROM [{$schema}].[UserMenuItem] umi
                WHERE umi.[UserId] = ur.[UserId] AND umi.[MenuItemId] = rmi.[MenuItemId]
            );
        ", [$branchId]);

        /*
         * 3. Where they land, and which workspace they are in, off the
         *    PRIMARY role — agora.User.RoleId is that pointer and it is what
         *    User::landingRoute() and ResolveBranchContext read today, so
         *    taking the value from it cannot disagree with the behaviour
         *    being replaced.
         */
        $landing = DB::connection($connection)->affectingStatement("
            UPDATE u
            SET u.[LandingRoute] = r.[LandingRoute],
                u.[UpdatedAt] = SYSDATETIME()
            FROM [{$schema}].[User] u
            JOIN [{$schema}].[Role] r ON r.[Id] = u.[RoleId]
            WHERE u.[LandingRoute] IS NULL AND r.[LandingRoute] IS NOT NULL;
        ");

        $workspace = DB::connection($connection)->affectingStatement("
            UPDATE u
            SET u.[Workspace] = r.[Workspace],
                u.[UpdatedAt] = SYSDATETIME()
            FROM [{$schema}].[User] u
            JOIN [{$schema}].[Role] r ON r.[Id] = u.[RoleId]
            WHERE u.[Workspace] IS NULL AND r.[Workspace] IS NOT NULL;
        ");

        $this->command?->info(
            "  Roles retired: {$permissions} permission(s) and {$menu} menu tick(s) copied to people, "
            ."{$landing} landing route(s) and {$workspace} workspace(s) settled."
        );
    }
}
