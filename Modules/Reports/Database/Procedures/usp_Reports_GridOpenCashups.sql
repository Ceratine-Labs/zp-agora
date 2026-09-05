/* ============================================================================
   agora.usp_Reports_GridOpenCashups

   WHAT IT IS FOR
   Cashups a manager has never closed off — a till was counted, the money was
   declared, and the day it belongs to has still not been balanced.

   WHO READS IT
   Head office. It is the queue behind the legacy "Cashups Not Closed <FY>"
   reports, which existed once per financial year; this is one report with a
   date range.

   THE TRAP THIS PROCEDURE EXISTS TO AVOID
   BRN_DailyBanking has a `Posted` bit, and it is the obvious column to use.
   It is 0 on ALL 164,019 rows — measured 4 Sep 2026 — so a report built on it
   would call every cashup in the company's history open, and be believed for
   about a week. `Posted` is returned here, named as itself, so anyone reaching
   for it can see what it does.

   What actually says a day is finished is RCN_ReconImports.DayBalance. Not
   SiteClosedOff either — see usp_Reports_GridDayClose for the measurements.

   THE JOIN IS A LEFT JOIN ON PURPOSE
   A cashup whose day has no ledger row at all has certainly not been closed
   off, and an inner join would drop exactly those rows: the worst ones.

   Cashups are transactional. Nothing here offers a delete, and nothing in this
   module writes.

     EXEC agora.usp_Reports_GridOpenCashups @DateFrom = '2026-07-01', @DateTo = '2026-09-03';
   ============================================================================ */

CREATE OR ALTER PROCEDURE [agora].[usp_Reports_GridOpenCashups]
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
    SET @DateFrom = ISNULL(@DateFrom, DATEADD(day, -90, @DateTo));
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
        b.Name                                          AS BranchName,
        d.TransactionDate,
        d.ShiftNo,
        s.ShiftDescription,
        d.TillNo,
        t.TillDescription,
        d.EmployeeCode,
        e.EmployeeName,
        d.DayEndNo,
        d.ClosingBalance                                AS ZReading,
        d.EmployeeAmount                                AS Variance,
        d.CreditCardAmount,
        d.EFuelAmount,
        d.DirectDepositAmount,
        d.Note,
        ISNULL(r.DayBalance, 0)                         AS DayBalanced,
        ISNULL(r.ShortsConfirmed, 0)                    AS ShortsConfirmed,
        CASE WHEN r.BranchId IS NULL THEN 'No day-close record'
             WHEN r.ShortsConfirmed = 0 THEN 'Shorts not confirmed'
             ELSE 'Day not balanced' END                AS HoldingUp,
        DATEDIFF(day, d.TransactionDate, @DateTo)       AS AgeDays,
        d.Posted                                        AS DeadPostedFlag
    INTO #Rows
    FROM agora.vw_DailyBanking d
    JOIN agora.vw_Branch b ON b.BranchId = d.BranchId
    LEFT JOIN agora.vw_ReconImports r
           ON r.BranchId = d.BranchId
          AND r.ReconDate = CONVERT(date, d.TransactionDate)
    LEFT JOIN agora.vw_Shift s
           ON s.BranchId = d.BranchId AND s.ShiftNo = d.ShiftNo
    LEFT JOIN agora.vw_Till t
           ON t.BranchId = d.BranchId AND t.TillNo = d.TillNo
    LEFT JOIN agora.vw_Employee e
           ON e.BranchId = d.BranchId AND e.EmployeeCode = d.EmployeeCode
    WHERE d.TransactionDate >= @DateFrom AND d.TransactionDate < DATEADD(day, 1, @DateTo)
      AND ISNULL(r.DayBalance, 0) = 0
      AND (@AllBranches = 1 OR d.BranchId IN (SELECT BranchId FROM @Branch))
      AND (@Search IS NULL
           OR b.Name          LIKE '%' + @Search + '%'
           OR e.EmployeeName  LIKE '%' + @Search + '%'
           OR d.EmployeeCode  LIKE '%' + @Search + '%'
           OR d.DayEndNo      LIKE '%' + @Search + '%');

    DECLARE @Total bigint = (SELECT COUNT_BIG(*) FROM #Rows);

    SELECT r.BranchId, r.BranchName, r.TransactionDate, r.ShiftNo, r.ShiftDescription,
           r.TillNo, r.TillDescription, r.EmployeeCode, r.EmployeeName, r.DayEndNo,
           r.ZReading, r.Variance, r.CreditCardAmount, r.EFuelAmount, r.DirectDepositAmount,
           r.Note, r.DayBalanced, r.ShortsConfirmed, r.HoldingUp, r.AgeDays, r.DeadPostedFlag
    FROM #Rows r
    ORDER BY
        CASE WHEN @SortAsc = 1 THEN
            CASE @SortColumn WHEN 'BranchName'   THEN r.BranchName
                             WHEN 'EmployeeName' THEN r.EmployeeName
                             WHEN 'DayEndNo'     THEN r.DayEndNo
                             WHEN 'HoldingUp'    THEN r.HoldingUp END END ASC,
        CASE WHEN @SortAsc = 0 THEN
            CASE @SortColumn WHEN 'BranchName'   THEN r.BranchName
                             WHEN 'EmployeeName' THEN r.EmployeeName
                             WHEN 'DayEndNo'     THEN r.DayEndNo
                             WHEN 'HoldingUp'    THEN r.HoldingUp END END DESC,
        CASE WHEN @SortAsc = 1 THEN
            CASE @SortColumn WHEN 'ZReading' THEN CONVERT(decimal(38,6), r.ZReading)
                             WHEN 'Variance' THEN CONVERT(decimal(38,6), r.Variance)
                             WHEN 'AgeDays'  THEN CONVERT(decimal(38,6), r.AgeDays)
                             WHEN 'TillNo'   THEN CONVERT(decimal(38,6), r.TillNo) END END ASC,
        CASE WHEN @SortAsc = 0 THEN
            CASE @SortColumn WHEN 'ZReading' THEN CONVERT(decimal(38,6), r.ZReading)
                             WHEN 'Variance' THEN CONVERT(decimal(38,6), r.Variance)
                             WHEN 'AgeDays'  THEN CONVERT(decimal(38,6), r.AgeDays)
                             WHEN 'TillNo'   THEN CONVERT(decimal(38,6), r.TillNo) END END DESC,
        CASE WHEN @SortAsc = 1 THEN
            CASE @SortColumn WHEN 'TransactionDate' THEN CONVERT(datetime2, r.TransactionDate) END END ASC,
        CASE WHEN @SortAsc = 0 THEN
            CASE @SortColumn WHEN 'TransactionDate' THEN CONVERT(datetime2, r.TransactionDate) END END DESC,
        r.TransactionDate, r.BranchName, r.ShiftNo, r.TillNo
    OFFSET (@Page - 1) * @PageSize ROWS FETCH NEXT @PageSize ROWS ONLY;

    SELECT @Total AS TotalRows;
END
