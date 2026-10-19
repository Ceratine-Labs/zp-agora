/* ============================================================================
   PumpIT — take a copy of dbo.STK_StockReconLine before the opening-qty update
   ----------------------------------------------------------------------------
   Requested by Zululand Retail & Petroleum, 21 Sep 2026.
   Run by:  Ryan, against the instance holding PumpIT (105.247.172.179)
   Run as:  any login with CREATE TABLE in PumpIT

   *** REVISION 2, 21 Sep 2026 — every object is now named in full
   *** (PumpIT.dbo.X) and the two statements that genuinely need a database
   *** context go through PumpIT.sys.sp_executesql. It no longer matters which
   *** database your client is connected to, and it no longer matters whether
   *** your client honours GO or lets you run one statement at a time.
   ***
   *** REVISION 3, 21 Sep 2026 — STEP 3 and STEP 4 rewritten. They used to say
   *** "expect zero rows from both halves" and compare row counts for exact
   *** equality, which is only true of a table nobody is writing to. On the
   *** real run the copy was taken at 09:54:02 and by 09:57 the source held 132
   *** rows the backup did not — every one created AFTER the copy, all at one
   *** branch, all capturing the previous day's shifts. The copy was perfect and
   *** the check reported it as a failure. STEP 3 now gives one verdict on the
   *** only question that matters: did the copy miss a row that already existed.
   ***
   *** Revision 1 opened with USE PumpIT and then used two-part names. In a
   *** client that runs only the statement under the cursor — DBeaver does —
   *** the USE never runs, the session stays on whatever database the
   *** connection defaults to, and you get
   ***     SQL Error [208] [S0002]: Invalid object name 'dbo.STK_StockReconLine'.
   *** That is what happened. Nothing was wrong with the table or the login.

   WHY THIS EXISTS
     The PumpIT database is in SIMPLE recovery. There is no transaction-log
     chain, so there is no point-in-time restore. If the opening-qty update
     writes the wrong thing, the ONLY way back is a copy taken beforehand.

   WHAT IT DOES
     Creates PumpIT.dbo.STK_StockReconLine_Bak_20260921 as a full row-for-row
     copy of PumpIT.dbo.STK_StockReconLine, then proves the copy is complete:
     every row that existed when the copy started is in the backup. It does not
     touch the source table.

     It does NOT expect the two tables to match afterwards. They will not.
     STK_StockReconLine is live and capture continues while you work — see the
     revision note below and STEP 3.

   FACTS MEASURED ON THE LIVE INSTANCE, 21 Sep 2026 07:00 UTC
     Source rows      7,271,083
     Source size        989 MB   (heap — no clustered index)
     Data file        228,853 MB allocated, 1,549 MB free inside the file
     Recovery model   SIMPLE

   READ BEFORE RUNNING
     1. SPACE. The copy needs ~989 MB. There is 1,549 MB reusable inside the
        PumpIT data file, so it should fit without the file growing — but that
        is a thin margin. Run STEP 0 first. If free_gb is under 5, use
        Variant B at the bottom, which copies only the rows the update can
        reach (~2.35 m rows, ~320 MB).
     2. TIMING. This reads all 989 MB of a live table under shared locks. It is
        a sequential heap scan and should take well under two minutes, but run
        it outside trading peak and outside the nightly SQL Agent window.
     3. NO INDEXES ARE COPIED. SELECT ... INTO copies columns and data only —
        not the primary key, not the six nonclustered indexes, not the
        (currently disabled) trg_Sync_STK_StockReconLine trigger. That is
        deliberate: this is an archive, not a working table. STEP 5 adds one
        index so the copy can be queried and restored from.
     4. NOTHING IS DROPPED OR OVERWRITTEN. STEP 2 throws rather than replacing
        a copy someone else made.
     5. RUN THE STEPS IN ORDER, one at a time is fine. Each is self-contained.
   ============================================================================ */


/* ---------------------------------------------------------------------------
   STEP 0 — where am I, is the table there, and is there room?
   Run this first. It writes nothing. If current_database is not PumpIT that is
   now FINE — the rest of the script does not care — but free_gb matters.
   --------------------------------------------------------------------------- */
