/* ============================================================================
   DEPLOY THE CORRECTION - no GO, no USE, no batch separators
   ----------------------------------------------------------------------------
   Same objects as 02-proposed-proc.sql. Use this one if your client does not
   handle GO - DBeaver reports

       SQL Error [102] [S0001]: Incorrect syntax near 'GO'.

   because GO is not T-SQL, it is a batch separator the client is supposed to
   split on.

   THIS FILE HAS FOUR STATEMENTS. Run them one at a time with Ctrl+Enter, or
   select all and run - either works. It does not matter which database you are
   connected to: every object is named in full, and the two procedures are
   created through PumpIT.sys.sp_executesql, which executes them inside PumpIT
   regardless.

   IT CREATES, AND WRITES NOTHING ELSE:
     1. PumpIT.dbo.STK_StockReconLine_OpeningFixLog   the audit trail
     2. PumpIT.dbo.STK_OpeningFixExclusion            the 4 water lines
     3. PumpIT.dbo.STK_OpeningFixScope                the 17 site dates
     4. the two procedures

   NOT ONE STOCK COUNT LINE IS TOUCHED BY THIS FILE. Nothing that already
   exists is altered or dropped. The procedures do nothing until called, and
   neither of them has a default branch - the caller supplies it. 06-run-one-
   site.sql is what passes branch 9.

   Run STEP 5 at the end to confirm all five objects exist.
   ============================================================================ */


/* ---- STATEMENT 1 - the audit trail -------------------------------------- */
IF OBJECT_ID('PumpIT.dbo.STK_StockReconLine_OpeningFixLog', 'U') IS NULL
CREATE TABLE PumpIT.dbo.STK_StockReconLine_OpeningFixLog
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


/* ---- STATEMENT 2 - the exclusion list, seeded with the 4 water lines ----- */
IF OBJECT_ID('PumpIT.dbo.STK_OpeningFixExclusion', 'U') IS NULL
EXEC PumpIT.sys.sp_executesql N'
CREATE TABLE dbo.STK_OpeningFixExclusion
(
    SSBranchId  int           NOT NULL,
    StockItemNo nvarchar(5)   NOT NULL,
    Reason      nvarchar(200) NOT NULL,
    CONSTRAINT PK_STK_OpeningFixExclusion PRIMARY KEY CLUSTERED (SSBranchId, StockItemNo)
);
INSERT dbo.STK_OpeningFixExclusion (SSBranchId, StockItemNo, Reason)
VALUES ( 3, N''346'', N''REFILL WATER 1LT - water machine totaliser captured as a stock count; 90 broken openings, 52,395,961 units''),
       (24, N''312'', N''REFILL WATER 1LT - water machine totaliser captured as a stock count; 174 broken openings, 41,119,360 units''),
       ( 9, N''378'', N''REFILL WATER 1LT - water machine totaliser captured as a stock count; 62 broken openings, 36,838,925 units''),
       (15, N''297'', N''REFILL WATER - water machine totaliser captured as a stock count; 16 broken openings, 15,583,854 units'');';


/* ---- STATEMENT 3 - the scope table, one row per site --------------------
   This is a lookup table of dates, not an instruction. Nothing reads it unless
   a procedure is called, and 06-run-one-site.sql does not read it at all - it
   passes the branch and the date as literals.                               */
