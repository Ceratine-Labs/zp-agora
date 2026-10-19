/* ============================================================================
   PROPOSED — the bulk opening-quantity correction
   ----------------------------------------------------------------------------
   *** DEPLOYED TO THE CUSTOMER'S PumpIT ON 21 Sep 2026 19:36:26.
   *** The header used to read "NOT DEPLOYED. NOT RUN." — that stopped being
   *** true that evening and was corrected on 22 Sep after checking sys.objects.
   ***
   *** sp_RebuildStockReconLine_QtyOpen_FromPriorClose has been APPLIED ONCE,
   *** at 19:36:38 the same evening, to BRANCH 9 (Esikhawini Convenience) only:
   ***   RunId  738E5311-8465-4993-8FC2-73665DFAF380
   ***   2,153 openings changed (2,075 as-captured, 141 working)
   ***   R 1,252,099.58 gross, transaction dates 2024-08-29 .. 2026-09-20
   ***   2,152 of the 2,153 rows are still reversible from the audit log.
   *** sp_RebuildStockReconLine_QtyOpen_RunAll has NEVER been called. The other
   *** sixteen sites are untouched.
   ***
   *** Revision 2, 21 Sep 2026 — rebuilt against the customer's
   *** answers to the report at
   *** https://claude.ai/artifact/LeNuYW9aiPZhr5BYYtV1Jk

   WHAT THE CUSTOMER DECIDED (21 Sep 2026)
     q1  "Thu, 07/09/2026" means 7 September 2026.
     q2  "Since Start" means the whole history — 30 June 2023 — at all six
         sites marked that way.
     q3  Exclude the four water-refill lines; the water meters are separate work.
     q4  Write BOTH opening quantities, the as-captured and the working one.
     q5  All seventeen sites in a single run.
     q6  Correct both kinds in one pass — never-entered openings AND openings
         that disagree with a count. No staging.
     q7  Include the twelve counting areas whose shift flags are all zero.
     q8  The table copy is taken first, by Ryan.

   ONE DECISION WAS TAKEN FOR THEM, AND IT NEEDS SAYING OUT LOUD
     q4 asked for both columns. Taken literally — writing the as-captured
     closing into the working opening as well — it does this, measured over the
     full scope on 21 Sep 2026:

       A  as-captured chain repaired from its own closing
              35,647 openings ·   504,254 units · R 20,975,731   <- the job
       B  working chain repaired from its own closing
               1,024 openings ·     7,009 units · R    274,856   <- nearly healthy already
       C  COLLATERAL, if the as-captured closing is forced into QtyOpen too
              31,805 openings ·   361,628 units · R 12,386,238   <- damage

     The working columns are not a stale copy of the as-captured ones. They are
     a separately maintained, already-reconciled set: 270,336 rows in scope have
     BOTH chains intact while the two carry different numbers, 3,060,972 units
     apart. Forcing one chain's closing into the other's opening would break
     31,805 working openings that are correct today, to the tune of R12.39 m,
     in order to repair a chain they have nothing to do with.

     So "both" is implemented as REPAIR BOTH CHAINS, EACH FROM ITS OWN CLOSING.
     The as-captured opening is set from the previous as-captured closing; the
     working opening is set from the previous working closing. Both chains end
     up self-consistent, which is what the request was actually asking for.

     The literal reading is still available — @ForceOriginalCloseIntoWorkingOpen
     — and is OFF. Do not switch it on without the customer seeing the R12.39 m.

   WHAT THIS REPLACES
     dbo.sp_UpdateStockReconLine_QtyOpen_xQtyClose_Original is correct and stays
     exactly as it is. The site-specific shift logic Chantal asked us to add is
     already in it: it reads DayShift / AfternoonShift / NightShift off STK_Area,
     works out the next shift (day -> afternoon where one exists, else day ->
     night; night -> the next day's day shift) and writes that row's opening.

     What it cannot do is run across a range. It takes @xQtyClose and
     @xQtyIssued as INPUT and acts on one (date, branch, shift, area, item) —
     it is the capture screen's single-row hook. Calling it in a loop would
     write the closing the CALLER supplies, not the closing already on the row.

   THE ONE DELIBERATE DIFFERENCE FROM THE LEGACY SHIFT LOGIC
     24 areas across 5 branches have DayShift = AfternoonShift = NightShift = 0.
     The legacy logic computes a NULL next shift for those and silently writes
     nothing. 3,877 broken openings worth R876,553 sit in twelve of them,
     including Tobacco at both Esikhawini sites and High Shrinkage Items at OK
     Liquor. The customer asked (q7) for those to be included, so this chains
     over the shifts that actually have counts recorded against them, in date
     and shift order, rather than over the shifts the area master claims exist.
     Where the flags are right the two approaches agree exactly.
     @UseAreaShiftFlags = 1 reverts to the legacy behaviour.

   WHAT IT STILL DOES NOT DO
     It does not recompute any closing or issued quantity, and it does not
     cascade — setting an opening does not change that shift's closing, so one
     pass finishes the job.

     It does not remove variance. It MOVES variance onto the shift whose
     opening was amended. Net effect across the scope is minus R227,017 on
     R20.97 m moved: the total barely changes, who carries it changes a lot.
     Shift-level and employee-level variance reports run before the correction
     will not agree with the same reports run after it.
   ============================================================================ */

