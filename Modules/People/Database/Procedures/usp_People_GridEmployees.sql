/* Setup → People and assets → Employees: the employee master listing (T023).

   One row per employee in PumpIT's BRN_Employee (agora.vw_PeopleEmployee),
   with the branch's name, the post's description and the counting areas the
   person may be signed on to. Read-only: nothing here writes anything.

   @Status picks which employees: 'active' (the default — the people a site
   trades with today), 'inactive' (terminated; PumpIT has only the IsActive
   flag) or 'all'. An inactive employee is never removed from the view, so the
   cashups and counts they worked still resolve their name — this parameter
   only decides what the LIST opens on.

   @IncludeSystem = 1 adds the PUMPIT / ADMIN system accounts, which PumpIT's
   own sign-on lists leave out (sp_CaptureAreaEmployee).

   @BranchIds is the grid's branches selector, already narrowed to the
   caller's granted sites by App\Grid\GridService; NULL means every branch.
   The post filter on the screen arrives as a header filter on PostDescription.

   The same filtered set drives the page, the total and the CSV extract.
   Read by Modules/People/Grids/EmployeeGrid. Deployed by v1__03p. */
CREATE OR ALTER PROCEDURE agora.usp_People_GridEmployees
    @BranchIds NVARCHAR(MAX) = NULL,
    @DateFrom DATE = NULL,
    @DateTo DATE = NULL,
    @Search NVARCHAR(200) = NULL,
    @SortColumn NVARCHAR(80) = NULL,
    @SortAsc BIT = 1,
    @Page INT = 1,
    @PageSize INT = 50,
    @FiltersJson NVARCHAR(MAX) = NULL,
    @Status NVARCHAR(12) = N'active',
    @IncludeSystem BIT = 0
