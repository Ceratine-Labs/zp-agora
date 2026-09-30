/* ============================================================================
   agora.usp_Cash_DeductionStockLoss

   WHAT IT IS FOR
   A person's share of the stock recon loss, stated once (T041). It fills the
   CALLER's #StockLoss with the legacy's "A2" rows — one per site, person, day
   (and shift), counting area, stock item and number of people on the area —
   which the four stock-loss and non-integrated tabs and the payroll build then
   add up each in their own way. Machinery, not a report.

   THE PORT, from the legacy set every one of those reports shares
   (dbo.sp_RPT_EmployeeStockLossReportPAYROLL / …PAYROLLDETAIL /
   …PAYROLLDETAIL_LeniencySummary and dbo.sp_RPT_NonIntegratedPOSDeductions-
   PAYROLL / …PAYROLLDETAIL, all @ShowLosses = 1):

     A   every recon line of a shift the person was signed on to
         (STK_StockReconEmployees), on the CURRENT — balanced — counts, where
         the line's variance is 0.02 or more either way:
             EmpLoss = SUM((-QtyOpen - QtyIssued + QtyClose + QtyComputer) * SellPrice)
         per person, item and sell price, with EmployeeCount the number of
         people signed on to that area and shift.
     A2  the lines that LOST, shared among the people on the area:
             @Kind 'stock'   EmpLoss < 0,        SUM(EmpLoss) / EmployeeCount
             @Kind 'nonint'  EmpLoss <= -1.00,   CONVERT(decimal(10,2), SUM(EmpLoss)) / EmployeeCount
         grouped by day — and by shift as well when @Grain = 'shift', which is
         how the DETAIL and LeniencySummary procedures group it. The two
         arithmetics are kept apart (LossF float, LossD decimal) so a total
         rounds to the cent exactly as the legacy's does.

   THE ROWS KEPT are the ones the legacy's output joins keep, as filters:
   the person is on BRN_Employee and their post on BRN_EmployeePost, the item is
   on STK_StockMaster, and the area is
     'stock'   anything but High Shrinkage Items (OK) and Lotto/Electricity/Airtime (OK)
     'nonint'  Lotto/Electricity/Airtime (OK) only.
   The legacy INNER JOINs STK_StockMaster on (site, item) alone. On the
   customer's instance that pair is unique (read 30 Sep 2026), so the join only
   filters today; it is written here as EXISTS so that a second POS family
   carrying the same item number can never double somebody's deduction.

   THE CALLER CREATES #StockLoss:
     CREATE TABLE #StockLoss (
         BranchId INT, TransactionDate DATE, ShiftNo INT NULL, AreaNo INT,
         EmployeeCode NVARCHAR(22) COLLATE DATABASE_DEFAULT, StockItemNo NVARCHAR(20) COLLATE DATABASE_DEFAULT, EmployeeCount INT,
         LossF FLOAT NULL, LossD DECIMAL(38,13) NULL);

   READ-ONLY (it writes only the caller's temp table).
   ============================================================================ */
