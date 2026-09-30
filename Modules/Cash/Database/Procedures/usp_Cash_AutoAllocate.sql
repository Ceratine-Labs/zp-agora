/* ============================================================================
   agora.usp_Cash_AutoAllocate

   WHAT IT IS FOR
   The Auto-allocate press on the ZREAD Allocation screen. Stages every open
   Z-read in the scope whose proposal is at or above the auto-accept threshold,
   so that the person's job is to look at what is left and press Update ZREAD —
   "only touch the ones it cannot call".

   IT STAGES; IT DOES NOT SAVE. A staged row is agora.ZreadAllocation with
   Status 'proposed' and Source 'auto'. Nothing is an allocation until
   agora.usp_Cash_CommitZreadAllocation runs, which is a separate press with a
   confirmation in front of it. Clear proposals throws the staging away.

   WHAT IS STAGED, AND WHAT IS NOT
     · open Z-reads only — never one already allocated or already staged;
     · complete proposals only — till, shift AND person. A "likely" whose
       operator splits its shifts is not staged whatever the threshold,
       because staging it would be guessing the shift;
     · at or above @Threshold: 'certain' (the shipped default), 'likely' or
       'review'. 'manual' is never staged — it is the absence of a proposal.

   The threshold is config('cash.zread.auto_accept') today and moves to
   agora.Setting when T026 lands (plan §3.4 "reads the auto-accept threshold
   from agora.Setting"). It is a parameter here so the procedure does not
   care where it came from.

   The proposals come from agora.usp_Cash_ProposeZreadAllocation, whole; this
   procedure never re-derives a confidence. ⚠ #aa_p is that procedure's result
   set, column for column — change them together.

   Writes:  agora.ZreadAllocation (Status 'proposed', Source 'auto') — nothing
            outside the Agora database.
   Returns: the writer's status row, plus the counts the screen reports.

   Refusals (and everything the proposer refuses):
     AGORA:CASH_ZREAD_THRESHOLD  a threshold that is not certain/likely/review
     AGORA:CASH_ZREAD_USER       no user to attribute the staging to
   ============================================================================ */
