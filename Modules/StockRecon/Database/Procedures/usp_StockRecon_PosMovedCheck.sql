/*
    agora.usp_StockRecon_PosMovedCheck — the items on a run whose amendments
    still stand but were worked out against a POS quantity PumpIT has since
    changed (14 Nov 2026).

    Caltex Ulundi, 14 Nov 2026: run 40111 balanced 31 October on 3 November,
    before that day's till quantities reached PumpIT. With a POS of 0 it read
    Loose Cigarette S as 61 short and cut the issue from 300 to 240. Head
    office's update script later filled the POS in (63), so the cut that stood
    in PumpIT now made the shift 62 short. 117 items at 8 sites were in the
    same state that day.

    An amendment is only as right as the POS it was worked out on. Per item
    (area and item) on this run: the active amendments whose run line's QtyPOS
    differs from the POS on the PumpIT line now, by more than 0.01. The fix is
    to reverse that item's chain (usp_StockRecon_ReverseChain) and preview the
    period again; RunLineId is the line the reversal is reached from.

    Read-only: this reports, it changes nothing and holds nothing back.

    Parameters: @BranchId, @RunId
    Result: one row per item whose POS has moved since the run.
*/
CREATE OR ALTER PROCEDURE agora.usp_StockRecon_PosMovedCheck
    @BranchId INT,
    @RunId    INT
AS
BEGIN
    SET NOCOUNT ON;

    IF NOT EXISTS (SELECT 1 FROM agora.StockReconRun r WHERE r.BranchId = @BranchId AND r.Id = @RunId)
        THROW 51000, 'AGORA:NOT_FOUND:That run is not on this site.', 1;

    WITH moved AS (
        SELECT a.AreaNo, a.StockItemNo, a.RunLineId, a.TransactionDate, a.ShiftNo,
               CONVERT(decimal(18, 4), ISNULL(rl.QtyPOS, 0))       AS QtyPOSRun,
               CONVERT(decimal(18, 4), ISNULL(l.QtyComputer, 0))   AS QtyPOSNow,
               CASE WHEN a.NewQtyIssued IS NOT NULL THEN 1 ELSE 0 END AS IssueWrite
        FROM agora.StockReconAmendment a
        JOIN agora.StockReconRunLine rl
          ON rl.BranchId = a.BranchId AND rl.RunId = a.RunId AND rl.Id = a.RunLineId
        JOIN agora.vw_StockReconLine l
          ON l.BranchId = a.BranchId AND l.AreaNo = a.AreaNo AND l.StockItemNo = a.StockItemNo
         AND l.TransactionDate = a.TransactionDate AND l.ShiftNo = a.ShiftNo
        WHERE a.BranchId = @BranchId AND a.RunId = @RunId
          AND a.State = 'active' AND a.RunLineId > 0
          AND ABS(ISNULL(rl.QtyPOS, 0) - ISNULL(l.QtyComputer, 0)) > 0.01
    )
    SELECT mv.AreaNo,
           ar.AreaDescription,
           mv.StockItemNo,
           sm.StockItemDescription,
           MIN(mv.RunLineId)                                AS RunLineId,
           COUNT(*)                                         AS Shifts,
           SUM(mv.IssueWrite)                               AS IssueWrites,
           CONVERT(date, MIN(mv.TransactionDate))           AS FromDate,
           CONVERT(date, MAX(mv.TransactionDate))           AS ToDate,
           CONVERT(decimal(18, 3), SUM(mv.QtyPOSRun))       AS QtyPOSRun,
           CONVERT(decimal(18, 3), SUM(mv.QtyPOSNow))       AS QtyPOSNow
    FROM moved mv
    LEFT JOIN agora.vw_StockArea ar
      ON ar.BranchId = @BranchId AND ar.AreaNo = mv.AreaNo
    LEFT JOIN agora.vw_StockMaster sm
      ON sm.BranchId = @BranchId AND sm.AreaNo = mv.AreaNo AND sm.StockItemNo = mv.StockItemNo
    GROUP BY mv.AreaNo, ar.AreaDescription, mv.StockItemNo, sm.StockItemDescription
    ORDER BY ar.AreaDescription, sm.StockItemDescription, mv.StockItemNo;
END
