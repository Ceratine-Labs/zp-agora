/* ============================================================================
   THE CORRECTION — the script you actually run on the night
   ----------------------------------------------------------------------------
   Written 21 Sep 2026. Companion to 01 (the copy), 02 (the procedures) and
   04 (the top-up). Fully qualified throughout, so it does not care which
   database your client is connected to.

   *** STEP 6 IS THE ONLY THING IN THIS FILE THAT WRITES. Everything before it
   *** reports. Everything after it verifies. Do not run STEP 6 until STEP 5
   *** has been read by a person.

   THE ORDER
     ONCE, BEFOREHAND
       1. 01-copy-STK_StockReconLine.sql   take the copy
       2. 02-proposed-proc.sql             DEPLOY THE PROCEDURES.
                                           *** This is the first thing in the
                                           pack that writes to PumpIT. It adds
                                           three new tables and two new
                                           procedures. It does not alter or
                                           drop anything that already exists,
                                           and it does not touch a single
                                           count line. Select PumpIT in your
                                           client's database dropdown first —
                                           see the guard at the top of it.
     ON THE NIGHT
       3. 04-top-up-the-copy.sql           bring the backup current
       4. this file, STEP 1 to STEP 5      preflight and dry run
       5. a human reads STEP 5
       6. this file, STEP 6                apply.  KEEP THE RunId.
       7. this file, STEP 7 to STEP 9      verify

     STEP 1 tells you which of those is missing. It no longer throws when
     something is not there — that was the whole point of it.

   WHEN TO RUN IT — measured, not guessed
     Count capture over the last 14 days, by hour of day:
       00:00  no rows at all        12:00  667/day
       01:00  4/day                 14:00  690/day
       03:00  6/day                 15:00  844/day   <- busiest
       04:00  97/day                17:00  496/day
       09:00  726/day               19:00  57/day

     SQL Agent and the backups:
       21:30  three MIST backup jobs
       22:00  PumpIT full backup to E:\\Shabeer PumpIT Backups\\  (~17 min, 221.8 GB)
       23:50  PumpIT - Month-End Stock Snapshot
       01:25  a second full backup, to C:\\Windows\\TEMP  (~6 min)
       02:00  Daily Alteryx Rev Refresh
       06:00  FuelSheet Nightly Refresh

     THE WINDOW IS 00:15 TO 01:15. Capture in those two hours has been
     essentially nil for a fortnight, the 23:50 job has finished, and the 01:25
     backup has not started. Avoid the 1st of the month — PumpIT Monthly
     Partition Maintenance runs at 01:00.

     Run it in that window and the 22:00 full backup from the same night is a
     complete database restore point taken BEFORE the change. That is a last
     resort, not the plan — restoring it would also undo a day of trading — but
     it exists, which is worth knowing on a database in SIMPLE recovery.

   WHAT REVERSES IT
     dbo.STK_StockReconLine_OpeningFixLog, one row per changed line, written by
     the same transaction that changed it. It cannot miss a row. STEP 10 has
     the statement, commented out. Prefer it over the table copy: it touches
     only what the correction touched.
   ============================================================================ */


/* ---------------------------------------------------------------------------
   STEP 1 — is everything in place? Writes nothing.
   Every column must read OK. Anything else tells you which file to run.

   *** REVISION 2, 21 Sep 2026. The first version referenced the tables
   *** directly — SELECT COUNT(*) FROM PumpIT.dbo.STK_OpeningFixScope — so when
   *** one was missing the whole batch failed to COMPILE with
   ***     SQL Error [208] [S0002]: Invalid object name '...STK_OpeningFixScope'
   *** A preflight that throws in exactly the case it exists to detect is
   *** useless. Every count now goes through sp_executesql, which is only
   *** invoked once the object is known to exist, so this batch always runs and
   *** always answers.
   --------------------------------------------------------------------------- */
SET NOCOUNT ON;

