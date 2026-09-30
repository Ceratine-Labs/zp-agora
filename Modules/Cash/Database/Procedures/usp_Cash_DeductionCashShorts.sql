/* ============================================================================
   agora.usp_Cash_DeductionCashShorts

   WHAT IT IS FOR
   The cash side of the deduction schedule, stated once (T041). It fills the
   CALLER's #CashShort — it is machinery, not a report: the Cash Short
   Deductions tab (usp_Cash_GridDeductionCashShorts) and the payroll build
   (usp_Cash_BuildDeductions) both call it, so the grid a person reads and the
   file payroll receives cannot disagree.

   THE PORT. dbo.sp_RPT_CashShortDeductionPerEmployee, PumpIT's "Cash Short
   Deductions Per Employee" (SS_Report 223, category PAYROLL), row for row:

     cashier_short  one row per cashup whose cashier is on BRN_Employee: the
                    fifteen tender legs added up, less the Z-read
                    (ClosingBalance). That sum is agora.vw_CashupTotals.Variance
                    — the same legs, the same master joins, the same exclusions
                    (Cheques and the two manual card legs are not in it; a
                    debtor, supplier, expense or pump line whose code is not on
                    its master counts for nothing), so the port reads it rather
                    than adding the legs a second time. A short WORSE than the
                    leniency (R3.00 in the legacy, config
                    cash.deductions.cash_leniency) is deducted in full; at or
                    inside it, it is written off. There is no partial rule.
     pump_short     one row per line of a cashup's pump shortages leg
                    (BRN_DailyBankingEmployees), on a cashup whose cashier is on
                    BRN_Employee: -AmountShort, deducted in full, no leniency.

   The legacy report lists only what it deducts, so a written-off cashier short
   never appears on it. This fills those rows too (IsWrittenOff = 1, Deduction
   0) because the tab's "leniency allowed" figure and the payroll run need
   them; the tab shows Deduction <> 0 only, which is exactly the legacy list.

   AND ONE SOURCE THE LEGACY NEVER HAD:
     cashbag_short  a staff short a cash-bag match raised (agora.StaffShort,
                    Source 'recon'), APPROVED, not declined, and on no cashup.
                    PM decision 01a0e5b5: such a short is never posted onto a
                    cashup (that would put the cashup over by the same amount);
                    deductions read it here instead. Deducted in full.

   THE CALLER CREATES #CashShort:
     CREATE TABLE #CashShort (
         BranchId INT, Source VARCHAR(16) COLLATE DATABASE_DEFAULT, EmployeeCode NVARCHAR(22) COLLATE DATABASE_DEFAULT,
         TransactionDate DATE, TransactionAt DATETIME, TillNo INT, ShiftNo INT,
         ZReading MONEY NULL, PumpAttShort MONEY, CashierShort MONEY,
         LeniencyAllowance MONEY, Deduction MONEY, IsWrittenOff BIT,
         DayEndNo NVARCHAR(20) COLLATE DATABASE_DEFAULT NULL, StaffShortsNo INT NULL, Note NVARCHAR(400) COLLATE DATABASE_DEFAULT NULL);

   @BranchIds  CSV of sites; NULL or empty = every site.
   @DateFrom, @DateTo  inclusive, by trading day, as the legacy's
               TransactionDate >= @FromDate AND <= @ToDate.

   READ-ONLY (it writes only the caller's temp table).
   ============================================================================ */
