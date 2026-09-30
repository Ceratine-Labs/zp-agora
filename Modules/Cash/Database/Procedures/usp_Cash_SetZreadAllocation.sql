/* ============================================================================
   agora.usp_Cash_SetZreadAllocation

   WHAT IT IS FOR
   One Z-read, staged by a person — the two buttons in a row's panel:

     @Source = 'accepted'  "Accept suggestion". The system's proposal, taken
                           exactly as it stands. The till, shift and person
                           are read from agora.usp_Cash_ProposeZreadAllocation
                           HERE, not from the form, so what is staged is what
                           the proposer said — with its confidence and its
                           evidence — and not whatever a request claimed it
                           said. Refused when the proposal is incomplete.
     @Source = 'manual'    "Set this Z-read". What the person chose. The
                           confidence is 'manual', and the evidence records
                           what the system had proposed, if anything, so an
                           override is visible as one.

   It STAGES (Status 'proposed'); Update ZREAD saves. A Z-read already staged
   has that staging replaced — a draft is somebody's working, not a record.
   A Z-read already ALLOCATED may be staged again: saving it supersedes the
   old allocation, which keeps its row (agora.usp_Cash_CommitZreadAllocation).

   Checked against the site, not just shaped: the Z-read must exist, and the
   till, the shift and the person must be the site's own — a till number from
   another site is a real till number, and the wrong one.

   ⚠ #sz_p is the proposer's result set, column for column.

   Writes:  agora.ZreadAllocation — nothing outside the Agora database.

   Refusals:
     AGORA:CASH_ZREAD_USER        no user
     AGORA:CASH_ZREAD_SOURCE      @Source is neither accepted nor manual
     AGORA:CASH_ZREAD_NOT_FOUND   no such Z-read at this site
     AGORA:CASH_ZREAD_INCOMPLETE  accepting a proposal with no till, shift or person
     AGORA:CASH_ZREAD_REQUIRED    a manual set without all three
     AGORA:CASH_ZREAD_TILL        not a till of this site
     AGORA:CASH_ZREAD_SHIFT       not a shift of this site
     AGORA:CASH_ZREAD_EMPLOYEE    not an employee of this site
   ============================================================================ */