/* ---------------------------------------------------------------------------
   ⚠ BEFORE YOU RUN THIS FILE: SELECT PumpIT IN YOUR CLIENT'S DATABASE DROPDOWN.

   01 and 04 do not care which database you are connected to, because every
   object in them can be named in full. This file is different. CREATE
   PROCEDURE cannot take a three-part name — it always creates the procedure in
   the CURRENT database — so if you run this from the Agora database you would
   silently create Agora's own copy of these procedures and tables, inside the
   database Agora migrates. That is a mess to unpick.

   The guard below stops that. If it fires, switch database and run the file
   again; nothing will have been created.
   --------------------------------------------------------------------------- */
USE PumpIT;
GO

SET NOEXEC OFF;
GO

IF DB_NAME() <> 'PumpIT'
BEGIN
    /* RAISERROR substitution arguments must be constants or variables - a
       function call here is a syntax error, not a runtime one, so it would
       take the whole batch down silently. Hence the variable. */
    DECLARE @wrong_db sysname = DB_NAME();
    RAISERROR('STOPPED. You are connected to "%s", not PumpIT, and CREATE PROCEDURE cannot be redirected with a three-part name. Select PumpIT in your client''s database dropdown and run this file again. Nothing has been created.', 16, 1, @wrong_db);
    SET NOEXEC ON;
END;
GO

SET NOCOUNT ON;
GO

