/* ============================================================================
   08 — WHERE THE OPENING-QTY CORRECTION MEETS AGORA'S STOCK-RECON BALANCING

   READS ONLY. Every statement is a SELECT. Nothing here writes, anywhere.

   WHY THIS FILE EXISTS
     Two different things now write QtyOpen / QtyClose on
     PumpIT.dbo.STK_StockReconLine:

       · dbo.sp_RebuildStockReconLine_QtyOpen_FromPriorClose  (this pack, 02)
         with @RepairWorking = 1, and
       · agora.usp_StockRecon_Commit / _Reverse in 'live' stamp mode, which
         Ryan turned on 8 September 2026.

     Neither knows about the other. The opening-qty correction has an
     optimistic guard and skips a row that moved under it; agora's REVERSE has
     no such guard and writes its recorded prior pair unconditionally. So a row
     that both have touched can end up with a value neither log can put back.

     Nothing in this file fixes that. It measures whether it has happened yet,
     and how big the exposure is before the remaining sixteen sites are run.

   HOW TO RUN IT
     DBeaver, one statement at a time (Ctrl+Enter). Every object is named in
     full, so it does not matter which database is selected in the dropdown —
     there is no USE and no GO anywhere in this file, and no variable is
     carried from one step to the next.

     Every step that depends on an object that may not exist is guarded by
     DB_ID / OBJECT_ID and runs the real query through sp_executesql, so a
     missing object gives you a readable line instead of

         SQL Error [208] [S0002]: Invalid object name '...'

     which is the failure mode step 1 of 05-run-the-correction.sql was rebuilt
     to avoid. A check that throws in exactly the case it exists to detect is
     no check at all.

   Prepared 22 September 2026.
   ============================================================================ */


/* ---------------------------------------------------------------------------
   STEP 1 — what is on this instance at all.

   agora_database_present   0 = Agora was never created here, and steps 2-5
                                have nothing to compare. Steps 6 and 7 still
                                work: they read PumpIT only.
   opening_fix_objects      5 = 02-proposed-proc.sql has been deployed.
   --------------------------------------------------------------------------- */
SELECT  (SELECT COUNT(*) FROM sys.databases WHERE name = 'Agora')       AS agora_database_present,
        (SELECT COUNT(*) FROM PumpIT.sys.objects
          WHERE name IN ('STK_StockReconLine_OpeningFixLog',
                         'STK_OpeningFixExclusion',
                         'STK_OpeningFixScope',
                         'sp_RebuildStockReconLine_QtyOpen_FromPriorClose',
                         'sp_RebuildStockReconLine_QtyOpen_RunAll'))    AS opening_fix_objects,
        (SELECT COUNT(*) FROM PumpIT.sys.objects
          WHERE name = 'sp_UpdateAUTOStockReconBalancing')              AS legacy_autobalance_present;


/* ---------------------------------------------------------------------------
   STEP 2 — how much balancing Agora has actually committed, per site.

   'active' means committed and not reversed. A journal-mode amendment wrote
   NOTHING to PumpIT; a live-mode one wrote both count columns. Only the live
   ones can collide.
   --------------------------------------------------------------------------- */
IF DB_ID('Agora') IS NULL
    SELECT 'The Agora database is not on this instance - no balancing has been committed here.' AS note;
ELSE
    EXEC sys.sp_executesql N'
        SELECT  a.BranchId,
                a.StampMode,
                COUNT(*)                                  AS active_amendments,
                CONVERT(date, MIN(a.TransactionDate))     AS earliest_shift,
                CONVERT(date, MAX(a.TransactionDate))     AS latest_shift,
                CONVERT(date, MIN(a.CreatedAt))           AS first_committed,
                CONVERT(date, MAX(a.CreatedAt))           AS last_committed
        FROM    Agora.agora.StockReconAmendment AS a
        WHERE   a.[State] = ''active''
        GROUP BY a.BranchId, a.StampMode
        ORDER BY a.BranchId, a.StampMode;';