SELECT DB_NAME()                                        AS current_database,
       @@SERVERNAME                                     AS server_name,
       ORIGINAL_LOGIN()                                 AS login_name,
       OBJECT_ID('PumpIT.dbo.STK_StockReconLine')       AS source_object_id,   -- must NOT be null
       (SELECT SUM(p.rows)
        FROM   PumpIT.sys.partitions p
        WHERE  p.object_id = OBJECT_ID('PumpIT.dbo.STK_StockReconLine')
          AND  p.index_id IN (0, 1))                    AS source_rows,
       (SELECT SUM(a.total_pages) * 8 / 1024
        FROM   PumpIT.sys.partitions p
        JOIN   PumpIT.sys.allocation_units a ON a.container_id = p.partition_id
        WHERE  p.object_id = OBJECT_ID('PumpIT.dbo.STK_StockReconLine')
          AND  p.index_id IN (0, 1))                    AS source_mb;

SELECT DISTINCT
       vs.volume_mount_point                                    AS drive,
       CAST(vs.total_bytes     / 1073741824.0 AS decimal(10, 1)) AS total_gb,
       CAST(vs.available_bytes / 1073741824.0 AS decimal(10, 1)) AS free_gb
FROM   sys.master_files AS f
CROSS APPLY sys.dm_os_volume_stats(f.database_id, f.file_id) AS vs
WHERE  f.database_id = DB_ID('PumpIT');
GO


/* ---------------------------------------------------------------------------
   STEP 1 — the recovery model, on the record.
   Expect SIMPLE. That is why the copy exists.
   --------------------------------------------------------------------------- */
SELECT name, recovery_model_desc, state_desc
FROM   sys.databases
WHERE  name = 'PumpIT';
GO


/* ---------------------------------------------------------------------------
   STEP 2 — take the copy.
   Guard and copy are in ONE batch on purpose, so a client that runs statements
   individually cannot separate them. SELECT ... INTO is minimally logged under
   SIMPLE recovery, so this does not inflate the transaction log. The column
   list is explicit rather than SELECT * so the copy still works if someone
   adds a column to the source tomorrow.
   --------------------------------------------------------------------------- */
SET NOCOUNT ON;

IF OBJECT_ID('PumpIT.dbo.STK_StockReconLine_Bak_20260921', 'U') IS NOT NULL
    THROW 51000, 'PumpIT.dbo.STK_StockReconLine_Bak_20260921 already exists. Rename or drop it deliberately before re-running this script.', 1;

IF OBJECT_ID('PumpIT.dbo.STK_StockReconLine', 'U') IS NULL
    THROW 51001, 'PumpIT.dbo.STK_StockReconLine is not visible to this login. Check you are on the right instance and that the login can read PumpIT.', 1;

SELECT  l.SSBranchId,
        l.TransactionDate,
        l.ShiftNo,
        l.AreaNo,
        l.StockItemNo,
        l.SellPrice,
        l.QtyOpen,
        l.QtyIssued,
        l.QtyClose,
        l.QtyComputer,
        l.CreateDateTime,
        l.MIST_EOD,
        l.QtyOpen_Original,
        l.QtyIssued_Original,
        l.QtyClose_Original,
        l.QtyComputer_Original,
        SYSDATETIME()                       AS BackupTakenAt,
        CONVERT(sysname, ORIGINAL_LOGIN())  AS BackupTakenBy
INTO    PumpIT.dbo.STK_StockReconLine_Bak_20260921
FROM    PumpIT.dbo.STK_StockReconLine AS l;

PRINT CONCAT('Rows copied: ', @@ROWCOUNT);
GO


/* ---------------------------------------------------------------------------
   STEP 3 — is the copy complete?  ONE verdict, on a table that is still moving.

   *** REVISION 3, 21 Sep 2026. The first version of steps 3 and 4 said "expect
   *** zero rows from both halves" and compared row counts for exact equality.
   *** That is only true of a table nobody is writing to. STK_StockReconLine is
   *** live: on the real run the copy was taken at 09:54:02 and by 09:57 the
   *** source had 132 more rows than the backup — every one of them created
   *** AFTER the copy, all at one branch, all capturing the previous day's
   *** shifts. The copy was perfect and the check called it a failure.

   THE ONLY CHECK THAT MATTERS is rows_missed_by_the_copy: a row that already
   existed when the copy started and is not in the backup. That must be 0.

   Everything else is information, not failure:
     · captured_after_the_copy  — rows inserted since. Expected on a live
       system. They have no backup row, by definition; see the note under
       STEP 4 for what covers them.
     · deleted_since_the_copy   — rows in the backup that are no longer in the
       source. Usually 0. Not an error, but worth a look if it is large.

   Row counts are NOT compared for equality. The backup will normally hold
   slightly MORE than the source held at copy time — the scan runs for a few
   seconds and picks up rows inserted ahead of it. On the real run the backup
   held 7,271,822 against 7,271,708 rows that existed at 09:54:02.
   --------------------------------------------------------------------------- */
SET NOCOUNT ON;

