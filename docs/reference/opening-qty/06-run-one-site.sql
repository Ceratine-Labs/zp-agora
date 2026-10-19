/* ============================================================================
   BRANCH 9 — ESIKHAWINI CONVENIENCE.  Revised 22 Sep 2026, 19:00.
   ----------------------------------------------------------------------------
   READ THIS FIRST. Branch 9 HAS ALREADY BEEN CORRECTED.

     RunId    738E5311-8465-4993-8FC2-73665DFAF380
     Applied  21 Sep 2026 19:36:38 by Revteck
     Changed  2,153 openings (2,075 as-captured, 141 working, 553 never entered)
     Value    R 1,252,099.58 gross · net +R 46,398.15
     Reversible  2,152 of 2,153 (one row has been edited since and will skip)

   So this file is no longer "run the correction". It is four separate jobs
   and you pick ONE. Measured live 22 Sep 19:00 — a dry run now returns SEVEN
   rows, not two thousand.

     JOB A — MOP UP        steps 1, 2, 3, then 4.   Writes 7 rows, R 328.66.
     JOB B — LEAVE IT      steps 1, 2, 3 only.      Writes nothing.
     JOB C — PUT IT BACK   steps 1, 7a, then 7b.    Reverts 2,152 rows.
                           Use this if the customer's objection is to the
                           correction itself rather than to a number in it.
     JOB D — SURGICAL      steps 1, 6, 9a, then 9b.  Reverts 22 of 23 rows.
                           Undoes ONLY the rows where the chain had a gap and
                           the correction bridged it from a count days or
                           years old. These are the indefensible ones and the
                           likeliest thing the customer is pointing at. It
                           leaves the other 2,130 rows corrected.

   Every step is ONE statement with no variables — put the cursor in it and
   run it on its own. The RunId is already filled in; there is nothing to
   paste. Only STEP 4, STEP 7b and STEP 9b write, and all three ship
   commented out.

   THE SEVEN OUTSTANDING ROWS ARE NOT LEFTOVERS FROM THE RUN. All seven were
   created 22 Sep between 12:01 and 12:04 — this morning's capture of
   Saturday's shifts — and none is in the audit log. The 19:36 run skipped
   nothing. See 09-branch-9-verify.sql step 5.
   ============================================================================ */


/* ---- STEP 1 — preflight. One statement. Writes nothing. ------------------
   Reads system catalogue views only, so it works in any state and cannot
   throw a 208. Expected on 22 Sep: every column OK, previous_runs shows the
   2,153 logged rows.                                                        */
SELECT
    9 /*branch*/                                                              AS branch,
    CASE WHEN OBJECT_ID('PumpIT.dbo.sp_RebuildStockReconLine_QtyOpen_FromPriorClose', 'P') IS NULL
         THEN 'MISSING - run file 02-proposed-proc.sql' ELSE 'OK' END         AS worker_proc,
    CASE WHEN OBJECT_ID('PumpIT.dbo.STK_StockReconLine_OpeningFixLog', 'U') IS NULL
         THEN 'MISSING - run file 02-proposed-proc.sql' ELSE 'OK' END         AS audit_log,
    CASE WHEN OBJECT_ID('PumpIT.dbo.STK_OpeningFixScope', 'U') IS NULL
         THEN 'MISSING - run file 02-proposed-proc.sql' ELSE 'OK' END         AS scope_table,
    CASE WHEN OBJECT_ID('PumpIT.dbo.STK_OpeningFixExclusion', 'U') IS NULL
         THEN 'MISSING - run file 02-proposed-proc.sql'
         ELSE CONCAT('OK - ', (SELECT SUM(p.row_count) FROM PumpIT.sys.dm_db_partition_stats p
                               WHERE p.object_id = OBJECT_ID('PumpIT.dbo.STK_OpeningFixExclusion')
                                 AND p.index_id IN (0, 1)), ' excluded items') END AS exclusions,
    CASE WHEN OBJECT_ID('PumpIT.dbo.STK_StockReconLine_Bak_20260921', 'U') IS NULL
         THEN 'MISSING - run file 01-copy-STK_StockReconLine.sql'
         ELSE CONCAT('OK - ', FORMAT((SELECT SUM(p.row_count) FROM PumpIT.sys.dm_db_partition_stats p
                                      WHERE p.object_id = OBJECT_ID('PumpIT.dbo.STK_StockReconLine_Bak_20260921')
                                        AND p.index_id IN (0, 1)), 'N0'), ' rows') END AS table_copy,
    CASE WHEN OBJECT_ID('PumpIT.dbo.STK_StockReconLine_OpeningFixLog', 'U') IS NULL THEN 'n/a'
         ELSE CONCAT((SELECT COUNT_BIG(*) FROM PumpIT.dbo.STK_StockReconLine_OpeningFixLog
                       WHERE SSBranchId = 9 /*branch*/), ' rows logged at branch 9') END AS previous_runs,
    CONVERT(varchar(30), SYSDATETIME(), 121)                                  AS server_time;