DECLARE @scope_enabled int = NULL,
        @exclusions    int = NULL,
        @log_rows      int = NULL,
        @backup_rows   bigint = NULL;

IF OBJECT_ID('PumpIT.dbo.STK_OpeningFixScope', 'U') IS NOT NULL
    EXEC PumpIT.sys.sp_executesql
         N'SELECT @n = COUNT(*) FROM dbo.STK_OpeningFixScope WHERE IsEnabled = 1',
         N'@n int OUTPUT', @n = @scope_enabled OUTPUT;

IF OBJECT_ID('PumpIT.dbo.STK_OpeningFixExclusion', 'U') IS NOT NULL
    EXEC PumpIT.sys.sp_executesql
         N'SELECT @n = COUNT(*) FROM dbo.STK_OpeningFixExclusion',
         N'@n int OUTPUT', @n = @exclusions OUTPUT;

IF OBJECT_ID('PumpIT.dbo.STK_StockReconLine_OpeningFixLog', 'U') IS NOT NULL
    EXEC PumpIT.sys.sp_executesql
         N'SELECT @n = COUNT(*) FROM dbo.STK_StockReconLine_OpeningFixLog',
         N'@n int OUTPUT', @n = @log_rows OUTPUT;

IF OBJECT_ID('PumpIT.dbo.STK_StockReconLine_Bak_20260921', 'U') IS NOT NULL
    EXEC PumpIT.sys.sp_executesql
         N'SELECT @n = COUNT_BIG(*) FROM dbo.STK_StockReconLine_Bak_20260921',
         N'@n bigint OUTPUT', @n = @backup_rows OUTPUT;

SELECT
    CASE WHEN OBJECT_ID('PumpIT.dbo.sp_RebuildStockReconLine_QtyOpen_FromPriorClose', 'P') IS NULL
         THEN 'MISSING - run file 02-proposed-proc.sql' ELSE 'OK' END                          AS worker_proc,
    CASE WHEN OBJECT_ID('PumpIT.dbo.sp_RebuildStockReconLine_QtyOpen_RunAll', 'P') IS NULL
         THEN 'MISSING - run file 02-proposed-proc.sql' ELSE 'OK' END                          AS driver_proc,
    CASE WHEN @log_rows IS NULL THEN 'MISSING - run file 02-proposed-proc.sql' ELSE 'OK' END   AS audit_log,
    CASE WHEN @scope_enabled IS NULL THEN 'MISSING - run file 02-proposed-proc.sql'
         WHEN @scope_enabled = 17 THEN 'OK - 17 sites'
         ELSE CONCAT('CHECK - ', @scope_enabled, ' enabled sites, expected 17') END AS scope,
    CASE WHEN @exclusions IS NULL THEN 'MISSING - run file 02-proposed-proc.sql'
         WHEN @exclusions >= 4 THEN CONCAT('OK - ', @exclusions, ' excluded items')
         ELSE CONCAT('CHECK - ', @exclusions, ' exclusions, expected the 4 water lines') END AS exclusions,
    CASE WHEN @backup_rows IS NULL THEN 'MISSING - run file 01-copy-STK_StockReconLine.sql'
         ELSE CONCAT('OK - ', FORMAT(@backup_rows, 'N0'), ' rows') END  AS table_copy,
    CASE WHEN @log_rows IS NULL THEN 'n/a'
         WHEN @log_rows = 0 THEN 'OK - never run'
         ELSE CONCAT('ALREADY RUN - ', FORMAT(@log_rows, 'N0'), ' logged rows, see STEP 2') END AS previous_runs;
GO


/* ---------------------------------------------------------------------------
   STEP 2 — has the correction been run before?
   Expect no rows the first time. If there are rows, STOP and work out what
   they are before adding another run on top.
   Silent if 02 has not been deployed yet.
   --------------------------------------------------------------------------- */