DECLARE @taken datetime2(7) = (SELECT MIN(BackupTakenAt) FROM PumpIT.dbo.STK_StockReconLine_Bak_20260921);

DECLARE @missed   bigint,
        @after    bigint,
        @deleted  bigint,
        @existing bigint,
        @backup   bigint;

SELECT @backup = COUNT_BIG(*) FROM PumpIT.dbo.STK_StockReconLine_Bak_20260921;

SELECT @existing = COUNT_BIG(*)
FROM   PumpIT.dbo.STK_StockReconLine AS s
WHERE  ISNULL(s.CreateDateTime, '1900-01-01') <= @taken;

/* A row that existed before the copy and is not in the backup. MUST be zero.
   CreateDateTime is nullable, so a NULL is treated as "existed before" — the
   conservative reading, which keeps such a row inside the check. */
SELECT @missed = COUNT_BIG(*)
FROM   PumpIT.dbo.STK_StockReconLine AS s
WHERE  ISNULL(s.CreateDateTime, '1900-01-01') <= @taken
  AND  NOT EXISTS (SELECT 1
                   FROM   PumpIT.dbo.STK_StockReconLine_Bak_20260921 AS b
                   WHERE  b.SSBranchId      = s.SSBranchId
                     AND  b.TransactionDate = s.TransactionDate
                     AND  b.ShiftNo         = s.ShiftNo
                     AND  b.AreaNo          = s.AreaNo
                     AND  b.StockItemNo     = s.StockItemNo);

SELECT @after = COUNT_BIG(*)
FROM   PumpIT.dbo.STK_StockReconLine AS s
WHERE  s.CreateDateTime > @taken
  AND  NOT EXISTS (SELECT 1
                   FROM   PumpIT.dbo.STK_StockReconLine_Bak_20260921 AS b
                   WHERE  b.SSBranchId      = s.SSBranchId
                     AND  b.TransactionDate = s.TransactionDate
                     AND  b.ShiftNo         = s.ShiftNo
                     AND  b.AreaNo          = s.AreaNo
                     AND  b.StockItemNo     = s.StockItemNo);

SELECT @deleted = COUNT_BIG(*)
FROM   PumpIT.dbo.STK_StockReconLine_Bak_20260921 AS b
WHERE  NOT EXISTS (SELECT 1
                   FROM   PumpIT.dbo.STK_StockReconLine AS s
                   WHERE  s.SSBranchId      = b.SSBranchId
                     AND  s.TransactionDate = b.TransactionDate
                     AND  s.ShiftNo         = b.ShiftNo
                     AND  s.AreaNo          = b.AreaNo
                     AND  s.StockItemNo     = b.StockItemNo);

SELECT  CASE WHEN @missed = 0 THEN 'PASS - the copy is complete'
             ELSE 'FAIL - the copy missed rows that already existed. Drop it and take it again.' END
                                                    AS verdict,
        @missed                                     AS rows_missed_by_the_copy,     -- must be 0
        CONVERT(varchar(30), @taken, 121)           AS backup_taken_at,
        @backup                                     AS backup_rows,
        @existing                                   AS source_rows_at_copy_time,
        @after                                      AS captured_after_the_copy,     -- expected, not an error
        @deleted                                    AS deleted_since_the_copy;      -- usually 0
GO


/* ---------------------------------------------------------------------------
   STEP 4 — only if STEP 3 said FAIL: which rows were missed.
   If STEP 3 said PASS this returns nothing and there is nothing to do.
   --------------------------------------------------------------------------- */
DECLARE @taken datetime2(7) = (SELECT MIN(BackupTakenAt) FROM PumpIT.dbo.STK_StockReconLine_Bak_20260921);

SELECT TOP (50) 'existed before the copy, NOT in the backup' AS problem, s.*
FROM   PumpIT.dbo.STK_StockReconLine AS s
WHERE  ISNULL(s.CreateDateTime, '1900-01-01') <= @taken
  AND  NOT EXISTS (SELECT 1
                   FROM   PumpIT.dbo.STK_StockReconLine_Bak_20260921 AS b
                   WHERE  b.SSBranchId      = s.SSBranchId
                     AND  b.TransactionDate = s.TransactionDate
                     AND  b.ShiftNo         = s.ShiftNo
                     AND  b.AreaNo          = s.AreaNo
                     AND  b.StockItemNo     = s.StockItemNo);
GO


