/* ============================================================================
   09 — BRANCH 9, VERIFIED. READS ONLY.

   Ryan, 22 Sep 2026: "just focus on getting branch id 9 right for now please."
   This is what that came to. Every statement is a SELECT; no procedure is
   EXECed against the customer's instance, because step 3 rewrites the
   correction's own what-if logic as a query rather than calling it.

   Run it in DBeaver one statement at a time (Ctrl+Enter). Everything is named
   in full, so the database dropdown does not matter, and no variable crosses
   a statement boundary.

   WHAT IT ANSWERED ON 22 Sep 2026 (results inline under each step, so a
   re-run can be diffed against the day it was first taken).
   ============================================================================ */


/* ---------------------------------------------------------------------------
   STEP 1 — what is deployed, and how much has been logged.

   22 Sep:  agora_db 1 | fix_objects 5 | runs 1 | log_rows 2153
   --------------------------------------------------------------------------- */
SELECT  (SELECT COUNT(*) FROM sys.databases WHERE name = 'Agora')                        AS agora_db,
        (SELECT COUNT(*) FROM PumpIT.sys.objects
          WHERE name IN ('STK_StockReconLine_OpeningFixLog','STK_OpeningFixExclusion',
                         'STK_OpeningFixScope','sp_RebuildStockReconLine_QtyOpen_FromPriorClose',
                         'sp_RebuildStockReconLine_QtyOpen_RunAll'))                     AS fix_objects,
        (SELECT COUNT(DISTINCT RunId) FROM PumpIT.dbo.STK_StockReconLine_OpeningFixLog)  AS runs,
        (SELECT COUNT(*) FROM PumpIT.dbo.STK_StockReconLine_OpeningFixLog)               AS log_rows;


/* ---------------------------------------------------------------------------
   STEP 2 — what the 21 Sep run did, out of its own audit log.

   22 Sep:  RunId 738E5311-8465-4993-8FC2-73665DFAF380, by Revteck,
            2026-09-21 19:36:38.730, branch 9
            2,153 rows | 2,075 as-captured | 141 working | 553 never entered
            12 areas | 307 items | 2024-08-29 .. 2026-09-20
            gross R 1,252,099.58 | NET +R 46,398.15
   --------------------------------------------------------------------------- */
SELECT  g.RunId,
        g.SSBranchId,
        MIN(g.RunBy)                                                      AS run_by,
        CONVERT(varchar(30), MIN(g.RunAt), 121)                           AS run_at,
        COUNT(*)                                                          AS rows_changed,
        SUM(CASE WHEN ABS(g.QtyOpen_Original_After - g.QtyOpen_Original_Before) > 0.0001 THEN 1 ELSE 0 END) AS as_captured_openings,
        SUM(CASE WHEN ABS(g.QtyOpen_After - g.QtyOpen_Before) > 0.0001 THEN 1 ELSE 0 END)                   AS working_openings,
        SUM(CASE WHEN g.QtyOpen_Original_Before = 0 THEN 1 ELSE 0 END)    AS openings_never_entered,
        CONVERT(date, MIN(g.TransactionDate))                             AS first_shift,
        CONVERT(date, MAX(g.TransactionDate))                             AS last_shift,
        COUNT(DISTINCT g.AreaNo)                                          AS areas,
        COUNT(DISTINCT g.StockItemNo)                                     AS items,
        CAST(SUM(ABS(g.QtyOpen_Original_After - g.QtyOpen_Original_Before) * g.SellPrice) AS decimal(20,2)) AS gross_value_moved,
        CAST(SUM((g.QtyOpen_Original_After - g.QtyOpen_Original_Before) * g.SellPrice)    AS decimal(20,2)) AS net_value_moved
FROM    PumpIT.dbo.STK_StockReconLine_OpeningFixLog AS g
GROUP BY g.RunId, g.SSBranchId;


/* ---------------------------------------------------------------------------
   STEP 3 — is the run still reversible?

   22 Sep:  log_rows 2153 | still_reversible 2152 | row_gone 0 | edited_since 1
   --------------------------------------------------------------------------- */
