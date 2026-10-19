/* ============================================================================
   WHAT THE CORRECTION ACTUALLY DID AT BRANCH 9  —  Esikhawini Convenience
   ----------------------------------------------------------------------------
   READS ONLY. Not one statement in this file writes anything, anywhere.
   Every object is named in full, so it does not matter which database the
   client is connected to. There is no GO, no USE, no variable and no temp
   table: each step is ONE statement, so Ctrl+Enter on its own works.

   It reads dbo.STK_StockReconLine_OpeningFixLog, which the correction wrote
   through OUTPUT in the same statement as the UPDATE — so it is the set that
   actually changed, with the true before values, not a snapshot taken near it.

   Run STEP 1 first. If it says NEVER RUN, stop: nothing was corrected here and
   the rest return nothing.

   Paste the grids back and the report gets built from them.
   ============================================================================ */


/* ---- STEP 1 — did it run here at all, and how many times? ----------------
   One row per run that touched branch 9. Keep the RunId — every later step
   reports per run, and the reversal in 06 STEP 7b needs it.                 */
SELECT  CONVERT(varchar(36), g.RunId)                        AS run_id,
        CONVERT(varchar(19), MIN(g.RunAt), 121)              AS run_started,
        CONVERT(varchar(19), MAX(g.RunAt), 121)              AS run_finished,
        DATEDIFF(second, MIN(g.RunAt), MAX(g.RunAt))         AS run_seconds,
        MAX(g.RunBy)                                         AS run_by,
        COUNT_BIG(*)                                         AS rows_changed,
        CONVERT(varchar(10), MIN(g.TransactionDate), 23)     AS earliest_shift_corrected,
        CONVERT(varchar(10), MAX(g.TransactionDate), 23)     AS latest_shift_corrected
FROM    PumpIT.dbo.STK_StockReconLine_OpeningFixLog AS g
WHERE   g.SSBranchId = 9 /*branch*/
GROUP BY g.RunId
ORDER BY MIN(g.RunAt);


/* ---- STEP 1b — the same question in one line, including the NEVER RUN case
   Safe to run before STEP 1. Returns exactly one row whatever the state.    */
SELECT  CASE WHEN OBJECT_ID('PumpIT.dbo.STK_StockReconLine_OpeningFixLog', 'U') IS NULL
             THEN 'NOT DEPLOYED - file 02b has not been run'
             WHEN (SELECT COUNT_BIG(*) FROM PumpIT.dbo.STK_StockReconLine_OpeningFixLog WHERE SSBranchId = 9 /*branch*/) = 0
             THEN 'NEVER RUN at branch 9 - STEP 4 of file 06 was not executed'
             ELSE 'CORRECTED' END                                                   AS verdict,
        (SELECT COUNT_BIG(*) FROM PumpIT.dbo.STK_StockReconLine_OpeningFixLog
          WHERE SSBranchId = 9 /*branch*/)                                          AS rows_at_branch_9,
        (SELECT COUNT_BIG(*) FROM PumpIT.dbo.STK_StockReconLine_OpeningFixLog)       AS rows_whole_estate,
        (SELECT COUNT(DISTINCT RunId) FROM PumpIT.dbo.STK_StockReconLine_OpeningFixLog)
                                                                                    AS runs_whole_estate,
        CONVERT(varchar(19), SYSDATETIME(), 121)                                    AS read_at;


/* ---- STEP 2 — THE HEADLINE. One row per run. ----------------------------
   as_captured_*  = QtyOpen_Original, the chain the correction was asked for
   working_*      = QtyOpen, repaired from its OWN closing (never cross-fed)
   gross          = every movement counted positive
   net            = the effect on the site's book value                      */
SELECT  CONVERT(varchar(36), g.RunId)                                                AS run_id,
        COUNT_BIG(*)                                                                 AS lines_changed,
        SUM(CASE WHEN ABS(g.QtyOpen_Original_After - g.QtyOpen_Original_Before) > 0.0001 THEN 1 ELSE 0 END) AS as_captured_openings,
        SUM(CASE WHEN ABS(g.QtyOpen_After          - g.QtyOpen_Before)          > 0.0001 THEN 1 ELSE 0 END) AS working_openings,
        COUNT(DISTINCT g.StockItemNo)                                                AS products,
        COUNT(DISTINCT g.AreaNo)                                                     AS areas,
        COUNT(DISTINCT CONVERT(date, g.TransactionDate))                             AS trading_days,
        SUM(CASE WHEN g.QtyOpen_Original_Before = 0 THEN 1 ELSE 0 END)                AS opening_never_entered,
        SUM(CASE WHEN g.QtyOpen_Original_Before <> 0 THEN 1 ELSE 0 END)               AS opening_disagreed_with_count,
        CAST(SUM(ABS(g.QtyOpen_Original_After - g.QtyOpen_Original_Before)) AS decimal(20,2))                AS units_as_captured,
        CAST(SUM(ABS(g.QtyOpen_After          - g.QtyOpen_Before))          AS decimal(20,2))                AS units_working,
        CAST(SUM(ABS(g.QtyOpen_Original_After - g.QtyOpen_Original_Before) * ISNULL(g.SellPrice, 0)) AS decimal(20,2)) AS gross_value,
        CAST(SUM(   (g.QtyOpen_Original_After - g.QtyOpen_Original_Before) * ISNULL(g.SellPrice, 0)) AS decimal(20,2)) AS net_value,
        SUM(CASE WHEN g.SellPrice IS NULL THEN 1 ELSE 0 END)                         AS rows_with_no_sell_price
