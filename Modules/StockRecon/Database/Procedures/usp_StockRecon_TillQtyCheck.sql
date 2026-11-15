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

    A WHOLE TILL MISSING (Ryan, 15 Nov 2026, Pongola run 80516). When a till's
    day never reached PumpIT, the recon lines and the till side are both empty
    and the comparison above sees nothing, while every shift that till sells
    for reads short. So, per day of the run and per till location its items
    sell through (STK_StockMaster.Location): no computer quantities at all
    from that location that day, where it has some on another day from a week
    before the run to its end, is one 'feed' row per day, area and location.
    Read from agora.vw_StockReconComputerQty, PumpIT's own source for
    QtyComputer, by MistEod (its only index).

    Parameters: @BranchId, @RunId
    Result: one row per day, area and item that disagrees (Kind 'day' or
            'part'), then one per day, area and till location with no sales
            at all (Kind 'feed', StockItemNo NULL, Location set).
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

    IF OBJECT_ID('tempdb..#gaps') IS NOT NULL DROP TABLE #gaps;

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
           CONVERT(nvarchar(10), r.StockItemNo)            AS StockItemNo,
           CONVERT(nvarchar(20), NULL)                     AS Location,
           CONVERT(decimal(18, 3), r.QtyRecon)             AS QtyRecon,
           CONVERT(decimal(18, 3), t.QtyTill)              AS QtyTill,
           CONVERT(decimal(18, 3), t.QtyTill - r.QtyRecon) AS QtyMissing,
           CONVERT(varchar(4), CASE WHEN ABS(r.QtyRecon) < 0.005 THEN 'day' ELSE 'part' END) AS Kind
    INTO #gaps
    FROM recon r
    JOIN till t
      ON t.TransactionDate = r.TransactionDate AND t.AreaNo = r.AreaNo AND t.StockItemNo = r.StockItemNo
    WHERE ABS(t.QtyTill - r.QtyRecon) > 0.01;

    /* A whole till missing. The days from a week before the run to its end,
       each as PumpIT keys it (yyyymmdd + site), so the read seeks MistEod. */
    DECLARE @FeedFrom DATE = DATEADD(day, -7, @From);

    IF OBJECT_ID('tempdb..#days') IS NOT NULL DROP TABLE #days;
    WITH d AS (
        SELECT @FeedFrom AS D
        UNION ALL
        SELECT DATEADD(day, 1, D) FROM d WHERE D < @To
    )
    SELECT D, CONVERT(varchar(10), CONVERT(char(8), D, 112) + CONVERT(varchar(10), @BranchId)) AS MistEod
    INTO #days
    FROM d
    OPTION (MAXRECURSION 400);

    IF OBJECT_ID('tempdb..#feed') IS NOT NULL DROP TABLE #feed;
    SELECT dd.D, f.Location, COUNT(*) AS Lines
    INTO #feed
    FROM #days dd
    JOIN agora.vw_StockReconComputerQty f ON f.MistEod = dd.MistEod
    WHERE f.BranchId = @BranchId
    GROUP BY dd.D, f.Location;

    -- The till locations the run's items sell through, per area.
    IF OBJECT_ID('tempdb..#locs') IS NOT NULL DROP TABLE #locs;
    SELECT DISTINCT i.AreaNo, RTRIM(m.StockLocation) AS Location
    INTO #locs
    FROM #items i
    JOIN agora.vw_StockMaster m
      ON m.BranchId = @BranchId AND m.AreaNo = i.AreaNo AND m.StockItemNo = i.StockItemNo
    WHERE NULLIF(RTRIM(m.StockLocation), '') IS NOT NULL;

    INSERT INTO #gaps (TransactionDate, AreaNo, StockItemNo, Location, QtyRecon, QtyTill, QtyMissing, Kind)
    SELECT ad.D, ad.AreaNo, NULL, lc.Location, ad.QtyRecon, NULL, NULL, 'feed'
    FROM (SELECT CONVERT(date, l.TransactionDate) AS D, l.AreaNo,
                 CONVERT(decimal(18, 3), SUM(ISNULL(l.QtyComputer, 0))) AS QtyRecon
            FROM agora.vw_StockReconLine l
            JOIN #items i ON i.AreaNo = l.AreaNo AND i.StockItemNo = l.StockItemNo
           WHERE l.BranchId = @BranchId
             AND l.TransactionDate >= @From AND l.TransactionDate < @Until
           GROUP BY CONVERT(date, l.TransactionDate), l.AreaNo) ad
    JOIN #locs lc ON lc.AreaNo = ad.AreaNo
    WHERE NOT EXISTS (SELECT 1 FROM #feed f WHERE f.D = ad.D AND f.Location = lc.Location)
      AND EXISTS (SELECT 1 FROM #feed f WHERE f.Location = lc.Location);

    SELECT g.TransactionDate,
           g.AreaNo,
           a.AreaDescription,
           g.StockItemNo,
           CASE WHEN g.Kind = 'feed' THEN N'No sales at all from the ' + g.Location + N' till'
                ELSE m.StockItemDescription END AS StockItemDescription,
           g.Location,
           g.QtyRecon,
           g.QtyTill,
           g.QtyMissing,
           g.Kind
    FROM #gaps g
    LEFT JOIN agora.vw_StockArea a
      ON a.BranchId = @BranchId AND a.AreaNo = g.AreaNo
    LEFT JOIN agora.vw_StockMaster m
      ON m.BranchId = @BranchId AND m.AreaNo = g.AreaNo AND m.StockItemNo = g.StockItemNo
    ORDER BY CASE WHEN g.Kind = 'feed' THEN 0 ELSE 1 END, g.TransactionDate, a.AreaDescription,
             m.StockItemDescription, g.StockItemNo;
END
