/* ============================================================================
   PumpIT — top the backup up with anything captured since the copy was taken
   ----------------------------------------------------------------------------
   Companion to 01-copy-STK_StockReconLine.sql. Written 21 Sep 2026.
   Safe to run as many times as you like, including immediately before the
   correction. Like 01, it does not care which database your client is on.

   WHY
     STK_StockReconLine is live. The copy taken at 09:54:02 on 21 Sep held
     7,271,822 rows; by 09:57 the source had 132 more, every one captured after
     the copy. Those rows have no backup row, so if the correction amends one,
     the table copy cannot put it back. This script closes that gap.

   WHAT IT DOES
     INSERTS into PumpIT.dbo.STK_StockReconLine_Bak_20260921 every source row
     that is not already there, stamping each with the time of THIS run.
     Then it re-runs the completeness verdict.

   *** WHAT IT DELIBERATELY DOES NOT DO — READ THIS ***
     It never UPDATES a row that is already in the backup.

     If a row was captured before the copy and somebody has since changed its
     quantities, the backup holds the value from BEFORE that change. That is
     the entire point of the backup. "Refreshing" it would overwrite the
     original with the current value and quietly destroy the only record of
     what the number used to be — you would still have a backup table, and it
     would no longer be able to undo anything.

     So: insert what is missing, touch nothing that is already there.

   ONE HONEST LIMITATION
     A row created after the copy and then edited before this top-up runs is
     captured here at its edited value, not its original one. There is no way
     around that — nobody was watching when it was first written. It is still
     far better than the row being absent, and the per-run audit log in
     02-proposed-proc.sql records the true prior value of anything the
     correction itself touches, which is the reversal path that matters.

   WHEN TO RUN IT
     Once now, and again immediately before the correction runs. The gap you
     care about is the one between the last top-up and the first write.

   IT IS SAFE TO CANCEL
     The insert is a single statement, so cancelling it rolls the whole thing
     back — you get "The statement has been terminated" and not one row lands.
     There is no half-finished state to clean up. Confirmed on the live run of
     21 Sep: a cancelled attempt reported 20 rows, was terminated, and the
     backup's batch list shows no trace of it — only the two runs that
     completed, 597 rows and 80 rows.

   IT WILL NEVER REACH ZERO WHILE THE TILLS ARE OPEN
     Capture is continuous. Between 09:54 and 10:34 on 21 Sep the sites entered
     677 new count lines; the gap was 9 rows a few seconds after a top-up. That
     is normal and it is not what the backup is for — see "what actually
     protects you" at the bottom of this file.
   ============================================================================ */


/* ---------------------------------------------------------------------------
   STEP 0 — THE INDEX. RUN THIS FIRST, ONCE. It is why the top-up is slow.

   Checked on the live backup on 21 Sep: the unique clustered index from STEP 5
   of 01 is NOT there. Without it the backup is a 989 MB heap, so every
   NOT EXISTS in this script scans all 7.27 million rows — minutes per run,
   which is why it looked like it had hung. With the index each run is seconds.

   It is also the safety net. A unique index makes a duplicate row impossible;
   without it, a mistake in this script could quietly insert the same key twice.
   (Checked: there are currently zero duplicates, so it will build cleanly.)

   It is a one-off. It sorts 7.27 m rows and adds roughly 250 MB. Give it a
   couple of minutes and do not cancel it.
   --------------------------------------------------------------------------- */
SET NOCOUNT ON;

IF OBJECT_ID('PumpIT.dbo.STK_StockReconLine_Bak_20260921', 'U') IS NULL
    THROW 51002, 'The backup table does not exist. Run 01-copy-STK_StockReconLine.sql first.', 1;

IF EXISTS (SELECT 1 FROM PumpIT.sys.indexes
           WHERE object_id = OBJECT_ID('PumpIT.dbo.STK_StockReconLine_Bak_20260921')
             AND name = 'CX_STK_StockReconLine_Bak_20260921')
    PRINT 'Index already present - nothing to do.';