FROM    PumpIT.dbo.STK_StockReconLine_OpeningFixLog AS g
WHERE   g.SSBranchId = 9 /*branch*/
GROUP BY g.RunId
ORDER BY MIN(g.RunAt);


/* ---- STEP 3 — which way did it move, and by how much --------------------
   An opening raised means the shift now starts with stock it was previously
   denied; lowered is the reverse. Both are the same repair.                 */
SELECT  CASE WHEN g.QtyOpen_Original_Before = 0                                  THEN '1 - opening was never entered'
             WHEN g.QtyOpen_Original_After  > g.QtyOpen_Original_Before          THEN '2 - opening raised to the prior close'
             ELSE                                                                     '3 - opening lowered to the prior close' END AS movement,
        COUNT_BIG(*)                                                             AS lines,
        COUNT(DISTINCT g.StockItemNo)                                            AS products,
        CAST(SUM(ABS(g.QtyOpen_Original_After - g.QtyOpen_Original_Before)) AS decimal(20,2))                          AS units,
        CAST(SUM(ABS(g.QtyOpen_Original_After - g.QtyOpen_Original_Before) * ISNULL(g.SellPrice, 0)) AS decimal(20,2)) AS gross_value,
        CAST(SUM(   (g.QtyOpen_Original_After - g.QtyOpen_Original_Before) * ISNULL(g.SellPrice, 0)) AS decimal(20,2)) AS net_value
FROM    PumpIT.dbo.STK_StockReconLine_OpeningFixLog AS g
WHERE   g.SSBranchId = 9 /*branch*/
GROUP BY CASE WHEN g.QtyOpen_Original_Before = 0                                  THEN '1 - opening was never entered'
              WHEN g.QtyOpen_Original_After  > g.QtyOpen_Original_Before          THEN '2 - opening raised to the prior close'
              ELSE                                                                     '3 - opening lowered to the prior close' END
ORDER BY movement;


/* ---- STEP 4 — by area. The area name is a scalar subquery, not a join, so
   a duplicate row in STK_Area cannot multiply the figures.                  */
SELECT  g.AreaNo,
        (SELECT MAX(a.AreaDescription) FROM PumpIT.dbo.STK_Area AS a
          WHERE a.SSBranchId = 9 /*branch*/ AND a.AreaNo = g.AreaNo)             AS area,
        COUNT_BIG(*)                                                             AS lines,
        COUNT(DISTINCT g.StockItemNo)                                            AS products,
        SUM(CASE WHEN g.QtyOpen_Original_Before = 0 THEN 1 ELSE 0 END)           AS never_entered,
        CAST(SUM(ABS(g.QtyOpen_Original_After - g.QtyOpen_Original_Before)) AS decimal(20,2))                          AS units,
        CAST(SUM(ABS(g.QtyOpen_Original_After - g.QtyOpen_Original_Before) * ISNULL(g.SellPrice, 0)) AS decimal(20,2)) AS gross_value,
        CAST(SUM(   (g.QtyOpen_Original_After - g.QtyOpen_Original_Before) * ISNULL(g.SellPrice, 0)) AS decimal(20,2)) AS net_value
FROM    PumpIT.dbo.STK_StockReconLine_OpeningFixLog AS g
WHERE   g.SSBranchId = 9 /*branch*/
GROUP BY g.AreaNo
ORDER BY gross_value DESC;


/* ---- STEP 5 — by month, so the spread over the three years is visible --- */
SELECT  CONVERT(char(7), g.TransactionDate, 126)                                 AS month_,
        COUNT_BIG(*)                                                             AS lines,
        COUNT(DISTINCT g.StockItemNo)                                            AS products,
        SUM(CASE WHEN g.QtyOpen_Original_Before = 0 THEN 1 ELSE 0 END)           AS never_entered,
        CAST(SUM(ABS(g.QtyOpen_Original_After - g.QtyOpen_Original_Before)) AS decimal(20,2))                          AS units,
        CAST(SUM(ABS(g.QtyOpen_Original_After - g.QtyOpen_Original_Before) * ISNULL(g.SellPrice, 0)) AS decimal(20,2)) AS gross_value
