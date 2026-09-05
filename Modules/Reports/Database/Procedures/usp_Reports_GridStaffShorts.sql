/* ============================================================================
   agora.usp_Reports_GridStaffShorts

   WHAT IT IS FOR
   Cash shorts raised against an employee that nobody has approved or declined.
   Until one is decided it is neither a deduction nor a write-off, and it sits
   against a person's name.

   WHO READS IT
   The approver, and head office. Measured 4 Sep 2026: 39 outstanding of 4,733.

   THE RULE, from dbo.sp_SelectUnApprovedStaffShortsByApproverUser:

       Approved <> 1  AND  IsDeclined <> 1

   The legacy procedure additionally restricts to the signed-in user's approval
   band. That belongs to the screen, not the report — this is the head-office
   view and the branches selector scopes it.

   WHY Reason AND Note ARE CONVERTED
   Both are `ntext` on BRN_StaffShorts. ntext cannot be compared, sorted or
   LIKEd, so the grid's @Search over them is a runtime error, not a slow query.
   agora.vw_StaffShort converts them once.

   THE SECOND HALF OF THE STORY
   A short raised here often has a matching pump-attendant row in
   BRN_DailyBankingEmployees, which carries the SAME StaffShortsNo. It is joined
   in so the shift and till the short came off are on the row: "R240 short" is
   an argument, "R240 short, till 2, afternoon shift" is a conversation.

   Shorts are transactional. Nothing here deletes.

     EXEC agora.usp_Reports_GridStaffShorts;
   ============================================================================ */

