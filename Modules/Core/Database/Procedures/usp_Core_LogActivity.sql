/* ----------------------------------------------------------------------------
   Records one thing a person did, in agora.UserActivity.

   Read by: nobody yet, and Setup -> Governance -> Audit trail (T010) once that
   screen exists. Written by every sign-in, sign-out and password reset.

   It is a procedure rather than an Eloquent create for the reason plan 3.4
   gives: the rule about what a sign-in does lives in one place, and the day
   T010 adds an agora.AuditLog row alongside this one it adds it HERE, inside
   this transaction, rather than in whichever controller happened to remember.

   A 'signin' also stamps agora.User.LastSignInAt. That pairing is the rule —
   the log row and the column can never disagree, because one statement writes
   both and XACT_ABORT rolls back both.

   Refusals:
     AGORA:CORE_ACTIVITY_REQUIRED  the activity name is blank
     AGORA:CORE_UNKNOWN_USER       no such user, in any branch
   ---------------------------------------------------------------------------- */
CREATE OR ALTER PROCEDURE agora.usp_Core_LogActivity
    @BranchId   INT,
    @UserId     BIGINT,
    @Activity   NVARCHAR(40),
    @Detail     NVARCHAR(400) = NULL,
    @IpAddress  NVARCHAR(45)  = NULL,
    @UserAgent  NVARCHAR(300) = NULL,
    @OccurredAt DATETIME2(0)  = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    SET @Activity = NULLIF(LTRIM(RTRIM(@Activity)), '');

    IF @Activity IS NULL
    BEGIN
        DECLARE @noActivity NVARCHAR(400) =
            'AGORA:CORE_ACTIVITY_REQUIRED:An activity row has to say what happened.';
        THROW 51000, @noActivity, 1;
    END

    /* Across every branch on purpose. A head-office user's row carries the
       group entity id and a branch user's carries their site, so scoping the
       existence check to @BranchId would refuse to log a real person the
       moment the caller passed the branch they were looking AT rather than
       the branch they belong TO. */
    IF NOT EXISTS (SELECT 1 FROM agora.[User] WHERE Id = @UserId)
    BEGIN
        DECLARE @noUser NVARCHAR(400) =
            'AGORA:CORE_UNKNOWN_USER:User ' + CONVERT(NVARCHAR(20), @UserId) + ' is not in agora.User.';
        THROW 51000, @noUser, 1;
    END

    DECLARE @now DATETIME2(0) = ISNULL(@OccurredAt, SYSDATETIME());

    INSERT INTO agora.UserActivity
        (BranchId, UserId, Activity, Detail, IpAddress, UserAgent, OccurredAt, CreatedAt, CreatedBy)
    VALUES
        (@BranchId, @UserId, @Activity, @Detail, @IpAddress, @UserAgent, @now, @now, @UserId);

    DECLARE @id BIGINT = SCOPE_IDENTITY();

    IF @Activity = 'signin'
        UPDATE agora.[User]
           SET LastSignInAt = @now,
               UpdatedAt    = @now,
               UpdatedBy    = @UserId
         WHERE Id = @UserId;

    SELECT
        CAST(1 AS BIT)                 AS Ok,
        'CORE_ACTIVITY_LOGGED'         AS Code,
        'Activity recorded.'           AS Message,
        @id                            AS Id;
END
