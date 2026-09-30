/* ============================================================================
   agora.usp_Reports_GridDailyBanking

   WHAT IT IS FOR
   Daily banking — manager's view: every cashup in the period, with the
   Z-read it was balanced against, what it actually totals, the variance, the
   cash bags, the cards and the pump shortages, whether the Z-read agrees and
   where the cashup stands. The legacy "Daily Banking - Managers View".

   WHO READS IT
   Head office, from the report library. The working screen with the same rows
   is Cash → Cashups (agora.usp_Cash_GridCashups), where a cashup can be opened,
   confirmed and closed.

   THE FIGURES ARE CASH'S. Since T037 every figure comes from
   agora.vw_CashupTotals, which computes the actual total and the variance
   exactly as PumpIT's own Daily Banking grid does (dbo.sp_GetDailyBankingWithMOPS:
   fifteen legs less ClosingBalance) — so this report, the Cashups grid and the
   Daily Banking screen cannot disagree about a variance.

   What it replaced, and why: until 27 Sep 2026 this report called
   CreditCardAmount + EFuelAmount + DirectDepositAmount "Declared". Those three
   header columns are the card total and two columns that are 0 on every row
   since 2024; the cashup is declared in the eighteen BRN_DailyBanking<Leg>
   tables, which the report never read. Its "Declared vs Z" was therefore the
   card takings less the whole Z-read. ActualTotal and Variance replace both.

   PumpShortages is BRN_DailyBankingEmployees — the pump attendants' shorts on
   the cashup — which PumpIT adds into the actual total like any other leg.
   StoredVariance is what PumpIT last wrote to EmployeeAmount; it differs from
   Variance when a leg moved after the cashup was saved.

   Status is one word — Open, Confirmed, Closed, or Day balanced (closed off in
   PumpIT) — and ZreadCheck one of Agrees, No Z-read, Z-read differs, Other
   cashier (see agora.vw_CashupTotals).

   READ-ONLY.

     EXEC agora.usp_Reports_GridDailyBanking @BranchIds = '6', @DateFrom = '2026-05-22', @DateTo = '2026-05-22';
   ============================================================================ */

