/* ============================================================================
   agora.usp_Reports_GridDailyBanking

   WHAT IT IS FOR
   The daily banking as each till declared it: the Z-reading against the legs it
   was made up of, and the variance the cashier carried.

   WHO READS IT
   The branch, capturing; head office, checking. This is the whole population —
   usp_Reports_GridOpenCashups is the same rows narrowed to days that have not
   balanced.

   THE LEGS
   BRN_DailyBanking carries the summary legs on the cashup row itself: credit
   card, two manual card fields, e-fuel and direct deposits. The per-tender
   detail lives in the BRN_DailyBanking<Tender> tables the Recon module reads —
   this report is the cashup, not the settlement, and it stops at the cashup.

   Declared is the sum of the legs. It is NOT stored anywhere: the legacy screen
   adds it up in the client, which is why two people can disagree about it. It
   is computed here, once, and DeclaredVsZ is the difference against the
   Z-reading, so the row says whether it balanced without anyone adding up.

   ⚠ CardAmount1 and CardAmount2 are the SPLIT of CreditCardAmount, not two more
   legs beside it — CreditCardAmount = CardAmount1 + CardAmount2 on every one of
   the 35,697 rows in 2026. The three are all in the SELECT, so the obvious
   Declared is the sum of all of them, and it is wrong by the card takings.
   CardSplitDisagrees is on the row so the day that stops being true is visible
   rather than silently changing what Declared means.

   EmployeeAmount is the cashier's own variance as PumpIT records it, and is
   returned beside DeclaredVsZ rather than instead of it — they are different
   figures and where they disagree, that is the finding.

   `Posted` on this table is 0 on all 164,019 rows and means nothing. See
   usp_Reports_GridOpenCashups.

   READ-ONLY.

     EXEC agora.usp_Reports_GridDailyBanking @BranchIds = '9', @DateFrom = '2026-09-01';
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
        d.BranchId,
        b.Name                                      AS BranchName,
        d.TransactionDate,
        d.ShiftNo,
        s.ShiftDescription,
        d.TillNo,
        t.TillDescription,
        d.EmployeeCode,
        e.EmployeeName,
        d.DayEndNo,
        d.ClosingBalance                            AS ZReading,
        d.CreditCardAmount,
        d.CardAmount1,
        d.CardAmount2,
        d.EFuelAmount,
        d.DirectDepositAmount,
        /* CardAmount1 and CardAmount2 are the SPLIT of CreditCardAmount, not
           legs beside it: CreditCardAmount = CardAmount1 + CardAmount2 on all
           35,697 rows of 2026, measured 4 Sep 2026. Adding all three — which
           is what the column names invite — doubled the card takings and put
           R250,319 of phantom variance on a till that had balanced. */
        CONVERT(money, ISNULL(d.CreditCardAmount, 0) + ISNULL(d.EFuelAmount, 0)
                     + ISNULL(d.DirectDepositAmount, 0))                        AS DeclaredLegs,
        CONVERT(money, ISNULL(d.CreditCardAmount, 0) + ISNULL(d.EFuelAmount, 0)
                     + ISNULL(d.DirectDepositAmount, 0) - ISNULL(d.ClosingBalance, 0)) AS DeclaredVsZ,
        CASE WHEN ABS(ISNULL(d.CreditCardAmount, 0)
                    - (ISNULL(d.CardAmount1, 0) + ISNULL(d.CardAmount2, 0))) > 0.005
             THEN CONVERT(bit, 1) ELSE CONVERT(bit, 0) END                      AS CardSplitDisagrees,
        d.EmployeeAmount                            AS CashierVariance,
        /* The pump attendant's short for the same till and shift, which is a
           different row and a different person from the cashier variance above.
           Aggregated, because a shift can carry more than one attendant. */
        p.AttendantShort,
        LEFT(d.Note, 400)                           AS Note,
        ISNULL(r.DayBalance, 0)                     AS DayBalanced,
        CASE WHEN ISNULL(r.DayBalance, 0) = 1 THEN 'Day balanced'
             WHEN ISNULL(d.EmployeeAmount, 0) <> 0 THEN 'Cashier variance'
             ELSE 'Open' END                        AS Status,
        d.Imported,
        d.CreateDateTime                            AS CapturedAt,
        d.Posted                                    AS DeadPostedFlag
    INTO #Rows
    FROM agora.vw_DailyBanking d
    JOIN agora.vw_Branch b ON b.BranchId = d.BranchId
    LEFT JOIN agora.vw_Shift s    ON s.BranchId = d.BranchId AND s.ShiftNo = d.ShiftNo
    LEFT JOIN agora.vw_Till t     ON t.BranchId = d.BranchId AND t.TillNo = d.TillNo
    LEFT JOIN agora.vw_Employee e ON e.BranchId = d.BranchId AND e.EmployeeCode = d.EmployeeCode
    LEFT JOIN agora.vw_ReconImports r
           ON r.BranchId = d.BranchId AND r.ReconDate = CONVERT(date, d.TransactionDate)
    OUTER APPLY (
        SELECT SUM(x.AmountShort) AS AttendantShort
        FROM agora.vw_DailyBankingEmployee x
        WHERE x.BranchId = d.BranchId
          AND x.TransactionDate = d.TransactionDate
          AND x.ShiftNo = d.ShiftNo
          AND x.TillNo  = d.TillNo
    ) p
    WHERE d.TransactionDate >= @DateFrom AND d.TransactionDate < DATEADD(day, 1, @DateTo)
      AND (@AllBranches = 1 OR d.BranchId IN (SELECT BranchId FROM @Branch))
      AND (@Search IS NULL
           OR b.Name         LIKE '%' + @Search + '%'
           OR e.EmployeeName LIKE '%' + @Search + '%'
           OR d.EmployeeCode LIKE '%' + @Search + '%'
           OR d.DayEndNo     LIKE '%' + @Search + '%'
           OR d.Note         LIKE '%' + @Search + '%');

    DECLARE @Total bigint = (SELECT COUNT_BIG(*) FROM #Rows);

    SELECT r.BranchId, r.BranchName, r.TransactionDate, r.ShiftNo, r.ShiftDescription,
           r.TillNo, r.TillDescription, r.EmployeeCode, r.EmployeeName, r.DayEndNo,
           r.ZReading, r.CreditCardAmount, r.CardAmount1, r.CardAmount2, r.EFuelAmount,
           r.DirectDepositAmount, r.DeclaredLegs, r.DeclaredVsZ, r.CardSplitDisagrees,
           r.CashierVariance, r.AttendantShort, r.Note,
           r.DayBalanced, r.Status, r.Imported, r.CapturedAt, r.DeadPostedFlag
    FROM #Rows r
    ORDER BY
        CASE WHEN @SortAsc = 1 THEN
            CASE @SortColumn WHEN 'BranchName'   THEN r.BranchName
                             WHEN 'EmployeeName' THEN r.EmployeeName
                             WHEN 'DayEndNo'     THEN r.DayEndNo
                             WHEN 'Status'       THEN r.Status END END ASC,
        CASE WHEN @SortAsc = 0 THEN
            CASE @SortColumn WHEN 'BranchName'   THEN r.BranchName
                             WHEN 'EmployeeName' THEN r.EmployeeName
                             WHEN 'DayEndNo'     THEN r.DayEndNo
                             WHEN 'Status'       THEN r.Status END END DESC,
        CASE WHEN @SortAsc = 1 THEN
            CASE @SortColumn WHEN 'ZReading'        THEN CONVERT(decimal(38,6), r.ZReading)
                             WHEN 'DeclaredLegs'    THEN CONVERT(decimal(38,6), r.DeclaredLegs)
                             WHEN 'DeclaredVsZ'     THEN CONVERT(decimal(38,6), r.DeclaredVsZ)
                             WHEN 'CashierVariance' THEN CONVERT(decimal(38,6), r.CashierVariance)
                             WHEN 'AttendantShort'  THEN CONVERT(decimal(38,6), r.AttendantShort)
                             WHEN 'TillNo'          THEN CONVERT(decimal(38,6), r.TillNo) END END ASC,
        CASE WHEN @SortAsc = 0 THEN
            CASE @SortColumn WHEN 'ZReading'        THEN CONVERT(decimal(38,6), r.ZReading)
                             WHEN 'DeclaredLegs'    THEN CONVERT(decimal(38,6), r.DeclaredLegs)
                             WHEN 'DeclaredVsZ'     THEN CONVERT(decimal(38,6), r.DeclaredVsZ)
                             WHEN 'CashierVariance' THEN CONVERT(decimal(38,6), r.CashierVariance)
                             WHEN 'AttendantShort'  THEN CONVERT(decimal(38,6), r.AttendantShort)
                             WHEN 'TillNo'          THEN CONVERT(decimal(38,6), r.TillNo) END END DESC,
        CASE WHEN @SortAsc = 1 THEN
            CASE @SortColumn WHEN 'TransactionDate' THEN CONVERT(datetime2, r.TransactionDate) END END ASC,
        CASE WHEN @SortAsc = 0 THEN
            CASE @SortColumn WHEN 'TransactionDate' THEN CONVERT(datetime2, r.TransactionDate) END END DESC,
        r.TransactionDate DESC, r.BranchName, r.ShiftNo, r.TillNo
    OFFSET (@Page - 1) * @PageSize ROWS FETCH NEXT @PageSize ROWS ONLY;

    SELECT @Total AS TotalRows;
END