CREATE OR ALTER PROCEDURE [agora].[usp_Cash_AutoAllocate]
    @BranchId      INT,
    @DateFrom      DATE,
    @DateTo        DATE,
    @Location      NVARCHAR(10)  = NULL,
    @Threshold     NVARCHAR(10)  = 'certain',
    @CertainShare  DECIMAL(5,4)  = 0.75,
    @LikelyShare   DECIMAL(5,4)  = 0.60,
    @ShiftShare    DECIMAL(5,4)  = 0.60,
    @HistoryDays   INT           = 90,
    @DayShiftNo    INT           = 1,
    @NightShiftNo  INT           = 3,
    @SequenceExcluded NVARCHAR(200) = 'ARCH',
    @UserId        INT
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    IF @Threshold IS NULL OR @Threshold NOT IN ('certain', 'likely', 'review')
        THROW 51000, 'AGORA:CASH_ZREAD_THRESHOLD:The auto-accept threshold must be certain, likely or review.', 1;

    IF @UserId IS NULL
        THROW 51000, 'AGORA:CASH_ZREAD_USER:Staging is attributed to a person, and none was given.', 1;

    DECLARE @Rank INT = CASE @Threshold WHEN 'certain' THEN 3 WHEN 'likely' THEN 2 ELSE 1 END;
    DECLARE @Now  DATETIME2(0) = SYSUTCDATETIME();

    CREATE TABLE #aa_p (
        BranchId INT, ZReadDate DATE, Location NVARCHAR(10) COLLATE DATABASE_DEFAULT, EodCounter NVARCHAR(8) COLLATE DATABASE_DEFAULT,
        TerminalNo NVARCHAR(3) COLLATE DATABASE_DEFAULT, LogFile NVARCHAR(10) COLLATE DATABASE_DEFAULT,
        PosUserId NVARCHAR(6) COLLATE DATABASE_DEFAULT, Sales DECIMAL(18,2),
        [State] NVARCHAR(10) COLLATE DATABASE_DEFAULT, AllocatedBy NVARCHAR(10) COLLATE DATABASE_DEFAULT, IsPartlyAllocated BIT, AllocationId BIGINT,
        TillNo INT, ShiftNo INT, EmployeeCode NVARCHAR(22) COLLATE DATABASE_DEFAULT,
        TillDescription NVARCHAR(100) COLLATE DATABASE_DEFAULT, ShiftDescription NVARCHAR(100) COLLATE DATABASE_DEFAULT,
        EmployeeName NVARCHAR(120) COLLATE DATABASE_DEFAULT,
        LegacyTillNo INT, LegacyShiftNo INT, LegacyEmployeeCode NVARCHAR(22) COLLATE DATABASE_DEFAULT,
        StagedId BIGINT, StagedSource NVARCHAR(10) COLLATE DATABASE_DEFAULT, StagedConfidence NVARCHAR(10) COLLATE DATABASE_DEFAULT,
        ProposedTillNo INT, ProposedShiftNo INT, ProposedEmployeeCode NVARCHAR(22) COLLATE DATABASE_DEFAULT,
        ProposedTillDescription NVARCHAR(100) COLLATE DATABASE_DEFAULT, ProposedShiftDescription NVARCHAR(100) COLLATE DATABASE_DEFAULT,
        ProposedEmployeeName NVARCHAR(120) COLLATE DATABASE_DEFAULT,
        Confidence NVARCHAR(10) COLLATE DATABASE_DEFAULT, Evidence NVARCHAR(400) COLLATE DATABASE_DEFAULT,
        ShiftEvidence NVARCHAR(400) COLLATE DATABASE_DEFAULT,
        PriorZreads INT, PriorTotal INT, IsComplete BIT, HistoryMatch NVARCHAR(10) COLLATE DATABASE_DEFAULT,
        ShiftSource NVARCHAR(10) COLLATE DATABASE_DEFAULT,
        ChangedInPumpIt BIT
    );

    INSERT INTO #aa_p
    EXEC agora.usp_Cash_ProposeZreadAllocation
         @BranchId = @BranchId, @DateFrom = @DateFrom, @DateTo = @DateTo, @Location = @Location,
         @Replay = 0, @CertainShare = @CertainShare, @LikelyShare = @LikelyShare,
         @ShiftShare = @ShiftShare, @HistoryDays = @HistoryDays,
         @DayShiftNo = @DayShiftNo, @NightShiftNo = @NightShiftNo, @SequenceExcluded = @SequenceExcluded;

    DECLARE @Open     INT = (SELECT COUNT(*) FROM #aa_p WHERE [State] = 'open');
    DECLARE @Certain  INT = (SELECT COUNT(*) FROM #aa_p WHERE [State] = 'open' AND Confidence = 'certain');
    DECLARE @Likely   INT = (SELECT COUNT(*) FROM #aa_p WHERE [State] = 'open' AND Confidence = 'likely');
    DECLARE @Review   INT = (SELECT COUNT(*) FROM #aa_p WHERE [State] = 'open' AND Confidence = 'review');
    DECLARE @Manual   INT = (SELECT COUNT(*) FROM #aa_p WHERE [State] = 'open' AND Confidence = 'manual');
    DECLARE @NoShift  INT = (SELECT COUNT(*) FROM #aa_p WHERE [State] = 'open' AND Confidence <> 'manual' AND IsComplete = 0);
    DECLARE @Staged   INT;

    BEGIN TRY
        BEGIN TRANSACTION;

        /* UPDLOCK + HOLDLOCK on the existence check: two people pressing
           Auto-allocate on the same site at once must not both stage a row,
           and the filtered unique key would otherwise refuse the second press
           as a fault rather than as nothing left to do. */
        INSERT INTO agora.ZreadAllocation
            (BranchId, ZReadDate, Location, EodCounter, TerminalNo, LogFile, PosUserId,
             TillNo, ShiftNo, EmployeeCode, [Status], Source, Confidence, Evidence, CreatedAt, CreatedBy)
        SELECT p.BranchId, p.ZReadDate, p.Location, p.EodCounter, p.TerminalNo, p.LogFile, p.PosUserId,
               p.ProposedTillNo, p.ProposedShiftNo, p.ProposedEmployeeCode,
               'proposed', 'auto', p.Confidence,
               LEFT(p.Evidence + ISNULL(' ' + p.ShiftEvidence, ''), 400),
               @Now, @UserId
        FROM #aa_p p
        WHERE p.[State] = 'open'
          AND p.IsComplete = 1
          AND CASE p.Confidence WHEN 'certain' THEN 3 WHEN 'likely' THEN 2 WHEN 'review' THEN 1 ELSE 0 END >= @Rank
          AND NOT EXISTS (
              SELECT 1 FROM agora.ZreadAllocation a WITH (UPDLOCK, HOLDLOCK)
              WHERE a.BranchId = p.BranchId AND a.ZReadDate = p.ZReadDate AND a.Location = p.Location
                AND a.TerminalNo = p.TerminalNo AND a.EodCounter = p.EodCounter AND a.LogFile = p.LogFile
                AND a.PosUserId = p.PosUserId AND a.[Status] IN ('proposed', 'allocated'));

        SET @Staged = @@ROWCOUNT;

        COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        THROW;
    END CATCH;

    SELECT
        CAST(1 AS BIT)                           AS Ok,
        CASE WHEN @Staged > 0 THEN 'CASH_ZREAD_STAGED' ELSE 'CASH_ZREAD_NOTHING_TO_STAGE' END AS Code,
        CONVERT(NVARCHAR(400),
            CASE WHEN @Open = 0 THEN 'Nothing is waiting in this scope — every Z-read is allocated or staged.'
                 ELSE 'Staged ' + CONVERT(NVARCHAR(10), @Staged) + ' of ' + CONVERT(NVARCHAR(10), @Open)
                      + ' waiting Z-read' + CASE WHEN @Open = 1 THEN '' ELSE 's' END
                      + ' (the threshold is ' + @Threshold + CASE WHEN @Threshold = 'certain' THEN '' ELSE ' or better' END
                      + '). Of the ' + CONVERT(NVARCHAR(10), @Open) + ': '
                      + CONVERT(NVARCHAR(10), @Certain) + ' certain, '
                      + CONVERT(NVARCHAR(10), @Likely) + ' likely, '
                      + CONVERT(NVARCHAR(10), @Review) + ' to review, '
                      + CONVERT(NVARCHAR(10), @Manual) + ' with nothing to go on'
                      + CASE WHEN @NoShift > 0 THEN ', and ' + CONVERT(NVARCHAR(10), @NoShift) + ' proposed with no shift' ELSE '' END
                      + '. ' + CASE WHEN @Staged > 0 THEN 'Press Update ZREAD to save what is staged.'
                                    ELSE 'Accept or set the rest from the rows below.' END
            END)                                  AS Message,
        CAST(NULL AS BIGINT)                      AS Id,
        @Staged  AS Staged,
        @Open    AS Waiting,
        @Certain AS Certain,
        @Likely  AS Likely,
        @Review  AS Review,
        @Manual  AS Manual,
        @NoShift AS NoShift;
END