/* ---------------------------------------------------------------------------
   STEP 3 — how many of those sit inside a site's opening-fix window.

   These are the rows the remaining sixteen-site run would consider. A row here
   is not yet a problem; it is a row where the two systems would be working on
   the same shift.
   --------------------------------------------------------------------------- */
IF DB_ID('Agora') IS NULL OR OBJECT_ID('PumpIT.dbo.STK_OpeningFixScope', 'U') IS NULL
    SELECT 'Needs the Agora database AND PumpIT.dbo.STK_OpeningFixScope - run 02-proposed-proc.sql first.' AS note;
ELSE
    EXEC sys.sp_executesql N'
        SELECT  s.SSBranchId,
                s.SiteName,
                CONVERT(date, s.FromDate)   AS fix_window_starts,
                a.StampMode,
                COUNT(*)                    AS active_amendments_in_window
        FROM    PumpIT.dbo.STK_OpeningFixScope AS s
        JOIN    Agora.agora.StockReconAmendment AS a
                ON  a.BranchId        = s.SSBranchId
                AND a.TransactionDate >= s.FromDate
                AND a.TransactionDate <= ISNULL(s.ToDate, CONVERT(date, SYSDATETIME()))
        WHERE   s.IsEnabled = 1
          AND   a.[State]   = ''active''
        GROUP BY s.SSBranchId, s.SiteName, s.FromDate, a.StampMode
        ORDER BY COUNT(*) DESC;';


/* ---------------------------------------------------------------------------
   STEP 4 — has it already happened at branch 9?

   The 21 Sep 2026 19:36 run changed 2,153 openings at Esikhawini Convenience.
   Any row here is a shift that run rewrote which ALSO carries a live Agora
   amendment nobody has reversed. For those rows:

     · agora.usp_StockRecon_Reverse would write PriorQtyOpen / PriorQtyClose
       back unconditionally, over the corrected opening, and
     · the opening-fix reversal in 02 would then SKIP the row, because its
       guard requires the row to still hold QtyOpen_After.

   Expected: 0. Confirm it rather than assume it.
   --------------------------------------------------------------------------- */
IF DB_ID('Agora') IS NULL OR OBJECT_ID('PumpIT.dbo.STK_StockReconLine_OpeningFixLog', 'U') IS NULL
    SELECT 'Needs the Agora database AND PumpIT.dbo.STK_StockReconLine_OpeningFixLog - run 02-proposed-proc.sql first.' AS note;
ELSE
    EXEC sys.sp_executesql N'
        SELECT  g.RunId,
                g.SSBranchId,
                COUNT(*) AS rows_the_fix_changed_that_carry_a_live_amendment
        FROM    PumpIT.dbo.STK_StockReconLine_OpeningFixLog AS g
        JOIN    Agora.agora.StockReconAmendment AS a
                ON  a.BranchId        = g.SSBranchId
                AND a.AreaNo          = g.AreaNo
                AND a.StockItemNo     = g.StockItemNo
                AND a.TransactionDate = CONVERT(date, g.TransactionDate)
                AND a.ShiftNo         = g.ShiftNo
        WHERE   a.[State]   = ''active''
          AND   a.StampMode = ''live''
        GROUP BY g.RunId, g.SSBranchId
        ORDER BY g.SSBranchId;';


/* ---------------------------------------------------------------------------
   STEP 5 — is any live amendment already stale?

   For every live amendment still marked active, does the source row still hold
   the figures the commit wrote? A row that has since moved means a reversal of
   that run would write a prior pair over a number somebody else put there.

   This is the one number that decides whether the sixteen-site run can go
   ahead as it stands. Expected: 0.
   --------------------------------------------------------------------------- */
IF DB_ID('Agora') IS NULL
    SELECT 'The Agora database is not on this instance.' AS note;