SELECT  g.RunId,
        COUNT(*)                                                                  AS log_rows,
        SUM(CASE WHEN ABS(l.QtyOpen_Original - g.QtyOpen_Original_After) <= 0.0001
                  AND ABS(l.QtyOpen          - g.QtyOpen_After)          <= 0.0001
                 THEN 1 ELSE 0 END)                                               AS still_reversible,
        SUM(CASE WHEN l.SSBranchId IS NULL THEN 1 ELSE 0 END)                     AS row_gone_from_source,
        SUM(CASE WHEN l.SSBranchId IS NOT NULL
                  AND (ABS(l.QtyOpen_Original - g.QtyOpen_Original_After) > 0.0001
                    OR ABS(l.QtyOpen          - g.QtyOpen_After)          > 0.0001)
                 THEN 1 ELSE 0 END)                                               AS edited_since
FROM    PumpIT.dbo.STK_StockReconLine_OpeningFixLog AS g
LEFT JOIN PumpIT.dbo.STK_StockReconLine AS l
        ON  l.SSBranchId      = g.SSBranchId
        AND l.TransactionDate = g.TransactionDate
        AND l.ShiftNo         = g.ShiftNo
        AND l.AreaNo          = g.AreaNo
        AND l.StockItemNo     = g.StockItemNo
GROUP BY g.RunId;


/* ---------------------------------------------------------------------------
   STEP 4 — the what-if, as a query. What would a re-run change NOW?

   This is sp_RebuildStockReconLine_QtyOpen_FromPriorClose's own logic —
   same window, same exclusion table, same LAG, same tolerance — written as a
   SELECT so nothing is executed against production.

   22 Sep:  302,359 lines scanned | 7 would change | 7 as-captured |
            0 working | R 328.66 outstanding
            -> the correction is IDEMPOTENT against live data.
   --------------------------------------------------------------------------- */
WITH src AS (
    SELECT  l.SSBranchId, l.TransactionDate, l.ShiftNo, l.AreaNo, l.StockItemNo,
            l.SellPrice, l.QtyOpen, l.QtyOpen_Original, l.QtyClose, l.QtyClose_Original
    FROM    PumpIT.dbo.STK_StockReconLine AS l
    WHERE   l.SSBranchId = 9
      AND   l.TransactionDate >= '1900-01-01'
      AND   l.TransactionDate <= CONVERT(datetime, CONVERT(date, SYSDATETIME()))
      AND   NOT EXISTS (SELECT 1 FROM PumpIT.dbo.STK_OpeningFixExclusion AS x
                        WHERE x.SSBranchId = l.SSBranchId AND x.StockItemNo = l.StockItemNo)
), chained AS (
    SELECT  src.*,
            LAG(src.QtyClose_Original) OVER (PARTITION BY src.AreaNo, src.StockItemNo
                                             ORDER BY src.TransactionDate, src.ShiftNo) AS PriorCloseOrig,
            LAG(src.QtyClose)          OVER (PARTITION BY src.AreaNo, src.StockItemNo
                                             ORDER BY src.TransactionDate, src.ShiftNo) AS PriorCloseWork
    FROM    src
), flagged AS (
    SELECT  c.*,
            CASE WHEN ABS(c.PriorCloseOrig - c.QtyOpen_Original) > 0.0001 THEN 1 ELSE 0 END AS OrigBroken,
            CASE WHEN ABS(c.PriorCloseWork - c.QtyOpen)          > 0.0001 THEN 1 ELSE 0 END AS WorkBroken
    FROM    chained AS c
    WHERE   c.PriorCloseOrig IS NOT NULL
)
SELECT  COUNT(*)                                                                AS lines_scanned,
        SUM(CASE WHEN f.OrigBroken = 1 OR f.WorkBroken = 1 THEN 1 ELSE 0 END)   AS would_change_now,
        SUM(f.OrigBroken)                                                       AS as_captured_still_broken,
        SUM(f.WorkBroken)                                                       AS working_still_broken,
        CAST(SUM(CASE WHEN f.OrigBroken = 1
                      THEN ABS(f.PriorCloseOrig - f.QtyOpen_Original) * f.SellPrice ELSE 0 END) AS decimal(20,2)) AS gross_still_outstanding
FROM    flagged AS f;