CREATE OR ALTER PROCEDURE [agora].[usp_Cash_DeductionCashShorts]
    @BranchIds  NVARCHAR(MAX) = NULL,
    @DateFrom   DATE,
    @DateTo     DATE,
    @Leniency   DECIMAL(18,2) = 3.00
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    IF OBJECT_ID('tempdb..#CashShort') IS NULL
        THROW 51000, 'AGORA:CASH_DEDUCTION_CALLER:usp_Cash_DeductionCashShorts fills the caller''s #CashShort, which does not exist.', 1;

    IF @DateFrom IS NULL OR @DateTo IS NULL OR @DateTo < @DateFrom
        THROW 51000, 'AGORA:CASH_DEDUCTION_PERIOD:Say which period: a from date on or before a to date.', 1;

    DECLARE @Branch TABLE (BranchId INT PRIMARY KEY);

    INSERT INTO @Branch (BranchId)
    SELECT DISTINCT TRY_CONVERT(INT, LTRIM(RTRIM(s.value)))
    FROM STRING_SPLIT(ISNULL(@BranchIds, ''), ',') s
    WHERE TRY_CONVERT(INT, LTRIM(RTRIM(s.value))) IS NOT NULL;

    DECLARE @All BIT = CASE WHEN EXISTS (SELECT 1 FROM @Branch) THEN 0 ELSE 1 END;

    DECLARE @From  DATETIME = @DateFrom,
            @To    DATETIME = DATEADD(day, 1, CONVERT(DATETIME, @DateTo)),
            @Allow MONEY    = -ABS(ISNULL(@Leniency, 3.00));

    /* Cashier shorts — every cashup that came out short, written off or not. */
    INSERT INTO #CashShort (BranchId, Source, EmployeeCode, TransactionDate, TransactionAt, TillNo, ShiftNo,
                            ZReading, PumpAttShort, CashierShort, LeniencyAllowance, Deduction, IsWrittenOff,
                            DayEndNo, StaffShortsNo, Note)
    SELECT t.BranchId, 'cashier_short', t.EmployeeCode, t.TransactionDate, t.TransactionAt, t.TillNo, t.ShiftNo,
           t.ZRead, 0, t.Variance, @Allow,
           CASE WHEN t.Variance < @Allow THEN t.Variance ELSE 0 END,
           CASE WHEN t.Variance < @Allow THEN 0 ELSE 1 END,
           CONVERT(NVARCHAR(20), t.DayEndNo), NULL, CONVERT(NVARCHAR(400), t.Note)
    FROM agora.vw_CashupTotals t
    WHERE (@All = 1 OR t.BranchId IN (SELECT BranchId FROM @Branch))
      AND t.TransactionAt >= @From AND t.TransactionAt < @To
      AND t.Variance < 0
      AND EXISTS (SELECT 1 FROM agora.vw_Employee e WHERE e.BranchId = t.BranchId AND e.EmployeeCode = t.EmployeeCode);

    /* Pump attendant shorts — the cashup's pump shortages leg, line by line. */
    INSERT INTO #CashShort (BranchId, Source, EmployeeCode, TransactionDate, TransactionAt, TillNo, ShiftNo,
                            ZReading, PumpAttShort, CashierShort, LeniencyAllowance, Deduction, IsWrittenOff,
                            DayEndNo, StaffShortsNo, Note)
    SELECT e.BranchId, 'pump_short', e.EmployeeCode, CONVERT(DATE, e.TransactionDate), e.TransactionDate,
           e.TillNo, e.ShiftNo,
           0, CONVERT(MONEY, e.AmountShort) * -1, 0, 0, CONVERT(MONEY, e.AmountShort) * -1, 0,
           CONVERT(NVARCHAR(20), h.DayEndNo), NULLIF(e.StaffShortsNo, 0), CONVERT(NVARCHAR(400), e.Note)
    FROM agora.vw_DailyBankingEmployee e
    JOIN agora.vw_Cashup h
      ON h.BranchId = e.BranchId AND h.TillNo = e.TillNo AND h.ShiftNo = e.ShiftNo AND h.TransactionAt = e.TransactionDate
    WHERE (@All = 1 OR e.BranchId IN (SELECT BranchId FROM @Branch))
      AND e.TransactionDate >= @From AND e.TransactionDate < @To
      AND ISNULL(e.AmountShort, 0) <> 0
      AND EXISTS (SELECT 1 FROM agora.vw_Employee x WHERE x.BranchId = h.BranchId AND x.EmployeeCode = h.EmployeeCode);

    /* Cash-bag shorts — raised by a match, approved, and (by design) on no cashup. */
    INSERT INTO #CashShort (BranchId, Source, EmployeeCode, TransactionDate, TransactionAt, TillNo, ShiftNo,
                            ZReading, PumpAttShort, CashierShort, LeniencyAllowance, Deduction, IsWrittenOff,
                            DayEndNo, StaffShortsNo, Note)
    SELECT a.BranchId, 'cashbag_short', LTRIM(RTRIM(s.EmployeeCode)), CONVERT(DATE, s.TransactionDate), s.TransactionDate,
           a.TillNo, a.ShiftNo,
           NULL, 0, CONVERT(MONEY, s.AmountShort) * -1, 0, CONVERT(MONEY, s.AmountShort) * -1, 0,
           NULL, s.StaffShortsNo,
           LEFT(ISNULL(a.SourceRef + N': ', N'') + ISNULL(CONVERT(NVARCHAR(400), s.Note), N''), 400)
    FROM agora.StaffShort a
    JOIN agora.vw_StaffShort s ON s.BranchId = a.BranchId AND s.StaffShortsNo = a.StaffShortsNo
    WHERE a.Source = 'recon'
      AND (@All = 1 OR a.BranchId IN (SELECT BranchId FROM @Branch))
      AND s.Approved = 1 AND ISNULL(s.IsDeclined, 0) = 0
      AND s.TransactionDate >= @From AND s.TransactionDate < @To
      AND ISNULL(s.AmountShort, 0) <> 0
      AND NOT EXISTS (SELECT 1 FROM agora.vw_DailyBankingEmployee x
                      WHERE x.BranchId = a.BranchId AND x.StaffShortsNo = a.StaffShortsNo);
END