CREATE OR ALTER PROCEDURE [agora].[usp_Reports_GridStaffShorts]
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
        s.BranchId,
        b.Name                                      AS BranchName,
        s.StaffShortsNo,
        s.TransactionDate,
        s.EmployeeCode,
        e.EmployeeName,
        e.IsPumpAttendant,
        s.AmountShort,
        LEFT(s.Reason, 400)                         AS Reason,
        LEFT(s.Note, 400)                           AS Note,
        u.UserName                                  AS CapturedBy,
        s.CreateDateTime                            AS CapturedAt,
        DATEDIFF(day, s.TransactionDate, @DateTo)   AS AgeDays,

        /* The matching pump-attendant row, when there is one. Aggregated in an
           APPLY rather than joined: the same StaffShortsNo can appear on more
           than one till row, and joining would multiply the short. */
        d.Tills,
        d.ShiftNos,
        d.PumpAmountShort
    INTO #Rows
    FROM agora.vw_StaffShort s
    JOIN agora.vw_Branch b ON b.BranchId = s.BranchId
    LEFT JOIN agora.vw_Employee e
           ON e.BranchId = s.BranchId AND e.EmployeeCode = s.EmployeeCode
    LEFT JOIN agora.vw_ErpUser u ON u.ErpUserId = s.CreatedByUserId
    OUTER APPLY (
        /* Two ordered STRING_AGGs cannot share a scope — SQL Server refuses
           "mutually incompatible orderings" — so each list is built in its own
           subquery. DISTINCT as well: a short spread over three rows of the
           same till should read "4", not "4, 4, 4". */
        SELECT (SELECT STRING_AGG(CONVERT(nvarchar(12), t.TillNo), ', ')
                       WITHIN GROUP (ORDER BY CONVERT(nvarchar(12), t.TillNo))
                FROM (SELECT DISTINCT x.TillNo
                      FROM agora.vw_DailyBankingEmployee x
                      WHERE x.BranchId = s.BranchId AND x.StaffShortsNo = s.StaffShortsNo) t) AS Tills,
               (SELECT STRING_AGG(CONVERT(nvarchar(12), f2.ShiftNo), ', ')
                       WITHIN GROUP (ORDER BY CONVERT(nvarchar(12), f2.ShiftNo))
                FROM (SELECT DISTINCT x.ShiftNo
                      FROM agora.vw_DailyBankingEmployee x
                      WHERE x.BranchId = s.BranchId AND x.StaffShortsNo = s.StaffShortsNo) f2) AS ShiftNos,
               (SELECT SUM(x.AmountShort)
                FROM agora.vw_DailyBankingEmployee x
                WHERE x.BranchId = s.BranchId AND x.StaffShortsNo = s.StaffShortsNo) AS PumpAmountShort
    ) d
    WHERE s.TransactionDate >= @DateFrom AND s.TransactionDate < DATEADD(day, 1, @DateTo)
      AND s.Approved = 0 AND s.IsDeclined = 0
      AND (@AllBranches = 1 OR s.BranchId IN (SELECT BranchId FROM @Branch))
      AND (@Search IS NULL
           OR b.Name         LIKE '%' + @Search + '%'
           OR e.EmployeeName LIKE '%' + @Search + '%'
           OR s.EmployeeCode LIKE '%' + @Search + '%'
           OR s.Reason       LIKE '%' + @Search + '%'
           OR s.Note         LIKE '%' + @Search + '%');

    DECLARE @Total bigint = (SELECT COUNT_BIG(*) FROM #Rows);

    SELECT r.BranchId, r.BranchName, r.StaffShortsNo, r.TransactionDate,
           r.EmployeeCode, r.EmployeeName, r.IsPumpAttendant, r.AmountShort,
           r.Reason, r.Note, r.CapturedBy, r.CapturedAt, r.AgeDays,
           r.Tills, r.ShiftNos, r.PumpAmountShort
    FROM #Rows r
    ORDER BY
        CASE WHEN @SortAsc = 1 THEN
            CASE @SortColumn WHEN 'BranchName'   THEN r.BranchName
                             WHEN 'EmployeeName' THEN r.EmployeeName
                             WHEN 'Reason'       THEN r.Reason
                             WHEN 'CapturedBy'   THEN r.CapturedBy END END ASC,
        CASE WHEN @SortAsc = 0 THEN
            CASE @SortColumn WHEN 'BranchName'   THEN r.BranchName
                             WHEN 'EmployeeName' THEN r.EmployeeName
                             WHEN 'Reason'       THEN r.Reason
                             WHEN 'CapturedBy'   THEN r.CapturedBy END END DESC,
        CASE WHEN @SortAsc = 1 THEN
            CASE @SortColumn WHEN 'AmountShort'   THEN CONVERT(decimal(38,6), r.AmountShort)
                             WHEN 'AgeDays'       THEN CONVERT(decimal(38,6), r.AgeDays)
                             WHEN 'StaffShortsNo' THEN CONVERT(decimal(38,6), r.StaffShortsNo) END END ASC,
        CASE WHEN @SortAsc = 0 THEN
            CASE @SortColumn WHEN 'AmountShort'   THEN CONVERT(decimal(38,6), r.AmountShort)
                             WHEN 'AgeDays'       THEN CONVERT(decimal(38,6), r.AgeDays)
                             WHEN 'StaffShortsNo' THEN CONVERT(decimal(38,6), r.StaffShortsNo) END END DESC,
        CASE WHEN @SortAsc = 1 THEN
            CASE @SortColumn WHEN 'TransactionDate' THEN CONVERT(datetime2, r.TransactionDate)
                             WHEN 'CapturedAt'      THEN CONVERT(datetime2, r.CapturedAt) END END ASC,
        CASE WHEN @SortAsc = 0 THEN
            CASE @SortColumn WHEN 'TransactionDate' THEN CONVERT(datetime2, r.TransactionDate)
                             WHEN 'CapturedAt'      THEN CONVERT(datetime2, r.CapturedAt) END END DESC,
        r.TransactionDate, r.BranchName, r.StaffShortsNo
    OFFSET (@Page - 1) * @PageSize ROWS FETCH NEXT @PageSize ROWS ONLY;

    SELECT @Total AS TotalRows;
END