AS
BEGIN
    SET NOCOUNT ON;
    SET @Page = CASE WHEN ISNULL(@Page, 0) < 1 THEN 1 ELSE @Page END;
    SET @PageSize = CASE WHEN ISNULL(@PageSize, 0) BETWEEN 1 AND 100000 THEN @PageSize ELSE 50 END;
    SET @Search = NULLIF(LTRIM(RTRIM(@Search)), '');
    SET @Status = CASE WHEN LOWER(@Status) IN (N'active', N'inactive', N'all') THEN LOWER(@Status) ELSE N'active' END;
    DECLARE @Offset BIGINT = CONVERT(BIGINT, @Page - 1) * @PageSize;

    DECLARE @Branches TABLE (BranchId INT PRIMARY KEY);
    INSERT INTO @Branches
    SELECT DISTINCT TRY_CONVERT(INT, value) FROM STRING_SPLIT(@BranchIds, ',')
    WHERE TRY_CONVERT(INT, value) IS NOT NULL;
    DECLARE @AllBranches BIT = CASE WHEN NULLIF(LTRIM(RTRIM(@BranchIds)), '') IS NULL THEN 1 ELSE 0 END;

    DECLARE @Filters TABLE ([Column] NVARCHAR(80), Op NVARCHAR(20), Value NVARCHAR(400), SetJson NVARCHAR(MAX));
    INSERT INTO @Filters
    SELECT JSON_VALUE(f.value, '$.column'), ISNULL(JSON_VALUE(f.value, '$.op'), 'contains'),
           JSON_VALUE(f.value, '$.value'), JSON_QUERY(f.value, '$.in')
    FROM OPENJSON(@FiltersJson) f;

    SELECT e.BranchId,
           b.Name AS BranchName,
           e.EmployeeCode,
           e.EmployeeName,
           e.Title,
           e.Initials,
           e.FirstName,
           e.Surname,
           e.NickName,
           e.PostNo,
           e.PostDescription,
           e.Status,
           e.IsActive,
           e.IsPumpAttendant,
           e.IsSystemAccount,
           ISNULL(a.AreaCount, 0) AS AreaCount,
           a.Areas,
           e.DateJoined,
           e.DateDepart,
           e.CashDeviceCode
    INTO #Employees
    FROM agora.vw_PeopleEmployee e
    LEFT JOIN agora.Branch b ON b.BranchId = e.BranchId
    OUTER APPLY (
        SELECT COUNT(*) AS AreaCount,
               LEFT(STRING_AGG(CONVERT(NVARCHAR(MAX), ISNULL(x.AreaDescription, N'Area ' + CONVERT(NVARCHAR(12), x.AreaNo))), N', ')
                    WITHIN GROUP (ORDER BY x.AreaNo), 400) AS Areas
        FROM agora.vw_PeopleEmployeeArea x
        WHERE x.BranchId = e.BranchId AND x.EmployeeCode = e.EmployeeCode
    ) a
    WHERE (@AllBranches = 1 OR e.BranchId IN (SELECT BranchId FROM @Branches))
      AND (@Status = N'all' OR (@Status = N'active' AND e.IsActive = 1) OR (@Status = N'inactive' AND e.IsActive = 0))
      AND (ISNULL(@IncludeSystem, 0) = 1 OR e.IsSystemAccount = 0)
      AND (@Search IS NULL OR e.EmployeeCode LIKE '%' + @Search + '%' OR e.EmployeeName LIKE '%' + @Search + '%'
           OR e.NickName LIKE '%' + @Search + '%' OR e.PostDescription LIKE '%' + @Search + '%'
           OR b.Name LIKE '%' + @Search + '%');

    SELECT x.* INTO #Filtered FROM #Employees x
    WHERE NOT EXISTS (
        SELECT 1 FROM @Filters f
        CROSS APPLY (SELECT CASE f.[Column]
            WHEN 'BranchName' THEN x.BranchName WHEN 'EmployeeCode' THEN x.EmployeeCode
            WHEN 'EmployeeName' THEN x.EmployeeName WHEN 'Title' THEN x.Title WHEN 'Initials' THEN x.Initials
            WHEN 'FirstName' THEN x.FirstName WHEN 'Surname' THEN x.Surname WHEN 'NickName' THEN x.NickName
            WHEN 'PostDescription' THEN x.PostDescription WHEN 'Areas' THEN x.Areas
            WHEN 'CashDeviceCode' THEN x.CashDeviceCode WHEN 'Status' THEN x.Status
            WHEN 'IsPumpAttendant' THEN CASE WHEN x.IsPumpAttendant = 1 THEN N'Yes' ELSE N'No' END END AS TextValue,
            CASE f.[Column] WHEN 'PostNo' THEN x.PostNo WHEN 'AreaCount' THEN x.AreaCount END AS NumberValue,
            CASE f.[Column] WHEN 'DateJoined' THEN x.DateJoined WHEN 'DateDepart' THEN x.DateDepart END AS DateValue) v
        WHERE (f.[Column] IN ('BranchName', 'EmployeeCode', 'EmployeeName', 'Title', 'Initials', 'FirstName', 'Surname',
                              'NickName', 'PostDescription', 'Areas', 'CashDeviceCode') AND f.SetJson IS NULL AND
              CASE WHEN f.Op = 'contains' AND v.TextValue LIKE '%' + f.Value + '%' THEN 1
                   WHEN f.Op = 'eq' AND v.TextValue = f.Value THEN 1
                   WHEN f.Op = 'ne' AND ISNULL(v.TextValue, N'') <> f.Value THEN 1
                   WHEN f.Op = 'gt' AND v.TextValue > f.Value THEN 1
                   WHEN f.Op = 'gte' AND v.TextValue >= f.Value THEN 1
                   WHEN f.Op = 'lt' AND v.TextValue < f.Value THEN 1
                   WHEN f.Op = 'lte' AND v.TextValue <= f.Value THEN 1 ELSE 0 END = 0)
           OR (f.SetJson IS NOT NULL AND f.[Column] IN ('BranchName', 'PostDescription', 'Status', 'IsPumpAttendant')
               AND NOT EXISTS (SELECT 1 FROM OPENJSON(f.SetJson) s WHERE s.value = v.TextValue))
           OR (f.[Column] IN ('PostNo', 'AreaCount') AND TRY_CONVERT(DECIMAL(18, 4), f.Value) IS NOT NULL AND
              CASE WHEN f.Op IN ('=', 'eq') AND v.NumberValue = TRY_CONVERT(DECIMAL(18, 4), f.Value) THEN 1
                   WHEN f.Op = 'ne' AND v.NumberValue <> TRY_CONVERT(DECIMAL(18, 4), f.Value) THEN 1
                   WHEN f.Op = 'gt' AND v.NumberValue > TRY_CONVERT(DECIMAL(18, 4), f.Value) THEN 1
                   WHEN f.Op = 'gte' AND v.NumberValue >= TRY_CONVERT(DECIMAL(18, 4), f.Value) THEN 1
                   WHEN f.Op = 'lt' AND v.NumberValue < TRY_CONVERT(DECIMAL(18, 4), f.Value) THEN 1
                   WHEN f.Op = 'lte' AND v.NumberValue <= TRY_CONVERT(DECIMAL(18, 4), f.Value) THEN 1 ELSE 0 END = 0)
           OR (f.[Column] IN ('DateJoined', 'DateDepart') AND TRY_CONVERT(DATE, f.Value) IS NOT NULL AND
              CASE WHEN f.Op IN ('=', 'eq') AND v.DateValue = TRY_CONVERT(DATE, f.Value) THEN 1
                   WHEN f.Op = 'ne' AND v.DateValue <> TRY_CONVERT(DATE, f.Value) THEN 1
                   WHEN f.Op = 'gt' AND v.DateValue > TRY_CONVERT(DATE, f.Value) THEN 1
                   WHEN f.Op = 'gte' AND v.DateValue >= TRY_CONVERT(DATE, f.Value) THEN 1
                   WHEN f.Op = 'lt' AND v.DateValue < TRY_CONVERT(DATE, f.Value) THEN 1
                   WHEN f.Op = 'lte' AND v.DateValue <= TRY_CONVERT(DATE, f.Value) THEN 1 ELSE 0 END = 0)
    );

    SELECT * FROM #Filtered
    ORDER BY
        CASE WHEN @SortAsc = 1 THEN CASE @SortColumn WHEN 'BranchName' THEN BranchName WHEN 'EmployeeCode' THEN EmployeeCode
            WHEN 'EmployeeName' THEN EmployeeName WHEN 'NickName' THEN NickName WHEN 'PostDescription' THEN PostDescription
            WHEN 'Status' THEN Status WHEN 'Surname' THEN Surname WHEN 'FirstName' THEN FirstName END END ASC,
        CASE WHEN @SortAsc = 0 THEN CASE @SortColumn WHEN 'BranchName' THEN BranchName WHEN 'EmployeeCode' THEN EmployeeCode
            WHEN 'EmployeeName' THEN EmployeeName WHEN 'NickName' THEN NickName WHEN 'PostDescription' THEN PostDescription
            WHEN 'Status' THEN Status WHEN 'Surname' THEN Surname WHEN 'FirstName' THEN FirstName END END DESC,
        CASE WHEN @SortAsc = 1 THEN CASE @SortColumn WHEN 'PostNo' THEN PostNo WHEN 'AreaCount' THEN AreaCount END END ASC,
        CASE WHEN @SortAsc = 0 THEN CASE @SortColumn WHEN 'PostNo' THEN PostNo WHEN 'AreaCount' THEN AreaCount END END DESC,
        CASE WHEN @SortAsc = 1 THEN CASE @SortColumn WHEN 'DateJoined' THEN DateJoined WHEN 'DateDepart' THEN DateDepart END END ASC,
        CASE WHEN @SortAsc = 0 THEN CASE @SortColumn WHEN 'DateJoined' THEN DateJoined WHEN 'DateDepart' THEN DateDepart END END DESC,
        BranchName, EmployeeName, EmployeeCode
    OFFSET @Offset ROWS FETCH NEXT @PageSize ROWS ONLY;

    SELECT COUNT_BIG(*) AS TotalRows FROM #Filtered;
END