/* ---------------------------------------------------------------------------
   STEP 4b — what has been captured since the copy, for information.
   These rows are NOT in the backup and do not need to be. They are ordinary
   trading activity that happened after the snapshot.

   WHAT COVERS THEM. If a row captured after the copy is inside the correction's
   scope and its opening is broken, the correction will amend it and the table
   copy cannot put it back. The audit log in 02-proposed-proc.sql can: it
   records the prior value of every single row it changes, in the same
   transaction, whether or not that row is in this backup. That is the reversal
   path for anything captured after today's snapshot, and it is the better
   reversal path in general because it touches only what the correction touched.
   --------------------------------------------------------------------------- */
DECLARE @taken datetime2(7) = (SELECT MIN(BackupTakenAt) FROM PumpIT.dbo.STK_StockReconLine_Bak_20260921);

SELECT  s.SSBranchId,
        CONVERT(varchar(10), s.TransactionDate, 23)   AS TransactionDate,
        COUNT(*)                                      AS rows_,
        CONVERT(varchar(30), MIN(s.CreateDateTime), 121) AS first_captured,
        CONVERT(varchar(30), MAX(s.CreateDateTime), 121) AS last_captured
FROM    PumpIT.dbo.STK_StockReconLine AS s
WHERE   s.CreateDateTime > @taken
GROUP BY s.SSBranchId, CONVERT(varchar(10), s.TransactionDate, 23)
ORDER BY rows_ DESC;
GO


/* ---------------------------------------------------------------------------
   STEP 5 — make the copy usable, and label it.
   CREATE INDEX and sp_addextendedproperty are the only two statements here
   that cannot take a three-part name, so both are executed through
   PumpIT.sys.sp_executesql — which runs them inside PumpIT whatever database
   your client is sitting on.

   Without the index the copy is a 989 MB heap and every lookup against it is a
   full scan; it is what makes the restore in STEP 6 run in minutes rather than
   hours. It adds roughly 250 MB.
   --------------------------------------------------------------------------- */
EXEC PumpIT.sys.sp_executesql N'
    CREATE UNIQUE CLUSTERED INDEX CX_STK_StockReconLine_Bak_20260921
        ON dbo.STK_StockReconLine_Bak_20260921
           (SSBranchId, TransactionDate, ShiftNo, AreaNo, StockItemNo);';
GO

EXEC PumpIT.sys.sp_executesql N'
    EXEC sys.sp_addextendedproperty
         @name  = N''MS_Description'',
         @value = N''Copy of dbo.STK_StockReconLine taken 21 Sep 2026 immediately before the bulk opening-quantity correction. PumpIT is in SIMPLE recovery and has no point-in-time restore; this table is the only way back. Do not drop without Zululand Retail & Petroleum sign-off.'',
         @level0type = N''SCHEMA'', @level0name = N''dbo'',
         @level1type = N''TABLE'',  @level1name = N''STK_StockReconLine_Bak_20260921'';';
GO


/* ---------------------------------------------------------------------------
   STEP 5b — confirm what you now have.
   --------------------------------------------------------------------------- */
SELECT  t.name                                   AS table_name,
        SUM(p.rows)                              AS rows_,
        SUM(a.total_pages) * 8 / 1024            AS mb,
        (SELECT COUNT(*) FROM PumpIT.sys.indexes i
         WHERE i.object_id = t.object_id AND i.type > 0) AS indexes_
FROM    PumpIT.sys.tables t
JOIN    PumpIT.sys.partitions p ON p.object_id = t.object_id AND p.index_id IN (0, 1)
JOIN    PumpIT.sys.allocation_units a ON a.container_id = p.partition_id
WHERE   t.name IN ('STK_StockReconLine', 'STK_StockReconLine_Bak_20260921')
GROUP BY t.name, t.object_id;
GO


