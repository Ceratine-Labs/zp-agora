/* Opening correction rollout: read-only evidence before any continuation.
   Captured 25 October 2026. Run these SELECTs against the customer instance;
   this file never runs the correction worker or changes customer data.

   Verified on 25 October: 17 configured sites; only branch 9 has logged
   changes (2,153 on 21 Sep and 7 on 22 Sep). A zero log count means no
   recorded changes, not proof that a zero-candidate run never occurred.
   The installed worker still uses @Tolerance=0.0001. The new local package
   uses count equality 0.0005 while retaining a tighter stale-value guard.

   Continuation order, after Ryan confirms scope and cutoff:
   1. Confirm an explicit end date for the remaining sites and whether later
      changes at branch 9 are included. Do not let NULL extend the old scope.
   2. Take and verify a new restorable backup/copy. The existing
      STK_StockReconLine_Bak_20260921 is dated evidence, not a current rollback.
   3. Review active Agora amendment overlap and resolve affected runs first.
   4. Deploy the reviewed tolerance-only worker package with both chains
      independent, water exclusions enabled, and ForceOriginalClose...=0.
   5. Run WhatIf=1 for the exact approved branch/date list. Retain candidate
      counts, totals and keyed rows; investigate unexpected movement first.
   6. Apply that approved list with a recorded RunId, then verify every
      changed key against its log, untouched closes/issues/POS, independent
      chains, water exclusions, and a repeat WhatIf returning no candidates.
   7. Compare to a fresh preview. Rollback uses this RunId's recorded priors
      only where the live row still equals the recorded post-change value.
   Execution is separate from this read-only preflight.
*/

SELECT s.SSBranchId, s.SiteName, s.FromDate, s.ToDate, s.IsEnabled,
       COUNT(g.FixLogId) AS LoggedChangedRows,
       COUNT(DISTINCT g.RunId) AS RunsWithChanges,
       MIN(g.RunAt) AS FirstRunAt, MAX(g.RunAt) AS LastRunAt,
       MAX(g.TransactionDate) AS LastCorrectedDate
FROM PumpIT.dbo.STK_OpeningFixScope s
LEFT JOIN PumpIT.dbo.STK_StockReconLine_OpeningFixLog g
  ON g.SSBranchId = s.SSBranchId
GROUP BY s.SSBranchId, s.SiteName, s.FromDate, s.ToDate, s.IsEnabled
ORDER BY s.SSBranchId;

SELECT o.name, o.modify_date, m.definition
FROM PumpIT.sys.objects o
JOIN PumpIT.sys.sql_modules m ON m.object_id = o.object_id
WHERE o.name IN ('sp_RebuildStockReconLine_QtyOpen_FromPriorClose',
                 'sp_RebuildStockReconLine_QtyOpen_RunAll');

SELECT SSBranchId, StockItemNo, Reason
FROM PumpIT.dbo.STK_OpeningFixExclusion
ORDER BY SSBranchId, StockItemNo;

SELECT t.name, SUM(p.rows) AS RowsInCopy
FROM PumpIT.sys.tables t
JOIN PumpIT.sys.partitions p ON p.object_id=t.object_id AND p.index_id IN (0,1)
WHERE t.name LIKE 'STK_StockReconLine_Bak_%'
GROUP BY t.name;

SELECT a.BranchId, a.RunId, COUNT_BIG(*) AS ActiveAmendmentsInConfiguredScope,
       MIN(a.TransactionDate) AS FirstShift, MAX(a.TransactionDate) AS LastShift
FROM Agora.agora.StockReconAmendment a
JOIN PumpIT.dbo.STK_OpeningFixScope s ON s.SSBranchId = a.BranchId
WHERE a.State = 'active' AND s.IsEnabled = 1
  AND a.TransactionDate >= s.FromDate
  AND (s.ToDate IS NULL OR a.TransactionDate <= s.ToDate)
GROUP BY a.BranchId, a.RunId
ORDER BY a.BranchId, a.RunId;