FROM    PumpIT.dbo.STK_StockReconLine_OpeningFixLog AS g
WHERE   g.SSBranchId = 9 /*branch*/
GROUP BY CONVERT(char(7), g.TransactionDate, 126)
ORDER BY month_;


/* ---- STEP 6 — the products that carry the value. Top 30. ----------------
   STK_StockMaster is unique on (SSBranchId, StockItemNo), so this join does
   not fan out — but it is a scalar subquery anyway, for the same reason as
   STEP 4.                                                                   */
SELECT  TOP (30)
        g.StockItemNo,
        (SELECT MAX(m.StockItemDescription) FROM PumpIT.dbo.STK_StockMaster AS m
          WHERE m.SSBranchId = 9 /*branch*/ AND m.StockItemNo = g.StockItemNo)   AS product,
        COUNT_BIG(*)                                                             AS lines,
        COUNT(DISTINCT g.AreaNo)                                                 AS areas,
        SUM(CASE WHEN g.QtyOpen_Original_Before = 0 THEN 1 ELSE 0 END)           AS never_entered,
        CAST(SUM(ABS(g.QtyOpen_Original_After - g.QtyOpen_Original_Before)) AS decimal(20,2))                          AS units,
        CAST(SUM(ABS(g.QtyOpen_Original_After - g.QtyOpen_Original_Before) * ISNULL(g.SellPrice, 0)) AS decimal(20,2)) AS gross_value,
        CAST(SUM(   (g.QtyOpen_Original_After - g.QtyOpen_Original_Before) * ISNULL(g.SellPrice, 0)) AS decimal(20,2)) AS net_value
FROM    PumpIT.dbo.STK_StockReconLine_OpeningFixLog AS g
WHERE   g.SSBranchId = 9 /*branch*/
GROUP BY g.StockItemNo
ORDER BY gross_value DESC;


/* ---- STEP 7 — the twenty single biggest line changes --------------------- */
SELECT  TOP (20)
        CONVERT(varchar(10), g.TransactionDate, 23)                              AS tx_date,
        g.ShiftNo, g.AreaNo, g.StockItemNo,
        (SELECT MAX(m.StockItemDescription) FROM PumpIT.dbo.STK_StockMaster AS m
          WHERE m.SSBranchId = 9 /*branch*/ AND m.StockItemNo = g.StockItemNo)   AS product,
        CAST(g.QtyOpen_Original_Before AS decimal(20,2))                         AS opening_before,
        CAST(g.QtyOpen_Original_After  AS decimal(20,2))                         AS opening_after,
        CONVERT(varchar(10), g.PriorShiftDate, 23)                               AS prior_shift_date,
        g.PriorShiftNo,
        CAST(g.PriorClose_Original AS decimal(20,2))                             AS prior_close,
        CAST(ABS(g.QtyOpen_Original_After - g.QtyOpen_Original_Before) * ISNULL(g.SellPrice, 0) AS decimal(20,2)) AS value_moved
FROM    PumpIT.dbo.STK_StockReconLine_OpeningFixLog AS g
WHERE   g.SSBranchId = 9 /*branch*/
ORDER BY value_moved DESC;


/* ---- STEP 8 — the working chain on its own ------------------------------
   Expected to be tens, not thousands. Thousands means
   @ForceOriginalCloseIntoWorkingOpen was on, which it must not be.          */
SELECT  COUNT_BIG(*)                                                             AS working_lines_changed,
        COUNT(DISTINCT g.StockItemNo)                                            AS products,
        CAST(SUM(ABS(g.QtyOpen_After - g.QtyOpen_Before)) AS decimal(20,2))                          AS units,
        CAST(SUM(ABS(g.QtyOpen_After - g.QtyOpen_Before) * ISNULL(g.SellPrice, 0)) AS decimal(20,2)) AS gross_value,
        SUM(CASE WHEN ABS(g.QtyOpen_After - g.QtyOpen_Before) > 0.0001
                  AND ABS(g.QtyOpen_After - g.PriorClose) > 0.0001 THEN 1 ELSE 0 END)                AS not_equal_to_its_own_prior_close
FROM    PumpIT.dbo.STK_StockReconLine_OpeningFixLog AS g
WHERE   g.SSBranchId = 9 /*branch*/
  AND   ABS(g.QtyOpen_After - g.QtyOpen_Before) > 0.0001;