IF OBJECT_ID('PumpIT.dbo.STK_StockReconLine_OpeningFixLog', 'U') IS NULL
    PRINT 'Audit log does not exist yet - run file 02-proposed-proc.sql first. Nothing to report.';
ELSE
    EXEC PumpIT.sys.sp_executesql N'
        SELECT  RunId,
                CONVERT(varchar(30), MIN(RunAt), 121) AS started,
                CONVERT(varchar(30), MAX(RunAt), 121) AS finished,
                MIN(RunBy)                            AS run_by,
                COUNT(*)                              AS rows_changed,
                COUNT(DISTINCT SSBranchId)            AS sites
        FROM    dbo.STK_StockReconLine_OpeningFixLog
        GROUP BY RunId
        ORDER BY started;';
GO


/* ---------------------------------------------------------------------------
   STEP 3 — is the backup current?
   outstanding should be small. If it is more than a few dozen, run
   04-top-up-the-copy.sql again before going on.
   --------------------------------------------------------------------------- */
IF OBJECT_ID('PumpIT.dbo.STK_StockReconLine_Bak_20260921', 'U') IS NULL
    PRINT 'The table copy does not exist - run file 01-copy-STK_StockReconLine.sql first.';
ELSE
    EXEC PumpIT.sys.sp_executesql N'
        SELECT
            CONVERT(varchar(30), (SELECT MIN(BackupTakenAt) FROM dbo.STK_StockReconLine_Bak_20260921), 121) AS copy_taken_at,
            CONVERT(varchar(30), (SELECT MAX(BackupTakenAt) FROM dbo.STK_StockReconLine_Bak_20260921), 121) AS last_topped_up,
            (SELECT COUNT_BIG(*) FROM dbo.STK_StockReconLine_Bak_20260921) AS backup_rows,
            (SELECT COUNT_BIG(*) FROM dbo.STK_StockReconLine)              AS source_rows,
            (SELECT COUNT_BIG(*)
             FROM   dbo.STK_StockReconLine AS s
             WHERE  NOT EXISTS (SELECT 1
                                FROM   dbo.STK_StockReconLine_Bak_20260921 AS b
                                WHERE  b.SSBranchId      = s.SSBranchId
                                  AND  b.TransactionDate = s.TransactionDate
                                  AND  b.ShiftNo         = s.ShiftNo
                                  AND  b.AreaNo          = s.AreaNo
                                  AND  b.StockItemNo     = s.StockItemNo)) AS outstanding;';
GO


/* ---------------------------------------------------------------------------
   STEP 4 — the scope, as it will be read. Check the dates against the
   customer's own list before you go any further.
   --------------------------------------------------------------------------- */
IF OBJECT_ID('PumpIT.dbo.STK_OpeningFixScope', 'U') IS NULL
    PRINT 'The scope table does not exist yet - run file 02-proposed-proc.sql first.';
ELSE
BEGIN
    EXEC PumpIT.sys.sp_executesql N'
        SELECT  SSBranchId,
                SiteName,
                AsSupplied                                          AS customer_said,
                CONVERT(varchar(10), FromDate, 23)                  AS correct_from,
                ISNULL(CONVERT(varchar(10), ToDate, 23), ''(today)'') AS correct_to,
                IsEnabled
        FROM    dbo.STK_OpeningFixScope
        ORDER BY FromDate DESC, SSBranchId;';

    EXEC PumpIT.sys.sp_executesql N'
        SELECT SSBranchId, StockItemNo, Reason
        FROM   dbo.STK_OpeningFixExclusion ORDER BY SSBranchId;';
END;
GO


/* ---------------------------------------------------------------------------
   STEP 5 — THE DRY RUN. Writes nothing.
   One result set per site. Read them. Then read the gate below.

   This takes a few minutes: it walks 2.35 million count lines across
   seventeen sites. Do not cancel it — it is only reading, and cancelling
   tells you nothing.
   --------------------------------------------------------------------------- */