ELSE
BEGIN
    /* Duplicates would make the unique index fail. Say so clearly rather than
       letting the CREATE throw a 1505 nobody can read. */
    IF EXISTS (SELECT 1
               FROM   PumpIT.dbo.STK_StockReconLine_Bak_20260921
               GROUP BY SSBranchId, TransactionDate, ShiftNo, AreaNo, StockItemNo
               HAVING COUNT(*) > 1)
        THROW 51004, 'The backup holds duplicate keys. Do not index it - work out where they came from first.', 1;

    PRINT 'Building the index. This sorts 7.27 million rows. Do not cancel it.';
    EXEC PumpIT.sys.sp_executesql N'
        CREATE UNIQUE CLUSTERED INDEX CX_STK_StockReconLine_Bak_20260921
            ON dbo.STK_StockReconLine_Bak_20260921
               (SSBranchId, TransactionDate, ShiftNo, AreaNo, StockItemNo);';
    PRINT 'Index built.';
END;
GO


/* ---------------------------------------------------------------------------
   STEP 1 — what would the top-up pick up? Writes nothing.
   --------------------------------------------------------------------------- */
SET NOCOUNT ON;

IF OBJECT_ID('PumpIT.dbo.STK_StockReconLine_Bak_20260921', 'U') IS NULL
    THROW 51002, 'The backup table does not exist. Run 01-copy-STK_StockReconLine.sql first.', 1;

DECLARE @taken datetime2(7) = (SELECT MIN(BackupTakenAt) FROM PumpIT.dbo.STK_StockReconLine_Bak_20260921);

SELECT  CONVERT(varchar(30), @taken, 121)                  AS original_copy_taken_at,
        CONVERT(varchar(30), SYSDATETIME(), 121)           AS now_,
        (SELECT COUNT_BIG(*) FROM PumpIT.dbo.STK_StockReconLine_Bak_20260921) AS backup_rows_now,
        (SELECT COUNT_BIG(*) FROM PumpIT.dbo.STK_StockReconLine)              AS source_rows_now,
        (SELECT COUNT_BIG(*)
         FROM   PumpIT.dbo.STK_StockReconLine AS s
         WHERE  NOT EXISTS (SELECT 1
                            FROM   PumpIT.dbo.STK_StockReconLine_Bak_20260921 AS b
                            WHERE  b.SSBranchId      = s.SSBranchId
                              AND  b.TransactionDate = s.TransactionDate
                              AND  b.ShiftNo         = s.ShiftNo
                              AND  b.AreaNo          = s.AreaNo
                              AND  b.StockItemNo     = s.StockItemNo)) AS would_be_inserted;
GO


/* ---------------------------------------------------------------------------
   STEP 2 — where they came from, so the number is not a mystery.
   --------------------------------------------------------------------------- */
SET NOCOUNT ON;

IF OBJECT_ID('PumpIT.dbo.STK_StockReconLine_Bak_20260921', 'U') IS NULL
    THROW 51002, 'The backup table does not exist. Run 01-copy-STK_StockReconLine.sql first.', 1;

SELECT  s.SSBranchId,
        CONVERT(varchar(10), s.TransactionDate, 23)         AS TransactionDate,
        COUNT(*)                                            AS rows_,
        CONVERT(varchar(30), MIN(s.CreateDateTime), 121)    AS first_captured,
        CONVERT(varchar(30), MAX(s.CreateDateTime), 121)    AS last_captured
FROM    PumpIT.dbo.STK_StockReconLine AS s
WHERE   NOT EXISTS (SELECT 1
                    FROM   PumpIT.dbo.STK_StockReconLine_Bak_20260921 AS b
                    WHERE  b.SSBranchId      = s.SSBranchId
                      AND  b.TransactionDate = s.TransactionDate
                      AND  b.ShiftNo         = s.ShiftNo
                      AND  b.AreaNo          = s.AreaNo
                      AND  b.StockItemNo     = s.StockItemNo)
GROUP BY s.SSBranchId, CONVERT(varchar(10), s.TransactionDate, 23)
ORDER BY rows_ DESC;
GO


/* ---------------------------------------------------------------------------
   STEP 3 — do it.
   One set-based INSERT, so the NOT EXISTS and the write see the same snapshot
   and a row arriving mid-statement cannot be inserted twice. Guard and insert
   are in one batch so a statement-at-a-time client cannot separate them.

   BackupTakenAt on these rows is the time of THIS run, not of the original
   copy — so you can always tell which rows came from the first snapshot and
   which were topped up later. STEP 1 and the verdict in 01 both read
   MIN(BackupTakenAt), so the original copy time survives a top-up.
   --------------------------------------------------------------------------- */
SET NOCOUNT ON;