/* ---- STEP 9 — the three guarantees, checked rather than asserted ---------
   water_lines_touched        must be 0 — item 378 is the excluded totaliser
   chains_cross_contaminated  must be 0 — an opening written from the OTHER
                              chain's closing, which is the R12.39m switch
   openings_not_from_prior_close
                              must be 0 — every new opening equals the prior
                              shift's closing on the same chain              */
SELECT  SUM(CASE WHEN g.StockItemNo = N'378' THEN 1 ELSE 0 END)                  AS water_lines_touched,
        SUM(CASE WHEN ABS(g.QtyOpen_Original_After - g.QtyOpen_Original_Before) > 0.0001
                  AND ABS(g.QtyOpen_Original_After - g.PriorClose_Original) > 0.0001 THEN 1 ELSE 0 END)
                                                                                 AS openings_not_from_prior_close,
        SUM(CASE WHEN ABS(g.QtyOpen_After - g.QtyOpen_Before) > 0.0001
                  AND ABS(g.QtyOpen_After - g.PriorClose_Original) <= 0.0001
                  AND ABS(g.QtyOpen_After - g.PriorClose)          >  0.0001 THEN 1 ELSE 0 END)
                                                                                 AS chains_cross_contaminated,
        SUM(CASE WHEN g.PriorShiftDate > g.TransactionDate THEN 1 ELSE 0 END)     AS prior_shift_after_the_shift
FROM    PumpIT.dbo.STK_StockReconLine_OpeningFixLog AS g
WHERE   g.SSBranchId = 9 /*branch*/;


/* ---- STEP 10 — is it still reversible? -----------------------------------
   still_as_the_correction_left_it is what 06 STEP 7b would put back.
   edited_since is a line somebody has captured or amended after the run:
   the reversal deliberately skips those, so they stay as they are now.      */
SELECT  COUNT_BIG(*)                                                             AS logged_rows,
        SUM(CASE WHEN ABS(l.QtyOpen_Original - g.QtyOpen_Original_After) <= 0.0001
                  AND ABS(l.QtyOpen          - g.QtyOpen_After)          <= 0.0001 THEN 1 ELSE 0 END) AS still_as_the_correction_left_it,
        SUM(CASE WHEN ABS(l.QtyOpen_Original - g.QtyOpen_Original_After) >  0.0001
                   OR ABS(l.QtyOpen          - g.QtyOpen_After)          >  0.0001 THEN 1 ELSE 0 END) AS edited_since,
        SUM(CASE WHEN l.SSBranchId IS NULL THEN 1 ELSE 0 END)                    AS line_no_longer_exists
FROM    PumpIT.dbo.STK_StockReconLine_OpeningFixLog AS g
LEFT JOIN PumpIT.dbo.STK_StockReconLine AS l
       ON  l.SSBranchId      = g.SSBranchId
       AND l.TransactionDate = g.TransactionDate
       AND l.ShiftNo         = g.ShiftNo
       AND l.AreaNo          = g.AreaNo
       AND l.StockItemNo     = g.StockItemNo
WHERE   g.SSBranchId = 9 /*branch*/;


/* ---- STEP 10b — can the join in STEP 10 fan out? ------------------------
   dbo.STK_StockReconLine is a HEAP with no unique index, so nothing in the
   database enforces that (branch, date, shift, area, item) is one row. Every
   script in this pack treats it as the key. If duplicate_keys is not 0, the
   figures in STEP 10 are inflated and the reversal in 06 STEP 7b would write
   to more rows than it logged. It is 0 on the local stub for branch 9; this
   is the same question asked of the live table.                             */
SELECT  COUNT_BIG(*)                                                             AS duplicate_keys,
        ISNULL(SUM(d.copies), 0)                                                 AS rows_involved
FROM   (SELECT COUNT_BIG(*) AS copies
        FROM   PumpIT.dbo.STK_StockReconLine
        WHERE  SSBranchId = 9 /*branch*/
        GROUP BY SSBranchId, TransactionDate, ShiftNo, AreaNo, StockItemNo
        HAVING COUNT_BIG(*) > 1) AS d;


/* ---- STEP 11 — what is left broken at branch 9 now ----------------------
   The procedure in what-if mode. Reads the site's whole history and writes
   NOTHING. Close to zero proves the correction landed and is idempotent; a
   large number means it was skipped under contention and should be re-run.
   Give it a minute or two and do not cancel it.                             */
EXEC PumpIT.dbo.sp_RebuildStockReconLine_QtyOpen_FromPriorClose
     @BranchId = 9 /*branch*/,
     @FromDate = '1900-01-01' /*from*/,
     @WhatIf   = 1;