/* ---------------------------------------------------------------------------
   1. THE AUDIT TRAIL
   PumpIT is in SIMPLE recovery — no transaction-log chain, so no point-in-time
   restore. Every prior value has to be written down somewhere it survives, and
   it is written in the same transaction as the change.
   --------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.STK_StockReconLine_OpeningFixLog', 'U') IS NULL
CREATE TABLE dbo.STK_StockReconLine_OpeningFixLog
(
    FixLogId                bigint IDENTITY(1,1) NOT NULL,
    RunId                   uniqueidentifier     NOT NULL,
    RunAt                   datetime2(3)         NOT NULL CONSTRAINT DF_OpeningFixLog_RunAt DEFAULT (SYSDATETIME()),
    RunBy                   sysname              NOT NULL CONSTRAINT DF_OpeningFixLog_RunBy DEFAULT (ORIGINAL_LOGIN()),
    SSBranchId              int                  NOT NULL,
    TransactionDate         datetime             NOT NULL,
    ShiftNo                 int                  NOT NULL,
    AreaNo                  int                  NOT NULL,
    StockItemNo             nvarchar(5)          NOT NULL,
    QtyOpen_Original_Before float                NOT NULL,
    QtyOpen_Original_After  float                NOT NULL,
    QtyOpen_Before          float                NOT NULL,
    QtyOpen_After           float                NOT NULL,
    PriorShiftDate          datetime             NOT NULL,
    PriorShiftNo            int                  NOT NULL,
    PriorClose_Original     float                NOT NULL,
    PriorClose              float                NOT NULL,
    SellPrice               money                NULL,
    CONSTRAINT PK_STK_StockReconLine_OpeningFixLog PRIMARY KEY CLUSTERED (FixLogId),
    INDEX IX_OpeningFixLog_Run (RunId, SSBranchId),
    INDEX IX_OpeningFixLog_Line (SSBranchId, TransactionDate, ShiftNo, AreaNo, StockItemNo)
);
GO

/* ---------------------------------------------------------------------------
   2. THE EXCLUSION LIST  (customer answer q3)
   The four water-refill lines carry 145,938,100 of the 146,442,316 units the
   uncorrected job would rewrite — 99.66% of it, in 342 of 35,969 openings.
   They are a water machine's litre totaliser typed into the closing-count box,
   not stock. A table rather than a hard-coded description match, so the
   customer can add or remove lines without a code change.
   --------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.STK_OpeningFixExclusion', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.STK_OpeningFixExclusion
    (
        SSBranchId  int           NOT NULL,
        StockItemNo nvarchar(5)   NOT NULL,
        Reason      nvarchar(200) NOT NULL,
        CONSTRAINT PK_STK_OpeningFixExclusion PRIMARY KEY CLUSTERED (SSBranchId, StockItemNo)
    );

    INSERT dbo.STK_OpeningFixExclusion (SSBranchId, StockItemNo, Reason)
    VALUES ( 3, N'346', N'REFILL WATER 1LT  - water machine totaliser captured as a stock count; 90 broken openings, 52,395,961 units'),
           (24, N'312', N'REFILL WATER 1LT  - water machine totaliser captured as a stock count; 174 broken openings, 41,119,360 units'),
           ( 9, N'378', N'REFILL WATER 1LT  - water machine totaliser captured as a stock count; 62 broken openings, 36,838,925 units'),
           (15, N'297', N'REFILL WATER      - water machine totaliser captured as a stock count; 16 broken openings, 15,583,854 units');
END;
GO

/* ---------------------------------------------------------------------------
   3. THE SCOPE  (customer answers q1, q2, q5)
   One row per site, carrying the date the correction starts from. This is the
   customer's own list of 21 Sep 2026, with "Thu, 07/09/2026" read as
   7 September 2026 (q1) and "Since Start" read as the whole history (q2).
   1900-01-01 is a floor, not a real date — the earliest row in the table is
   30 June 2023.
   --------------------------------------------------------------------------- */
