/* ----------------------------------------------------------------------------
   Brings the customer's 85 PumpIT users across into agora.User.

   Read by: whoever is doing the cutover, through
   `php artisan agora:migrate-users`. It is safe to run at any time and safe to
   run twice — the second run reports 'update' where the first reported
   'migrate', and writes nothing anyone would notice.

   It DEFAULTS TO A DRY RUN. @Apply = 0 classifies every legacy row, returns
   the report and the summary, and touches nothing. @Apply = 1 does the same
   work and then writes, inside one transaction. Look at the report first: the
   number that matters is not how many rows moved, it is how many did not and
   why.

   NOTHING IN THE CUSTOMER'S DATABASE IS WRITTEN. dbo.SS_Users is read through
   agora.vw_LegacyUser and only read. In particular dbo.SS_Users.Password —
   varchar(50), plaintext or a legacy hash, nobody now knows which — is never
   selected, never carried and never converted. Every migrated user arrives
   with an unusable PasswordHash and MustChangePassword set, and has to come in
   through forgot-password. That is the deliberate behaviour, not a gap.

   Two result sets, in this order:

     1. The report. One row per legacy user: what it is, what happened to it,
        and why. Action is 'migrate' | 'update' | 'skip'; ReasonCode says which
        kind, and Reason says it in English.
     2. The summary. One row: Considered, Migrated, Updated, Unmatched
        (no address, or an address with no @ in it), Duplicate, Blocked, and
        UnmappedUserType.

   Refusals:
     AGORA:CORE_UNKNOWN_BRANCH  @BranchId is not a row in agora.Branch

   The head-office / branch mapping is derived from SS_UserType's TEXT and is
   the one rule in here that has not been checked against the customer's own
   data — the report prints LegacyUserType beside the derived UserType for
   exactly that reason. If the real rows say something else, the CASE in
   agora.vw_LegacyUser is the single place to correct it.
   ---------------------------------------------------------------------------- */
