<?php

use App\Support\Database\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * A permission granted to ONE PERSON, beside the ones their roles carry.
 *
 * A LETTERED follow-on, not an edit to v1__01b, because agora.UserRole and
 * agora.RolePermission already carry the grants the customer's instance runs
 * on. From 01a onwards the rule in CLAUDE.md applies without exception.
 *
 * WHY THIS EXISTS WHEN ROLES ALREADY DO. Six roles cover the shape of the
 * business, not its exceptions, and the exceptions are real: the finance
 * controller who must also reverse a reconciliation, the one branch manager
 * who runs the group's fuel report. Today the only way to say that is to grant
 * them a whole second role, which hands over everything else that role carries
 * — the classic way access accumulates until everyone is an administrator.
 * A named permission on a named person is the smaller, auditable answer.
 *
 * IT IS ADDITIVE ONLY. There is no deny row. A permission a person must NOT
 * have is removed by taking away the role that carries it, because a deny that
 * beats a role makes "what may this person do" a question you cannot answer by
 * reading their roles — and this screen exists to make that question readable.
 * If a deny is ever genuinely needed it is a new column and a new decision, not
 * something to leave a hole for now.
 *
 * PermissionService reads role grants UNION these, so a direct grant matches
 * through exactly the same wildcard expansion. A row here is a CONCRETE
 * permission id rather than a pattern: patterns are how a ROLE is defined
 * (RolePermissionSeeder writes the intent), and a person is granted the thing
 * itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * BranchId is the group entity, like every other grant table. A
         * permission is not site-specific — the SITES a person may see are
         * agora.UserBranch, which is a different question with a different
         * table, and conflating the two is how a head-office administrator
         * would vanish from their own screen.
         */
        MigrationHelper::table('UserPermission', function (Blueprint $table) {
            $table->bigInteger('UserId');
            $table->bigInteger('PermissionId');
        });
        MigrationHelper::naturalKey('UserPermission', ['BranchId', 'UserId', 'PermissionId']);

        MigrationHelper::recordVersion(
            '1.5',
            'agora.UserPermission: a permission granted to one person, additively, beside their roles.'
        );
    }

    public function down(): void
    {
        MigrationHelper::drop('UserPermission');
    }
};
