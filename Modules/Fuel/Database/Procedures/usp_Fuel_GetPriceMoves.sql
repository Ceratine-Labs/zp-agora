/* ============================================================================
 * agora.usp_Fuel_GetPriceMoves — the fuel matrix's price-change log. READ ONLY.
 *
 * Read by:  Fuel -> Fuel matrix (and Setup -> Trading rules -> Fuel matrix), T025.
 * Reads:    agora.vw_FuelObservation (PumpIT.dbo.BRN_FuelPrice), agora.Branch.
 * Writes:   nothing.
 *
 * WHY BRN_FuelPrice AND NOT THE DOE MATRIX. BRN_MonthlyDOEFuelPricingMatrix
 * holds one row per site, grade and DOE period: it moves on the first
 * Wednesday of the month and nowhere else. The pump price a site actually
 * charged is BRN_FuelPrice, written per end of day by the POS. August 2026 at
 * Elephant Coast is why the difference matters: the DOE matrix moved on
 * 05 Aug only, while the pumps moved twice — 05 Aug (ULP 95 25.54 -> 25.02,
 * both diesels 27.77 -> 29.01) and 13 Aug (both diesels 29.01 -> 29.10).
 *
 * WHAT A MOVE IS. Observations are grouped by SOURCE date (S_Date, the day the
 * POS file carried the price; the trading Date of the same row is often the
 * day before) and by site, grade and fleet flag, into the SET of prices seen
 * that day. A move is a day whose set holds a price the previous priced day's
 * set did not. "From" is the whole previous set and "To" is only the new
 * price(s). A day on which old and new were both recorded (a mid-day change,
 * 13 Aug above) is flagged BothRecorded rather than split into two moves, and
 * the day after, when only the new price remains, is not a move. No price is
 * chosen as the winner of a day, and a missing cost or margin stays NULL:
 * nothing is filled from the DOE matrix or another day.
 *
 * The first day of the selection is compared with the last priced day before
 * it (up to 92 days back), so a move on the 1st is still a move.
 *
 * Result 1 (the fmtable summary): one row per site, grade and fleet flag —
 *   days priced, opening and closing price sets, low, high, moves, change.
 * Result 2 (the log): one row per move, oldest first per site and grade.
 *
 * Scope: @BranchIds NULL means every branch in @AllowedBranchIds (NULL there
 * means unrestricted); a selection outside the allowed set returns nothing,
 * never more.
 *
 *     EXEC agora.usp_Fuel_GetPriceMoves @BranchIds = '18', @DateFrom = '20260801', @DateTo = '20260831';
 *
 * Deployed by v1__11pa_fuel_procs.php.
 * ============================================================================ */