CREATE OR ALTER PROCEDURE agora.usp_Core_MigrateUsers
    @BranchId INT,
    @Apply    BIT    = 0,
    @UserId   BIGINT = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    IF NOT EXISTS (SELECT 1 FROM agora.Branch WHERE BranchId = @BranchId)
    BEGIN
        DECLARE @noBranch NVARCHAR(400) =
            'AGORA:CORE_UNKNOWN_BRANCH:Branch ' + CONVERT(NVARCHAR(20), @BranchId)
            + ' is not in agora.Branch. Users are group rows and carry the group entity id, never NULL.';
        THROW 51000, @noBranch, 1;
    END

    DECLARE @now DATETIME2(0) = SYSDATETIME();

    DECLARE @candidate TABLE (
        LegacyUserId   INT           NOT NULL PRIMARY KEY,
        UserCode       NVARCHAR(40)  NOT NULL,
        UserName       NVARCHAR(80)  NOT NULL,
        EmailAddress   NVARCHAR(160) NULL,
        LegacyUserType NVARCHAR(50)  NULL,
        UserType       NVARCHAR(10)  NOT NULL,
        IsActive       BIT           NOT NULL,
        IsLocked       BIT           NOT NULL,
        LastSignInAt   DATETIME2(0)  NULL,
        DuplicateRank  INT           NOT NULL,
        DuplicateCount INT           NOT NULL,
        Action         NVARCHAR(20)  NULL,
        ReasonCode     NVARCHAR(30)  NULL,
        Reason         NVARCHAR(300) NULL
    );

    /* One pass over the legacy view. The address is lower-cased here so the
       duplicate window, the existence check and the stored value all agree —
       the instance collates case-insensitively, so 'TEST-Shared@' and
       'test-shared@' are one address and must be reported as one. */
    INSERT INTO @candidate
        (LegacyUserId, UserCode, UserName, EmailAddress, LegacyUserType, UserType,
         IsActive, IsLocked, LastSignInAt, DuplicateRank, DuplicateCount)
    SELECT
        v.LegacyUserId,
        v.UserCode,
        LEFT(ISNULL(v.UserName, 'Legacy user ' + v.UserCode), 80),
        LOWER(v.EmailAddress),
        v.LegacyUserType,
        ISNULL(v.UserType, 'ho'),
        v.IsActive,
        v.IsLocked,
        v.LastSignInAt,
        CASE WHEN v.EmailAddress IS NULL THEN 1
             ELSE ROW_NUMBER() OVER (PARTITION BY LOWER(v.EmailAddress) ORDER BY v.LegacyUserId) END,
        CASE WHEN v.EmailAddress IS NULL THEN 1
             ELSE COUNT(*)     OVER (PARTITION BY LOWER(v.EmailAddress)) END
    FROM agora.vw_LegacyUser v;

    /* ---- classify, most specific reason first ----------------------------- */

    UPDATE @candidate
       SET Action = 'skip', ReasonCode = 'no-email',
           Reason = 'No email address on the legacy row. Agora signs in by email, so this person has nothing to sign in with.'
     WHERE EmailAddress IS NULL;

    UPDATE @candidate
       SET Action = 'skip', ReasonCode = 'bad-email',
           Reason = 'Legacy address has no @ in it, so no reset mail could ever reach it.'
     WHERE Action IS NULL AND CHARINDEX('@', EmailAddress) = 0;

    UPDATE @candidate
       SET Action = 'skip', ReasonCode = 'duplicate-email',
           Reason = CONVERT(NVARCHAR(10), DuplicateCount)
                  + ' legacy rows share this address and agora.User is unique on (BranchId, EmailAddress). '
                  + 'The lowest Autoidx was taken; this row was not.'
     WHERE Action IS NULL AND DuplicateRank > 1;

    /* A soft-deleted Agora user still occupies the unique index, so the insert
       would fail rather than quietly do nothing. Reported, not resurrected:
       undeleting somebody is a decision, not a side effect of a migration. */
    UPDATE c
       SET Action = 'skip', ReasonCode = 'blocked',
           Reason = 'An Agora user with this address exists but is deleted (Id '
                  + CONVERT(NVARCHAR(20), u.Id) + '). Restore or rename it first.'
      FROM @candidate c
      JOIN agora.[User] u
        ON u.BranchId = @BranchId
       AND u.EmailAddress = c.EmailAddress
       AND u.DeletedAt IS NOT NULL
     WHERE c.Action IS NULL;

    UPDATE c
       SET Action = 'update', ReasonCode = 'exists',
           Reason = 'An Agora user already has this address (Id ' + CONVERT(NVARCHAR(20), u.Id)
                  + '). Name, user type and the legacy link are refreshed; password, role and access are left alone.'
      FROM @candidate c
      JOIN agora.[User] u
        ON u.BranchId = @BranchId
       AND u.EmailAddress = c.EmailAddress
       AND u.DeletedAt IS NULL
     WHERE c.Action IS NULL;

    UPDATE @candidate
       SET Action = 'migrate', ReasonCode = 'new',
           Reason = 'New Agora user. No password is carried across, so it must be set through forgot-password before this account can sign in.'
     WHERE Action IS NULL;

    /* ---- write, if asked -------------------------------------------------- */

    IF @Apply = 1
    BEGIN
        BEGIN TRANSACTION;

        INSERT INTO agora.[User]
            (BranchId, UserName, EmailAddress, PasswordHash, RoleId, HomeBranchId,
             IsActive, IsLocked, LastSignInAt, LegacyUserId,
             UserCode, UserType, LegacyUserType, MustChangePassword,
             CreatedAt, CreatedBy, UpdatedAt, UpdatedBy)
        SELECT
            @BranchId,
            c.UserName,
            c.EmailAddress,
            /* Not a password, and not a hash of one. No bcrypt digest can
               equal this string, so every check against it fails — which is
               the whole point of "passwords reset on first login". */
            '!reset-required',
            /* No role and no home branch. T008 proposes role membership from
               BRN_ZUserRights for Ryan to review; guessing one here would
               hand somebody permissions nobody granted. */
            NULL,
            NULL,
            c.IsActive,
            c.IsLocked,
            c.LastSignInAt,
            c.LegacyUserId,
            c.UserCode,
            c.UserType,
            c.LegacyUserType,
            1,
            @now, @UserId, @now, @UserId
        FROM @candidate c
        WHERE c.Action = 'migrate';

        /* Deliberately narrow. IsActive, IsLocked, RoleId, HomeBranchId and
           PasswordHash are NOT refreshed from the legacy row: an Agora account
           somebody is already using must not have its access changed by a
           re-run of an import. */
        UPDATE u
           SET u.UserName       = c.UserName,
               u.UserType       = c.UserType,
               u.LegacyUserType = c.LegacyUserType,
               u.LegacyUserId   = c.LegacyUserId,
               u.UserCode       = c.UserCode,
               u.UpdatedAt      = @now,
               u.UpdatedBy      = @UserId
          FROM agora.[User] u
          JOIN @candidate c
            ON c.EmailAddress = u.EmailAddress
         WHERE u.BranchId = @BranchId
           AND u.DeletedAt IS NULL
           AND c.Action = 'update';

        COMMIT TRANSACTION;
    END

    /* ---- result set 1: the report ----------------------------------------- */

    SELECT
        c.LegacyUserId,
        c.UserCode,
        c.UserName,
        c.EmailAddress,
        c.LegacyUserType,
        c.UserType,
        c.IsActive,
        c.IsLocked,
        c.LastSignInAt,
        c.DuplicateCount,
        c.Action,
        c.ReasonCode,
        c.Reason
    FROM @candidate c
    ORDER BY
        CASE c.Action WHEN 'skip' THEN 0 WHEN 'update' THEN 1 ELSE 2 END,
        c.LegacyUserId;

    /* ---- result set 2: the summary ---------------------------------------- */

    SELECT
        CAST(@Apply AS BIT)                                                   AS Applied,
        @BranchId                                                             AS BranchId,
        COUNT(*)                                                              AS Considered,
        SUM(CASE WHEN c.Action = 'migrate' THEN 1 ELSE 0 END)                 AS Migrated,
        SUM(CASE WHEN c.Action = 'update'  THEN 1 ELSE 0 END)                 AS Updated,
        SUM(CASE WHEN c.ReasonCode IN ('no-email', 'bad-email') THEN 1 ELSE 0 END) AS Unmatched,
        SUM(CASE WHEN c.ReasonCode = 'duplicate-email' THEN 1 ELSE 0 END)     AS Duplicate,
        SUM(CASE WHEN c.ReasonCode = 'blocked' THEN 1 ELSE 0 END)             AS Blocked,
        SUM(CASE WHEN c.LegacyUserType IS NULL THEN 1 ELSE 0 END)             AS UnmappedUserType
    FROM @candidate c;
END