/* ---------------------------------------------------------------------------
   STEP 5 — name the outstanding rows, and say where each came from.

   THIS IS THE STEP THAT SETTLED THE OPEN QUESTION IN THE PACK. The 21 Sep run
   went at 19:36, inside trading hours, and its RowsSkipped_ChangedUnderUs
   count was never recorded. If the run had skipped rows, they would show up
   here as broken openings that PRE-DATE the run.

   22 Sep: all seven carry CreateDateTime 2026-09-22 12:01..12:04 and none is
   in the log. NOTHING WAS SKIPPED. The seven are this morning's capture of
   Saturday's shifts, and they are the ongoing leak, not run residue.

     trans_date  sh area item  opening_now prior_close  row_created
     2026-09-21   1   11  634        0.218       0.658  2026-09-22 12:04:36.193
     2026-09-21   1   11  635        0.276       0.000  2026-09-22 12:04:37.063
     2026-09-21   1   11  650        1.000       4.000  2026-09-22 12:04:39.140
     2026-09-21   1   11  653        1.000       2.000  2026-09-22 12:04:41.040
     2026-09-21   3   10  270        8.000       3.000  2026-09-22 12:01:09.380
     2026-09-21   3   11  634        0.362       0.356  2026-09-22 12:04:36.310
     2026-09-21   3   11  635        0.374       0.372  2026-09-22 12:04:37.303

   Re-run this on consecutive days. If the count climbs by roughly seven a day,
   the capture path is writing an opening without reading the previous closing
   and the correction is a mop, not a fix.
   --------------------------------------------------------------------------- */
WITH src AS (
    SELECT  l.SSBranchId, l.TransactionDate, l.ShiftNo, l.AreaNo, l.StockItemNo,
            l.QtyOpen_Original, l.QtyClose_Original, l.CreateDateTime
    FROM    PumpIT.dbo.STK_StockReconLine AS l
    WHERE   l.SSBranchId = 9
      AND   NOT EXISTS (SELECT 1 FROM PumpIT.dbo.STK_OpeningFixExclusion AS x
                        WHERE x.SSBranchId = l.SSBranchId AND x.StockItemNo = l.StockItemNo)
), chained AS (
    SELECT  src.*,
            LAG(src.QtyClose_Original) OVER (PARTITION BY src.AreaNo, src.StockItemNo
                                             ORDER BY src.TransactionDate, src.ShiftNo) AS PriorCloseOrig
    FROM    src
)
SELECT  CONVERT(date, c.TransactionDate)            AS trans_date,
        c.ShiftNo, c.AreaNo, c.StockItemNo,
        c.QtyOpen_Original                          AS opening_now,
        c.PriorCloseOrig                            AS prior_closing,
        CONVERT(varchar(30), c.CreateDateTime, 121) AS row_created,
        CASE WHEN g.FixLogId IS NULL THEN 'not in the 21 Sep log'
             ELSE 'in the log - changed then, changed again since' END AS provenance
FROM    chained AS c
LEFT JOIN PumpIT.dbo.STK_StockReconLine_OpeningFixLog AS g
        ON  g.SSBranchId      = c.SSBranchId
        AND g.TransactionDate = c.TransactionDate
        AND g.ShiftNo         = c.ShiftNo
        AND g.AreaNo          = c.AreaNo
        AND g.StockItemNo     = c.StockItemNo
WHERE   c.PriorCloseOrig IS NOT NULL
  AND   ABS(c.PriorCloseOrig - c.QtyOpen_Original) > 0.0001
ORDER BY c.TransactionDate, c.ShiftNo, c.AreaNo, c.StockItemNo;


/* ---------------------------------------------------------------------------
   STEP 6 — does the repair unblock Agora's balancing? Branch 9 against 18.

   agora.usp_StockRecon_PreviewBalancing raises FlagChainBroken wherever
   Open(i) <> Close(i-1) on the _Original pair and BLOCKS THE WHOLE CHAIN.
   Branch 9 was corrected back to the start of history on 21 Sep;
   branch 18 never has been. Same month, same arithmetic.

   22 Sep:  branch  9 | 11,008 lines | link_broken   2 | chains 190 | blocked   1
            branch 18 | 14,484 lines | link_broken 864 | chains 234 | blocked 137

   59% of branch 18's chains carry no proposal at all. Branch 9 has one.
   --------------------------------------------------------------------------- */
