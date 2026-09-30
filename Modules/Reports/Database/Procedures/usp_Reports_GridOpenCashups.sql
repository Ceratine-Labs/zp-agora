/* ============================================================================
   agora.usp_Reports_GridOpenCashups

   WHAT IT IS FOR
   Cashups nobody has signed off — a till was counted, the money was declared,
   and neither a manager in Agora nor head office in PumpIT has closed it.

   WHO READS IT
   Head office. It is the queue behind the legacy "Cashups Not Closed <FY>"
   reports, which existed once per financial year; this is one report with a
   date range. The working screen is Cash → Cashups with "Open cashups"
   (agora.usp_Cash_GridCashups @Show = 'open'), which answers from the same
   rule.

   THE RULE is agora.vw_CashupTotals.IsOpen, stated once in Cash (T037):
     · closed by a manager in Agora            — not open
     · confirmed in Agora, PumpIT day balanced — not open
     · confirmed in Agora, day not balanced    — open, waiting on a close
     · nothing in Agora, day balanced          — not open ("Day balanced")
     · nothing in Agora, day not balanced      — open
   For every cashup Agora has never touched that is exactly the rule this
   report has used since 4 Sep 2026: the day is not balanced
   (RCN_ReconImports.DayBalance). What says a day is finished is DayBalance —
   not SiteClosedOff, and not the header's `Posted` bit, which is 0 on every
   one of the customer's rows and would call the whole history open.

   A cashup whose day has no RCN_ReconImports row at all has certainly not
   been closed off, and is open — "No day-close record" in HoldingUp.

   Variance is the legacy Daily Banking grid's variance (fifteen legs less the
   Z-read — dbo.sp_GetDailyBankingWithMOPS), not the stored EmployeeAmount,
   which goes stale when a leg moves after the save.

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
        b.Name                                          AS BranchName,
        t.TransactionDate,
        t.ShiftNo,
        s.ShiftDescription,
        t.TillNo,
        tl.TillDescription,
        t.EmployeeCode,
        e.EmployeeName,
        t.DayEndNo,
        t.ZRead                                         AS ZReading,
        t.ActualTotal,
        t.Variance,
        t.ZreadCheck,
        t.[Status],
        t.Note,
        t.DayBalanced,
        t.ShortsConfirmed,
        CASE WHEN t.AgoraStatus = 'confirmed' THEN 'Waiting on a manager''s close'
             WHEN t.DayRecorded = 0           THEN 'No day-close record'
             WHEN t.ShortsConfirmed = 0       THEN 'Shorts not confirmed'
             ELSE 'Day not balanced' END                AS HoldingUp,
        DATEDIFF(day, t.TransactionDate, @DateTo)       AS AgeDays
    INTO #Rows
    FROM agora.vw_CashupTotals t
    JOIN agora.vw_Branch b ON b.BranchId = t.BranchId
    LEFT JOIN agora.vw_Shift s    ON s.BranchId = t.BranchId AND s.ShiftNo = t.ShiftNo
    LEFT JOIN agora.vw_Till tl    ON tl.BranchId = t.BranchId AND tl.TillNo = t.TillNo
    LEFT JOIN agora.vw_Employee e ON e.BranchId = t.BranchId AND e.EmployeeCode = t.EmployeeCode
    WHERE t.TransactionAt >= @From AND t.TransactionAt < @To
      AND t.IsOpen = 1
      AND (@AllBranches = 1 OR t.BranchId IN (SELECT BranchId FROM @Branch))
      AND (@Search IS NULL
           OR b.Name          LIKE '%' + @Search + '%'
           OR e.EmployeeName  LIKE '%' + @Search + '%'
           OR t.EmployeeCode  LIKE '%' + @Search + '%'
           OR t.DayEndNo      LIKE '%' + @Search + '%');

    DECLARE @Total bigint = (SELECT COUNT_BIG(*) FROM #Rows);

    SELECT r.BranchId, r.BranchName, r.TransactionDate, r.ShiftNo, r.ShiftDescription,
           r.TillNo, r.TillDescription, r.EmployeeCode, r.EmployeeName, r.DayEndNo,
           r.ZReading, r.ActualTotal, r.Variance, r.ZreadCheck, r.[Status],
           r.Note, r.DayBalanced, r.ShortsConfirmed, r.HoldingUp, r.AgeDays
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