IF OBJECT_ID('PumpIT.dbo.STK_StockReconLine_Bak_20260921', 'U') IS NULL
    THROW 51002, 'The backup table does not exist. Run 01-copy-STK_StockReconLine.sql first.', 1;

INSERT  PumpIT.dbo.STK_StockReconLine_Bak_20260921
       (SSBranchId, TransactionDate, ShiftNo, AreaNo, StockItemNo, SellPrice,
        QtyOpen, QtyIssued, QtyClose, QtyComputer, CreateDateTime, MIST_EOD,
        QtyOpen_Original, QtyIssued_Original, QtyClose_Original, QtyComputer_Original,
        BackupTakenAt, BackupTakenBy)
SELECT  s.SSBranchId, s.TransactionDate, s.ShiftNo, s.AreaNo, s.StockItemNo, s.SellPrice,
        s.QtyOpen, s.QtyIssued, s.QtyClose, s.QtyComputer, s.CreateDateTime, s.MIST_EOD,
        s.QtyOpen_Original, s.QtyIssued_Original, s.QtyClose_Original, s.QtyComputer_Original,
        SYSDATETIME(), CONVERT(sysname, ORIGINAL_LOGIN())
FROM    PumpIT.dbo.STK_StockReconLine AS s
WHERE   NOT EXISTS (SELECT 1
                    FROM   PumpIT.dbo.STK_StockReconLine_Bak_20260921 AS b
                    WHERE  b.SSBranchId      = s.SSBranchId
                      AND  b.TransactionDate = s.TransactionDate
                      AND  b.ShiftNo         = s.ShiftNo
                      AND  b.AreaNo          = s.AreaNo
                      AND  b.StockItemNo     = s.StockItemNo);

PRINT CONCAT('Rows added to the backup: ', @@ROWCOUNT);
GO


/* ---------------------------------------------------------------------------
   STEP 4 — the verdict, again.
   rows_missed_by_the_copy must be 0. still_not_in_the_backup should be 0 or a
   very small number — anything captured in the seconds since STEP 3 ran. Run
   STEP 3 again if you want it at zero; it will never quite stay there while
   the tills are open, which is why the audit log, not this table, is the
   reversal path that matters.
   --------------------------------------------------------------------------- */
SET NOCOUNT ON;

IF OBJECT_ID('PumpIT.dbo.STK_StockReconLine_Bak_20260921', 'U') IS NULL
    THROW 51002, 'The backup table does not exist. Run 01-copy-STK_StockReconLine.sql first.', 1;

DECLARE @taken datetime2(7) = (SELECT MIN(BackupTakenAt) FROM PumpIT.dbo.STK_StockReconLine_Bak_20260921);
DECLARE @missed bigint, @still bigint, @deleted bigint;

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