WITH c AS (
    SELECT  l.SSBranchId, l.AreaNo, l.StockItemNo,
            CONVERT(date, l.TransactionDate)                        AS TransactionDate,
            l.ShiftNo,
            CONVERT(decimal(18,4), ISNULL(l.QtyOpen_Original,  0))  AS QtyOpen,
            CONVERT(decimal(18,4), ISNULL(l.QtyClose_Original, 0))  AS QtyClose
    FROM    PumpIT.dbo.STK_StockReconLine AS l
    WHERE   l.SSBranchId IN (9, 18)
      AND   l.TransactionDate >= '2026-08-01'
      AND   l.TransactionDate <  '2026-09-01'
), s AS (
    SELECT  c.*,
            LAG(c.QtyClose) OVER (PARTITION BY c.SSBranchId, c.AreaNo, c.StockItemNo
                                  ORDER BY c.TransactionDate, c.ShiftNo) AS PrevClose
    FROM    c
)
SELECT  s.SSBranchId,
        COUNT(*)                                                                 AS lines_in_august,
        SUM(CASE WHEN s.PrevClose IS NOT NULL
                  AND ABS(s.QtyOpen - s.PrevClose) > 0.005 THEN 1 ELSE 0 END)    AS link_broken,
        COUNT(DISTINCT CONVERT(varchar(20), s.AreaNo) + '/' + s.StockItemNo)     AS chains,
        COUNT(DISTINCT CASE WHEN s.PrevClose IS NOT NULL
                  AND ABS(s.QtyOpen - s.PrevClose) > 0.005
                  THEN CONVERT(varchar(20), s.AreaNo) + '/' + s.StockItemNo END) AS chains_blocked
FROM    s
GROUP BY s.SSBranchId
ORDER BY s.SSBranchId;


/* ---------------------------------------------------------------------------
   STEP 7 — how much of the run was churn below Agora's own threshold?

   @Tolerance on the correction is 0.0001. @eps in every Agora stock-recon
   procedure is 0.005 — fifty times looser. On weighed stock (butchery, deli)
   that gap is real.

   22 Sep:  below_agora_eps 207 of 2,153 (9.6%), worth R 59.44 in total.
   --------------------------------------------------------------------------- */
SELECT  COUNT(*)                                                                 AS total_rows_changed,
        SUM(CASE WHEN ABS(g.QtyOpen_Original_After - g.QtyOpen_Original_Before) <= 0.005
                  AND ABS(g.QtyOpen_After          - g.QtyOpen_Before)          <= 0.005
                 THEN 1 ELSE 0 END)                                              AS below_agora_eps,
        CAST(SUM(CASE WHEN ABS(g.QtyOpen_Original_After - g.QtyOpen_Original_Before) <= 0.005
                      THEN ABS(g.QtyOpen_Original_After - g.QtyOpen_Original_Before) * g.SellPrice
                      ELSE 0 END) AS decimal(20,2))                              AS value_of_those
FROM    PumpIT.dbo.STK_StockReconLine_OpeningFixLog AS g;


/* ---------------------------------------------------------------------------
   STEP 8 — Agora's own balancing: what is committed, and is any of it stale?

   Branch 9 carries NO amendments, which is why the two-writers problem in
   08-overlap-with-agora-amendments.sql does not touch this work.

   22 Sep:  branch 18 | 44 live active | 0 missing | 6 counts have since moved
            branch 25 | 17 live active | 0 missing | 0 counts have since moved

   Those 6 are not this pack's doing — it has never run at branch 18. They are
   ordinary re-capture, and they matter because agora.usp_StockRecon_Reverse
   writes its prior pair unconditionally. Ryan's call, 22 Sep: leave it
   absolute.
   --------------------------------------------------------------------------- */
SELECT  a.BranchId,
        a.StampMode,
        a.[State],
        COUNT(*)                                                                  AS amendments,
        SUM(CASE WHEN l.SSBranchId IS NULL THEN 1 ELSE 0 END)                     AS source_row_missing,
        SUM(CASE WHEN l.SSBranchId IS NOT NULL
                  AND (ABS(CONVERT(decimal(18,3), ISNULL(l.QtyOpen,  0)) - a.NewQtyOpen)  > 0.005
                    OR ABS(CONVERT(decimal(18,3), ISNULL(l.QtyClose, 0)) - a.NewQtyClose) > 0.005)
                 THEN 1 ELSE 0 END)                                               AS counts_have_since_moved
FROM    Agora.agora.StockReconAmendment AS a
LEFT JOIN PumpIT.dbo.STK_StockReconLine AS l
        ON  l.SSBranchId                     = a.BranchId
        AND CONVERT(date, l.TransactionDate) = a.TransactionDate
        AND l.ShiftNo                        = a.ShiftNo
        AND l.AreaNo                         = a.AreaNo
        AND l.StockItemNo                    = a.StockItemNo
GROUP BY a.BranchId, a.StampMode, a.[State]
ORDER BY a.BranchId;