CREATE OR ALTER PROCEDURE [agora].[usp_Cash_SetZreadAllocation]
    @BranchId      INT,
    @ZReadDate     DATE,
    @Location      NVARCHAR(10),
    @EodCounter    NVARCHAR(8),
    @TerminalNo    NVARCHAR(3),
    @LogFile       NVARCHAR(10),
    @PosUserId     NVARCHAR(6),
    @Source        NVARCHAR(10),
    @TillNo        INT           = NULL,
    @ShiftNo       INT           = NULL,
    @EmployeeCode  NVARCHAR(11)  = NULL,
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

    IF @UserId IS NULL
        THROW 51000, 'AGORA:CASH_ZREAD_USER:Staging is attributed to a person, and none was given.', 1;

    IF @Source IS NULL OR @Source NOT IN ('accepted', 'manual')
        THROW 51000, 'AGORA:CASH_ZREAD_SOURCE:A Z-read is either accepted as proposed or set by hand.', 1;

    SET @Location     = ISNULL(LTRIM(RTRIM(@Location)), '');
    SET @EodCounter   = LTRIM(RTRIM(@EodCounter));
    SET @TerminalNo   = LTRIM(RTRIM(@TerminalNo));
    SET @LogFile      = LTRIM(RTRIM(@LogFile));
    SET @PosUserId    = LTRIM(RTRIM(@PosUserId));
    SET @EmployeeCode = NULLIF(LTRIM(RTRIM(@EmployeeCode)), '');

    IF NOT EXISTS (
        SELECT 1 FROM agora.vw_CashZread z
        WHERE z.BranchId = @BranchId
          AND z.ZReadAt >= @ZReadDate AND z.ZReadAt < DATEADD(day, 1, @ZReadDate)
          AND z.Location = @Location AND z.TerminalNo = @TerminalNo AND z.EodCounter = @EodCounter
          AND z.LogFile = @LogFile AND z.PosUserId = @PosUserId)
        THROW 51000, 'AGORA:CASH_ZREAD_NOT_FOUND:That Z-read is not in the site''s readings. It may have been reloaded under a different log file.', 1;

    CREATE TABLE #sz_p (
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

    INSERT INTO #sz_p
    EXEC agora.usp_Cash_ProposeZreadAllocation
         @BranchId = @BranchId, @DateFrom = @ZReadDate, @DateTo = @ZReadDate, @Location = @Location,
         @Replay = 0, @CertainShare = @CertainShare, @LikelyShare = @LikelyShare,
         @ShiftShare = @ShiftShare, @HistoryDays = @HistoryDays,
         @DayShiftNo = @DayShiftNo, @NightShiftNo = @NightShiftNo, @SequenceExcluded = @SequenceExcluded;

    DECLARE @PTill INT, @PShift INT, @PEmp NVARCHAR(22), @PConfidence NVARCHAR(10), @PEvidence NVARCHAR(400),
            @PShiftEvidence NVARCHAR(400), @PComplete BIT, @PLabel NVARCHAR(300);

    SELECT TOP 1
        @PTill = p.ProposedTillNo, @PShift = p.ProposedShiftNo, @PEmp = p.ProposedEmployeeCode,
        @PConfidence = p.Confidence, @PEvidence = p.Evidence, @PShiftEvidence = p.ShiftEvidence,
        @PComplete = p.IsComplete,
        @PLabel = CONCAT(ISNULL(p.ProposedEmployeeName, p.ProposedEmployeeCode), ' · ',
                         ISNULL(p.ProposedShiftDescription, 'no shift'), ' · ',
                         ISNULL(p.ProposedTillDescription, 'no till'), ' (', p.Confidence, ')')
    FROM #sz_p p
    WHERE p.EodCounter = @EodCounter AND p.TerminalNo = @TerminalNo
      AND p.LogFile = @LogFile AND p.PosUserId = @PosUserId;

    DECLARE @Confidence NVARCHAR(10), @Evidence NVARCHAR(400);

    IF @Source = 'accepted'
    BEGIN
        IF ISNULL(@PComplete, 0) = 0
            THROW 51000, 'AGORA:CASH_ZREAD_INCOMPLETE:The system has not proposed a till, a shift and a person for this Z-read. Set it by hand.', 1;

        SELECT @TillNo = @PTill, @ShiftNo = @PShift, @EmployeeCode = @PEmp,
               @Confidence = @PConfidence,
               @Evidence = LEFT(@PEvidence + ISNULL(' ' + @PShiftEvidence, ''), 400);
    END
    ELSE
    BEGIN
        IF @TillNo IS NULL OR @ShiftNo IS NULL OR @EmployeeCode IS NULL
            THROW 51000, 'AGORA:CASH_ZREAD_REQUIRED:Choose the till, the shift and the person. A Z-read is allocated to all three or not at all.', 1;

        SET @Confidence = 'manual';
        SET @Evidence = LEFT(CASE
            WHEN @PEmp IS NULL THEN 'Set by hand. The system had no proposal.'
            WHEN @PEmp = @EmployeeCode AND @PTill = @TillNo AND @PShift = @ShiftNo
                 THEN 'Set by hand, agreeing with the system''s proposal: ' + @PLabel + '.'
            WHEN @PEmp = @EmployeeCode AND ISNULL(@PTill, @TillNo) = @TillNo AND ISNULL(@PShift, @ShiftNo) = @ShiftNo
                 THEN 'Set by hand, completing what the system left to a person: ' + @PLabel + '.'
            ELSE 'Set by hand, overriding the system''s proposal: ' + @PLabel + '.' END, 400);
    END

    IF NOT EXISTS (SELECT 1 FROM agora.vw_Till t WHERE t.BranchId = @BranchId AND t.TillNo = @TillNo)
        THROW 51000, 'AGORA:CASH_ZREAD_TILL:That till is not one of this site''s.', 1;

    IF NOT EXISTS (SELECT 1 FROM agora.vw_Shift s WHERE s.BranchId = @BranchId AND s.ShiftNo = @ShiftNo)
        THROW 51000, 'AGORA:CASH_ZREAD_SHIFT:That shift is not one of this site''s.', 1;

    IF NOT EXISTS (SELECT 1 FROM agora.vw_Employee e WHERE e.BranchId = @BranchId AND e.EmployeeCode = @EmployeeCode)
        THROW 51000, 'AGORA:CASH_ZREAD_EMPLOYEE:That person is not on this site''s staff list.', 1;

    DECLARE @Now DATETIME2(0) = SYSUTCDATETIME();
    DECLARE @Id BIGINT;

    BEGIN TRY
        BEGIN TRANSACTION;

        SELECT @Id = a.Id
        FROM agora.ZreadAllocation a WITH (UPDLOCK, HOLDLOCK)
        WHERE a.BranchId = @BranchId AND a.ZReadDate = @ZReadDate AND a.Location = @Location
          AND a.TerminalNo = @TerminalNo AND a.EodCounter = @EodCounter AND a.LogFile = @LogFile
          AND a.PosUserId = @PosUserId AND a.[Status] = 'proposed';

        IF @Id IS NOT NULL
            UPDATE agora.ZreadAllocation
               SET TillNo = @TillNo, ShiftNo = @ShiftNo, EmployeeCode = @EmployeeCode,
                   Source = @Source, Confidence = @Confidence, Evidence = @Evidence,
                   UpdatedAt = @Now, UpdatedBy = @UserId
             WHERE BranchId = @BranchId AND Id = @Id;
        ELSE
        BEGIN
            INSERT INTO agora.ZreadAllocation
                (BranchId, ZReadDate, Location, EodCounter, TerminalNo, LogFile, PosUserId,
                 TillNo, ShiftNo, EmployeeCode, [Status], Source, Confidence, Evidence, CreatedAt, CreatedBy)
            VALUES
                (@BranchId, @ZReadDate, @Location, @EodCounter, @TerminalNo, @LogFile, @PosUserId,
                 @TillNo, @ShiftNo, @EmployeeCode, 'proposed', @Source, @Confidence, @Evidence, @Now, @UserId);

            SET @Id = SCOPE_IDENTITY();
        END

        COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        THROW;
    END CATCH;

    SELECT
        CAST(1 AS BIT)             AS Ok,
        'CASH_ZREAD_SET'           AS Code,
        CONVERT(NVARCHAR(400), CASE @Source WHEN 'accepted' THEN 'Accepted the suggestion.' ELSE 'Set.' END
            + ' Press Update ZREAD to save.') AS Message,
        @Id                        AS Id;
END