IF OBJECT_ID('PumpIT.dbo.sp_RebuildStockReconLine_QtyOpen_RunAll', 'P') IS NULL
    PRINT 'The driver procedure does not exist yet - run file 02-proposed-proc.sql first.';
ELSE
BEGIN
    DECLARE @dryrun uniqueidentifier;
    EXEC PumpIT.dbo.sp_RebuildStockReconLine_QtyOpen_RunAll
         @WhatIf = 1,
         @RunId  = @dryrun OUTPUT;
END;
GO


/* ---------------------------------------------------------------------------
   THE GATE — read this before STEP 6.

   Against the 21 Sep 2026 snapshot the dry run should total, across the
   seventeen sites, roughly:

       AsCapturedOpeningsFixed      35,647
       WorkingOpeningsFixed          1,024
       GrossValueAsCaptured     R 20,975,731
       NetValueAsCaptured       -R    227,017
       OpeningsNeverEntered          5,067

   Expect small differences — capture is live and the figures move by tens of
   rows in an hour. Expect LARGE differences to mean something is wrong:

     · A total in the tens of millions of units means the water lines are not
       being excluded. Check STK_OpeningFixExclusion has its four rows.
     · Many more sites reporting than seventeen means the scope table has been
       added to.
     · WorkingOpeningsFixed in the tens of thousands rather than about a
       thousand means @ForceOriginalCloseIntoWorkingOpen has been switched on.
       It must not be. That is the R12.39 m of collateral the customer has not
       agreed to.
     · Zero everywhere means the correction has already been applied. Check
       STEP 2.

   If the numbers read right, go to STEP 6. If they do not, stop and ask.
   --------------------------------------------------------------------------- */


/* ============================================================================
   STEP 6 — APPLY.  *** THIS WRITES TO PumpIT. ***
   ----------------------------------------------------------------------------
   Each site commits on its own, under one shared RunId, so a failure at site
   twelve leaves the first eleven applied and fully recorded rather than
   rolling back the whole night.

   COPY THE RunId IT PRINTS AND KEEP IT. It is the handle for the entire run:
   what it did, what it was worth, and how to undo it.

   Cancelling mid-run is survivable — the site in flight rolls back, the sites
   already committed stay. Re-running afterwards picks up where it stopped,
   because a link that has been repaired is no longer broken.
   ============================================================================ */

-- DECLARE @run uniqueidentifier;
-- EXEC PumpIT.dbo.sp_RebuildStockReconLine_QtyOpen_RunAll
--      @WhatIf = 0,
--      @RunId  = @run OUTPUT;
-- SELECT @run AS KEEP_THIS_RUN_ID;
-- GO


/* ---------------------------------------------------------------------------
   STEP 7 — what the run actually did. Paste the RunId in.
   --------------------------------------------------------------------------- */
-- DECLARE @run uniqueidentifier = 'PASTE-THE-RUN-ID-HERE';

-- SELECT  s.SSBranchId,
--         s.SiteName,
--         COUNT(*)                                                                            AS rows_changed,
--         SUM(CASE WHEN g.QtyOpen_Original_Before <> g.QtyOpen_Original_After THEN 1 ELSE 0 END) AS as_captured_openings,
--         SUM(CASE WHEN g.QtyOpen_Before          <> g.QtyOpen_After          THEN 1 ELSE 0 END) AS working_openings,
--         SUM(CASE WHEN g.QtyOpen_Original_Before = 0 THEN 1 ELSE 0 END)                       AS openings_never_entered,
--         CAST(SUM(ABS(g.QtyOpen_Original_After - g.QtyOpen_Original_Before) * g.SellPrice) AS decimal(20,2)) AS gross_value_moved,
--         CAST(SUM((g.QtyOpen_Original_After - g.QtyOpen_Original_Before) * g.SellPrice)    AS decimal(20,2)) AS net_value_moved,
--         CONVERT(varchar(10), MIN(g.TransactionDate), 23) AS from_date,
--         CONVERT(varchar(10), MAX(g.TransactionDate), 23) AS to_date
-- FROM    PumpIT.dbo.STK_StockReconLine_OpeningFixLog AS g
-- JOIN    PumpIT.dbo.STK_OpeningFixScope AS s ON s.SSBranchId = g.SSBranchId
-- WHERE   g.RunId = @run
-- GROUP BY s.SSBranchId, s.SiteName
-- ORDER BY gross_value_moved DESC;
-- GO