/* ============================================================================
   STEP 6 — THE WAY BACK.  *** COMMENTED OUT ON PURPOSE. ***
   ----------------------------------------------------------------------------
   Only run this if the opening-qty update has to be reversed wholesale. It puts
   the four _Original columns and the two working quantity columns back exactly
   as they were when the copy was taken, for rows that still exist in both
   tables. It does NOT delete rows captured after the copy.

   Prefer the per-run reversal in 02-proposed-proc.sql where you can: that one
   reverses one correction run from its own audit log and leaves everything
   else alone. This is the blunt instrument.

   Read the row count it reports before you commit.
   ============================================================================

BEGIN TRANSACTION;

UPDATE  s
SET     s.QtyOpen_Original     = b.QtyOpen_Original,
        s.QtyIssued_Original   = b.QtyIssued_Original,
        s.QtyClose_Original    = b.QtyClose_Original,
        s.QtyComputer_Original = b.QtyComputer_Original,
        s.QtyOpen              = b.QtyOpen,
        s.QtyClose             = b.QtyClose
FROM    PumpIT.dbo.STK_StockReconLine AS s
JOIN    PumpIT.dbo.STK_StockReconLine_Bak_20260921 AS b
        ON  b.SSBranchId      = s.SSBranchId
        AND b.TransactionDate = s.TransactionDate
        AND b.ShiftNo         = s.ShiftNo
        AND b.AreaNo          = s.AreaNo
        AND b.StockItemNo     = s.StockItemNo
WHERE   s.QtyOpen_Original     <> b.QtyOpen_Original
   OR   s.QtyIssued_Original   <> b.QtyIssued_Original
   OR   s.QtyClose_Original    <> b.QtyClose_Original
   OR   s.QtyComputer_Original <> b.QtyComputer_Original
   OR   s.QtyOpen              <> b.QtyOpen
   OR   s.QtyClose             <> b.QtyClose;

PRINT CONCAT('Rows reverted: ', @@ROWCOUNT);

-- Check the number above, then ONE of:
-- COMMIT TRANSACTION;
-- ROLLBACK TRANSACTION;

   ============================================================================ */


/* ============================================================================
   VARIANT B — copy only the rows the update can reach.
   ----------------------------------------------------------------------------
   Use this INSTEAD OF STEP 2 and STEP 5 if the data volume is short of space.
   It backs up only the 2,348,724 rows inside the per-site effective dates the
   customer supplied — about 320 MB instead of 989 MB.

   The trade-off is real: if the update is later found to have run wider than
   intended, anything outside these date windows has no copy to go back to.
   ============================================================================

SET NOCOUNT ON;

IF OBJECT_ID('PumpIT.dbo.STK_StockReconLine_Bak_20260921', 'U') IS NOT NULL
    THROW 51000, 'Backup table already exists.', 1;

;WITH scope (BranchId, FromDate) AS (
    SELECT * FROM (VALUES
        ( 3, CONVERT(datetime, '1900-01-01')),   -- Ngwelezane Convenience Centre  SINCE START
        ( 8, CONVERT(datetime, '1900-01-01')),   -- Caltex Ulundi                  SINCE START
        ( 9, CONVERT(datetime, '1900-01-01')),   -- Esikhawini Convenience         SINCE START
        (15, CONVERT(datetime, '1900-01-01')),   -- Esikhawini OK Liquor           SINCE START
        (18, CONVERT(datetime, '1900-01-01')),   -- Elephant Coast                 SINCE START
        (24, CONVERT(datetime, '1900-01-01')),   -- Nseleni Convenience Centre     SINCE START
        ( 6, CONVERT(datetime, '2026-09-07')),   -- Nyala One Stop
        ( 7, CONVERT(datetime, '2026-09-07')),   -- Theku Plaza (Cygnisol)
        (13, CONVERT(datetime, '2026-09-07')),   -- Baobab Convenience Centre
        (22, CONVERT(datetime, '2026-09-07')),   -- Total Mkuze
        ( 5, CONVERT(datetime, '2026-09-14')),   -- Baobab Inn
        (17, CONVERT(datetime, '2026-09-14')),   -- Captains Convenience Centre
        (19, CONVERT(datetime, '2026-09-14')),   -- Pongola Convenience Centre
        (21, CONVERT(datetime, '2026-09-14')),   -- Engen Manguzi
        (23, CONVERT(datetime, '2026-09-14')),   -- Engen Bethlehem One Plus
        (25, CONVERT(datetime, '2026-09-14')),   -- Ngwenya Service Station
        (26, CONVERT(datetime, '2026-09-14'))    -- Total Hluhluwe
    ) v (BranchId, FromDate)
)
SELECT  l.*,
        SYSDATETIME()                      AS BackupTakenAt,
        CONVERT(sysname, ORIGINAL_LOGIN()) AS BackupTakenBy
INTO    PumpIT.dbo.STK_StockReconLine_Bak_20260921
FROM    PumpIT.dbo.STK_StockReconLine AS l
JOIN    scope AS s
        ON  s.BranchId = l.SSBranchId
        AND l.TransactionDate >= s.FromDate;

PRINT CONCAT('Rows copied: ', @@ROWCOUNT, '  (expected about 2,348,724)');
GO

EXEC PumpIT.sys.sp_executesql N'
    CREATE UNIQUE CLUSTERED INDEX CX_STK_StockReconLine_Bak_20260921
        ON dbo.STK_StockReconLine_Bak_20260921
           (SSBranchId, TransactionDate, ShiftNo, AreaNo, StockItemNo);';
GO

   ============================================================================ */