IF OBJECT_ID('dbo.STK_OpeningFixScope', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.STK_OpeningFixScope
    (
        SSBranchId  int          NOT NULL,
        FromDate    datetime     NOT NULL,
        ToDate      datetime     NULL,          -- NULL = up to and including today
        SiteName    nvarchar(100) NOT NULL,
        AsSupplied  nvarchar(40)  NOT NULL,     -- what the customer's list said, verbatim
        IsEnabled   bit           NOT NULL CONSTRAINT DF_OpeningFixScope_Enabled DEFAULT (1),
        CONSTRAINT PK_STK_OpeningFixScope PRIMARY KEY CLUSTERED (SSBranchId)
    );

    INSERT dbo.STK_OpeningFixScope (SSBranchId, FromDate, ToDate, SiteName, AsSupplied)
    VALUES ( 3, '1900-01-01', NULL, N'Ngwelezane Convenience Centre',  N'Since Start'),
           ( 8, '1900-01-01', NULL, N'Caltex Ulundi',                  N'Since Start'),
           ( 9, '1900-01-01', NULL, N'Esikhawini Convenience',         N'Since Start'),
           (15, '1900-01-01', NULL, N'Esikhawini OK Liquor',           N'Since Start'),
           (18, '1900-01-01', NULL, N'Elephant Coast',                 N'Since Start'),
           (24, '1900-01-01', NULL, N'Nseleni Convenience Centre',     N'Since Start'),
           ( 6, '2026-09-07', NULL, N'Nyala One Stop',                 N'Thu, 07/09/2026'),
           ( 7, '2026-09-07', NULL, N'Theku Plaza (Cygnisol)',         N'Thu, 07/09/2026'),
           (13, '2026-09-07', NULL, N'Baobab Convenience Centre',      N'Thu, 07/09/2026'),
           (22, '2026-09-07', NULL, N'Total Mkuze',                    N'Thu, 07/09/2026'),
           ( 5, '2026-09-14', NULL, N'Baobab Inn',                     N'Mon, 14/09/2026'),
           (17, '2026-09-14', NULL, N'Captains Convenience Centre',    N'Mon, 14/09/2026'),
           (19, '2026-09-14', NULL, N'Pongola Convenience Centre',     N'Mon, 14/09/2026'),
           (21, '2026-09-14', NULL, N'Engen Manguzi',                  N'Mon, 14/09/2026'),
           (23, '2026-09-14', NULL, N'Engen Bethlehem One Plus',       N'Mon, 14/09/2026'),
           (25, '2026-09-14', NULL, N'Ngwenya Service Station',        N'Mon, 14/09/2026'),
           (26, '2026-09-14', NULL, N'Total Hluhluwe',                 N'Mon, 14/09/2026');
END;
GO


/* ============================================================================
   4. THE WORKER — one branch, one date range.
   The driver below calls this once per site. It is also the unit of reversal:
   every row it writes carries the RunId, so a single branch can be put back
   without unpicking the whole run.
   ============================================================================ */
CREATE OR ALTER PROCEDURE dbo.sp_RebuildStockReconLine_QtyOpen_FromPriorClose
    @BranchId                          int,
    @FromDate                          datetime,
    @ToDate                            datetime = NULL,
    @WhatIf                            bit      = 1,   -- report, write nothing
    @RepairOriginal                    bit      = 1,   -- QtyOpen_Original := prior QtyClose_Original
    @RepairWorking                     bit      = 1,   -- QtyOpen          := prior QtyClose        (q4)
    @ForceOriginalCloseIntoWorkingOpen bit      = 0,   -- the literal reading of q4. R12.39m of collateral. Leave off.
    @ApplyExclusions                   bit      = 1,   -- q3
    @OnlyWhereOpeningIsZero            bit      = 0,   -- q6 says no staging
    @UseAreaShiftFlags                 bit      = 0,   -- q7 says cover the unflagged areas
    @Tolerance                         float    = 0.0001,
    @RunId                             uniqueidentifier = NULL OUTPUT,
    @Quiet                             bit      = 0    -- driver sets this; suppresses the per-branch detail select
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    IF @BranchId IS NULL OR @FromDate IS NULL
    BEGIN
        RAISERROR('@BranchId and @FromDate are both required. There is no implicit all-branches mode: use sp_RebuildStockReconLine_QtyOpen_RunAll, which reads dbo.STK_OpeningFixScope.', 16, 1);
        RETURN;
    END;

    IF @RepairOriginal = 0 AND @RepairWorking = 0
    BEGIN
        RAISERROR('Nothing to do: both @RepairOriginal and @RepairWorking are 0.', 16, 1);
        RETURN;
    END;

    SET @ToDate = ISNULL(@ToDate, CONVERT(datetime, CONVERT(date, SYSDATETIME())));
    SET @RunId  = ISNULL(@RunId, NEWID());

    /* The chain. LAG over the rows that exist, per (area, item), in shift order.
       Rows outside [@FromDate, @ToDate] are excluded BEFORE the LAG, so the
       first shift in the window keeps its opening — "correct from this date"
       means exactly that, and the day before the window is never read. */
    ;WITH src AS (
        SELECT  l.SSBranchId, l.TransactionDate, l.ShiftNo, l.AreaNo, l.StockItemNo,
                l.SellPrice, l.QtyOpen, l.QtyOpen_Original, l.QtyClose, l.QtyClose_Original
        FROM    dbo.STK_StockReconLine AS l
        LEFT JOIN dbo.STK_Area AS a
               ON  a.SSBranchId = l.SSBranchId
               AND a.AreaNo     = l.AreaNo
        WHERE   l.SSBranchId = @BranchId
          AND   l.TransactionDate >= @FromDate
          AND   l.TransactionDate <= @ToDate
          AND   (@ApplyExclusions = 0
                 OR NOT EXISTS (SELECT 1
                                FROM   dbo.STK_OpeningFixExclusion AS x
                                WHERE  x.SSBranchId  = l.SSBranchId
                                  AND  x.StockItemNo = l.StockItemNo))
          AND   (@UseAreaShiftFlags = 0
                 OR (l.ShiftNo = 1 AND a.DayShift       = 1)
                 OR (l.ShiftNo = 2 AND a.AfternoonShift = 1)
                 OR (l.ShiftNo = 3 AND a.NightShift     = 1))
    ),
    chained AS (
        SELECT  src.*,
                LAG(src.QtyClose_Original) OVER (PARTITION BY src.AreaNo, src.StockItemNo
                                                 ORDER BY src.TransactionDate, src.ShiftNo) AS PriorCloseOrig,
                LAG(src.QtyClose)          OVER (PARTITION BY src.AreaNo, src.StockItemNo
                                                 ORDER BY src.TransactionDate, src.ShiftNo) AS PriorCloseWork,
                LAG(src.TransactionDate)   OVER (PARTITION BY src.AreaNo, src.StockItemNo
                                                 ORDER BY src.TransactionDate, src.ShiftNo) AS PriorDate,
                LAG(src.ShiftNo)           OVER (PARTITION BY src.AreaNo, src.StockItemNo
                                                 ORDER BY src.TransactionDate, src.ShiftNo) AS PriorShift
        FROM    src
    ),
    flagged AS (
        SELECT  c.*,
                CASE WHEN @RepairOriginal = 1
                      AND ABS(c.PriorCloseOrig - c.QtyOpen_Original) > @Tolerance
                     THEN 1 ELSE 0 END AS OrigBroken,
                CASE WHEN @RepairWorking = 1
                      AND ABS(c.PriorCloseWork - c.QtyOpen) > @Tolerance
                     THEN 1 ELSE 0 END AS WorkBroken
        FROM    chained AS c
        WHERE   c.PriorCloseOrig IS NOT NULL
          AND   (@OnlyWhereOpeningIsZero = 0
                 OR c.QtyOpen_Original = 0
                 OR c.QtyOpen = 0)
    )
    SELECT  f.SSBranchId, f.TransactionDate, f.ShiftNo, f.AreaNo, f.StockItemNo, f.SellPrice,
            f.QtyOpen_Original, f.QtyOpen, f.PriorCloseOrig, f.PriorCloseWork,
            f.PriorDate, f.PriorShift, f.OrigBroken, f.WorkBroken,
            CAST(CASE WHEN f.OrigBroken = 1 THEN f.PriorCloseOrig
                      ELSE f.QtyOpen_Original END AS float)             AS NewOpenOrig,
            CAST(CASE WHEN @ForceOriginalCloseIntoWorkingOpen = 1 AND f.OrigBroken = 1 THEN f.PriorCloseOrig
                      WHEN f.WorkBroken = 1 THEN f.PriorCloseWork
                      ELSE f.QtyOpen END AS float)                      AS NewOpenWork
    INTO    #fix
    FROM    flagged AS f
    WHERE   f.OrigBroken = 1
       OR   f.WorkBroken = 1;

    /* Drop rows where nothing actually moves — possible when a switch is off. */
    DELETE FROM #fix
    WHERE ABS(NewOpenOrig - QtyOpen_Original) <= @Tolerance
      AND ABS(NewOpenWork - QtyOpen)          <= @Tolerance;

    /* ---- the report, always ---- */
    SELECT  @RunId                                    AS RunId,
            @BranchId                                 AS BranchId,
            (SELECT SiteName FROM dbo.STK_OpeningFixScope WHERE SSBranchId = @BranchId) AS SiteName,
            CONVERT(date, @FromDate)                  AS FromDate,
            CONVERT(date, @ToDate)                    AS ToDate,
            CASE WHEN @WhatIf = 1 THEN 'WHAT-IF - nothing written' ELSE 'APPLIED' END AS Mode,
            COUNT(*)                                  AS LinesTouched,
            SUM(CAST(OrigBroken AS int))              AS AsCapturedOpeningsFixed,
            SUM(CAST(WorkBroken AS int))              AS WorkingOpeningsFixed,
            COUNT(DISTINCT StockItemNo)               AS Items,
            COUNT(DISTINCT AreaNo)                    AS Areas,
            SUM(CASE WHEN OrigBroken = 1 AND QtyOpen_Original = 0 THEN 1 ELSE 0 END) AS OpeningsNeverEntered,
            CAST(SUM(ABS(NewOpenOrig - QtyOpen_Original))             AS decimal(20,2)) AS UnitsAsCaptured,
            CAST(SUM(ABS(NewOpenWork - QtyOpen))                      AS decimal(20,2)) AS UnitsWorking,
            CAST(SUM(ABS(NewOpenOrig - QtyOpen_Original) * SellPrice) AS decimal(20,2)) AS GrossValueAsCaptured,
            CAST(SUM((NewOpenOrig - QtyOpen_Original) * SellPrice)    AS decimal(20,2)) AS NetValueAsCaptured
    FROM    #fix;

    IF @WhatIf = 1
    BEGIN
        IF @Quiet = 0
            SELECT TOP (500) * FROM #fix ORDER BY ABS((NewOpenOrig - QtyOpen_Original) * SellPrice) DESC;
        DROP TABLE #fix;
        RETURN;
    END;

    /* ---- apply ----------------------------------------------------------
       ONE statement. The UPDATE writes the audit log itself, through OUTPUT,
       and refuses any row that has changed since #fix was built.

       *** WHY IT IS BUILT THIS WAY. REVISION 3, 21 Sep 2026. ***

       The first version did two statements: INSERT the log from #fix, then
       UPDATE from #fix. Both are wrong the moment anybody else is typing.

       #fix is a snapshot. Between building it and running the UPDATE, a clerk
       can capture or amend one of those very rows. The old code then did two
       damaging things at once:

         1. The UPDATE had no guard, so it overwrote the clerk's figure with
            one computed before that figure existed. Their work is gone, with
            no error and nothing on screen.
         2. The log recorded QtyOpen_Original_Before as the value read at
            SELECT time - not the value that was actually replaced. So the
            reversal would "restore" a number the row never held at the moment
            of the write. The audit trail, the one thing that makes this
            reversible, would be quietly wrong.

       The optimistic WHERE below fixes both. A row that changed under us is
       SKIPPED, not overwritten, and never reaches the log. OUTPUT guarantees
       the log contains exactly the rows that changed, with the true before
       values, because it is the same statement.

       Skipped rows are reported as RowsSkipped_ChangedUnderUs. In the quiet
       window that number is 0. It is not 0 during trading, and every one of
       those is a row this run did not correct - which is the other half of why
       the correction belongs in the small hours.
       --------------------------------------------------------------------- */
    DECLARE @applied int, @candidates int = (SELECT COUNT(*) FROM #fix);

    UPDATE  l
    SET     l.QtyOpen_Original = f.NewOpenOrig,
            l.QtyOpen          = f.NewOpenWork
    OUTPUT  @RunId, inserted.SSBranchId, inserted.TransactionDate, inserted.ShiftNo,
            inserted.AreaNo, inserted.StockItemNo,
            deleted.QtyOpen_Original, inserted.QtyOpen_Original,
            deleted.QtyOpen,          inserted.QtyOpen,
            f.PriorDate, f.PriorShift, f.PriorCloseOrig, f.PriorCloseWork, f.SellPrice
      INTO  dbo.STK_StockReconLine_OpeningFixLog
            (RunId, SSBranchId, TransactionDate, ShiftNo, AreaNo, StockItemNo,
             QtyOpen_Original_Before, QtyOpen_Original_After,
             QtyOpen_Before, QtyOpen_After,
             PriorShiftDate, PriorShiftNo, PriorClose_Original, PriorClose, SellPrice)
    FROM    dbo.STK_StockReconLine AS l
    JOIN    #fix AS f
            ON  f.SSBranchId      = l.SSBranchId
            AND f.TransactionDate = l.TransactionDate
            AND f.ShiftNo         = l.ShiftNo
            AND f.AreaNo          = l.AreaNo
            AND f.StockItemNo     = l.StockItemNo
    /* the optimistic guard: only if the row is still exactly as we read it */
    WHERE   ABS(l.QtyOpen_Original - f.QtyOpen_Original) <= @Tolerance
      AND   ABS(l.QtyOpen          - f.QtyOpen)          <= @Tolerance;

    SET @applied = @@ROWCOUNT;

    SELECT  @RunId                   AS RunId,
            @BranchId                AS BranchId,
            @candidates              AS Candidates,
            @applied                 AS RowsUpdated,
            @candidates - @applied   AS RowsSkipped_ChangedUnderUs;

    IF @candidates <> @applied
        RAISERROR('%d of %d rows changed while this branch was being corrected and were SKIPPED, not overwritten. Re-run to pick them up, or run in a quiet window.', 10, 1, @applied, @candidates) WITH NOWAIT;

    DROP TABLE #fix;
END;
GO


/* ============================================================================
   5. THE DRIVER — all seventeen sites in one run  (customer answer q5)
   One RunId across the whole run, so the entire correction can be listed,
   valued and reversed as a unit — but each site commits on its own, so a
   failure at site twelve leaves the first eleven intact and recorded rather
   than rolling back forty minutes of work.
   ============================================================================ */
CREATE OR ALTER PROCEDURE dbo.sp_RebuildStockReconLine_QtyOpen_RunAll
    @WhatIf                            bit = 1,
    @RepairOriginal                    bit = 1,
    @RepairWorking                     bit = 1,
    @ForceOriginalCloseIntoWorkingOpen bit = 0,
    @ApplyExclusions                   bit = 1,
    @OnlyWhereOpeningIsZero            bit = 0,
    @UseAreaShiftFlags                 bit = 0,
    @RunId                             uniqueidentifier = NULL OUTPUT
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    SET @RunId = ISNULL(@RunId, NEWID());

    IF NOT EXISTS (SELECT 1 FROM dbo.STK_OpeningFixScope WHERE IsEnabled = 1)
    BEGIN
        RAISERROR('dbo.STK_OpeningFixScope has no enabled rows. Nothing to run.', 16, 1);
        RETURN;
    END;

    PRINT CONCAT('RunId ', CONVERT(varchar(36), @RunId), '  mode ',
                 CASE WHEN @WhatIf = 1 THEN 'WHAT-IF' ELSE 'APPLY' END);

    DECLARE @BranchId int, @FromDate datetime, @ToDate datetime, @SiteName nvarchar(100);

    DECLARE sites CURSOR LOCAL FAST_FORWARD FOR
        SELECT SSBranchId, FromDate, ToDate, SiteName
        FROM   dbo.STK_OpeningFixScope
        WHERE  IsEnabled = 1
        ORDER  BY FromDate DESC, SSBranchId;   -- smallest windows first

    OPEN sites;
    FETCH NEXT FROM sites INTO @BranchId, @FromDate, @ToDate, @SiteName;

    WHILE @@FETCH_STATUS = 0
    BEGIN
        PRINT CONCAT('  branch ', @BranchId, '  ', @SiteName,
                     '  from ', CONVERT(varchar(10), @FromDate, 23));

        EXEC dbo.sp_RebuildStockReconLine_QtyOpen_FromPriorClose
             @BranchId                          = @BranchId,
             @FromDate                          = @FromDate,
             @ToDate                            = @ToDate,
             @WhatIf                            = @WhatIf,
             @RepairOriginal                    = @RepairOriginal,
             @RepairWorking                     = @RepairWorking,
             @ForceOriginalCloseIntoWorkingOpen = @ForceOriginalCloseIntoWorkingOpen,
             @ApplyExclusions                   = @ApplyExclusions,
             @OnlyWhereOpeningIsZero            = @OnlyWhereOpeningIsZero,
             @UseAreaShiftFlags                 = @UseAreaShiftFlags,
             @RunId                             = @RunId OUTPUT,
             @Quiet                             = 1;

        FETCH NEXT FROM sites INTO @BranchId, @FromDate, @ToDate, @SiteName;
    END;

    CLOSE sites;
    DEALLOCATE sites;

    IF @WhatIf = 0
        SELECT  s.SSBranchId, s.SiteName,
                COUNT(*)                                                             AS RowsChanged,
                SUM(CASE WHEN g.QtyOpen_Original_Before <> g.QtyOpen_Original_After THEN 1 ELSE 0 END) AS AsCapturedOpenings,
                SUM(CASE WHEN g.QtyOpen_Before          <> g.QtyOpen_After          THEN 1 ELSE 0 END) AS WorkingOpenings,
                CAST(SUM(ABS(g.QtyOpen_Original_After - g.QtyOpen_Original_Before) * g.SellPrice) AS decimal(20,2)) AS GrossValueMoved
        FROM    dbo.STK_StockReconLine_OpeningFixLog AS g
        JOIN    dbo.STK_OpeningFixScope AS s ON s.SSBranchId = g.SSBranchId
        WHERE   g.RunId = @RunId
        GROUP BY s.SSBranchId, s.SiteName
        ORDER BY GrossValueMoved DESC;

    SELECT @RunId AS RunId;
END;
GO


/* ============================================================================
   6. HOW IT IS DRIVEN

   Step 1 — take the copy first. 01-copy-STK_StockReconLine.sql. Ryan runs it.

   Step 2 — what-if across the estate. Writes nothing.

       DECLARE @run uniqueidentifier;
       EXEC dbo.sp_RebuildStockReconLine_QtyOpen_RunAll @WhatIf = 1, @RunId = @run OUTPUT;

   Step 3 — check the totals against the report, then apply.

       DECLARE @run uniqueidentifier;
       EXEC dbo.sp_RebuildStockReconLine_QtyOpen_RunAll @WhatIf = 0, @RunId = @run OUTPUT;
       SELECT @run;       -- KEEP THIS. It is the handle for the whole run.

   Expected on the day, water excluded and against the 21 Sep 2026 snapshot:
       as-captured openings   35,647 · 504,254 units · R 20,975,731 gross · net -R 227,017
       working openings        1,024 ·   7,009 units · R    274,856 gross

   The numbers will differ slightly. Capture is live — two identical readings
   forty minutes apart on 21 Sep returned 35,990 and then 35,969.


   THE WAY BACK — reverses one whole run from the audit log, not from the table
   copy. *** COMMENTED OUT ON PURPOSE. Read the row count before committing. ***

   BEGIN TRANSACTION;

   UPDATE  l
   SET     l.QtyOpen_Original = g.QtyOpen_Original_Before,
           l.QtyOpen          = g.QtyOpen_Before
   FROM    dbo.STK_StockReconLine AS l
   JOIN    dbo.STK_StockReconLine_OpeningFixLog AS g
           ON  g.SSBranchId      = l.SSBranchId
           AND g.TransactionDate = l.TransactionDate
           AND g.ShiftNo         = l.ShiftNo
           AND g.AreaNo          = l.AreaNo
           AND g.StockItemNo     = l.StockItemNo
   WHERE   g.RunId = 'PASTE-THE-RUN-ID-HERE'
     AND   l.QtyOpen_Original = g.QtyOpen_Original_After     -- only if untouched since
     AND   l.QtyOpen          = g.QtyOpen_After;

   PRINT CONCAT('Rows reverted: ', @@ROWCOUNT);
   -- COMMIT TRANSACTION;   /  ROLLBACK TRANSACTION;

   Add  AND g.SSBranchId = <n>  to reverse a single site.
   ============================================================================ */


/* ---------------------------------------------------------------------------
   DEPLOYMENT CHECK — what exists now, and where.
   All five objects must be listed, and the database must be PumpIT.
   --------------------------------------------------------------------------- */
SET NOEXEC OFF;
GO

SELECT  DB_NAME() AS created_in_database,
        o.name    AS object_name,
        o.type_desc,
        CONVERT(varchar(30), o.modify_date, 121) AS modified
FROM    PumpIT.sys.objects AS o
WHERE   o.name IN ('STK_StockReconLine_OpeningFixLog',
                   'STK_OpeningFixExclusion',
                   'STK_OpeningFixScope',
                   'sp_RebuildStockReconLine_QtyOpen_FromPriorClose',
                   'sp_RebuildStockReconLine_QtyOpen_RunAll')
ORDER BY o.type_desc, o.name;

SELECT COUNT(*) AS sites_in_scope, SUM(CAST(IsEnabled AS int)) AS enabled
FROM   PumpIT.dbo.STK_OpeningFixScope;

SELECT COUNT(*) AS excluded_items FROM PumpIT.dbo.STK_OpeningFixExclusion;
GO