/* ---------------------------------------------------------------------------
   STEP 8 — the twenty biggest single changes, so a person has actually looked
   at the largest things that moved.
   --------------------------------------------------------------------------- */
-- DECLARE @run uniqueidentifier = 'PASTE-THE-RUN-ID-HERE';

-- SELECT TOP (20)
--        g.SSBranchId,
--        m.StockItemDescription,
--        CONVERT(varchar(10), g.TransactionDate, 23) AS tx_date,
--        g.ShiftNo, g.AreaNo, g.StockItemNo,
--        g.QtyOpen_Original_Before, g.QtyOpen_Original_After,
--        CONVERT(varchar(10), g.PriorShiftDate, 23)  AS prior_date,
--        g.PriorShiftNo, g.PriorClose_Original,
--        CAST(ABS(g.QtyOpen_Original_After - g.QtyOpen_Original_Before) * g.SellPrice AS decimal(20,2)) AS value_moved
-- FROM   PumpIT.dbo.STK_StockReconLine_OpeningFixLog AS g
-- LEFT JOIN PumpIT.dbo.STK_StockMaster AS m
--        ON m.SSBranchId = g.SSBranchId AND m.StockItemNo = g.StockItemNo
-- WHERE  g.RunId = @run
-- ORDER BY value_moved DESC;
-- GO


/* ---------------------------------------------------------------------------
   STEP 9 — prove it landed and prove it is idempotent.
   The dry run should now report close to zero: a link that has been repaired
   is no longer broken. Anything left is capture that arrived while the run
   was going.
   --------------------------------------------------------------------------- */
-- DECLARE @after uniqueidentifier;
-- EXEC PumpIT.dbo.sp_RebuildStockReconLine_QtyOpen_RunAll @WhatIf = 1, @RunId = @after OUTPUT;
-- GO


/* ============================================================================
   STEP 10 — THE WAY BACK.  *** COMMENTED OUT ON PURPOSE. ***
   ----------------------------------------------------------------------------
   Reverses one run from its own audit log. It only puts back rows that are
   still exactly as the correction left them — if someone has edited a row
   since, it is skipped rather than overwritten, and the difference between
   rows_reverted and the run's row count tells you how many.

   Add  AND g.SSBranchId = <n>  to reverse a single site.

   Read the row count BEFORE you commit.
   ============================================================================

BEGIN TRANSACTION;

DECLARE @run uniqueidentifier = 'PASTE-THE-RUN-ID-HERE';

UPDATE  l
SET     l.QtyOpen_Original = g.QtyOpen_Original_Before,
        l.QtyOpen          = g.QtyOpen_Before
FROM    PumpIT.dbo.STK_StockReconLine AS l
JOIN    PumpIT.dbo.STK_StockReconLine_OpeningFixLog AS g
        ON  g.SSBranchId      = l.SSBranchId
        AND g.TransactionDate = l.TransactionDate
        AND g.ShiftNo         = l.ShiftNo
        AND g.AreaNo          = l.AreaNo
        AND g.StockItemNo     = l.StockItemNo
WHERE   g.RunId = @run
  AND   l.QtyOpen_Original = g.QtyOpen_Original_After
  AND   l.QtyOpen          = g.QtyOpen_After;

PRINT CONCAT('Rows reverted: ', @@ROWCOUNT);

-- Check the number above, then ONE of:
-- COMMIT TRANSACTION;
-- ROLLBACK TRANSACTION;

   ============================================================================ */