GO


/* ---- STEP 2 — is anyone capturing right now? ----------------------------
   The write escalates to a table lock that every site shares, so JOB A and
   JOB C both belong in the 00:15-01:15 window, any night but the 1st.
   Seven rows is small, but the lock is not proportional to the row count.   */
SELECT  CONVERT(varchar(30), SYSDATETIME(), 121)                                  AS now_,
        ISNULL(SUM(CASE WHEN s.SSBranchId = 9 /*branch*/ THEN 1 ELSE 0 END), 0)   AS this_site_last_30_min,
        COUNT(*)                                                                  AS whole_estate_last_30_min
FROM    PumpIT.dbo.STK_StockReconLine AS s
WHERE   s.CreateDateTime > DATEADD(minute, -30, SYSDATETIME());
GO


/* ---- STEP 3 — THE DRY RUN. One statement. Writes nothing. ----------------
   Reads the site's whole history — 302,359 lines — so give it a minute.
   Do not cancel it; it is only reading.

   EXPECTED ON 22 Sep 2026: LinesTouched 7 · AsCapturedOpeningsFixed 7 ·
   WorkingOpeningsFixed 0 · Gross about R 329.

   Read it against that:
     7 or a few more    normal. One day's capture. Go to STEP 4 or stop.
     hundreds           something re-broke the chain in bulk, or somebody
                        re-ran an old import. STOP and find out what.
     thousands          the correction has been reversed. Check STEP 7a.
     millions of units  the water exclusions are not being applied. STOP.
     WorkingOpeningsFixed in the thousands
                        @ForceOriginalCloseIntoWorkingOpen is on and must
                        not be. STOP.                                        */
EXEC PumpIT.dbo.sp_RebuildStockReconLine_QtyOpen_FromPriorClose
     @BranchId = 9 /*branch*/,
     @FromDate = '1900-01-01' /*from*/,
     @WhatIf   = 1;
GO


/* ============================================================================
   JOB A — STEP 4 — MOP UP.  *** THIS WRITES. *** One statement.
   Uncomment, run, and keep the RunId from the result grid — it will be a NEW
   RunId, separate from the 21 Sep one, so the two runs stay independently
   reversible.
   RowsSkipped_ChangedUnderUs should be 0. If it is not, those lines were being
   edited at the time, were left alone, and a re-run picks them up.
   ============================================================================ */

-- EXEC PumpIT.dbo.sp_RebuildStockReconLine_QtyOpen_FromPriorClose
--      @BranchId = 9 /*branch*/,
--      @FromDate = '1900-01-01' /*from*/,
--      @WhatIf   = 0;
-- GO


/* ---- STEP 5 — what the 21 Sep run changed, biggest first. Reads only. ----
   Not commented out: this is the grid to put in front of the customer when
   they say a number looks wrong. RunId is already filled in.                */
SELECT TOP (20)
       m.StockItemDescription,
       CONVERT(varchar(10), g.TransactionDate, 23) AS tx_date,
       g.ShiftNo, g.AreaNo, g.StockItemNo,
       g.QtyOpen_Original_Before, g.QtyOpen_Original_After,
       CONVERT(varchar(10), g.PriorShiftDate, 23)  AS prior_date, g.PriorShiftNo,
       CAST(ABS(g.QtyOpen_Original_After - g.QtyOpen_Original_Before) * g.SellPrice AS decimal(20,2)) AS value_moved
