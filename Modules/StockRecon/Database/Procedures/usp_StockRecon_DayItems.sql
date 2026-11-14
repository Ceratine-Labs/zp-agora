/*
    agora.usp_StockRecon_DayItems — one site, one day: every item counted in
    each area, with what the tills sold beside the POS on its recon lines
    (14 Nov 2026).

    Ryan, 14 Nov 2026, for Chantal Peens's "COMPUTER QTY" check: "if a user
    clicks on a day ... show the areas as expandables with the items". The
    reconciled-day page opens each counting area onto its items, and an item
    whose till quantity PumpIT has not rolled into the recon lines says so.

    Per area and item, over the day's shifts: the first shift's open, the
    issues, the last shift's close, the POS on the recon lines (QtyComputer)
    and the day's variance (close - open - issued + POS, summed by shift).
    QtyTill is what the tills sold from agora.vw_StockReconTillQty, NULL when
    the till feed has nothing for the item that day; QtyMissing and Kind follow
    usp_StockRecon_TillQtyCheck exactly ('day' = no POS at all, 'part' = a shift
    is missing its quantity, NULL = they agree within 0.01). Day level, as
    head office's own check reads it: the time-based till feed carries a date
    and no time, so a shift cannot be told apart. Water meters are left out,
    as usp_StockRecon_DayStatus leaves them out.

    Read-only.

    Parameters: @BranchId, @Day, @AreaNo (NULL = every area)
    Result: one row per area and item counted that day.
*/
CREATE OR ALTER PROCEDURE agora.usp_StockRecon_DayItems
    @BranchId INT,
    @Day      DATE,
    @AreaNo   INT = NULL
AS
BEGIN
    SET NOCOUNT ON;

    IF @Day IS NULL
        THROW 51000, 'AGORA:INVALID:Choose a day.', 1;

    DECLARE @From DATETIME = CONVERT(datetime, @Day);
    DECLARE @Until DATETIME = DATEADD(day, 1, @From);

    WITH lines AS (
        SELECT l.AreaNo, l.StockItemNo, l.ShiftNo,
               CONVERT(decimal(18, 4), ISNULL(l.QtyOpen, 0))     AS QtyOpen,
               CONVERT(decimal(18, 4), ISNULL(l.QtyIssued, 0))   AS QtyIssued,
               CONVERT(decimal(18, 4), ISNULL(l.QtyClose, 0))    AS QtyClose,
               CONVERT(decimal(18, 4), ISNULL(l.QtyComputer, 0)) AS QtyPOS,
               ROW_NUMBER() OVER (PARTITION BY l.AreaNo, l.StockItemNo ORDER BY l.ShiftNo)      AS FirstShift,
               ROW_NUMBER() OVER (PARTITION BY l.AreaNo, l.StockItemNo ORDER BY l.ShiftNo DESC) AS LastShift
        FROM agora.vw_StockReconLine l
        WHERE l.BranchId = @BranchId
          AND l.TransactionDate >= @From AND l.TransactionDate < @Until
          AND (@AreaNo IS NULL OR l.AreaNo = @AreaNo)
          AND NOT EXISTS (SELECT 1 FROM agora.StockReconMeter meter
              WHERE meter.BranchId = @BranchId AND meter.AreaNo = l.AreaNo
                AND meter.StockItemNo = l.StockItemNo AND meter.IsActive = 1)
    ),
    items AS (
        SELECT AreaNo, StockItemNo,
               COUNT(*)                                          AS Shifts,
               SUM(CASE WHEN FirstShift = 1 THEN QtyOpen END)    AS QtyOpen,
               SUM(QtyIssued)                                    AS QtyIssued,
               SUM(CASE WHEN LastShift = 1 THEN QtyClose END)    AS QtyClose,
               SUM(QtyPOS)                                       AS QtyPOS,
               SUM(QtyClose - QtyOpen - QtyIssued + QtyPOS)      AS QtyVar
        FROM lines
        GROUP BY AreaNo, StockItemNo
    ),
    till AS (
        SELECT t.AreaNo, t.StockItemNo, SUM(t.Qty) AS QtyTill
        FROM agora.vw_StockReconTillQty t
        WHERE t.BranchId = @BranchId
          AND t.TransactionDate >= @From AND t.TransactionDate < @Until
          AND (@AreaNo IS NULL OR t.AreaNo = @AreaNo)
        GROUP BY t.AreaNo, t.StockItemNo
    )
    SELECT i.AreaNo,
           a.AreaDescription,
           i.StockItemNo,
           m.StockItemDescription,
           i.Shifts,
           CONVERT(decimal(18, 3), i.QtyOpen)   AS QtyOpen,
           CONVERT(decimal(18, 3), i.QtyIssued) AS QtyIssued,
           CONVERT(decimal(18, 3), i.QtyClose)  AS QtyClose,
           CONVERT(decimal(18, 3), i.QtyPOS)    AS QtyPOS,
           CONVERT(decimal(18, 3), t.QtyTill)   AS QtyTill,
           CONVERT(decimal(18, 3), CASE WHEN ABS(t.QtyTill - i.QtyPOS) > 0.01 THEN t.QtyTill - i.QtyPOS END) AS QtyMissing,
           CASE WHEN t.QtyTill IS NULL OR ABS(t.QtyTill - i.QtyPOS) <= 0.01 THEN NULL
                WHEN ABS(i.QtyPOS) < 0.005 THEN 'day' ELSE 'part' END AS Kind,
           CONVERT(decimal(18, 3), i.QtyVar)    AS QtyVar
    FROM items i
    LEFT JOIN till t ON t.AreaNo = i.AreaNo AND t.StockItemNo = i.StockItemNo
    LEFT JOIN agora.vw_StockArea a ON a.BranchId = @BranchId AND a.AreaNo = i.AreaNo
    LEFT JOIN agora.vw_StockMaster m ON m.BranchId = @BranchId AND m.AreaNo = i.AreaNo AND m.StockItemNo = i.StockItemNo
    ORDER BY a.AreaDescription, i.AreaNo,
             CASE WHEN t.QtyTill IS NOT NULL AND ABS(t.QtyTill - i.QtyPOS) > 0.01 THEN 0 ELSE 1 END,
             m.StockItemDescription, i.StockItemNo;
END
