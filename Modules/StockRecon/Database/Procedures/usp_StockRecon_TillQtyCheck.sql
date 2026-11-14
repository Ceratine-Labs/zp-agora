/*
    agora.usp_StockRecon_TillQtyCheck — the run's days where PumpIT's till
    quantity on the recon lines (QtyComputer) disagrees with what the tills
    actually sold (14 Nov 2026).

    Chantal Peens, 13 Nov 2026 ("COMPUTER QTY"): about 2,500 line items since
    1 October carried a computer quantity that did not match the till, so the
    shifts could not balance however they were amended. She sent the script
    she runs to find them and asked for it in Agora: when a site runs a period
    and area, say which quantities are missing or wrong, and that balancing
    cannot be achieved until they are updated.

    Her method, unchanged: per day, area and item, the recon lines' summed
    QtyComputer against the till total from agora.vw_StockReconTillQty, both
    sides present and more than 0.01 apart. Day level, not shift level. A
    recon figure of 0 means the whole day is missing; a figure below the till
    means one shift recorded its quantity and another did not.

    Only the items the run carries are compared. Read-only: this reports, it
    changes nothing and holds nothing back.

    Parameters: @BranchId, @RunId
    Result: one row per day, area and item that disagrees.
*/
CREATE OR ALTER PROCEDURE agora.usp_StockRecon_TillQtyCheck
    @BranchId INT,
    @RunId    INT
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @From DATE, @To DATE;
    SELECT @From = CONVERT(date, r.FromDate), @To = CONVERT(date, r.ToDate)
    FROM agora.StockReconRun r
    WHERE r.BranchId = @BranchId AND r.Id = @RunId;

    IF @From IS NULL
        THROW 51000, 'AGORA:NOT_FOUND:That run is not on this site.', 1;

    DECLARE @Until DATETIME = DATEADD(day, 1, CONVERT(datetime, @To));

    IF OBJECT_ID('tempdb..#items') IS NOT NULL DROP TABLE #items;
    SELECT DISTINCT rl.AreaNo, rl.StockItemNo
    INTO #items
    FROM agora.StockReconRunLine rl
    WHERE rl.BranchId = @BranchId AND rl.RunId = @RunId;

    WITH recon AS (
        SELECT CONVERT(date, l.TransactionDate) AS TransactionDate, l.AreaNo, l.StockItemNo,
               SUM(CONVERT(decimal(18, 4), ISNULL(l.QtyComputer, 0))) AS QtyRecon
        FROM agora.vw_StockReconLine l
        JOIN #items i ON i.AreaNo = l.AreaNo AND i.StockItemNo = l.StockItemNo
        WHERE l.BranchId = @BranchId
          AND l.TransactionDate >= @From AND l.TransactionDate < @Until
        GROUP BY CONVERT(date, l.TransactionDate), l.AreaNo, l.StockItemNo
    ),
    till AS (
        SELECT CONVERT(date, t.TransactionDate) AS TransactionDate, t.AreaNo, t.StockItemNo,
               SUM(t.Qty) AS QtyTill
        FROM agora.vw_StockReconTillQty t
        JOIN #items i ON i.AreaNo = t.AreaNo AND i.StockItemNo = t.StockItemNo
        WHERE t.BranchId = @BranchId
          AND t.TransactionDate >= @From AND t.TransactionDate < @Until
        GROUP BY CONVERT(date, t.TransactionDate), t.AreaNo, t.StockItemNo
    )
    SELECT r.TransactionDate,
           r.AreaNo,
           a.AreaDescription,
           r.StockItemNo,
           m.StockItemDescription,
           CONVERT(decimal(18, 3), r.QtyRecon)            AS QtyRecon,
           CONVERT(decimal(18, 3), t.QtyTill)             AS QtyTill,
           CONVERT(decimal(18, 3), t.QtyTill - r.QtyRecon) AS QtyMissing,
           CASE WHEN ABS(r.QtyRecon) < 0.005 THEN 'day' ELSE 'part' END AS Kind
    FROM recon r
    JOIN till t
      ON t.TransactionDate = r.TransactionDate AND t.AreaNo = r.AreaNo AND t.StockItemNo = r.StockItemNo
    LEFT JOIN agora.vw_StockArea a
      ON a.BranchId = @BranchId AND a.AreaNo = r.AreaNo
    LEFT JOIN agora.vw_StockMaster m
      ON m.BranchId = @BranchId AND m.AreaNo = r.AreaNo AND m.StockItemNo = r.StockItemNo
    WHERE ABS(t.QtyTill - r.QtyRecon) > 0.01
    ORDER BY r.TransactionDate, a.AreaDescription, m.StockItemDescription, r.StockItemNo;
END