IF OBJECT_ID('PumpIT.dbo.STK_OpeningFixScope', 'U') IS NULL
EXEC PumpIT.sys.sp_executesql N'
CREATE TABLE dbo.STK_OpeningFixScope
(
    SSBranchId  int           NOT NULL,
    FromDate    datetime      NOT NULL,
    ToDate      datetime      NULL,
    SiteName    nvarchar(100) NOT NULL,
    AsSupplied  nvarchar(40)  NOT NULL,
    IsEnabled   bit           NOT NULL CONSTRAINT DF_OpeningFixScope_Enabled DEFAULT (1),
    CONSTRAINT PK_STK_OpeningFixScope PRIMARY KEY CLUSTERED (SSBranchId)
);
INSERT dbo.STK_OpeningFixScope (SSBranchId, FromDate, ToDate, SiteName, AsSupplied)
VALUES ( 3, ''1900-01-01'', NULL, N''Ngwelezane Convenience Centre'', N''Since Start''),
       ( 8, ''1900-01-01'', NULL, N''Caltex Ulundi'',                 N''Since Start''),
       ( 9, ''1900-01-01'', NULL, N''Esikhawini Convenience'',        N''Since Start''),
       (15, ''1900-01-01'', NULL, N''Esikhawini OK Liquor'',          N''Since Start''),
       (18, ''1900-01-01'', NULL, N''Elephant Coast'',                N''Since Start''),
       (24, ''1900-01-01'', NULL, N''Nseleni Convenience Centre'',    N''Since Start''),
       ( 6, ''2026-09-07'', NULL, N''Nyala One Stop'',                N''Thu, 07/09/2026''),
       ( 7, ''2026-09-07'', NULL, N''Theku Plaza (Cygnisol)'',        N''Thu, 07/09/2026''),
       (13, ''2026-09-07'', NULL, N''Baobab Convenience Centre'',     N''Thu, 07/09/2026''),
       (22, ''2026-09-07'', NULL, N''Total Mkuze'',                   N''Thu, 07/09/2026''),
       ( 5, ''2026-09-14'', NULL, N''Baobab Inn'',                    N''Mon, 14/09/2026''),
       (17, ''2026-09-14'', NULL, N''Captains Convenience Centre'',   N''Mon, 14/09/2026''),
       (19, ''2026-09-14'', NULL, N''Pongola Convenience Centre'',    N''Mon, 14/09/2026''),
       (21, ''2026-09-14'', NULL, N''Engen Manguzi'',                 N''Mon, 14/09/2026''),
       (23, ''2026-09-14'', NULL, N''Engen Bethlehem One Plus'',      N''Mon, 14/09/2026''),
       (25, ''2026-09-14'', NULL, N''Ngwenya Service Station'',       N''Mon, 14/09/2026''),
       (26, ''2026-09-14'', NULL, N''Total Hluhluwe'',                N''Mon, 14/09/2026'');';


/* ---- STATEMENT 4 - the worker procedure, one branch at a time -----------
   @BranchId has NO default. This procedure does nothing until something calls
   it with a branch. See 06-run-one-site.sql.                                */
EXEC PumpIT.sys.sp_executesql N'CREATE OR ALTER PROCEDURE dbo.sp_RebuildStockReconLine_QtyOpen_FromPriorClose
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
        RAISERROR(''@BranchId and @FromDate are both required. There is no implicit all-branches mode: use sp_RebuildStockReconLine_QtyOpen_RunAll, which reads dbo.STK_OpeningFixScope.'', 16, 1);
        RETURN;
    END;

    IF @RepairOriginal = 0 AND @RepairWorking = 0
    BEGIN
        RAISERROR(''Nothing to do: both @RepairOriginal and @RepairWorking are 0.'', 16, 1);
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
            CASE WHEN @WhatIf = 1 THEN ''WHAT-IF - nothing written'' ELSE ''APPLIED'' END AS Mode,
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

         1. The UPDATE had no guard, so it overwrote the clerk''s figure with
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
        RAISERROR(''%d of %d rows changed while this branch was being corrected and were SKIPPED, not overwritten. Re-run to pick them up, or run in a quiet window.'', 10, 1, @applied, @candidates) WITH NOWAIT;

    DROP TABLE #fix;
END;';


/* ---- STATEMENT 5 - the driver procedure, all seventeen ------------------
   Only 05-run-the-correction.sql calls this. 06 does not.                   */
EXEC PumpIT.sys.sp_executesql N'CREATE OR ALTER PROCEDURE dbo.sp_RebuildStockReconLine_QtyOpen_RunAll
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
        RAISERROR(''dbo.STK_OpeningFixScope has no enabled rows. Nothing to run.'', 16, 1);
        RETURN;
    END;

    PRINT CONCAT(''RunId '', CONVERT(varchar(36), @RunId), ''  mode '',
                 CASE WHEN @WhatIf = 1 THEN ''WHAT-IF'' ELSE ''APPLY'' END);

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
        PRINT CONCAT(''  branch '', @BranchId, ''  '', @SiteName,
                     ''  from '', CONVERT(varchar(10), @FromDate, 23));

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
END;';


/* ---- STATEMENT 6 - confirm. Five rows expected, all in PumpIT. ---------- */
SELECT  o.name AS object_name, o.type_desc,
        CONVERT(varchar(30), o.modify_date, 121) AS modified
FROM    PumpIT.sys.objects AS o
WHERE   o.name IN ('STK_StockReconLine_OpeningFixLog',
                   'STK_OpeningFixExclusion',
                   'STK_OpeningFixScope',
                   'sp_RebuildStockReconLine_QtyOpen_FromPriorClose',
                   'sp_RebuildStockReconLine_QtyOpen_RunAll')
ORDER BY o.type_desc, o.name;