CREATE OR ALTER PROCEDURE [agora].[usp_Fuel_GetPriceMoves]
    @BranchIds nvarchar(max) = NULL,
    @DateFrom date = NULL,
    @DateTo date = NULL,
    @AllowedBranchIds nvarchar(max) = NULL,
    @FuelTypeNo int = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET @DateTo = ISNULL(@DateTo, CONVERT(date, GETDATE()));
    SET @DateFrom = ISNULL(@DateFrom, DATEADD(day, -365, @DateTo));
    IF @DateFrom > @DateTo OR DATEDIFF(day, @DateFrom, @DateTo) > 3660
        THROW 51000, 'AGORA:DATE_RANGE:Choose an ordered date range of ten years or less.', 1;

    DECLARE @LookFrom date = DATEADD(day, -92, @DateFrom);

    /* One row per site, grade, fleet flag, source date and distinct price. */
    SELECT p.BranchId, p.FuelTypeNo, CONVERT(tinyint, p.IsFleetCard) AS IsFleetCard,
           CONVERT(date, p.SourceDate) AS PriceDate, p.SellingPrice,
           MIN(p.CostPrice) AS CostLow, MAX(p.CostPrice) AS CostHigh,
           MIN(p.Margin) AS MarginLow, MAX(p.Margin) AS MarginHigh,
           SUM(CASE WHEN p.CostPrice IS NULL THEN 1 ELSE 0 END) AS MissingCost,
           COUNT_BIG(*) AS Observations
    INTO #Price
    FROM agora.vw_FuelObservation p
    WHERE p.SourceDate >= @LookFrom AND CONVERT(date, p.SourceDate) <= @DateTo
      AND (@FuelTypeNo IS NULL OR p.FuelTypeNo = @FuelTypeNo)
      AND (@BranchIds IS NULL OR p.BranchId IN (SELECT TRY_CONVERT(int, value) FROM STRING_SPLIT(@BranchIds, ',')))
      AND (@AllowedBranchIds IS NULL OR p.BranchId IN (SELECT TRY_CONVERT(int, value) FROM STRING_SPLIT(@AllowedBranchIds, ',')))
    GROUP BY p.BranchId, p.FuelTypeNo, CONVERT(tinyint, p.IsFleetCard), CONVERT(date, p.SourceDate), p.SellingPrice;

    /* The label PumpIT gave the grade at that site; a grade number that carried
       two labels says so instead of picking one. */
    SELECT p.BranchId, p.FuelTypeNo,
           CASE WHEN COUNT(DISTINCT p.FuelTypeDescription) > 1 THEN CONCAT('Grade ', p.FuelTypeNo, ' (multiple labels)')
                ELSE MAX(p.FuelTypeDescription) END AS FuelTypeDescription
    INTO #Grade
    FROM agora.vw_FuelObservation p
    WHERE p.SourceDate >= @LookFrom AND CONVERT(date, p.SourceDate) <= @DateTo
      AND EXISTS (SELECT 1 FROM #Price x WHERE x.BranchId = p.BranchId AND x.FuelTypeNo = p.FuelTypeNo)
    GROUP BY p.BranchId, p.FuelTypeNo;

    /* One row per priced day, with the previous priced day beside it. */
    SELECT d.BranchId, d.FuelTypeNo, d.IsFleetCard, d.PriceDate, d.SellLow, d.SellHigh,
           LAG(d.PriceDate) OVER (PARTITION BY d.BranchId, d.FuelTypeNo, d.IsFleetCard ORDER BY d.PriceDate) AS PreviousDate,
           LAG(d.SellLow) OVER (PARTITION BY d.BranchId, d.FuelTypeNo, d.IsFleetCard ORDER BY d.PriceDate) AS PreviousLow,
           LAG(d.SellHigh) OVER (PARTITION BY d.BranchId, d.FuelTypeNo, d.IsFleetCard ORDER BY d.PriceDate) AS PreviousHigh
    INTO #Day
    FROM (
        SELECT BranchId, FuelTypeNo, IsFleetCard, PriceDate,
               MIN(SellingPrice) AS SellLow, MAX(SellingPrice) AS SellHigh
        FROM #Price
        GROUP BY BranchId, FuelTypeNo, IsFleetCard, PriceDate
    ) d;

    /* A move: the day carries a price the previous priced day did not. */
    SELECT d.BranchId, d.FuelTypeNo, d.IsFleetCard, d.PriceDate AS MoveDate, d.PreviousDate,
           d.PreviousLow AS FromLow, d.PreviousHigh AS FromHigh,
           MIN(n.SellingPrice) AS ToLow, MAX(n.SellingPrice) AS ToHigh,
           MIN(n.CostLow) AS ToCostLow, MAX(n.CostHigh) AS ToCostHigh,
           MIN(n.MarginLow) AS ToMarginLow, MAX(n.MarginHigh) AS ToMarginHigh,
           SUM(n.MissingCost) AS ToMissingCost,
           CONVERT(bit, CASE WHEN EXISTS (
               SELECT 1 FROM #Price o JOIN #Price q
                 ON q.BranchId = o.BranchId AND q.FuelTypeNo = o.FuelTypeNo AND q.IsFleetCard = o.IsFleetCard
                AND q.PriceDate = d.PreviousDate AND q.SellingPrice = o.SellingPrice
               WHERE o.BranchId = d.BranchId AND o.FuelTypeNo = d.FuelTypeNo AND o.IsFleetCard = d.IsFleetCard
                 AND o.PriceDate = d.PriceDate
           ) THEN 1 ELSE 0 END) AS BothRecorded
    INTO #Move
    FROM #Day d
    JOIN #Price n
      ON n.BranchId = d.BranchId AND n.FuelTypeNo = d.FuelTypeNo AND n.IsFleetCard = d.IsFleetCard
     AND n.PriceDate = d.PriceDate
    WHERE d.PreviousDate IS NOT NULL
      AND NOT EXISTS (
          SELECT 1 FROM #Price q
          WHERE q.BranchId = n.BranchId AND q.FuelTypeNo = n.FuelTypeNo AND q.IsFleetCard = n.IsFleetCard
            AND q.PriceDate = d.PreviousDate AND q.SellingPrice = n.SellingPrice)
    GROUP BY d.BranchId, d.FuelTypeNo, d.IsFleetCard, d.PriceDate, d.PreviousDate, d.PreviousLow, d.PreviousHigh;

    /* Result 1: the summary per site and grade, over the selected days only. */
    ;WITH span AS (
        SELECT BranchId, FuelTypeNo, IsFleetCard,
               COUNT(*) AS DaysPriced, MIN(PriceDate) AS FirstDate, MAX(PriceDate) AS LastDate,
               MIN(SellLow) AS SellLow, MAX(SellHigh) AS SellHigh
        FROM #Day
        WHERE PriceDate >= @DateFrom
        GROUP BY BranchId, FuelTypeNo, IsFleetCard
    )
    SELECT s.BranchId, COALESCE(b.Name, CONCAT('Site ', s.BranchId)) AS BranchName,
           s.FuelTypeNo, g.FuelTypeDescription, CONVERT(bit, s.IsFleetCard) AS IsFleetCard,
           s.DaysPriced, s.FirstDate, s.LastDate,
           f.SellLow AS OpeningLow, f.SellHigh AS OpeningHigh,
           l.SellLow AS ClosingLow, l.SellHigh AS ClosingHigh,
           s.SellLow, s.SellHigh,
           (SELECT COUNT(*) FROM #Move m WHERE m.BranchId = s.BranchId AND m.FuelTypeNo = s.FuelTypeNo
               AND m.IsFleetCard = s.IsFleetCard AND m.MoveDate >= @DateFrom) AS Moves,
           CASE WHEN f.SellLow = f.SellHigh AND l.SellLow = l.SellHigh THEN l.SellLow - f.SellLow END AS Change
    FROM span s
    JOIN #Day f ON f.BranchId = s.BranchId AND f.FuelTypeNo = s.FuelTypeNo AND f.IsFleetCard = s.IsFleetCard AND f.PriceDate = s.FirstDate
    JOIN #Day l ON l.BranchId = s.BranchId AND l.FuelTypeNo = s.FuelTypeNo AND l.IsFleetCard = s.IsFleetCard AND l.PriceDate = s.LastDate
    LEFT JOIN #Grade g ON g.BranchId = s.BranchId AND g.FuelTypeNo = s.FuelTypeNo
    LEFT JOIN agora.Branch b ON b.BranchId = s.BranchId
    ORDER BY BranchName, s.FuelTypeNo, s.IsFleetCard;

    /* Result 2: the log. */
    SELECT m.BranchId, COALESCE(b.Name, CONCAT('Site ', m.BranchId)) AS BranchName,
           m.FuelTypeNo, g.FuelTypeDescription, CONVERT(bit, m.IsFleetCard) AS IsFleetCard,
           m.MoveDate, m.PreviousDate, m.FromLow, m.FromHigh, m.ToLow, m.ToHigh,
           CASE WHEN m.FromLow = m.FromHigh AND m.ToLow = m.ToHigh THEN m.ToLow - m.FromLow END AS Change,
           m.ToCostLow, m.ToCostHigh, m.ToMarginLow, m.ToMarginHigh, m.ToMissingCost, m.BothRecorded
    FROM #Move m
    LEFT JOIN #Grade g ON g.BranchId = m.BranchId AND g.FuelTypeNo = m.FuelTypeNo
    LEFT JOIN agora.Branch b ON b.BranchId = m.BranchId
    WHERE m.MoveDate >= @DateFrom
    ORDER BY m.MoveDate, BranchName, m.FuelTypeNo, m.IsFleetCard;
END;