ELSE
    EXEC sys.sp_executesql N'
        SELECT  a.BranchId,
                COUNT(*) AS live_amendments_whose_counts_have_since_moved
        FROM    Agora.agora.StockReconAmendment AS a
        JOIN    PumpIT.dbo.STK_StockReconLine AS l
                ON  l.SSBranchId                      = a.BranchId
                AND CONVERT(date, l.TransactionDate)  = a.TransactionDate
                AND l.ShiftNo                         = a.ShiftNo
                AND l.AreaNo                          = a.AreaNo
                AND l.StockItemNo                     = a.StockItemNo
        WHERE   a.[State]   = ''active''
          AND   a.StampMode = ''live''
          AND ( ABS(CONVERT(decimal(18,3), ISNULL(l.QtyOpen,  0)) - a.NewQtyOpen)  > 0.005
             OR ABS(CONVERT(decimal(18,3), ISNULL(l.QtyClose, 0)) - a.NewQtyClose) > 0.005 )
        GROUP BY a.BranchId
        ORDER BY a.BranchId;';


/* ---------------------------------------------------------------------------
   STEP 6 — the payoff, measured: does repairing openings unblock balancing?

   agora.usp_StockRecon_PreviewBalancing computes LinkBroken as
       ABS(QtyOpen - LAG(QtyClose) OVER (PARTITION BY AreaNo, StockItemNo
                                         ORDER BY TransactionDate, ShiftNo)) > 0.005
   on the _Original pair, and BLOCKS the whole chain wherever it is 1. On
   branch 18's August that was 880 of 14,483 lines.

   The opening-qty correction's LAG uses the SAME partition and the SAME order,
   so inside its window it should drive that count to zero.

   Branch 9 was corrected on 21 Sep back to the start of history.
   Branch 18 was not corrected at all. Same month, same arithmetic:
   branch 9 should now read near zero and branch 18 should not.

   Branch 9 residue is expected and is not a failure — stock item 378 at
   branch 9 is on the exclusion list (the water-refill totaliser), and a chain
   whose first shift in August falls at the window edge carries no LAG.
   --------------------------------------------------------------------------- */
WITH c AS (
    SELECT  l.SSBranchId,
            l.AreaNo,
            l.StockItemNo,
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
        COUNT(*)                                                                AS lines_in_the_month,
        SUM(CASE WHEN s.PrevClose IS NOT NULL
                  AND ABS(s.QtyOpen - s.PrevClose) > 0.005 THEN 1 ELSE 0 END)   AS link_broken,
        CAST(100.0 * SUM(CASE WHEN s.PrevClose IS NOT NULL
                  AND ABS(s.QtyOpen - s.PrevClose) > 0.005 THEN 1 ELSE 0 END)
             / NULLIF(COUNT(*), 0) AS decimal(6,2))                             AS pct_broken
FROM    s
GROUP BY s.SSBranchId
ORDER BY s.SSBranchId;


/* ---------------------------------------------------------------------------
   STEP 7 — the same measure on the WORKING pair.

   This is the chain @RepairWorking writes and the chain agora's commit writes.
   If balancing has been committed live at a site, this number is ALREADY low
   there by construction, because a commit sets the next opening from the
   closing it just wrote. A high number means balancing has not run there, not
   that balancing broke something.
   --------------------------------------------------------------------------- */
WITH c AS (
    SELECT  l.SSBranchId,
            l.AreaNo,
            l.StockItemNo,
            CONVERT(date, l.TransactionDate)               AS TransactionDate,
            l.ShiftNo,
            CONVERT(decimal(18,4), ISNULL(l.QtyOpen,  0))  AS QtyOpen,
            CONVERT(decimal(18,4), ISNULL(l.QtyClose, 0))  AS QtyClose
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
        COUNT(*)                                                                AS lines_in_the_month,
        SUM(CASE WHEN s.PrevClose IS NOT NULL
                  AND ABS(s.QtyOpen - s.PrevClose) > 0.005 THEN 1 ELSE 0 END)   AS working_link_broken
FROM    s
GROUP BY s.SSBranchId
ORDER BY s.SSBranchId;