SELECT @still = COUNT_BIG(*)
FROM   PumpIT.dbo.STK_StockReconLine AS s
WHERE  NOT EXISTS (SELECT 1
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

SELECT  CASE WHEN @missed = 0 THEN 'PASS - every row that existed at copy time is in the backup'
             ELSE 'FAIL - the original copy missed rows. Investigate before running the correction.' END AS verdict,
        @missed                                            AS rows_missed_by_the_copy,   -- must be 0
        @still                                             AS still_not_in_the_backup,   -- 0, or seconds of new capture
        @deleted                                           AS in_backup_not_in_source,   -- deleted since; expected to be 0
        CONVERT(varchar(30), @taken, 121)                  AS original_copy_taken_at,
        (SELECT COUNT_BIG(*) FROM PumpIT.dbo.STK_StockReconLine_Bak_20260921) AS backup_rows,
        (SELECT COUNT_BIG(*) FROM PumpIT.dbo.STK_StockReconLine)              AS source_rows;
GO


/* ---------------------------------------------------------------------------
   STEP 5 — the batches, for the record.
   One row per top-up run: when it happened, who ran it, how many rows it added.
   --------------------------------------------------------------------------- */
SET NOCOUNT ON;

IF OBJECT_ID('PumpIT.dbo.STK_StockReconLine_Bak_20260921', 'U') IS NULL
    THROW 51002, 'The backup table does not exist. Run 01-copy-STK_StockReconLine.sql first.', 1;

SELECT  CONVERT(varchar(30), BackupTakenAt, 121) AS batch_taken_at,
        BackupTakenBy                            AS taken_by,
        COUNT(*)                                 AS rows_,
        CONVERT(varchar(10), MIN(TransactionDate), 23) AS min_tx_date,
        CONVERT(varchar(10), MAX(TransactionDate), 23) AS max_tx_date
FROM    PumpIT.dbo.STK_StockReconLine_Bak_20260921
GROUP BY BackupTakenAt, BackupTakenBy
ORDER BY batch_taken_at;
GO


/* ---------------------------------------------------------------------------
   STEP 6 — "is this a bug, or is it real activity?"
   Run this whenever the top-up inserting again makes you suspicious. It answers
   the question from the data instead of from argument.

   HOW TO READ IT
     candidates_right_now              what the top-up would insert if run now.
     SUSPICIOUS_older_than_last_topup  rows it wants to insert that were created
                                       BEFORE the last top-up ran. **This must
                                       be 0.** If it is not, the NOT EXISTS is
                                       failing to match and it IS a bug.
     SUSPICIOUS_older_than_the_copy    same thing, worse. Must be 0.
     candidates_with_no_timestamp      rows with no CreateDateTime at all. They
                                       are counted as suspicious above, because
                                       a row with no timestamp cannot be shown
                                       to be new. Zero on the live table.
     same_day_area_item_already_present  a candidate whose branch+area+item+date
                                       is already in the backup under a
                                       DIFFERENT shift. A high number here is
                                       normal and reassuring, not alarming: it
                                       is what a site capturing its second
                                       shift of a day looks like when the first
                                       shift is already backed up. On the live
                                       reading of 11:07 it was 52 out of 52.
                                       It is context, not a fault signal — the
                                       two SUSPICIOUS columns are the fault
                                       signals.

   A CLEAN LIVE READING, 21 Sep 11:07 — this is what "no bug" looks like:
       candidates_right_now                 52
       created_since_last_topup             52   <- all of them
       SUSPICIOUS_older_than_last_topup      0
       SUSPICIOUS_older_than_the_copy        0
       candidates_with_no_timestamp          0
       captured_in_the_last_10_min         876
       oldest_candidate    2026-09-21 11:06:30   (the top-up ran at 11:05:23)

   THE ARITHMETIC THAT SETTLES IT, from the live run of 21 Sep:

     batch      rows   rows created between        gap since previous  rate
     09:54:02   7.27m  2023-07-26 .. 09:54:15      (the copy)
     10:32:16     597  09:54:38 .. 10:32:22        38.2 min            15.6/min
     10:32:57      80  10:32:58 .. 10:33:11         0.7 min           118.1/min
     10:50:56     570  10:34:44 .. 10:51:20        18.0 min            31.7/min
     11:02:55     234  10:51:34 .. 11:02:31        12.0 min            19.5/min
     11:03:16      16  11:03:18 .. 11:03:18         0.4 min            45.2/min

   Every batch's OLDEST row was created after the previous batch finished. The
   windows tile end to end with no overlap and no gap backwards:
   09:54:15 -> 09:54:38, 10:32:22 -> 10:32:58, 10:33:11 -> 10:34:44,
   10:51:20 -> 10:51:34, 11:02:31 -> 11:03:18.

   A matching bug cannot produce that. A bug re-offers rows it has already
   inserted, so their CreateDateTime would predate earlier batches. Not one does.

   And the batch SIZE tracks the elapsed time, not the number of runs: 1,497
   rows over 69 minutes, about 22 a minute. Run it twice in a row and the second
   run is small because only seconds of capture have happened — which is exactly
   the "it gets smaller the more often I run it" you would see.
   --------------------------------------------------------------------------- */
SET NOCOUNT ON;

IF OBJECT_ID('PumpIT.dbo.STK_StockReconLine_Bak_20260921', 'U') IS NULL
    THROW 51002, 'The backup table does not exist. Run 01-copy-STK_StockReconLine.sql first.', 1;

DECLARE @last  datetime2(7) = (SELECT MAX(BackupTakenAt) FROM PumpIT.dbo.STK_StockReconLine_Bak_20260921);
DECLARE @first datetime2(7) = (SELECT MIN(BackupTakenAt) FROM PumpIT.dbo.STK_StockReconLine_Bak_20260921);
DECLARE @recent bigint = (SELECT COUNT_BIG(*) FROM PumpIT.dbo.STK_StockReconLine
                          WHERE CreateDateTime > DATEADD(minute, -10, SYSDATETIME()));

;WITH cand AS (
    SELECT s.CreateDateTime,
           CASE WHEN EXISTS (SELECT 1
                             FROM   PumpIT.dbo.STK_StockReconLine_Bak_20260921 AS b2
                             WHERE  b2.SSBranchId      = s.SSBranchId
                               AND  b2.AreaNo          = s.AreaNo
                               AND  b2.StockItemNo     = s.StockItemNo
                               AND  b2.TransactionDate = s.TransactionDate)
                THEN 1 ELSE 0 END AS same_day_area_item_present
    FROM   PumpIT.dbo.STK_StockReconLine AS s
    WHERE  NOT EXISTS (SELECT 1
                       FROM   PumpIT.dbo.STK_StockReconLine_Bak_20260921 AS b
                       WHERE  b.SSBranchId      = s.SSBranchId
                         AND  b.TransactionDate = s.TransactionDate
                         AND  b.ShiftNo         = s.ShiftNo
                         AND  b.AreaNo          = s.AreaNo
                         AND  b.StockItemNo     = s.StockItemNo)
)
SELECT
    CONVERT(varchar(30), @first, 121)                           AS first_copy,
    CONVERT(varchar(30), @last, 121)                            AS last_topup,
    CONVERT(varchar(30), SYSDATETIME(), 121)                    AS now_,
    COUNT(*)                                                    AS candidates_right_now,
    ISNULL(SUM(CASE WHEN c.CreateDateTime > @last THEN 1 ELSE 0 END), 0) AS created_since_last_topup,
    /* ISNULL, not a bare comparison. A candidate with no CreateDateTime cannot
       be shown to be new, so it is treated as old and counted as suspicious.
       Without this it escapes BOTH counters — NULL > x and NULL <= x are each
       UNKNOWN, so the row falls through every CASE to ELSE 0 and a real
       matching bug reads as a clean bill of health. Found by deliberately
       removing rows from the backup on the container and watching this say
       everything was fine. */
    ISNULL(SUM(CASE WHEN ISNULL(c.CreateDateTime, '1900-01-01') <= @last  THEN 1 ELSE 0 END), 0) AS SUSPICIOUS_older_than_last_topup,
    ISNULL(SUM(CASE WHEN ISNULL(c.CreateDateTime, '1900-01-01') <= @first THEN 1 ELSE 0 END), 0) AS SUSPICIOUS_older_than_the_copy,
    ISNULL(SUM(CASE WHEN c.CreateDateTime IS NULL THEN 1 ELSE 0 END), 0)  AS candidates_with_no_timestamp,
    ISNULL(SUM(c.same_day_area_item_present), 0)                AS same_day_area_item_already_present,
    @recent                                                     AS captured_in_the_last_10_min,
    CONVERT(varchar(30), MIN(c.CreateDateTime), 121)            AS oldest_candidate,
    CONVERT(varchar(30), MAX(c.CreateDateTime), 121)            AS newest_candidate
FROM cand AS c;
GO


/* ============================================================================
   WHAT ACTUALLY PROTECTS YOU
   ----------------------------------------------------------------------------
   The table copy can never be a perfect instantaneous snapshot of a system
   people are typing into, and it does not need to be. Three things sit behind
   the correction, and they are not equally strong:

   1. THE AUDIT LOG — the strong one.
      dbo.STK_StockReconLine_OpeningFixLog records the prior value of every row
      the correction changes, written by the same transaction that changes it.
      It cannot miss a row, because a row cannot be changed without being
      logged. It does not care whether that row is in the table copy, when the
      copy was taken, or how busy the tills were. This is the reversal path.

   2. THIS TABLE COPY — the broad one.
      A full row-for-row copy as of a moment. Useful if something goes wrong
      that the correction did not do — a bad manual statement, a mistake
      elsewhere. Kept current by this script.

   3. THE NIGHTLY FULL BACKUP — the last resort.
      PumpIT is backed up in full twice a day: about 22:00 to
      E:\Shabeer PumpIT Backups\, and again about 01:25. 221.8 GB, roughly 17
      minutes. The most recent completed at 01:30 on 21 Sep. Restoring it would
      undo the correction — and everything else that happened since, including
      a day of trading. Never the plan, but it exists.

   So a handful of rows arriving between the last top-up and the first write is
   not a hole in the safety net. Those rows are covered by (1), which is the
   layer that matters.
   ============================================================================ */
