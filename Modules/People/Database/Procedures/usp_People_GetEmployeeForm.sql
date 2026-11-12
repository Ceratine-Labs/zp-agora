/* What the employee edit screen needs (T023). Read-only.

   Result sets, in order:
     1. the employee as PumpIT holds it now — IsActive, PostNo and NickName
        are what the form hands back as @Expected*, so a save over somebody
        else's change is refused (STALE). Empty when there is no such employee
        or the site is not one of @AllowedBranchIds.
     2. the posts at the employee's branch (BRN_EmployeePost)
     3. every counting area at the branch (STK_Area), with Assigned = 1 for
        the ones the person may be signed on to now (BRN_EmployeeArea)

   Read by Modules/People/Services/EmployeeWriteService. Deployed by v1__03pa. */
CREATE OR ALTER PROCEDURE agora.usp_People_GetEmployeeForm
    @BranchId INT,
    @EmployeeCode NVARCHAR(11),
    @AllowedBranchIds NVARCHAR(MAX) = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET @EmployeeCode = LTRIM(RTRIM(@EmployeeCode));

    DECLARE @Visible BIT = CASE
        WHEN NULLIF(LTRIM(RTRIM(@AllowedBranchIds)), '') IS NULL THEN 1
        WHEN EXISTS (SELECT 1 FROM STRING_SPLIT(@AllowedBranchIds, ',') WHERE TRY_CONVERT(INT, value) = @BranchId) THEN 1
        ELSE 0 END;

    SELECT e.BranchId, b.Name AS BranchName, e.EmployeeCode, e.EmployeeName, e.FirstName, e.Surname,
           e.IsActive, e.PostNo, e.PostDescription, e.NickName, e.IsSystemAccount
    FROM agora.vw_PeopleEmployee e
    LEFT JOIN agora.Branch b ON b.BranchId = e.BranchId
    WHERE e.BranchId = @BranchId AND e.EmployeeCode = @EmployeeCode AND @Visible = 1;

    SELECT p.PostNo, p.PostDescription, p.StockControl
    FROM agora.vw_PeoplePost p
    WHERE p.BranchId = @BranchId AND @Visible = 1
    ORDER BY p.PostDescription, p.PostNo;

    SELECT s.AreaNo, s.AreaDescription, s.AreaGroup,
           CONVERT(BIT, CASE WHEN EXISTS (SELECT 1 FROM agora.vw_PeopleEmployeeArea a
                                          WHERE a.BranchId = s.SSBranchId AND a.AreaNo = s.AreaNo
                                            AND a.EmployeeCode = @EmployeeCode) THEN 1 ELSE 0 END) AS Assigned
    FROM [PumpIT].dbo.STK_Area s
    WHERE s.SSBranchId = @BranchId AND @Visible = 1
    ORDER BY s.AreaNo;
END
