/* Setup → People and assets → Employee areas (T023).

   One row per BRN_EmployeeArea row (agora.vw_PeopleEmployeeArea): which
   counting area a person may be signed on to at a site, with the area's
   description and the person's name and status. Read-only.

   PumpIT's sign-on screens offer only the ACTIVE people of an area
   (sp_SelectAreaEmployee, sp_CaptureAreaEmployee). This list keeps the
   inactive ones and says so, because the row still exists and still decides
   what PumpIT would offer if the person were made active again. A row whose
   code is on no employee (23 on 12 Nov 2026) is kept too, marked "Not on the
   employee list" — a finding, not a blank.

   @Status: 'active' (default), 'inactive' or 'all', on the person's status;
   a row with no employee counts as inactive.
   @BranchIds is the grid's branches selector, already narrowed to the
   caller's granted sites; NULL means every branch.
   Read by Modules/People/Grids/EmployeeAreaGrid. Deployed by v1__03p. */
CREATE OR ALTER PROCEDURE agora.usp_People_GridEmployeeAreas
    @BranchIds NVARCHAR(MAX) = NULL,
    @DateFrom DATE = NULL,
    @DateTo DATE = NULL,
    @Search NVARCHAR(200) = NULL,
    @SortColumn NVARCHAR(80) = NULL,
    @SortAsc BIT = 1,
    @Page INT = 1,
    @PageSize INT = 50,
    @FiltersJson NVARCHAR(MAX) = NULL,
    @Status NVARCHAR(12) = N'active'
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

    SELECT a.BranchId,
           b.Name AS BranchName,
           a.EmployeeAreaId,
           a.AreaNo,
           ISNULL(a.AreaDescription, N'Area ' + CONVERT(NVARCHAR(12), a.AreaNo)) AS AreaDescription,
           a.AreaGroup,
           a.EmployeeCode,
           ISNULL(e.EmployeeName, a.EmployeeCode) AS EmployeeName,
           e.NickName,
           e.PostDescription,
           CONVERT(VARCHAR(30), CASE WHEN e.EmployeeCode IS NULL THEN 'Not on the employee list' ELSE e.Status END) AS Status,
           CONVERT(BIT, CASE WHEN e.EmployeeCode IS NULL THEN 0 ELSE 1 END) AS OnEmployeeList
    INTO #Areas
    FROM agora.vw_PeopleEmployeeArea a
    LEFT JOIN agora.Branch b ON b.BranchId = a.BranchId
    LEFT JOIN agora.vw_PeopleEmployee e ON e.BranchId = a.BranchId AND e.EmployeeCode = a.EmployeeCode
    WHERE (@AllBranches = 1 OR a.BranchId IN (SELECT BranchId FROM @Branches))
      AND (@Status = N'all' OR (@Status = N'active' AND e.IsActive = 1)
           OR (@Status = N'inactive' AND ISNULL(e.IsActive, 0) = 0))
      AND (@Search IS NULL OR a.EmployeeCode LIKE '%' + @Search + '%' OR e.EmployeeName LIKE '%' + @Search + '%'
           OR a.AreaDescription LIKE '%' + @Search + '%' OR b.Name LIKE '%' + @Search + '%');

    SELECT x.* INTO #Filtered FROM #Areas x
    WHERE NOT EXISTS (
        SELECT 1 FROM @Filters f
        CROSS APPLY (SELECT CASE f.[Column]
            WHEN 'BranchName' THEN x.BranchName WHEN 'AreaDescription' THEN x.AreaDescription
            WHEN 'AreaGroup' THEN x.AreaGroup WHEN 'EmployeeCode' THEN x.EmployeeCode
            WHEN 'EmployeeName' THEN x.EmployeeName WHEN 'NickName' THEN x.NickName
            WHEN 'PostDescription' THEN x.PostDescription WHEN 'Status' THEN x.Status END AS TextValue,
            CASE f.[Column] WHEN 'AreaNo' THEN x.AreaNo END AS NumberValue) v
        WHERE (f.[Column] IN ('BranchName', 'AreaDescription', 'AreaGroup', 'EmployeeCode', 'EmployeeName', 'NickName',
                              'PostDescription') AND f.SetJson IS NULL AND
              CASE WHEN f.Op = 'contains' AND v.TextValue LIKE '%' + f.Value + '%' THEN 1
                   WHEN f.Op = 'eq' AND v.TextValue = f.Value THEN 1
                   WHEN f.Op = 'ne' AND ISNULL(v.TextValue, N'') <> f.Value THEN 1
                   WHEN f.Op = 'gt' AND v.TextValue > f.Value THEN 1
                   WHEN f.Op = 'gte' AND v.TextValue >= f.Value THEN 1
                   WHEN f.Op = 'lt' AND v.TextValue < f.Value THEN 1
                   WHEN f.Op = 'lte' AND v.TextValue <= f.Value THEN 1 ELSE 0 END = 0)
           OR (f.SetJson IS NOT NULL AND f.[Column] IN ('BranchName', 'AreaDescription', 'AreaGroup', 'PostDescription', 'Status')
               AND NOT EXISTS (SELECT 1 FROM OPENJSON(f.SetJson) s WHERE s.value = v.TextValue))
           OR (f.[Column] = 'AreaNo' AND TRY_CONVERT(DECIMAL(18, 4), f.Value) IS NOT NULL AND
              CASE WHEN f.Op IN ('=', 'eq') AND v.NumberValue = TRY_CONVERT(DECIMAL(18, 4), f.Value) THEN 1
                   WHEN f.Op = 'ne' AND v.NumberValue <> TRY_CONVERT(DECIMAL(18, 4), f.Value) THEN 1
                   WHEN f.Op = 'gt' AND v.NumberValue > TRY_CONVERT(DECIMAL(18, 4), f.Value) THEN 1
                   WHEN f.Op = 'gte' AND v.NumberValue >= TRY_CONVERT(DECIMAL(18, 4), f.Value) THEN 1
                   WHEN f.Op = 'lt' AND v.NumberValue < TRY_CONVERT(DECIMAL(18, 4), f.Value) THEN 1
                   WHEN f.Op = 'lte' AND v.NumberValue <= TRY_CONVERT(DECIMAL(18, 4), f.Value) THEN 1 ELSE 0 END = 0)
    );

    SELECT * FROM #Filtered
    ORDER BY
        CASE WHEN @SortAsc = 1 THEN CASE @SortColumn WHEN 'BranchName' THEN BranchName WHEN 'AreaDescription' THEN AreaDescription
            WHEN 'EmployeeName' THEN EmployeeName WHEN 'EmployeeCode' THEN EmployeeCode WHEN 'Status' THEN Status
            WHEN 'PostDescription' THEN PostDescription END END ASC,
        CASE WHEN @SortAsc = 0 THEN CASE @SortColumn WHEN 'BranchName' THEN BranchName WHEN 'AreaDescription' THEN AreaDescription
            WHEN 'EmployeeName' THEN EmployeeName WHEN 'EmployeeCode' THEN EmployeeCode WHEN 'Status' THEN Status
            WHEN 'PostDescription' THEN PostDescription END END DESC,
        CASE WHEN @SortAsc = 1 AND @SortColumn = 'AreaNo' THEN AreaNo END ASC,
        CASE WHEN @SortAsc = 0 AND @SortColumn = 'AreaNo' THEN AreaNo END DESC,
        BranchName, AreaNo, EmployeeName
    OFFSET @Offset ROWS FETCH NEXT @PageSize ROWS ONLY;

    SELECT COUNT_BIG(*) AS TotalRows FROM #Filtered;
END
