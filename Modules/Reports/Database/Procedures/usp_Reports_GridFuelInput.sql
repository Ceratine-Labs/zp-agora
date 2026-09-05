/* ============================================================================
   agora.usp_Reports_GridFuelInput

   WHAT IT IS FOR
   The tank side of the forecourt: opening dip, deliveries, closing dip, what
   that implies the tank sold, and how that compares with what the pumps say
   they sold.

   WHO READS IT
   The forecourt controller. This is the wet-stock reconciliation in one row per
   tank per day.

   THE ARITHMETIC, from the customer's own _DayEndSummaryB block in
   dbo.sp_RPT_DayEndSummaryComprehensiveReport:

       TankSales = OpeningDip + Delivery - ClosingDip
       Variance  = PumpSales  - TankSales

   OPENING DIP IS YESTERDAY'S CLOSING DIP. There is no opening column: BRN_FuelInput
   holds one row per branch per fuel type per day with FuelDip as the CLOSE. The
   legacy report gets the opening by self-joining on FuelDate - 1, which quietly
   drops the first day of any gap — if a branch missed a capture on Sunday,
   Monday's row disappears from the report entirely rather than showing a gap.

   Here it is LAG() over the fuel type, so Monday still reports and carries
   PreviousDipDate. When that is not the day before, DipGapDays says how far
   back it reached and the variance is marked as spanning a gap rather than
   being presented as a day's trading.

   WHERE THE PRICE COMES FROM
   NOT from BRN_FuelType. Its CostPrice and SellingPrice are 0 on all 41 rows,
   measured 4 Sep 2026, so valuing the variance from there makes every figure
   R0.00 and the column reads as "no loss". BRN_FuelPrice holds one row per
   price CHANGE per branch and fuel type; the price in force on a day is the
   latest row on or before it, which is what the APPLY below fetches.

   READ-ONLY.

     EXEC agora.usp_Reports_GridFuelInput @BranchIds = '5', @DateFrom = '2026-09-01';
   ============================================================================ */

CREATE OR ALTER PROCEDURE [agora].[usp_Reports_GridFuelInput]
    @BranchIds   NVARCHAR(MAX) = NULL,
    @DateFrom    DATE          = NULL,
    @DateTo      DATE          = NULL,
    @Search      NVARCHAR(200) = NULL,
    @SortColumn  NVARCHAR(80)  = NULL,
    @SortAsc     BIT           = 1,
    @Page        INT           = 1,
    @PageSize    INT           = 50
