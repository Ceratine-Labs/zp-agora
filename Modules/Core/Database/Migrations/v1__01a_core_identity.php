<?php

use App\Support\Database\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Core — what identity needs beyond signing in (slot 01a).
 *
 * A LETTERED follow-on rather than an edit to v1__01_core_tables, because
 * `agora.User` already carries the administrator seeded on the customer's
 * instance. The one-time consolidation of 01a-01d on 4 Sep 2026 was allowed
 * precisely because nothing in those tables was business data yet; a working
 * credential is, so from here the rule in CLAUDE.md applies without exception.
 *
 * Three things arrive:
 *
 *  - **`agora.UserActivity`** — who signed in, from where, and when. Append
 *    only, and deliberately keyless beyond (BranchId, Id): a log has no
 *    natural key, and inventing one would either reject a legitimate second
 *    event in the same second or be a unique index over columns nobody
 *    searches by. T010 grows this table into the wider audit trail; the
 *    columns here are the ones a sign-in row actually needs.
 *
 *  - **`agora.PasswordReset`** — the forgot-password channel. Laravel's own
 *    `password_reset_tokens` is not used: it is snake_case, unqualified, and
 *    branch-less, so it would be the one table in the system that breaks all
 *    three of the estate's conventions. One live request per address, which is
 *    why (BranchId, EmailAddress) is the natural key and a new request
 *    replaces the old one rather than queueing behind it.
 *
 *  - **Four columns on `agora.User`** — the ones the SS_Users migration
 *    carries that the baseline had nowhere to put: the legacy user code, the
 *    head-office/branch user type (both the derived value and the legacy text
 *    it came from, so the derivation is auditable), and the two columns that
 *    make "passwords reset on first login" a fact rather than a hope.
 *
 * And `agora.vw_LegacyUser`, the read over the old estate. Agora never writes
 * there; SS_Users is 85 rows of the customer's live system and this view is
 * the only way Agora sees it.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Sign-in rows (plan §T007), and the seam T010 widens.
         *
         * OccurredAt is stamped by the procedure rather than defaulted here:
         * the value that matters is when the event happened, and a column
         * default records when the row was written, which is the same thing
         * right up until it is not.
         */
        MigrationHelper::table('UserActivity', function (Blueprint $table) {
            $table->bigInteger('UserId');
            // 'signin', 'signout', 'signin-refused', 'password-reset'. Text
            // rather than a code table: this is a log, and a join to read it
            // costs more than the 40 characters saves.
            $table->string('Activity', 40);
            $table->string('Detail', 400)->nullable();
            // 45 characters holds an IPv6 address in full.
            $table->string('IpAddress', 45)->nullable();
            $table->string('UserAgent', 300)->nullable();
            $table->dateTime('OccurredAt', 0);

            // How this table is actually read: one person, most recent first.
            $table->index(['BranchId', 'UserId', 'OccurredAt'], 'IX_UserActivity_User');
        });

        /*
         * Forgot-password.
         *
         * The token is stored HASHED. A reset token is a bearer credential for
         * the length of its life, and a database this one shares an instance
         * with 249 GB of production data is not a place to keep bearer
         * credentials in the clear.
         *
         * Keyed by address rather than by user id on purpose: the request
         * arrives before anyone is identified, and an address that matches no
         * user must be indistinguishable from one that does.
         */
        MigrationHelper::table('PasswordReset', function (Blueprint $table) {
            $table->string('EmailAddress', 160);
            $table->string('TokenHash', 255);
            $table->dateTime('ExpiresAt', 0);
            $table->dateTime('UsedAt', 0)->nullable();
            $table->string('RequestedIp', 45)->nullable();
        });
        MigrationHelper::naturalKey('PasswordReset', ['BranchId', 'EmailAddress']);

        /*
         * What SS_Users carries that the baseline had nowhere to put.
         *
         * UserType is the derived 'ho' / 'branch'; LegacyUserType is the text
         * SS_UserType actually held. Keeping both is the difference between a
         * derivation somebody can check and a value somebody has to trust —
         * and the mapping rule is a rule this checkout could not verify
         * against the customer's own SS_UserType rows.
         */
        $schema = config('agora.schema');

        Schema::table("{$schema}.User", function (Blueprint $table) {
            // dbo.sp_csGetUser returns Autoidx as 'UserCode', so this is the
            // number the customer already says out loud on the phone.
            $table->string('UserCode', 40)->nullable();
            $table->string('UserType', 10)->nullable();
            $table->string('LegacyUserType', 50)->nullable();
            // Set by the migration procedure and cleared by a successful
            // reset. A migrated user also has an unusable PasswordHash, so
            // this is the second lock on the same door rather than the only
            // one.
            $table->boolean('MustChangePassword')->default(false);
            $table->dateTime('PasswordChangedAt', 0)->nullable();
        });

        /*
         * The legacy identity read.
         *
         * PumpIT is NAMED across databases, from config, for the reasons
         * v1__01's vw_Branch sets out. Four things happen here that the
         * procedure would otherwise have to repeat:
         *
         *  - Autoidx becomes LegacyUserId and, as text, UserCode.
         *  - The address is trimmed and NULLIF'd, so '   ' and NULL are one
         *    case downstream instead of two.
         *  - isLocked (BIT on some restores, INT on others) becomes one
         *    honest pair of flags. The legacy table has no "active" column at
         *    all: locked is the only signal that somebody has left, so
         *    IsActive is its inverse and the report says as much.
         *  - UserType is derived from SS_UserType's TEXT, not from its id.
         *    The ids are not stable across the estate's history and this
         *    checkout has never seen the rows; the text is at least readable
         *    in the report beside what it produced.
         *
         * LEFT JOIN, always: a user whose UserTypeId matches nothing must
         * still appear in the report. An INNER JOIN here would silently drop
         * people, which is the exact failure this migration exists to avoid.
         */
        $erp = config('agora.source_databases.erp');

        DB::unprepared("
            CREATE OR ALTER VIEW [{$schema}].[vw_LegacyUser] AS
            SELECT
                u.Autoidx                                        AS LegacyUserId,
                CONVERT(NVARCHAR(40), u.Autoidx)                 AS UserCode,
                NULLIF(LTRIM(RTRIM(u.UserName)), '')             AS UserName,
                NULLIF(LTRIM(RTRIM(u.UserEmailAddress)), '')     AS EmailAddress,
                u.UserTypeId,
                t.UserType                                       AS LegacyUserType,
                CASE
                    WHEN t.UserType IS NULL THEN NULL
                    WHEN t.UserType LIKE '%branch%' OR t.UserType LIKE '%site%' THEN 'branch'
                    ELSE 'ho'
                END                                              AS UserType,
                CASE WHEN ISNULL(u.isLocked, 0) = 1
                     THEN CAST(0 AS BIT) ELSE CAST(1 AS BIT) END AS IsActive,
                CASE WHEN ISNULL(u.isLocked, 0) = 1
                     THEN CAST(1 AS BIT) ELSE CAST(0 AS BIT) END AS IsLocked,
                u.LoggedOnDate                                   AS LastSignInAt
            FROM [{$erp}].dbo.SS_Users u
            LEFT JOIN [{$erp}].dbo.SS_UserType t ON t.UserTypeId = u.UserTypeId;
        ");

        MigrationHelper::recordVersion(
            '1.1',
            'Identity: sign-in activity, the forgot-password channel, the SS_Users columns on agora.User, '
            .'and the legacy user view usp_Core_MigrateUsers reads.'
        );
    }

    public function down(): void
    {
        $schema = config('agora.schema');

        DB::unprepared("DROP VIEW IF EXISTS [{$schema}].[vw_LegacyUser];");

        Schema::table("{$schema}.User", function (Blueprint $table) {
            $table->dropColumn([
                'UserCode', 'UserType', 'LegacyUserType',
                'MustChangePassword', 'PasswordChangedAt',
            ]);
        });

        MigrationHelper::drop('PasswordReset');
        MigrationHelper::drop('UserActivity');
    }
};
