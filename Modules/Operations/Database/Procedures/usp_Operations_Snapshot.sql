/*
   Operations Wall: the selected trading day's exceptions and source evidence.

   All reads are through existing Agora views. The caller passes yesterday in
   South African time; the SQL host's local date is never assumed. The explicit
   site selection is REQUIRED: blank or invalid BranchIds returns no rows.
   AllowedBranchIds is an independent permission fence (blank = unrestricted).

   Values carry a Unit and MetricKey; unlike-unit rows must never be added.
   Missing readings stay NULL and neutral rather than becoming healthy zeroes.
   Fuel losses use PumpIT's PumpSales - (opening + delivery - closing), only
   when yesterday's dip exists. Cover uses a seven-calendar-day average, only
   when all seven daily observations exist. Sales comparisons use the four
   preceding same weekdays and require all four to assign a warning.

   Stock quantities/waste/rounding match usp_StockRecon_GridVariance; the
   focused parity test compares its returned lines with these ranked totals.
   READ-ONLY.
*/
CREATE OR ALTER PROCEDURE [agora].[usp_Operations_Snapshot]
    @BranchIds NVARCHAR(MAX) = NULL,
    @AllowedBranchIds NVARCHAR(MAX) = NULL,
    @TradeDate DATE = NULL
AS
BEGIN
    SET NOCOUNT ON;

    SET @TradeDate = ISNULL(@TradeDate, CONVERT(date, DATEADD(day, -1, DATEADD(hour, 2, SYSUTCDATETIME()))));
    DECLARE @From datetime = @TradeDate,
            @Until datetime = DATEADD(day, 1, CONVERT(datetime, @TradeDate)),
            @WeekFrom datetime = DATEADD(day, -6, CONVERT(datetime, @TradeDate)),
            @BaselineFrom datetime = DATEADD(day, -28, CONVERT(datetime, @TradeDate)),
            @NowSast datetime2 = DATEADD(hour, 2, SYSUTCDATETIME());

    /* Provisional starting levels from the customer's overview, editable here
       after operations agree them. The disputed attendant thresholds are not
       implemented: no connected paying-time source is available. */
    DECLARE @FuelWarnPct decimal(6,3) = 0.25, @FuelCriticalPct decimal(6,3) = 0.40,
            @SalesWarnDrop decimal(6,3) = 0.10, @SalesCriticalDrop decimal(6,3) = 0.20,
            @CashWarn money = 200, @CashCritical money = 500;

    SELECT b.BranchId, b.Name AS BranchName
    INTO #Branch
    FROM agora.Branch b
    WHERE b.IsActive = 1
      AND b.IsTrading = 1
      AND b.DeletedAt IS NULL
      AND b.BranchId IN (
          SELECT TRY_CONVERT(int, value) FROM STRING_SPLIT(ISNULL(@BranchIds, ''), ',')
          WHERE LTRIM(RTRIM(value)) <> '')
      AND (NULLIF(LTRIM(RTRIM(@AllowedBranchIds)), '') IS NULL OR b.BranchId IN (
          SELECT TRY_CONVERT(int, value) FROM STRING_SPLIT(@AllowedBranchIds, ',')
          WHERE LTRIM(RTRIM(value)) <> ''));

    CREATE UNIQUE CLUSTERED INDEX IX_Branch ON #Branch (BranchId);

    CREATE TABLE #Rows (
        SourceKey varchar(20) NOT NULL,
        MetricKey varchar(40) NOT NULL,
        BranchId int NOT NULL,
        BranchName nvarchar(200) NOT NULL,
        BusinessDate date NOT NULL,
        Title nvarchar(200) NOT NULL,
        Value decimal(22,4) NULL,
        SecondaryValue decimal(22,4) NULL,
        Unit varchar(20) NOT NULL,
        Severity varchar(10) NOT NULL,
        Detail nvarchar(1000) NOT NULL,
        SourceUpdatedAt datetime2 NULL,
        ItemCode nvarchar(100) NULL,
        Area nvarchar(100) NULL,
        SourceCount int NOT NULL
    );

    /* Materialise each bounded source once, before any site-by-site joins. */
    SELECT f.BranchId, f.FuelTypeNo, CONVERT(date, f.FuelDate) AS FuelDate,
           COUNT(*) AS Readings,
           CASE WHEN SUM(CASE WHEN f.FuelDip IS NULL THEN 0 ELSE 1 END) > 0
                THEN MAX(ISNULL(f.FuelDip, 0)) END AS ClosingDip,
           CASE WHEN SUM(CASE WHEN f.FuelDelivery IS NULL THEN 0 ELSE 1 END) > 0
                THEN MAX(ISNULL(f.FuelDelivery, 0)) END AS Delivery,
           CASE WHEN SUM(CASE WHEN f.FuelVolume IS NULL THEN 0 ELSE 1 END) > 0
                THEN MAX(ISNULL(f.FuelVolume, 0)) END AS PumpSales,
           NULLIF(MAX(ISNULL(f.CreateDateTime, CONVERT(datetime, '19000101'))), CONVERT(datetime, '19000101')) AS CapturedAt
    INTO #Fuel
    FROM agora.vw_FuelInput f
    JOIN #Branch b ON b.BranchId = f.BranchId
    WHERE f.FuelDate >= @WeekFrom AND f.FuelDate < @Until
    GROUP BY f.BranchId, f.FuelTypeNo, CONVERT(date, f.FuelDate);

    /* Keep configured grades and observed grades, including a now-removed
       master. A configured grade with no reading remains visible as missing. */
    SELECT BranchId, FuelTypeNo INTO #Grade
    FROM (
        SELECT t.BranchId, t.FuelTypeNo FROM agora.vw_FuelType t
        JOIN #Branch b ON b.BranchId = t.BranchId
        UNION
        SELECT BranchId, FuelTypeNo FROM #Fuel
    ) g;

    INSERT INTO #Rows
    SELECT 'fuel', 'tank-variance', b.BranchId, b.BranchName, @TradeDate,
           CONCAT(COALESCE(t.FuelTypeDescription, CONVERT(nvarchar(20), g.FuelTypeNo)), ' · tank variance'),
           CASE WHEN d.Readings = 1 AND p.Readings = 1
                     AND d.PumpSales IS NOT NULL AND d.Delivery IS NOT NULL
                     AND d.ClosingDip IS NOT NULL AND p.ClosingDip IS NOT NULL
                THEN d.PumpSales - (p.ClosingDip + d.Delivery - d.ClosingDip) END,
           d.ClosingDip, 'litres',
           CASE WHEN d.Readings IS NULL OR p.Readings IS NULL OR d.Readings <> 1 OR p.Readings <> 1
                     OR d.PumpSales IS NULL OR d.Delivery IS NULL OR d.ClosingDip IS NULL OR p.ClosingDip IS NULL THEN 'neutral'
                WHEN d.PumpSales <= 0 THEN 'neutral'
                WHEN d.PumpSales - (p.ClosingDip + d.Delivery - d.ClosingDip) <= -d.PumpSales * @FuelCriticalPct / 100 THEN 'crit'
                WHEN d.PumpSales - (p.ClosingDip + d.Delivery - d.ClosingDip) <= -d.PumpSales * @FuelWarnPct / 100 THEN 'warn'
                ELSE 'good' END,
           CASE WHEN d.Readings IS NULL THEN 'No fuel input for this trading day.'
                WHEN p.Readings IS NULL THEN 'Previous day dip missing; daily loss cannot be calculated.'
                WHEN d.Readings <> 1 OR p.Readings <> 1 THEN 'Multiple readings for a grade/day; investigate before valuing loss.'
                WHEN d.PumpSales IS NULL OR d.Delivery IS NULL OR d.ClosingDip IS NULL OR p.ClosingDip IS NULL
                     THEN 'A dip, delivery or sales value is missing; no variance inferred.'
                WHEN d.PumpSales <= 0 THEN 'No positive pump throughput; loss percentage cannot be calculated.'
                ELSE 'Pump litres less tank movement. Provisional loss warning 0.25% / critical 0.40% of pump throughput. Supporting value: closing dip.' END,
           d.CapturedAt, CONVERT(nvarchar(100), g.FuelTypeNo), NULL, ISNULL(d.Readings, 0)
    FROM #Grade g
    JOIN #Branch b ON b.BranchId = g.BranchId
    LEFT JOIN agora.vw_FuelType t ON t.BranchId = g.BranchId AND t.FuelTypeNo = g.FuelTypeNo
    LEFT JOIN #Fuel d ON d.BranchId = g.BranchId AND d.FuelTypeNo = g.FuelTypeNo AND d.FuelDate = @TradeDate
    LEFT JOIN #Fuel p ON p.BranchId = g.BranchId AND p.FuelTypeNo = g.FuelTypeNo AND p.FuelDate = DATEADD(day, -1, @TradeDate);

    INSERT INTO #Rows
    SELECT 'fuel', 'tank-cover', b.BranchId, b.BranchName, @TradeDate,
           CONCAT(COALESCE(t.FuelTypeDescription, CONVERT(nvarchar(20), g.FuelTypeNo)), ' · tank cover'),
           CASE WHEN h.ValidDays = 7 AND h.AvgSales > 0 AND d.Readings = 1 THEN d.ClosingDip / h.AvgSales END,
           h.AvgSales, 'days',
           CASE WHEN h.ValidDays <> 7 OR h.AvgSales IS NULL OR h.AvgSales <= 0 OR d.Readings IS NULL
                     OR d.Readings <> 1 OR d.ClosingDip IS NULL THEN 'neutral'
                WHEN d.ClosingDip / h.AvgSales < 1.5 THEN 'crit'
                WHEN d.ClosingDip / h.AvgSales < 2.5 THEN 'warn'
                ELSE 'good' END,
           CASE WHEN h.ValidDays <> 7 OR d.Readings IS NULL THEN CONCAT('Cover unavailable: ', h.ValidDays, ' of 7 daily sales observations.')
                WHEN h.AvgSales IS NULL OR h.AvgSales <= 0 THEN 'No positive seven-day draw; cover cannot be estimated.'
                WHEN d.Readings <> 1 OR d.ClosingDip IS NULL THEN 'Closing dip unavailable or ambiguous.'
                ELSE 'Closing dip / seven-day average litres. Order now below 1.5 days; order today below 2.5 days.' END,
           d.CapturedAt, CONVERT(nvarchar(100), g.FuelTypeNo), NULL, ISNULL(d.Readings, 0)
    FROM #Grade g
    JOIN #Branch b ON b.BranchId = g.BranchId
    LEFT JOIN agora.vw_FuelType t ON t.BranchId = g.BranchId AND t.FuelTypeNo = g.FuelTypeNo
    LEFT JOIN #Fuel d ON d.BranchId = g.BranchId AND d.FuelTypeNo = g.FuelTypeNo AND d.FuelDate = @TradeDate
    CROSS APPLY (
        SELECT COUNT(*) AS ValidDays, AVG(f.PumpSales) AS AvgSales
        FROM #Fuel f WHERE f.BranchId = g.BranchId AND f.FuelTypeNo = g.FuelTypeNo
          AND f.Readings = 1 AND f.PumpSales IS NOT NULL AND f.PumpSales >= 0
    ) h;

    SELECT p.BranchId, p.PumpNo, p.ReadingDate, p.OpenReading, p.CloseReading,
           p.OpenReadingElectronic, p.CloseReadingElectronic, p.POSSales,
           p.IsClocked, p.CreateDateTime,
           CONVERT(decimal(22,4), p.CloseReading - p.OpenReading + CASE WHEN p.IsClocked = 1 THEN 1000000 ELSE 0 END) AS MechLitres,
           CONVERT(decimal(22,4), p.CloseReadingElectronic - p.OpenReadingElectronic + CASE WHEN p.IsClocked = 1 THEN 1000000 ELSE 0 END) AS ElecLitres
    INTO #Meters
    FROM agora.vw_PumpReading p JOIN #Branch b ON b.BranchId = p.BranchId
    WHERE p.ReadingDate >= @From AND p.ReadingDate < @Until;

    INSERT INTO #Rows
    SELECT 'meters', 'meter-gap', b.BranchId, b.BranchName, @TradeDate,
           CONCAT('Pump ', p.PumpNo, ' · meter check'),
           p.MechLitres - p.ElecLitres, p.MechLitres - p.POSSales, 'litres',
           CASE WHEN p.MechLitres IS NULL OR p.ElecLitres IS NULL OR p.POSSales IS NULL THEN 'neutral'
                WHEN ISNULL(p.IsClocked, 0) = 0 AND p.CloseReading < p.OpenReading THEN 'crit'
                WHEN ABS(p.MechLitres - p.ElecLitres) > 15 OR ABS(p.MechLitres - p.POSSales) > 15 THEN 'warn'
                ELSE 'good' END,
           CASE WHEN p.MechLitres IS NULL OR p.ElecLitres IS NULL OR p.POSSales IS NULL THEN 'A mechanical, electronic or POS reading is missing.'
                WHEN ISNULL(p.IsClocked, 0) = 0 AND p.CloseReading < p.OpenReading THEN 'Close below open without a recorded rollover.'
                ELSE 'Mechanical less electronic litres; supporting value is mechanical less POS. Existing tolerance: 15 L.' END,
           p.CreateDateTime, p.PumpNo, NULL, 1
    FROM #Meters p JOIN #Branch b ON b.BranchId = p.BranchId;

    INSERT INTO #Rows
    SELECT 'meters', 'meter-missing', b.BranchId, b.BranchName, @TradeDate,
           CONCAT('Pump ', p.PumpNo, ' · reading missing'), NULL, NULL, 'litres', 'neutral',
           'Configured pump has no reading for this trading day.', NULL, p.PumpNo, NULL, 0
    FROM agora.vw_Pump p JOIN #Branch b ON b.BranchId = p.BranchId
    WHERE p.IsActive = 1
      AND NOT EXISTS (SELECT 1 FROM #Meters m WHERE m.BranchId = p.BranchId AND m.PumpNo = p.PumpNo);

    /* Day-end source is turnover, not a product-sales or GP feed. Several
       day-end types can belong to a site; aggregate each date before comparing. */
    SELECT d.BranchId, CONVERT(date, d.DayEndDate) AS SalesDate, COUNT(*) AS Records,
           CASE WHEN SUM(CASE WHEN d.QShopTO IS NULL THEN 0 ELSE 1 END) > 0 THEN SUM(ISNULL(d.QShopTO, 0)) END AS ShopSales,
           CASE WHEN SUM(CASE WHEN d.FastFoodTO IS NULL THEN 0 ELSE 1 END) > 0 THEN SUM(ISNULL(d.FastFoodTO, 0)) END AS QsrSales,
           NULLIF(MAX(ISNULL(d.CreateDateTime, CONVERT(datetime, '19000101'))), CONVERT(datetime, '19000101')) AS CapturedAt
    INTO #Sales
    FROM agora.vw_DayEnd d JOIN #Branch b ON b.BranchId = d.BranchId
    WHERE d.DayEndDate >= @BaselineFrom AND d.DayEndDate < @Until
      AND (d.DayEndDate >= @From OR DATEDIFF(day, CONVERT(date, d.DayEndDate), @TradeDate) IN (7, 14, 21, 28))
    GROUP BY d.BranchId, CONVERT(date, d.DayEndDate);

    INSERT INTO #Rows
    SELECT k.SourceKey, 'turnover', b.BranchId, b.BranchName, @TradeDate,
           CONCAT(k.Caption, ' turnover'), currentSales.Amount, priorSales.Average, 'rand',
           CASE WHEN currentSales.Amount IS NULL OR priorSales.Observations <> 4 OR ISNULL(priorSales.Average, 0) <= 0 THEN 'neutral'
                WHEN currentSales.Amount <= priorSales.Average * (1 - @SalesCriticalDrop) THEN 'crit'
                WHEN currentSales.Amount <= priorSales.Average * (1 - @SalesWarnDrop) THEN 'warn'
                ELSE 'good' END,
           CASE WHEN currentSales.Amount IS NULL THEN 'No day-end turnover for this trading day; missing is not zero sales.'
                WHEN priorSales.Observations <> 4 THEN CONCAT('Loaded turnover; comparison has ', priorSales.Observations, ' of 4 previous same weekdays.')
                WHEN ISNULL(priorSales.Average, 0) <= 0 THEN 'Loaded turnover; previous same-weekday baseline is not positive.'
                ELSE 'Day-end turnover against the previous four same weekdays. Provisional warning at 10% down; critical at 20% down. Not budget or GP.' END,
           d.CapturedAt, NULL, NULL, ISNULL(d.Records, 0)
    FROM #Branch b
    CROSS JOIN (VALUES ('shop', 'Shop'), ('qsr', 'QSR')) k(SourceKey, Caption)
    LEFT JOIN #Sales d ON d.BranchId = b.BranchId AND d.SalesDate = @TradeDate
    CROSS APPLY (SELECT CASE k.SourceKey WHEN 'shop' THEN d.ShopSales ELSE d.QsrSales END AS Amount) currentSales
    CROSS APPLY (
        SELECT SUM(ISNULL(h.ShopSales, 0)) / NULLIF(SUM(CASE WHEN h.ShopSales IS NULL THEN 0 ELSE 1 END), 0) AS ShopAverage,
               SUM(ISNULL(h.QsrSales, 0)) / NULLIF(SUM(CASE WHEN h.QsrSales IS NULL THEN 0 ELSE 1 END), 0) AS QsrAverage,
               ISNULL(SUM(CASE WHEN h.ShopSales IS NULL THEN 0 ELSE 1 END), 0) AS ShopObservations,
               ISNULL(SUM(CASE WHEN h.QsrSales IS NULL THEN 0 ELSE 1 END), 0) AS QsrObservations
        FROM #Sales h
        WHERE h.BranchId = b.BranchId AND h.SalesDate < @TradeDate
    ) baseline
    CROSS APPLY (
        SELECT CASE k.SourceKey WHEN 'shop' THEN baseline.ShopAverage ELSE baseline.QsrAverage END AS Average,
               CASE k.SourceKey WHEN 'shop' THEN baseline.ShopObservations ELSE baseline.QsrObservations END AS Observations
    ) priorSales;

    /* Canonical Cash view owns all tender arithmetic and the open/closed rule.
       Materialise once: its tender and Z-read lookups are the expensive source. */
    SELECT t.BranchId, t.Variance, t.IsOpen, t.CapturedAt
    INTO #Cash
    FROM agora.vw_CashupTotals t JOIN #Branch b ON b.BranchId = t.BranchId
    WHERE t.TransactionAt >= @From AND t.TransactionAt < @Until;

    INSERT INTO #Rows
    SELECT 'cash', 'cashups-open', b.BranchId, b.BranchName, @TradeDate,
           'Cashups awaiting close',
           CASE WHEN c.Records > 0 THEN c.OpenCashups END, c.Records, 'count',
           CASE WHEN c.Records = 0 THEN 'neutral'
                WHEN c.OpenCashups = 0 THEN 'good'
                WHEN @NowSast >= DATEADD(hour, 11, @Until) THEN 'crit'
                WHEN @NowSast >= DATEADD(hour, 10, @Until) THEN 'warn'
                ELSE 'neutral' END,
           CASE WHEN c.Records = 0 THEN 'No cashup headers for this trading day; completion cannot be confirmed.'
                ELSE CONCAT(c.OpenCashups, ' open of ', c.Records, ' captured. 10:00 warning / 11:00 critical on the next day, South African time.') END,
           c.CapturedAt, NULL, NULL, c.Records
    FROM #Branch b
    CROSS APPLY (SELECT COUNT(*) AS Records, SUM(CONVERT(int, t.IsOpen)) AS OpenCashups,
                        NULLIF(MAX(ISNULL(t.CapturedAt, CONVERT(datetime, '19000101'))), CONVERT(datetime, '19000101')) AS CapturedAt
                 FROM #Cash t WHERE t.BranchId = b.BranchId) c;

    INSERT INTO #Rows
    SELECT 'cash', 'cashup-variance', b.BranchId, b.BranchName, @TradeDate,
           'Cashup shorts / overs',
           CASE WHEN c.Records > 0 THEN c.Shorts END,
           CASE WHEN c.Records > 0 THEN c.Overs END, 'rand',
           CASE WHEN c.Records = 0 THEN 'neutral'
                WHEN c.Shorts <= -@CashCritical THEN 'crit'
                WHEN c.Shorts <= -@CashWarn OR c.Overs >= @CashWarn THEN 'warn' ELSE 'good' END,
           CASE WHEN c.Records = 0 THEN 'No cashup headers for this trading day.'
                ELSE 'Shorts and overs separately; current tender legs less Z-read. Provisional warning R200 / critical shortage R500.' END,
           c.CapturedAt, NULL, NULL, c.Records
    FROM #Branch b
    CROSS APPLY (SELECT COUNT(*) AS Records,
                        SUM(CASE WHEN t.Variance < 0 THEN t.Variance ELSE 0 END) AS Shorts,
                        SUM(CASE WHEN t.Variance > 0 THEN t.Variance ELSE 0 END) AS Overs,
                        NULLIF(MAX(ISNULL(t.CapturedAt, CONVERT(datetime, '19000101'))), CONVERT(datetime, '19000101')) AS CapturedAt
                 FROM #Cash t WHERE t.BranchId = b.BranchId) c;

    /* The legacy count table has millions of rows; constrain on its branch
       and datetime key before joining master/waste. Same decimal arithmetic
       and location preference as the full variance report. */
    SELECT l.BranchId, l.StockItemNo, m.StockLocation,
           COALESCE(m.StockItemDescription, l.StockItemNo) AS ItemDescription,
           a.AreaDescription,
           CONVERT(decimal(18,2), x.QtyVar * ISNULL(l.SellPrice, 0)) AS ValueVar
    INTO #StockLines
    FROM agora.vw_StockReconLine l
    JOIN #Branch b ON b.BranchId = l.BranchId
    LEFT JOIN agora.vw_StockArea a ON a.BranchId = l.BranchId AND a.AreaNo = l.AreaNo
    OUTER APPLY (
        SELECT TOP 1 mm.StockItemDescription, mm.StockLocation
        FROM agora.vw_StockMaster mm
        WHERE mm.BranchId = l.BranchId AND mm.StockItemNo = l.StockItemNo
        ORDER BY CASE WHEN mm.AreaNo = l.AreaNo THEN 0 ELSE 1 END, mm.StockLocation
    ) m
    LEFT JOIN agora.vw_StockWasteLine w
           ON w.BranchId = l.BranchId AND w.TransactionDate = l.TransactionDate
          AND w.ShiftNo = l.ShiftNo AND w.AreaNo = l.AreaNo AND w.StockItemNo = l.StockItemNo
    OUTER APPLY (SELECT TOP 1 z.IsAuthorized, z.CapturedGood, z.AuthorizedGood, z.CapturedAt
        FROM agora.StockWasteAuthorization z
        WHERE z.BranchId = l.BranchId AND z.TransactionDate = CONVERT(date, l.TransactionDate)
          AND z.ShiftNo = l.ShiftNo AND z.AreaNo = l.AreaNo AND z.StockItemNo = l.StockItemNo
        ORDER BY z.Id DESC) auth
    CROSS APPLY (SELECT
        CONVERT(decimal(18,4), ISNULL(l.QtyOpen, 0)) AS QtyOpen,
        CONVERT(decimal(18,4), ISNULL(l.QtyIssued, 0)) AS QtyIssued,
        CONVERT(decimal(18,4), ISNULL(l.QtyClose, 0)) AS QtyClose,
        CONVERT(decimal(18,4), ISNULL(l.QtyComputer, 0)) AS QtyComputer,
        CONVERT(decimal(18,4), CASE WHEN auth.IsAuthorized = 1
            AND auth.CapturedGood = CONVERT(decimal(18,4), ISNULL(w.QtyGoodWaste, 0))
            AND (auth.CapturedAt = w.CreateDateTime OR (auth.CapturedAt IS NULL AND w.CreateDateTime IS NULL))
            THEN auth.AuthorizedGood ELSE 0 END) AS QtyGoodWaste
    ) q
    CROSS APPLY (SELECT q.QtyComputer - (q.QtyOpen + q.QtyIssued - q.QtyClose) AS QtyVarB4Waste) v
    CROSS APPLY (SELECT CASE
        WHEN v.QtyVarB4Waste >= 0 THEN v.QtyVarB4Waste
        WHEN q.QtyGoodWaste = 0 THEN v.QtyVarB4Waste
        WHEN v.QtyVarB4Waste + q.QtyGoodWaste < 0 THEN v.QtyVarB4Waste + q.QtyGoodWaste
        ELSE CONVERT(decimal(18,4), 0) END AS QtyVar
    ) x
    WHERE l.TransactionDate >= @From AND l.TransactionDate < @Until
      AND NOT EXISTS (SELECT 1 FROM agora.StockReconMeter meter
          WHERE meter.BranchId = l.BranchId AND meter.AreaNo = l.AreaNo
            AND meter.StockItemNo = l.StockItemNo AND meter.IsActive = 1)
    OPTION (RECOMPILE);

    ;WITH ItemLoss AS (
        SELECT BranchId, StockLocation, StockItemNo,
               MAX(ItemDescription) AS ItemDescription,
               SUM(ValueVar) AS LossValue, COUNT(*) AS ShortLines,
               CASE WHEN COUNT(DISTINCT ISNULL(AreaDescription, '')) = 1 THEN NULLIF(MAX(ISNULL(AreaDescription, '')), '')
                    ELSE 'Multiple areas' END AS Area
        FROM #StockLines
        WHERE ValueVar < 0
        GROUP BY BranchId, StockLocation, StockItemNo
    )
    SELECT i.*, ROW_NUMBER() OVER (
        PARTITION BY BranchId ORDER BY LossValue, StockItemNo, StockLocation
    ) AS LossRank
    INTO #RankedLoss
    FROM ItemLoss i;

    INSERT INTO #Rows
    SELECT 'stock', 'stock-loss-item', b.BranchId, b.BranchName, @TradeDate,
           CONCAT(r.StockItemNo, ' · ', r.ItemDescription,
                  CASE WHEN NULLIF(r.StockLocation, '') IS NOT NULL THEN CONCAT(' (', r.StockLocation, ')') ELSE '' END),
           r.LossValue, r.LossRank, 'rand', 'crit',
           CONCAT('Top ', r.LossRank, ' loss code at this site; ', r.ShortLines,
                  ' shortage lines after authorized good waste, at selling price. Overs are kept separate. Source: ',
                  COALESCE(r.StockLocation, 'unmapped stock location'), '.'),
           NULL, r.StockItemNo, r.Area, r.ShortLines
    FROM #RankedLoss r JOIN #Branch b ON b.BranchId = r.BranchId
    WHERE r.LossRank <= 10;

    INSERT INTO #Rows
    SELECT 'stock', 'stock-summary', b.BranchId, b.BranchName, @TradeDate,
           'Amended stock losses / overs',
           CASE WHEN s.Records > 0 THEN s.Losses END,
           CASE WHEN s.Records > 0 THEN s.Overs END, 'rand',
           CASE WHEN s.Records = 0 THEN 'neutral'
                WHEN s.Losses < 0 THEN 'crit' WHEN s.Overs > 0 THEN 'warn' ELSE 'good' END,
           CASE WHEN s.Records = 0 THEN 'No count lines for this trading day; a balanced site cannot be inferred.'
                ELSE CONCAT('All ', s.Records, ' count lines after authorized good waste, at selling price. Losses and overs separate; top 10 item codes shown below.') END,
           NULL, NULL, NULL, s.Records
    FROM #Branch b
    CROSS APPLY (
        SELECT COUNT(*) AS Records,
               SUM(CASE WHEN ValueVar < 0 THEN ValueVar ELSE 0 END) AS Losses,
               SUM(CASE WHEN ValueVar > 0 THEN ValueVar ELSE 0 END) AS Overs
        FROM #StockLines s WHERE s.BranchId = b.BranchId
    ) s;

    SELECT s.BranchId, COUNT(*) AS Records,
           NULLIF(MAX(ISNULL(s.CreateDateTime, CONVERT(datetime, '19000101'))), CONVERT(datetime, '19000101')) AS CapturedAt
    INTO #StockLoads
    FROM agora.vw_StockRecon s JOIN #Branch b ON b.BranchId = s.BranchId
    WHERE s.TransactionDate >= @From AND s.TransactionDate < @Until
    GROUP BY s.BranchId;

    INSERT INTO #Rows
    SELECT 'loads', 'stock-captures', b.BranchId, b.BranchName, @TradeDate,
           'Stock recon captures', s.Records, NULL, 'count',
           CASE WHEN s.Records IS NULL THEN 'neutral' ELSE 'good' END,
           CASE WHEN s.Records IS NULL THEN 'No stock recon headers for this trading day. Check the site schedule and import status.'
                ELSE 'Stock recon headers received. This confirms data arrived, not that every scheduled area was counted or balanced.' END,
           s.CapturedAt, NULL, NULL, ISNULL(s.Records, 0)
    FROM #Branch b LEFT JOIN #StockLoads s ON s.BranchId = b.BranchId;

    /* A source with no configured or observed fuel/pumps still has a visible
       coverage record, without pretending the site should trade that product. */
    INSERT INTO #Rows
    SELECT k.SourceKey, 'source-unavailable', b.BranchId, b.BranchName, @TradeDate,
           k.Title, NULL, NULL, 'count', 'neutral',
           k.Detail, NULL, NULL, NULL, 0
    FROM #Branch b
    CROSS JOIN (VALUES
        ('fuel', 'Fuel coverage unavailable', 'No configured grades or fuel input in this seven-day window.'),
        ('meters', 'Pump coverage unavailable', 'No configured pumps or meter readings for this trading day.')
    ) k(SourceKey, Title, Detail)
    WHERE NOT EXISTS (SELECT 1 FROM #Rows r WHERE r.BranchId = b.BranchId AND r.SourceKey = k.SourceKey);

    SELECT r.SourceKey, r.MetricKey, r.BranchId, r.BranchName, r.BusinessDate, r.Title,
           r.Value, r.SecondaryValue, r.Unit, r.Severity, r.Detail, r.SourceUpdatedAt,
           r.ItemCode, r.Area, r.SourceCount, l.StockLocation
    FROM #Rows r
    LEFT JOIN #RankedLoss l ON r.SourceKey = 'stock' AND r.MetricKey = 'stock-loss-item'
                          AND l.BranchId = r.BranchId AND l.LossRank = r.SecondaryValue
    ORDER BY CASE r.Severity WHEN 'crit' THEN 0 WHEN 'warn' THEN 1 WHEN 'neutral' THEN 2 ELSE 3 END,
             r.BranchName, r.SourceKey, r.MetricKey, r.ItemCode;
END
