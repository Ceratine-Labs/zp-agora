/* One employee, for the row's detail panel and its own page (T023).
   Read-only.

   Result sets, in order:
     1. the employee (agora.vw_PeopleEmployee) with the branch's name — empty
        when there is no such employee or the branch is not one of
        @AllowedBranchIds
     2. the counting areas the person may be signed on to (BRN_EmployeeArea)
     3. the most recent shifts they worked, newest first: cashups where they
        were the cashier (BRN_DailyBanking.EmployeeCode) and stock counts they
        were signed on to (STK_StockReconEmployees), @ShiftLimit rows at most
     4. one row per kind of shift: how many, the first and the last day

   An INACTIVE employee answers exactly like an active one. That is the point
   of 3 and 4: a terminated cashier's historic cashups still name them, and
   this is where that is seen.

   @AllowedBranchIds is the caller's granted sites as a CSV, NULL for every
   branch — the controller resolves it from BranchContext and checks it too.
   Read by Modules/People/Services/EmployeeService. Deployed by v1__03p. */
CREATE OR ALTER PROCEDURE agora.usp_People_GetEmployee
    @BranchId INT,
    @EmployeeCode NVARCHAR(11),
    @AllowedBranchIds NVARCHAR(MAX) = NULL,
    @ShiftLimit INT = 30
AS
BEGIN
    SET NOCOUNT ON;
    SET @EmployeeCode = LTRIM(RTRIM(@EmployeeCode));
    SET @ShiftLimit = CASE WHEN ISNULL(@ShiftLimit, 0) BETWEEN 1 AND 500 THEN @ShiftLimit ELSE 30 END;

    DECLARE @Visible BIT = CASE
        WHEN NULLIF(LTRIM(RTRIM(@AllowedBranchIds)), '') IS NULL THEN 1
        WHEN EXISTS (SELECT 1 FROM STRING_SPLIT(@AllowedBranchIds, ',') WHERE TRY_CONVERT(INT, value) = @BranchId) THEN 1
        ELSE 0 END;

    SELECT e.BranchId, b.Name AS BranchName, e.EmployeeCode, e.EmployeeName,
           e.Title, e.Initials, e.FirstName, e.Surname, e.NickName,
           e.PostNo, e.PostDescription, e.PostStockControl,
           e.IsActive, e.Status, e.IsPumpAttendant, e.IsSystemAccount,
           e.DateJoined, e.DateDepart, e.CashDeviceCode, e.DepositorName,
           e.CanSignIn, e.CanAccessManager, e.CanAccessSetup, e.CreateDateTime
    FROM agora.vw_PeopleEmployee e
    LEFT JOIN agora.Branch b ON b.BranchId = e.BranchId
    WHERE e.BranchId = @BranchId AND e.EmployeeCode = @EmployeeCode AND @Visible = 1;

    SELECT a.EmployeeAreaId, a.AreaNo, a.AreaDescription, a.AreaGroup
    FROM agora.vw_PeopleEmployeeArea a
    WHERE a.BranchId = @BranchId AND a.EmployeeCode = @EmployeeCode AND @Visible = 1
    ORDER BY a.AreaNo;

    /* The base tables are read rather than agora.vw_DailyBanking and
       agora.vw_StockReconEmployee, because both are indexed on
       (SSBranchId, EmployeeCode) on the customer's instance and the views
       trim the code, which would turn the seek into a scan of the branch.
       The codes are stored without leading spaces; a trailing one is
       ignored by '=' in T-SQL. Named [PumpIT] as the other procedures that
       read a base table do (usp_Cash_GetTillBalance). */
    SELECT TOP (@ShiftLimit) s.Kind, s.TransactionDate, s.ShiftNo, s.TillNo, s.AreaNo, ar.AreaDescription
    FROM (
        SELECT N'Cashup' AS Kind, CONVERT(date, d.TransactionDate) AS TransactionDate, d.ShiftNo, d.TillNo,
               CONVERT(INT, NULL) AS AreaNo
        FROM [PumpIT].dbo.BRN_DailyBanking d
        WHERE d.SSBranchId = @BranchId AND d.EmployeeCode = @EmployeeCode AND @Visible = 1
        UNION ALL
        SELECT N'Stock count', CONVERT(date, c.TransactionDate), c.ShiftNo, CONVERT(INT, NULL), c.AreaNo
        FROM [PumpIT].dbo.STK_StockReconEmployees c
        WHERE c.SSBranchId = @BranchId AND c.EmployeeCode = @EmployeeCode AND @Visible = 1
    ) s
    OUTER APPLY (SELECT TOP 1 x.AreaDescription FROM [PumpIT].dbo.STK_Area x
                 WHERE x.SSBranchId = @BranchId AND x.AreaNo = s.AreaNo) ar
    ORDER BY s.TransactionDate DESC, s.ShiftNo DESC, s.Kind;

    SELECT s.Kind, COUNT(*) AS Shifts, MIN(s.TransactionDate) AS FirstDay, MAX(s.TransactionDate) AS LastDay
    FROM (
        SELECT N'Cashup' AS Kind, CONVERT(date, d.TransactionDate) AS TransactionDate
        FROM [PumpIT].dbo.BRN_DailyBanking d
        WHERE d.SSBranchId = @BranchId AND d.EmployeeCode = @EmployeeCode AND @Visible = 1
        UNION ALL
        SELECT N'Stock count', CONVERT(date, c.TransactionDate)
        FROM [PumpIT].dbo.STK_StockReconEmployees c
        WHERE c.SSBranchId = @BranchId AND c.EmployeeCode = @EmployeeCode AND @Visible = 1
    ) s
    GROUP BY s.Kind
    ORDER BY s.Kind;
END