FROM   PumpIT.dbo.STK_StockReconLine_OpeningFixLog AS g
LEFT JOIN PumpIT.dbo.STK_StockMaster AS m
       ON m.SSBranchId = g.SSBranchId AND m.StockItemNo = g.StockItemNo
WHERE  g.RunId = '738E5311-8465-4993-8FC2-73665DFAF380'
ORDER BY value_moved DESC;
GO


/* ---- STEP 6 — the twenty rows where the chain had a GAP. Reads only. -----
   These are the weakest rows in the whole run: the previous shift with a
   count was days or years earlier, and the correction bridged it silently.
   Measured 22 Sep: 23 rows span 11 to 950 days, R 15,558 gross. The other
   2,130 took their opening from the same day or the day before.

   Worst by area:  13 (motor oils)  5 rows  R 10,471
                   32               10 rows  R  3,139
                   31                3 rows  R  1,820
                   11                3 rows  R    128
                   30                2 rows  R      0

   The one to look at first is ENGEN SUP OUTBOARD TCW3 500ML, 2026-08-29
   shift 3 area 13: opening 27 -> 0, taken from a closing on 2024-01-22,
   950 days earlier. Area 13 is counted rarely, so "the previous shift that
   exists" is years back and bridging it produces a figure nobody can defend.

   IF THE CUSTOMER IS DISPUTING A LINE, LOOK HERE FIRST.                     */
SELECT TOP (25)
       m.StockItemDescription,
       CONVERT(varchar(10), g.TransactionDate, 23) AS tx_date,
       g.ShiftNo, g.AreaNo, g.StockItemNo,
       CONVERT(varchar(10), g.PriorShiftDate, 23)  AS opening_taken_from,
       DATEDIFF(day, g.PriorShiftDate, g.TransactionDate) AS days_bridged,
       g.QtyOpen_Original_Before, g.QtyOpen_Original_After,
       CAST(ABS(g.QtyOpen_Original_After - g.QtyOpen_Original_Before) * g.SellPrice AS decimal(20,2)) AS value_moved
FROM   PumpIT.dbo.STK_StockReconLine_OpeningFixLog AS g
LEFT JOIN PumpIT.dbo.STK_StockMaster AS m
       ON m.SSBranchId = g.SSBranchId AND m.StockItemNo = g.StockItemNo
WHERE  g.RunId = '738E5311-8465-4993-8FC2-73665DFAF380'
  AND  DATEDIFF(day, g.PriorShiftDate, g.TransactionDate) > 7
ORDER BY days_bridged DESC;
GO


/* ---- STEP 7a — how many rows the reversal WOULD put back. Writes nothing.
   Expected 22 Sep: would_revert 2,152 of rows_in_the_run 2,153.             */
SELECT  COUNT_BIG(*)                                        AS would_revert,
        (SELECT COUNT_BIG(*) FROM PumpIT.dbo.STK_StockReconLine_OpeningFixLog
          WHERE RunId = '738E5311-8465-4993-8FC2-73665DFAF380') AS rows_in_the_run
FROM    PumpIT.dbo.STK_StockReconLine AS l
JOIN    PumpIT.dbo.STK_StockReconLine_OpeningFixLog AS g
        ON  g.SSBranchId = l.SSBranchId AND g.TransactionDate = l.TransactionDate
        AND g.ShiftNo = l.ShiftNo AND g.AreaNo = l.AreaNo AND g.StockItemNo = l.StockItemNo
WHERE   g.RunId = '738E5311-8465-4993-8FC2-73665DFAF380'
  AND   l.QtyOpen_Original = g.QtyOpen_Original_After
  AND   l.QtyOpen          = g.QtyOpen_After;
GO


/* ============================================================================
   JOB C — STEP 7b — PUT BRANCH 9 BACK TO 20 SEPTEMBER.
   *** THIS WRITES. COMMENTED OUT ON PURPOSE. *** One statement, commits on
   its own. Read STEP 7a's count first.

   It restores only rows still exactly as the correction left them. Anything
   edited since is skipped, never overwritten — which is why 7a says 2,152
   and not 2,153.

   AFTER THIS, the openings are back but the audit log still holds the run.
   That is deliberate: the log is the record that it happened. A second
   reversal of the same RunId would find nothing to do, because the guard no
   longer matches.
   ============================================================================ */