CREATE OR ALTER PROCEDURE [agora].[usp_Cash_DeductionStockLoss]
    @BranchIds  NVARCHAR(MAX) = NULL,
    @DateFrom   DATE,
    @DateTo     DATE,
    @Kind       VARCHAR(10)   = 'stock',   -- stock | nonint
    @Grain      VARCHAR(10)   = 'day'      -- day | shift ('nonint' is always day)
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    IF OBJECT_ID('tempdb..#StockLoss') IS NULL
        THROW 51000, 'AGORA:CASH_DEDUCTION_CALLER:usp_Cash_DeductionStockLoss fills the caller''s #StockLoss, which does not exist.', 1;

    IF @DateFrom IS NULL OR @DateTo IS NULL OR @DateTo < @DateFrom
        THROW 51000, 'AGORA:CASH_DEDUCTION_PERIOD:Say which period: a from date on or before a to date.', 1;

    IF @Kind NOT IN ('stock', 'nonint')
        THROW 51000, 'AGORA:CASH_DEDUCTION_KIND:The loss is ''stock'' or ''nonint''.', 1;

    DECLARE @Branch TABLE (BranchId INT PRIMARY KEY);

    INSERT INTO @Branch (BranchId)
    SELECT DISTINCT TRY_CONVERT(INT, LTRIM(RTRIM(s.value)))
    FROM STRING_SPLIT(ISNULL(@BranchIds, ''), ',') s
    WHERE TRY_CONVERT(INT, LTRIM(RTRIM(s.value))) IS NOT NULL;

    DECLARE @All BIT = CASE WHEN EXISTS (SELECT 1 FROM @Branch) THEN 0 ELSE 1 END;

    /* The areas each kind reads. The legacy names them; so does this. */
    DECLARE @NonIntAreas TABLE (AreaDescription NVARCHAR(100) COLLATE DATABASE_DEFAULT PRIMARY KEY);
    INSERT INTO @NonIntAreas VALUES (N'Lotto/Electricity/Airtime'), (N'Lotto/Electricity/Airtime OK');

    DECLARE @StockExcluded TABLE (AreaDescription NVARCHAR(100) COLLATE DATABASE_DEFAULT PRIMARY KEY);
    INSERT INTO @StockExcluded VALUES (N'High Shrinkage Items'), (N'High Shrinkage Items OK'),
                                      (N'Lotto/Electricity/Airtime'), (N'Lotto/Electricity/Airtime OK');

    IF OBJECT_ID('tempdb..#sl_a') IS NOT NULL DROP TABLE #sl_a;

    /* A — the person's lines. */
    SELECT e.BranchId,
           e.TransactionDate,
           e.ShiftNo,
           e.AreaNo,
           e.EmployeeCode,
           l.StockItemNo,
           l.SellPrice,
           c.EmployeeCount,
           SUM((-l.QtyOpen - l.QtyIssued + l.QtyClose + l.QtyComputer) * l.SellPrice) AS EmpLoss
    INTO #sl_a
    FROM agora.vw_StockReconEmployee e
    JOIN agora.vw_StockReconLine l
      ON l.BranchId = e.BranchId AND l.TransactionDate = e.TransactionDate
     AND l.ShiftNo = e.ShiftNo AND l.AreaNo = e.AreaNo
    CROSS APPLY (SELECT COUNT(*) AS EmployeeCount
                 FROM agora.vw_StockReconEmployee x
                 WHERE x.BranchId = l.BranchId AND x.TransactionDate = l.TransactionDate
                   AND x.ShiftNo = l.ShiftNo AND x.AreaNo = l.AreaNo) c
    WHERE (@All = 1 OR e.BranchId IN (SELECT BranchId FROM @Branch))
      AND e.TransactionDate >= @DateFrom AND e.TransactionDate <= @DateTo
      AND (   (l.QtyComputer - (l.QtyOpen + l.QtyIssued - l.QtyClose)) <= -0.02
           OR (l.QtyComputer - (l.QtyOpen + l.QtyIssued - l.QtyClose)) >= 0.02)
    GROUP BY e.BranchId, e.TransactionDate, e.ShiftNo, e.AreaNo, e.EmployeeCode, l.StockItemNo, l.SellPrice, c.EmployeeCount;

    /* The output joins, as filters: person and post, item, and the area the kind reads. */
    DELETE a
    FROM #sl_a a
    WHERE NOT EXISTS (SELECT 1 FROM agora.vw_Employee m
                      JOIN agora.vw_EmployeePost p ON p.BranchId = m.BranchId AND p.PostNo = m.PostNo
                      WHERE m.BranchId = a.BranchId AND m.EmployeeCode = a.EmployeeCode)
       OR NOT EXISTS (SELECT 1 FROM agora.vw_StockMaster s WHERE s.BranchId = a.BranchId AND s.StockItemNo = a.StockItemNo)
       OR NOT EXISTS (SELECT 1 FROM agora.vw_StockArea r
                      WHERE r.BranchId = a.BranchId AND r.AreaNo = a.AreaNo
                        AND (   (@Kind = 'nonint' AND r.AreaDescription IN (SELECT AreaDescription FROM @NonIntAreas))
                             OR (@Kind = 'stock'  AND r.AreaDescription NOT IN (SELECT AreaDescription FROM @StockExcluded))));

    /* A2 — the lines that lost, shared. */
    IF @Kind = 'stock'
        INSERT INTO #StockLoss (BranchId, TransactionDate, ShiftNo, AreaNo, EmployeeCode, StockItemNo, EmployeeCount, LossF, LossD)
        SELECT a.BranchId, CONVERT(DATE, a.TransactionDate),
               CASE WHEN @Grain = 'shift' THEN a.ShiftNo END,
               a.AreaNo, a.EmployeeCode, a.StockItemNo, a.EmployeeCount,
               SUM(a.EmpLoss) / a.EmployeeCount, NULL
        FROM #sl_a a
        WHERE a.EmpLoss < 0
        GROUP BY a.BranchId, a.TransactionDate, CASE WHEN @Grain = 'shift' THEN a.ShiftNo END,
                 a.AreaNo, a.EmployeeCode, a.StockItemNo, a.EmployeeCount;
    ELSE
        INSERT INTO #StockLoss (BranchId, TransactionDate, ShiftNo, AreaNo, EmployeeCode, StockItemNo, EmployeeCount, LossF, LossD)
        SELECT a.BranchId, CONVERT(DATE, a.TransactionDate), NULL,
               a.AreaNo, a.EmployeeCode, a.StockItemNo, a.EmployeeCount,
               NULL, CONVERT(DECIMAL(10,2), SUM(a.EmpLoss)) / a.EmployeeCount
        FROM #sl_a a
        WHERE a.EmpLoss <= -1.00
        GROUP BY a.BranchId, a.TransactionDate, a.AreaNo, a.EmployeeCode, a.StockItemNo, a.EmployeeCount;

    DROP TABLE #sl_a;
END
