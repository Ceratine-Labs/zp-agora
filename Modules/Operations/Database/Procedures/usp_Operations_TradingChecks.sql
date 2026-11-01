/*
   Operations Wall: current critical-line stock and prices, and daily budgets.

   The critical list drives the POS join on branch + system + code: a line
   need not be in the recon master to be critical. Low means one pack or less,
   exactly as usp_Product_GridCriticalLines. No days-cover estimate is made:
   the POS master has no trustworthy snapshot timestamp or dated sales window.
   These are CURRENT recorded quantities, not historical stock on TradeDate.

   Budget mapping follows ZP-NQL docs/sql/exco/31-budget-extract.sql:
   identical monthly duplicates collapse; conflicting duplicates stay unknown;
   branch 9's Retail/Liquor budget belongs to branch 15; accommodation is kept
   out of shop. Calendar-day target = month budget / days in that month.
   No actual comparison is made: day-end VAT basis is not established.

   READ-ONLY. Both selection and independent allowed-site fences are applied.
   Blank/invalid BranchIds means no sites, never the whole estate.
   Deployed by v1__25p_operations_procs; view by v1__25_operations_budget_view.
*/
CREATE OR ALTER PROCEDURE [agora].[usp_Operations_TradingChecks]
    @BranchIds NVARCHAR(MAX) = NULL,
    @AllowedBranchIds NVARCHAR(MAX) = NULL,
    @TradeDate DATE = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    SET @TradeDate = ISNULL(@TradeDate, CONVERT(date, DATEADD(day, -1, DATEADD(hour, 2, SYSUTCDATETIME()))));
    DECLARE @MonthStart date = DATEFROMPARTS(YEAR(@TradeDate), MONTH(@TradeDate), 1),
            @DaysInMonth int = DAY(EOMONTH(@TradeDate)),
            @BudgetYear int = YEAR(DATEADD(month, 6, @TradeDate));

    SELECT b.BranchId, b.Name AS BranchName
    INTO #Branch
    FROM agora.Branch b
    WHERE b.IsActive = 1 AND b.IsTrading = 1 AND b.DeletedAt IS NULL
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

    SELECT c.BranchId, c.PosSystem, c.PosCode,
           COALESCE(NULLIF(c.Description, ''), NULLIF(p.PosDescription, ''), c.PosCode) AS Description,
           COALESCE(NULLIF(c.Category, ''), NULLIF(p.Category, '')) AS Category,
           p.QtyOnHand, p.CostPrice, p.PosSellPrice,
           CASE WHEN ISNULL(p.PackSize, 0) > 0 THEN p.PackSize ELSE 1 END AS LowAt,
           CASE WHEN p.PosCode IS NULL THEN 0 ELSE 1 END AS HasPos
    INTO #Critical
    FROM agora.vw_StockItemCritical c
    JOIN #Branch b ON b.BranchId = c.BranchId
    LEFT JOIN agora.vw_StockItemPos p
           ON p.BranchId = c.BranchId AND p.PosSystem = c.PosSystem AND p.PosCode = c.PosCode
    WHERE c.IsActive = 1;

    INSERT INTO #Rows
    SELECT 'critical', 'critical-stock', b.BranchId, b.BranchName, @TradeDate,
           LEFT(CONCAT(c.PosCode, ' · ', c.Description), 200),
           c.QtyOnHand, c.LowAt, 'quantity',
           CASE WHEN c.QtyOnHand IS NULL THEN 'neutral'
                WHEN c.QtyOnHand <= 0 THEN 'crit'
                WHEN c.QtyOnHand <= c.LowAt THEN 'warn' ELSE 'good' END,
           CONCAT(
               CASE WHEN c.QtyOnHand IS NULL THEN 'No POS stock quantity; stock availability is unknown. '
                    WHEN c.QtyOnHand <= 0 THEN 'Critical line at or below zero recorded stock. Check shelf and receipts. '
                    WHEN c.QtyOnHand <= c.LowAt THEN 'Critical line at or below one pack; check replenishment. '
                    ELSE 'Critical line above one pack of recorded stock. ' END,
               'Current POS snapshot, not historical stock for the selected day; source refresh timestamp unavailable. ',
               'Supporting value: low-stock threshold (pack size, or 1 when unset). POS: ', c.PosSystem, '.'),
           NULL, c.PosCode, LEFT(c.PosSystem, 100), c.HasPos
    FROM #Critical c JOIN #Branch b ON b.BranchId = c.BranchId;

    INSERT INTO #Rows
    SELECT 'critical', 'critical-pricing', b.BranchId, b.BranchName, @TradeDate,
           LEFT(CONCAT(c.PosCode, ' · ', c.Description, ' · price check'), 200),
           CASE WHEN c.PosSellPrice > 0 AND c.CostPrice > 0
                THEN (c.PosSellPrice - c.CostPrice) * 100.0 / c.PosSellPrice END,
           c.PosSellPrice, 'percent',
           CASE WHEN c.CostPrice > 0 AND c.PosSellPrice > 0 AND c.CostPrice > c.PosSellPrice THEN 'crit'
                ELSE 'neutral' END,
           CONCAT(
               CASE WHEN c.HasPos = 0 THEN 'No POS record; price and margin cannot be checked. '
                    WHEN ISNULL(c.CostPrice, 0) <= 0 THEN 'No positive cost price; no margin inferred. '
                    WHEN ISNULL(c.PosSellPrice, 0) <= 0 THEN 'No positive POS sell price; no margin inferred. '
                    ELSE 'Critical line priced below cost. ' END,
               'Current unit margin: (POS sell - standard cost) / POS sell; both exclude VAT. ',
               'Supporting value: POS sell price. Cost: ', COALESCE(CONVERT(varchar(40), c.CostPrice), 'unknown'),
               '. Source refresh timestamp unavailable. POS: ', c.PosSystem, '.'),
           NULL, c.PosCode, LEFT(c.PosSystem, 100), c.HasPos
    FROM #Critical c JOIN #Branch b ON b.BranchId = c.BranchId
    WHERE c.HasPos = 0 OR ISNULL(c.CostPrice, 0) <= 0 OR ISNULL(c.PosSellPrice, 0) <= 0
       OR c.CostPrice > c.PosSellPrice;

    INSERT INTO #Rows
    SELECT 'critical', 'critical-empty', b.BranchId, b.BranchName, @TradeDate,
           'Critical stock list unavailable', NULL, NULL, 'quantity', 'neutral',
           'No active critical lines configured for this site; stock availability cannot be inferred.',
           NULL, NULL, NULL, 0
    FROM #Branch b
    WHERE NOT EXISTS (SELECT 1 FROM #Critical c WHERE c.BranchId = b.BranchId);

    /* Group on the source's natural key BEFORE mapping categories or sites.
       Otherwise identical October rows double the target, and conflicting
       duplicate classifications can quietly land in two different blocks. */
    SELECT x.BranchId, x.TableKey, x.TableUnit, x.BudgetMonth,
           MAX(x.Category) AS Category, MAX(x.SubCategory) AS SubCategory,
           MAX(x.Value) AS MonthValue, COUNT(*) AS SourceRows,
           CASE WHEN MIN(x.Value) <> MAX(x.Value) OR COUNT(x.Value) <> COUNT(*)
                     OR COUNT(DISTINCT x.Category) <> 1 OR COUNT(DISTINCT x.SubCategory) <> 1
                THEN 1 ELSE 0 END AS IsConflict
    INTO #BudgetKey
    FROM agora.vw_OperationsBudget x
    WHERE x.BudgetYear = @BudgetYear
      AND x.BudgetMonth >= @MonthStart AND x.BudgetMonth < DATEADD(month, 1, @MonthStart)
      AND (x.BranchId IN (SELECT BranchId FROM #Branch)
           OR (x.BranchId = 9 AND EXISTS (SELECT 1 FROM #Branch WHERE BranchId = 15)))
    GROUP BY x.BranchId, x.TableKey, x.TableUnit, x.BudgetMonth;

    SELECT m.BranchId, m.Block, SUM(k.MonthValue) AS MonthValue,
           SUM(k.SourceRows) AS SourceRows, MAX(k.IsConflict) AS IsConflict
    INTO #Budget
    FROM #BudgetKey k
    CROSS APPLY (SELECT
        CASE WHEN k.BranchId = 9 AND k.Category = 'Retail' AND k.SubCategory = 'Liquor'
             THEN 15 ELSE k.BranchId END AS BranchId,
        CASE WHEN k.Category = 'Fuel' AND k.TableUnit = 'Volume' THEN 'FUEL'
             WHEN k.Category = 'QSR' AND k.TableUnit = 'TO_Excl' THEN 'QSR'
             WHEN k.Category = 'Retail' AND k.SubCategory = 'Lubes' AND k.TableUnit = 'TO_Excl' THEN 'LUBES'
             WHEN k.Category = 'Retail' AND k.SubCategory <> 'Lubes' AND k.TableUnit = 'TO_Excl' THEN 'SHOP'
             WHEN k.Category = 'Hospitality' AND k.SubCategory <> 'Accomodation' AND k.TableUnit = 'TO_Excl' THEN 'SHOP'
        END AS Block
    ) m
    JOIN #Branch b ON b.BranchId = m.BranchId
    WHERE m.Block IS NOT NULL
    GROUP BY m.BranchId, m.Block;

    INSERT INTO #Rows
    SELECT k.SourceKey, 'daily-budget', b.BranchId, b.BranchName, @TradeDate,
           CONCAT(k.Caption, ' · daily budget'),
           CASE WHEN d.IsConflict = 0 THEN d.MonthValue / @DaysInMonth END,
           NULL, k.Unit, 'neutral',
           CASE WHEN d.BranchId IS NULL THEN 'No budget row available for this site and month; no zero target inferred.'
                WHEN d.IsConflict = 1 THEN 'Conflicting or incomplete duplicate budget rows; target withheld for review.'
                ELSE CONCAT(
                    'Calendar-day target: monthly budget / ', @DaysInMonth,
                    ' days. ', CASE WHEN k.Unit = 'rand' THEN 'Excludes VAT. ' ELSE 'Litres. ' END,
                    CASE k.Block WHEN 'SHOP' THEN 'Excludes lubes and accommodation. ' ELSE '' END,
                    'Identical source duplicates counted once. No actual comparison until the sales basis is verified; source refresh timestamp unavailable.')
           END,
           NULL, k.Block, k.Caption, ISNULL(d.SourceRows, 0)
    FROM #Branch b
    CROSS JOIN (VALUES
        ('budget-shop', 'SHOP', 'Shop', 'rand'),
        ('budget-shop', 'LUBES', 'Lubes', 'rand'),
        ('budget-qsr', 'QSR', 'QSR', 'rand'),
        ('budget-fuel', 'FUEL', 'Fuel', 'litres')
    ) k(SourceKey, Block, Caption, Unit)
    LEFT JOIN #Budget d ON d.BranchId = b.BranchId AND d.Block = k.Block;

    SELECT SourceKey, MetricKey, BranchId, BranchName, BusinessDate, Title,
           Value, SecondaryValue, Unit, Severity, Detail, SourceUpdatedAt,
           ItemCode, Area, SourceCount
    FROM #Rows
    ORDER BY CASE Severity WHEN 'crit' THEN 0 WHEN 'warn' THEN 1 WHEN 'neutral' THEN 2 ELSE 3 END,
             BranchName, SourceKey, MetricKey, ItemCode, Area;
END
