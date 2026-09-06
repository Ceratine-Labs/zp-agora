<?php

use App\Support\Database\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * Roles, permissions and the grant between them (T008).
 *
 * THE SLUG IS THREE PARTS: {module}.{resource}.{action} — `cash.dropsafe.view`.
 *
 * The task text says `module.action` and feature-rules "Proposed §D" says
 * `{module}.{resource}.{action}`; they disagree and §D wins, because the
 * rulebook elsewhere is unambiguous that "edit, save and delete each sit behind
 * their own permission PER RESOURCE". Two parts cannot express that — a module
 * with two grids would need `cash.view` to mean both, or invent a slug per
 * screen and lose the module prefix. Three parts also make the Auditor role
 * expressible as `*.*.view` plus `audit.*`, which is what the plan already says
 * that role is. Following §D rather than deciding it: the ruling is Ryan's and
 * this migration is the proposal made concrete.
 *
 * WHY UserRole EXISTS WHEN agora.User ALREADY HAS RoleId. RoleId is one role
 * per person and the landing route reads it. Real people here hold more than
 * one hat — the 88 legacy users include nine Super Users who are also branch
 * operators. UserRole is the grant table and the only thing PermissionService
 * reads. RoleId stays, backfilled and kept as the PRIMARY role, because
 * User::landingRoute() depends on it and a person needs one answer to "where do
 * I land". IsPrimary on UserRole says which grant that is, so the two cannot
 * drift silently: agora.usp_Core_SetUserRoles is the only writer and it sets
 * both.
 *
 * Every table carries BranchId first, like everything else. Roles and
 * permissions are group-level rows, so they carry the group entity's id (2)
 * rather than NULL — the same rule that stopped legacy rows becoming
 * unreportable.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = config('agora.schema');

        /*
         * A permission is a fact about the system, not about a customer. The
         * three parts are stored separately as well as joined into Code so a
         * screen can list "everything in the cash module" without parsing a
         * string, and so the wildcard expansion in PermissionService is an
         * index seek rather than a LIKE.
         */
        MigrationHelper::table('Permission', function (Blueprint $table) {
            $table->string('Code', 120);
            $table->string('Module', 40);
            $table->string('Resource', 60);
            $table->string('Action', 40);
            $table->string('Name', 120);
            $table->string('Description', 255)->nullable();
            $table->integer('SortOrder')->default(0);
        });
        MigrationHelper::naturalKey('Permission', ['BranchId', 'Code']);

        MigrationHelper::table('RolePermission', function (Blueprint $table) {
            $table->bigInteger('RoleId');
            $table->bigInteger('PermissionId');
        });
        MigrationHelper::naturalKey('RolePermission', ['BranchId', 'RoleId', 'PermissionId']);

        /*
         * IsPrimary is the role the landing route comes from. Exactly one grant
         * per user carries it; the filtered unique index below is what makes
         * "exactly one" a database fact rather than a convention somebody
         * remembers. SQL Server allows this because the index is filtered.
         */
        MigrationHelper::table('UserRole', function (Blueprint $table) {
            $table->bigInteger('UserId');
            $table->bigInteger('RoleId');
            $table->boolean('IsPrimary')->default(false);
        });
        MigrationHelper::naturalKey('UserRole', ['BranchId', 'UserId', 'RoleId']);

        DB::statement("
            CREATE UNIQUE INDEX [UX_UserRole_OnePrimary]
                ON [{$schema}].[UserRole] ([BranchId], [UserId])
                WHERE [IsPrimary] = 1;
        ");

        /*
         * Backfill from the RoleId that is already there, so nobody loses the
         * access they have today and the two representations start in step.
         * Group entity id for BranchId — a role grant is not site-specific.
         */
        $group = (int) config('agora.group_branch_id', 2);

        DB::statement("
            INSERT INTO [{$schema}].[UserRole] ([BranchId], [UserId], [RoleId], [IsPrimary], [CreatedAt])
            SELECT {$group}, u.[Id], u.[RoleId], 1, SYSDATETIME()
            FROM [{$schema}].[User] u
            WHERE u.[RoleId] IS NOT NULL
              AND NOT EXISTS (
                    SELECT 1 FROM [{$schema}].[UserRole] r
                    WHERE r.[UserId] = u.[Id] AND r.[RoleId] = u.[RoleId]
              );
        ");

        MigrationHelper::recordVersion(
            '1.3',
            'RBAC: Permission, RolePermission, UserRole. Slugs are module.resource.action per feature-rules §D.'
        );
    }

    public function down(): void
    {
        $schema = config('agora.schema');

        DB::statement("DROP INDEX IF EXISTS [UX_UserRole_OnePrimary] ON [{$schema}].[UserRole];");
        MigrationHelper::drop('UserRole');
        MigrationHelper::drop('RolePermission');
        MigrationHelper::drop('Permission');
    }
};