AS
BEGIN
    SET NOCOUNT ON;

    SET @DateTo   = ISNULL(@DateTo, CONVERT(date, GETDATE()));
    SET @DateFrom = ISNULL(@DateFrom, DATEADD(day, -6, @DateTo));
    SET @Page     = CASE WHEN ISNULL(@Page, 1) < 1 THEN 1 ELSE @Page END;
    SET @PageSize = CASE WHEN ISNULL(@PageSize, 50) BETWEEN 1 AND 100000 THEN @PageSize ELSE 50 END;
    SET @Search   = NULLIF(LTRIM(RTRIM(ISNULL(@Search, ''))), '');

    DECLARE @Branch TABLE (BranchId int PRIMARY KEY);

    /* The empty-string trap. STRING_SPLIT('', ',') returns ONE row holding an
       empty string, and TRY_CONVERT(int, '') is 0 — not NULL. Without the
       first predicate below, a caller who passes no branches gets a @Branch
       table containing branch 0, @AllBranches is 0, and every report in this
       module silently returns nothing at all. Caught on the first run. */
    INSERT INTO @Branch (BranchId)
    SELECT DISTINCT TRY_CONVERT(int, LTRIM(RTRIM(s.value)))
    FROM STRING_SPLIT(ISNULL(@BranchIds, ''), ',') s
    WHERE LTRIM(RTRIM(s.value)) <> ''
      AND TRY_CONVERT(int, LTRIM(RTRIM(s.value))) IS NOT NULL;

    DECLARE @AllBranches bit = CASE WHEN EXISTS (SELECT 1 FROM @Branch) THEN 0 ELSE 1 END;

    /* One day before the window, so the first day in it has an opening dip.
       Fetching the window alone is what makes the first row of every report
       look like a delivery-sized variance. */
    DECLARE @DipFrom date = DATEADD(day, -14, @DateFrom);

    ;WITH Dips AS (
        SELECT f.BranchId, f.FuelTypeNo, CONVERT(date, f.FuelDate) AS FuelDate,
               f.FuelVolume, f.FuelDip, f.FuelDelivery, f.Imported, f.CreateDateTime,
               LAG(f.FuelDip)  OVER (PARTITION BY f.BranchId, f.FuelTypeNo ORDER BY f.FuelDate) AS PreviousDip,
               LAG(CONVERT(date, f.FuelDate))
                               OVER (PARTITION BY f.BranchId, f.FuelTypeNo ORDER BY f.FuelDate) AS PreviousDipDate
        FROM agora.vw_FuelInput f
        WHERE f.FuelDate >= @DipFrom AND f.FuelDate < DATEADD(day, 1, @DateTo)
          AND (@AllBranches = 1 OR f.BranchId IN (SELECT BranchId FROM @Branch))
    )
    SELECT
        d.BranchId,
        b.Name                                      AS BranchName,
        d.FuelDate,
        d.FuelTypeNo,
        t.FuelTypeDescription,
        d.PreviousDip                               AS OpeningDip,
        d.PreviousDipDate,
        DATEDIFF(day, d.PreviousDipDate, d.FuelDate) AS DipGapDays,
        d.FuelDelivery                              AS Delivery,
        d.FuelDip                                   AS ClosingDip,
        CONVERT(decimal(18,6),
            ISNULL(d.PreviousDip, 0) + ISNULL(d.FuelDelivery, 0) - ISNULL(d.FuelDip, 0)) AS TankSales,
        d.FuelVolume                                AS PumpSales,
        CONVERT(decimal(18,6),
            ISNULL(d.FuelVolume, 0)
          - (ISNULL(d.PreviousDip, 0) + ISNULL(d.FuelDelivery, 0) - ISNULL(d.FuelDip, 0))) AS Variance,
        pr.CostPrice,
        pr.SellingPrice,
        pr.EffectiveFrom                            AS PriceEffectiveFrom,
        CONVERT(money,
            (ISNULL(d.FuelVolume, 0)
           - (ISNULL(d.PreviousDip, 0) + ISNULL(d.FuelDelivery, 0) - ISNULL(d.FuelDip, 0)))
            * ISNULL(pr.CostPrice, 0))              AS VarianceAtCost,
        CASE WHEN d.PreviousDip IS NULL THEN 'No opening dip in range'
             WHEN DATEDIFF(day, d.PreviousDipDate, d.FuelDate) > 1 THEN 'Spans a capture gap'
             ELSE 'Day on day' END                  AS Status,
        d.Imported,
        d.CreateDateTime                            AS CapturedAt
    INTO #Rows
    FROM Dips d
    JOIN agora.vw_Branch b ON b.BranchId = d.BranchId
    LEFT JOIN agora.vw_FuelType t
           ON t.BranchId = d.BranchId AND t.FuelTypeNo = d.FuelTypeNo
    /* The price in force on the day: the latest change on or before it. TOP 1
       in an APPLY rather than a join, because BRN_FuelPrice has one row per
       change and joining it would multiply the day by its price history. */
    OUTER APPLY (
        SELECT TOP 1 x.CostPrice, x.SellingPrice, x.EffectiveFrom
        FROM agora.vw_FuelPrice x
        WHERE x.BranchId = d.BranchId
          AND x.FuelTypeNo = d.FuelTypeNo
          AND x.EffectiveFrom <= d.FuelDate
        ORDER BY x.EffectiveFrom DESC
    ) pr
    /* Now cut back to the window the caller asked for — the extra fortnight
       existed only to supply the opening dip. */
    WHERE d.FuelDate >= @DateFrom AND d.FuelDate <= @DateTo
      AND (@Search IS NULL
           OR b.Name                LIKE '%' + @Search + '%'
           OR t.FuelTypeDescription LIKE '%' + @Search + '%');

    DECLARE @Total bigint = (SELECT COUNT_BIG(*) FROM #Rows);

    SELECT r.BranchId, r.BranchName, r.FuelDate, r.FuelTypeNo, r.FuelTypeDescription,
           r.OpeningDip, r.PreviousDipDate, r.DipGapDays, r.Delivery, r.ClosingDip,
           r.TankSales, r.PumpSales, r.Variance, r.CostPrice, r.SellingPrice,
           r.PriceEffectiveFrom, r.VarianceAtCost, r.Status, r.Imported, r.CapturedAt
    FROM #Rows r
    ORDER BY
        CASE WHEN @SortAsc = 1 THEN
            CASE @SortColumn WHEN 'BranchName'          THEN r.BranchName
                             WHEN 'FuelTypeDescription' THEN r.FuelTypeDescription
                             WHEN 'Status'              THEN r.Status END END ASC,
        CASE WHEN @SortAsc = 0 THEN
            CASE @SortColumn WHEN 'BranchName'          THEN r.BranchName
                             WHEN 'FuelTypeDescription' THEN r.FuelTypeDescription
                             WHEN 'Status'              THEN r.Status END END DESC,
        CASE WHEN @SortAsc = 1 THEN
            CASE @SortColumn WHEN 'Variance'       THEN r.Variance
                             WHEN 'TankSales'      THEN r.TankSales
                             WHEN 'PumpSales'      THEN r.PumpSales
                             WHEN 'Delivery'       THEN r.Delivery
                             WHEN 'VarianceAtCost' THEN CONVERT(decimal(38,6), r.VarianceAtCost) END END ASC,
        CASE WHEN @SortAsc = 0 THEN
            CASE @SortColumn WHEN 'Variance'       THEN r.Variance
                             WHEN 'TankSales'      THEN r.TankSales
                             WHEN 'PumpSales'      THEN r.PumpSales
                             WHEN 'Delivery'       THEN r.Delivery
                             WHEN 'VarianceAtCost' THEN CONVERT(decimal(38,6), r.VarianceAtCost) END END DESC,
        CASE WHEN @SortAsc = 1 THEN
            CASE @SortColumn WHEN 'FuelDate' THEN CONVERT(datetime2, r.FuelDate) END END ASC,
        CASE WHEN @SortAsc = 0 THEN
            CASE @SortColumn WHEN 'FuelDate' THEN CONVERT(datetime2, r.FuelDate) END END DESC,
        r.FuelDate DESC, r.BranchName, r.FuelTypeNo
    OFFSET (@Page - 1) * @PageSize ROWS FETCH NEXT @PageSize ROWS ONLY;

    SELECT @Total AS TotalRows;
END
