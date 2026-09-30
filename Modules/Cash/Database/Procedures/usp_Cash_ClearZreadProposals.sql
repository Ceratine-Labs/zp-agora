/* ============================================================================
   agora.usp_Cash_ClearZreadProposals

   WHAT IT IS FOR
   "Clear proposals" on the ZREAD Allocation screen, and "Clear" on one row's
   panel: throw away what is staged and not yet saved.

   Scope is one site's period (and optionally one location). Pass the Z-read's
   key as well to clear just that one.

   NOTHING IS DELETED. A staged row is set to Status 'cleared' and stamped with
   who and when, so "who staged this, and who took it back" can still be
   answered — which is the whole of the audit a draft needs. An ALLOCATION is
   never touched here: a saved allocation is superseded by another, never
   cleared.

   Writes:  agora.ZreadAllocation — nothing outside the Agora database.

   Refusals:
     AGORA:CASH_ZREAD_USER   no user
     AGORA:CASH_ZREAD_RANGE  no period
   ============================================================================ */
CREATE OR ALTER PROCEDURE [agora].[usp_Cash_ClearZreadProposals]
    @BranchId    INT,
    @DateFrom    DATE,
    @DateTo      DATE,
    @Location    NVARCHAR(10) = NULL,
    @EodCounter  NVARCHAR(8)  = NULL,
    @TerminalNo  NVARCHAR(3)  = NULL,
    @LogFile     NVARCHAR(10) = NULL,
    @PosUserId   NVARCHAR(6)  = NULL,
    @UserId      INT
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    IF @UserId IS NULL
        THROW 51000, 'AGORA:CASH_ZREAD_USER:Clearing is attributed to a person, and none was given.', 1;

    IF @DateFrom IS NULL OR @DateTo IS NULL OR @DateTo < @DateFrom
        THROW 51000, 'AGORA:CASH_ZREAD_RANGE:Give the period to clear, from before to.', 1;

    SET @Location = NULLIF(LTRIM(RTRIM(@Location)), '');
    DECLARE @One BIT = CASE WHEN @LogFile IS NOT NULL THEN 1 ELSE 0 END;
    DECLARE @Now DATETIME2(0) = SYSUTCDATETIME();
    DECLARE @Cleared INT;

    BEGIN TRY
        BEGIN TRANSACTION;

        UPDATE a
           SET [Status] = 'cleared',
               Note = 'Cleared before it was saved.',
               UpdatedAt = @Now, UpdatedBy = @UserId
        FROM agora.ZreadAllocation a
        WHERE a.BranchId = @BranchId
          AND a.[Status] = 'proposed'
          AND a.ZReadDate BETWEEN @DateFrom AND @DateTo
          AND (@Location IS NULL OR a.Location = @Location)
          AND (@One = 0 OR (a.EodCounter = LTRIM(RTRIM(@EodCounter)) AND a.TerminalNo = LTRIM(RTRIM(@TerminalNo))
                            AND a.LogFile = LTRIM(RTRIM(@LogFile)) AND a.PosUserId = LTRIM(RTRIM(@PosUserId))));

        SET @Cleared = @@ROWCOUNT;

        COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        THROW;
    END CATCH;

    SELECT
        CAST(1 AS BIT) AS Ok,
        CASE WHEN @Cleared > 0 THEN 'CASH_ZREAD_CLEARED' ELSE 'CASH_ZREAD_NOTHING_STAGED' END AS Code,
        CONVERT(NVARCHAR(400), CASE WHEN @Cleared = 0 THEN 'Nothing was staged, so nothing was cleared.'
             ELSE 'Cleared ' + CONVERT(NVARCHAR(10), @Cleared) + ' staged proposal'
                  + CASE WHEN @Cleared = 1 THEN '' ELSE 's' END + '. No allocation was touched.' END) AS Message,
        CAST(NULL AS BIGINT) AS Id,
        @Cleared AS Cleared;
END