CREATE OR ALTER PROCEDURE [agora].[usp_Reports_GridDailyBanking]
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

    DECLARE @From DATETIME = @DateFrom, @To DATETIME = DATEADD(day, 1, CONVERT(DATETIME, @DateTo));
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

    SELECT
        t.BranchId,
        b.Name                                      AS BranchName,
        t.TransactionDate,
        t.ShiftNo,
        s.ShiftDescription,
        t.TillNo,
        tl.TillDescription,
        t.EmployeeCode,
        e.EmployeeName,
        t.DayEndNo,
        t.ZRead                                     AS ZReading,
        t.ActualTotal,
        t.Variance,
        t.CashBags,
        t.Cards,
        t.StaffShorts                               AS PumpShortages,
        t.StoredVariance,
        t.ZreadCheck,
        t.[Status],
        t.DayBalanced,
        LEFT(t.Note, 400)                           AS Note,
        t.CapturedAt
    INTO #Rows
    FROM agora.vw_CashupTotals t
    JOIN agora.vw_Branch b ON b.BranchId = t.BranchId
    LEFT JOIN agora.vw_Shift s    ON s.BranchId = t.BranchId AND s.ShiftNo = t.ShiftNo
    LEFT JOIN agora.vw_Till tl    ON tl.BranchId = t.BranchId AND tl.TillNo = t.TillNo
    LEFT JOIN agora.vw_Employee e ON e.BranchId = t.BranchId AND e.EmployeeCode = t.EmployeeCode
    WHERE t.TransactionAt >= @From AND t.TransactionAt < @To
      AND (@AllBranches = 1 OR t.BranchId IN (SELECT BranchId FROM @Branch))
      AND (@Search IS NULL
           OR b.Name         LIKE '%' + @Search + '%'
           OR e.EmployeeName LIKE '%' + @Search + '%'
           OR t.EmployeeCode LIKE '%' + @Search + '%'
           OR t.DayEndNo     LIKE '%' + @Search + '%'
           OR t.Note         LIKE '%' + @Search + '%');

    DECLARE @Total bigint = (SELECT COUNT_BIG(*) FROM #Rows);

    SELECT r.BranchId, r.BranchName, r.TransactionDate, r.ShiftNo, r.ShiftDescription,
           r.TillNo, r.TillDescription, r.EmployeeCode, r.EmployeeName, r.DayEndNo,
           r.ZReading, r.ActualTotal, r.Variance, r.CashBags, r.Cards, r.PumpShortages,
           r.StoredVariance, r.ZreadCheck, r.[Status], r.DayBalanced, r.Note, r.CapturedAt
    FROM #Rows r
    ORDER BY
        CASE WHEN @SortAsc = 1 THEN
            CASE @SortColumn WHEN 'BranchName'   THEN r.BranchName
                             WHEN 'EmployeeName' THEN r.EmployeeName
                             WHEN 'DayEndNo'     THEN r.DayEndNo
                             WHEN 'ZreadCheck'   THEN r.ZreadCheck
                             WHEN 'Status'       THEN r.[Status] END END ASC,
        CASE WHEN @SortAsc = 0 THEN
            CASE @SortColumn WHEN 'BranchName'   THEN r.BranchName
                             WHEN 'EmployeeName' THEN r.EmployeeName
                             WHEN 'DayEndNo'     THEN r.DayEndNo
                             WHEN 'ZreadCheck'   THEN r.ZreadCheck
                             WHEN 'Status'       THEN r.[Status] END END DESC,
        CASE WHEN @SortAsc = 1 THEN
            CASE @SortColumn WHEN 'ZReading'      THEN CONVERT(decimal(38,6), r.ZReading)
                             WHEN 'ActualTotal'   THEN CONVERT(decimal(38,6), r.ActualTotal)
                             WHEN 'Variance'      THEN CONVERT(decimal(38,6), r.Variance)
                             WHEN 'CashBags'      THEN CONVERT(decimal(38,6), r.CashBags)
                             WHEN 'Cards'         THEN CONVERT(decimal(38,6), r.Cards)
                             WHEN 'PumpShortages' THEN CONVERT(decimal(38,6), r.PumpShortages)
                             WHEN 'TillNo'        THEN CONVERT(decimal(38,6), r.TillNo) END END ASC,
        CASE WHEN @SortAsc = 0 THEN
            CASE @SortColumn WHEN 'ZReading'      THEN CONVERT(decimal(38,6), r.ZReading)
                             WHEN 'ActualTotal'   THEN CONVERT(decimal(38,6), r.ActualTotal)
                             WHEN 'Variance'      THEN CONVERT(decimal(38,6), r.Variance)
                             WHEN 'CashBags'      THEN CONVERT(decimal(38,6), r.CashBags)
                             WHEN 'Cards'         THEN CONVERT(decimal(38,6), r.Cards)
                             WHEN 'PumpShortages' THEN CONVERT(decimal(38,6), r.PumpShortages)
                             WHEN 'TillNo'        THEN CONVERT(decimal(38,6), r.TillNo) END END DESC,
        CASE WHEN @SortAsc = 1 THEN
            CASE @SortColumn WHEN 'TransactionDate' THEN CONVERT(datetime2, r.TransactionDate) END END ASC,
        CASE WHEN @SortAsc = 0 THEN
            CASE @SortColumn WHEN 'TransactionDate' THEN CONVERT(datetime2, r.TransactionDate) END END DESC,
        r.TransactionDate DESC, r.BranchName, r.ShiftNo, r.TillNo
    OFFSET (@Page - 1) * @PageSize ROWS FETCH NEXT @PageSize ROWS ONLY;

    SELECT @Total AS TotalRows;
END