-- UPDATE  l
-- SET     l.QtyOpen_Original = g.QtyOpen_Original_Before,
--         l.QtyOpen          = g.QtyOpen_Before
-- FROM    PumpIT.dbo.STK_StockReconLine AS l
-- JOIN    PumpIT.dbo.STK_StockReconLine_OpeningFixLog AS g
--         ON  g.SSBranchId = l.SSBranchId AND g.TransactionDate = l.TransactionDate
--         AND g.ShiftNo = l.ShiftNo AND g.AreaNo = l.AreaNo AND g.StockItemNo = l.StockItemNo
-- WHERE   g.RunId = '738E5311-8465-4993-8FC2-73665DFAF380'
--   AND   l.QtyOpen_Original = g.QtyOpen_Original_After
--   AND   l.QtyOpen          = g.QtyOpen_After;
-- GO


/* ---- STEP 8 — after JOB A or JOB C, prove it landed. Reads only. ---------
   After JOB A: close to zero.
   After JOB C: back to roughly 2,075 as-captured openings.                  */
-- EXEC PumpIT.dbo.sp_RebuildStockReconLine_QtyOpen_FromPriorClose
--      @BranchId = 9 /*branch*/,
--      @FromDate = '1900-01-01' /*from*/,
--      @WhatIf   = 1;
-- GO


/* ---- STEP 9a — JOB D. How many gap rows the surgical reversal would undo.
   Reads only. Expected 22 Sep: would_revert 22 of gap_rows_in_the_run 23 —
   one gap row is the single row edited since the run, and it will skip.   */
SELECT  COUNT_BIG(*)                                                AS would_revert,
        (SELECT COUNT_BIG(*) FROM PumpIT.dbo.STK_StockReconLine_OpeningFixLog
          WHERE RunId = '738E5311-8465-4993-8FC2-73665DFAF380'
            AND DATEDIFF(day, PriorShiftDate, TransactionDate) > 7) AS gap_rows_in_the_run
FROM    PumpIT.dbo.STK_StockReconLine AS l
JOIN    PumpIT.dbo.STK_StockReconLine_OpeningFixLog AS g
        ON  g.SSBranchId = l.SSBranchId AND g.TransactionDate = l.TransactionDate
        AND g.ShiftNo = l.ShiftNo AND g.AreaNo = l.AreaNo AND g.StockItemNo = l.StockItemNo
WHERE   g.RunId = '738E5311-8465-4993-8FC2-73665DFAF380'
  AND   DATEDIFF(day, g.PriorShiftDate, g.TransactionDate) > 7
  AND   l.QtyOpen_Original = g.QtyOpen_Original_After
  AND   l.QtyOpen          = g.QtyOpen_After;
GO


/* ============================================================================
   JOB D — STEP 9b — SURGICAL REVERSAL of the gap-bridged rows only.
   *** THIS WRITES. COMMENTED OUT ON PURPOSE. *** One statement.
   Read STEP 9a first. Identical to 7b except for the DATEDIFF line, which is
   what limits it to the 23 rows the correction should never have touched.

   After this, re-running the dry run (STEP 3) will report those 23 as broken
   again — correctly, because they ARE broken and no count exists to repair
   them from. Add them to PumpIT.dbo.STK_OpeningFixExclusion if you want them
   left alone permanently, or leave them and accept 23 + 7 on the next dry run.
   ============================================================================ */

-- UPDATE  l
-- SET     l.QtyOpen_Original = g.QtyOpen_Original_Before,
--         l.QtyOpen          = g.QtyOpen_Before
-- FROM    PumpIT.dbo.STK_StockReconLine AS l
-- JOIN    PumpIT.dbo.STK_StockReconLine_OpeningFixLog AS g
--         ON  g.SSBranchId = l.SSBranchId AND g.TransactionDate = l.TransactionDate
--         AND g.ShiftNo = l.ShiftNo AND g.AreaNo = l.AreaNo AND g.StockItemNo = l.StockItemNo
-- WHERE   g.RunId = '738E5311-8465-4993-8FC2-73665DFAF380'
--   AND   DATEDIFF(day, g.PriorShiftDate, g.TransactionDate) > 7
--   AND   l.QtyOpen_Original = g.QtyOpen_Original_After
--   AND   l.QtyOpen          = g.QtyOpen_After;
-- GO
