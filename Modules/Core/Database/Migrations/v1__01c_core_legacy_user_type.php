<?php

use App\Support\Database\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Derive head-office vs branch from what the legacy data actually says.
 *
 * v1__01a's vw_LegacyUser read SS_UserType's TEXT and called a user 'branch'
 * when it matched '%branch%' or '%site%'. That was written without ever having
 * seen the table, and the table was read for the first time on 6 September
 * 2026, against the customer's instance. It holds four rows:
 *
 *     Super User · Standard User · Read Only User · User
 *
 * None of them contains either word, so EVERY user came out as 'ho'. The
 * derivation was not merely unverified, it was uniformly wrong — and worse, it
 * was wrong about the wrong column: SS_UserType is a PRIVILEGE LEVEL, not a
 * location. There is nothing in it that could ever have answered the question.
 *
 * Where the answer actually lives is SS_UserBranches — 1,189 grants across 86
 * of the 88 users. The distribution is unambiguous:
 *
 *     1 branch      27 users     a site
 *     2-29 branches 47 users     head office or regional
 *     31 branches   12 users     head office, everything
 *
 * So: exactly one grant is a branch user, anything else is head office, and no
 * grant at all leaves it NULL for a person to decide rather than guessing on
 * their behalf. Two of the 88 have no grants.
 *
 * LegacyUserType keeps the privilege text, because it is genuinely useful — it
 * is what the initial role proposal is built from, where Super User maps to an
 * administrator and Standard User does not.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = config('agora.schema');
        $erp = config('agora.source_databases.erp');

        DB::unprepared("
            CREATE OR ALTER VIEW [{$schema}].[vw_LegacyUser] AS
            SELECT
                u.Autoidx                                        AS LegacyUserId,
                CAST(u.Autoidx AS NVARCHAR(40))                   AS UserCode,
                NULLIF(LTRIM(RTRIM(u.UserName)), '')             AS UserName,
                NULLIF(LTRIM(RTRIM(u.UserEmailAddress)), '')     AS EmailAddress,
                u.UserTypeId,
                t.UserType                                       AS LegacyUserType,
                /*
                 * One branch granted = a site. More than one = head office.
                 * None = unknown, and NULL says so rather than guessing.
                 */
                CASE
                    WHEN b.BranchCount IS NULL THEN NULL
                    WHEN b.BranchCount = 1 THEN 'branch'
                    ELSE 'ho'
                END                                              AS UserType,
                ISNULL(b.BranchCount, 0)                         AS BranchGrantCount,
                CASE WHEN ISNULL(u.isLocked, 0) = 1
                     THEN CAST(0 AS BIT) ELSE CAST(1 AS BIT) END AS IsActive,
                CASE WHEN ISNULL(u.isLocked, 0) = 1
                     THEN CAST(1 AS BIT) ELSE CAST(0 AS BIT) END AS IsLocked,
                u.LoggedOnDate                                   AS LastSignInAt
            FROM [{$erp}].dbo.SS_Users u
            LEFT JOIN [{$erp}].dbo.SS_UserType t
                   ON t.UserTypeId = u.UserTypeId
            LEFT JOIN (
                SELECT UserId, COUNT(*) AS BranchCount
                FROM [{$erp}].dbo.SS_UserBranches
                GROUP BY UserId
            ) b ON b.UserId = u.Autoidx;
        ");

        MigrationHelper::recordVersion(
            '1.4',
            'vw_LegacyUser derives ho/branch from SS_UserBranches grant count, not from SS_UserType text.'
        );
    }

    public function down(): void
    {
        // Forward-only; the previous body is in v1__01a and was wrong.
    }
};
