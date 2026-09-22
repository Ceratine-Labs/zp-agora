<?php

use App\Support\Database\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The two facts a role carried that are not permissions (Ryan, 22 Sep 2026).
 *
 * Roles are being retired: every person's access is granted to them directly.
 * Permissions move by being copied — RetireRolesSeeder flattens
 * agora.RolePermission into agora.UserPermission — but a role carried two
 * other things that nothing else knows, and losing either is not a permission
 * bug, it is somebody signing in and landing nowhere:
 *
 *   LandingRoute  where this person goes after signing in. It was on the ROLE
 *                 so the business could change "where does Finance land"
 *                 without a deploy; it is on the PERSON now, for the same
 *                 reason and one row at a time.
 *   Workspace     head office or branch. ResolveBranchContext reads it to
 *                 decide which menu and which scope bar the request is in.
 *                 HomeBranchId already forces `branch` for a site user, so
 *                 this is only the default for everybody else — but the
 *                 default is what the whole shell is drawn from.
 *
 * BOTH NULLABLE, and the readers already have a fallback: User::landingRoute()
 * falls back to the dashboard for a route that does not resolve, and
 * ResolveBranchContext falls back to 'ho'. A person the seeder cannot settle
 * therefore lands somewhere sensible rather than on an error.
 *
 * A LETTERED FOLLOW-ON, not an edit to v1__01 — agora.User holds the 88 people
 * migrated out of SS_Users and the administrator credential, so there is no
 * fresh install to re-run.
 *
 * WHAT THIS DELIBERATELY DOES NOT DO IS DROP ANYTHING. agora.Role, UserRole,
 * RolePermission and RoleMenuItem stay exactly where they are, unread by the
 * application from this release. The customer's instance is in SIMPLE
 * recovery — no log chain, no point-in-time restore — so a DROP here is a
 * decision nobody can walk back, and the tables cost a few kilobytes. Leaving
 * them means the flatten can be checked against its source for as long as
 * anybody wants to check it. Dropping them is a separate migration on a day
 * somebody is confident, not a side effect of this one.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = config('agora.schema');

        DB::statement("
            ALTER TABLE [{$schema}].[User]
                ADD [LandingRoute] NVARCHAR(120) NULL,
                    [Workspace] NVARCHAR(10) NULL;
        ");

        MigrationHelper::recordVersion(
            '1.9',
            'agora.User.LandingRoute and .Workspace: the two facts a role carried that are not permissions.'
        );
    }

    public function down(): void
    {
        $schema = config('agora.schema');

        DB::statement("ALTER TABLE [{$schema}].[User] DROP COLUMN [LandingRoute], [Workspace];");
    }
};
