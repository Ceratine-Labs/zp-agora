/* Setup → People and assets → Employee posts (T023).

   One row per post per branch in PumpIT's BRN_EmployeePost
   (agora.vw_PeoplePost), with how many employees hold it — active and in
   total. Read-only.

   Posts are numbered PER BRANCH: "Site Manager" is post 46, 47 or 48
   depending on the site, so a post is (BranchId, PostNo) and is never looked
   up on its number alone. StockControl is PumpIT's flag for the posts whose
   holders carry stock losses.

   @BranchIds is the grid's branches selector, already narrowed to the
   caller's granted sites; NULL means every branch.
   Read by Modules/People/Grids/EmployeePostGrid. Deployed by v1__03p. */
CREATE OR ALTER PROCEDURE agora.usp_People_GridPosts
    @BranchIds NVARCHAR(MAX) = NULL,
    @DateFrom DATE = NULL,
    @DateTo DATE = NULL,
    @Search NVARCHAR(200) = NULL,
    @SortColumn NVARCHAR(80) = NULL,
    @SortAsc BIT = 1,
    @Page INT = 1,
    @PageSize INT = 50,
    @FiltersJson NVARCHAR(MAX) = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET @Page = CASE WHEN ISNULL(@Page, 0) < 1 THEN 1 ELSE @Page END;
    SET @PageSize = CASE WHEN ISNULL(@PageSize, 0) BETWEEN 1 AND 100000 THEN @PageSize ELSE 50 END;
    SET @Search = NULLIF(LTRIM(RTRIM(@Search)), '');
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

    SELECT p.BranchId,
           b.Name AS BranchName,
           p.PostNo,
           p.PostDescription,
           p.StockControl,
           ISNULL(c.Active, 0) AS ActiveEmployees,
           ISNULL(c.Total, 0) AS TotalEmployees
    INTO #Posts
    FROM agora.vw_PeoplePost p
    LEFT JOIN agora.Branch b ON b.BranchId = p.BranchId
    OUTER APPLY (
        SELECT SUM(CASE WHEN e.IsActive = 1 THEN 1 ELSE 0 END) AS Active, COUNT(*) AS Total
        FROM agora.vw_PeopleEmployee e
        WHERE e.BranchId = p.BranchId AND e.PostNo = p.PostNo AND e.IsSystemAccount = 0
    ) c
    WHERE (@AllBranches = 1 OR p.BranchId IN (SELECT BranchId FROM @Branches))
      AND (@Search IS NULL OR p.PostDescription LIKE '%' + @Search + '%' OR b.Name LIKE '%' + @Search + '%'
           OR CONVERT(NVARCHAR(12), p.PostNo) = @Search);

    SELECT x.* INTO #Filtered FROM #Posts x
    WHERE NOT EXISTS (
        SELECT 1 FROM @Filters f
        CROSS APPLY (SELECT CASE f.[Column]
            WHEN 'BranchName' THEN x.BranchName WHEN 'PostDescription' THEN x.PostDescription
            WHEN 'StockControl' THEN CASE WHEN x.StockControl = 1 THEN N'Yes' ELSE N'No' END END AS TextValue,
            CASE f.[Column] WHEN 'PostNo' THEN x.PostNo WHEN 'ActiveEmployees' THEN x.ActiveEmployees
                WHEN 'TotalEmployees' THEN x.TotalEmployees END AS NumberValue) v
        WHERE (f.[Column] IN ('BranchName', 'PostDescription') AND f.SetJson IS NULL AND
              CASE WHEN f.Op = 'contains' AND v.TextValue LIKE '%' + f.Value + '%' THEN 1
                   WHEN f.Op = 'eq' AND v.TextValue = f.Value THEN 1
                   WHEN f.Op = 'ne' AND ISNULL(v.TextValue, N'') <> f.Value THEN 1
                   WHEN f.Op = 'gt' AND v.TextValue > f.Value THEN 1
                   WHEN f.Op = 'gte' AND v.TextValue >= f.Value THEN 1
                   WHEN f.Op = 'lt' AND v.TextValue < f.Value THEN 1
                   WHEN f.Op = 'lte' AND v.TextValue <= f.Value THEN 1 ELSE 0 END = 0)
           OR (f.SetJson IS NOT NULL AND f.[Column] IN ('BranchName', 'PostDescription', 'StockControl')
               AND NOT EXISTS (SELECT 1 FROM OPENJSON(f.SetJson) s WHERE s.value = v.TextValue))
           OR (f.[Column] IN ('PostNo', 'ActiveEmployees', 'TotalEmployees') AND TRY_CONVERT(DECIMAL(18, 4), f.Value) IS NOT NULL AND
              CASE WHEN f.Op IN ('=', 'eq') AND v.NumberValue = TRY_CONVERT(DECIMAL(18, 4), f.Value) THEN 1
                   WHEN f.Op = 'ne' AND v.NumberValue <> TRY_CONVERT(DECIMAL(18, 4), f.Value) THEN 1
                   WHEN f.Op = 'gt' AND v.NumberValue > TRY_CONVERT(DECIMAL(18, 4), f.Value) THEN 1
                   WHEN f.Op = 'gte' AND v.NumberValue >= TRY_CONVERT(DECIMAL(18, 4), f.Value) THEN 1
                   WHEN f.Op = 'lt' AND v.NumberValue < TRY_CONVERT(DECIMAL(18, 4), f.Value) THEN 1
                   WHEN f.Op = 'lte' AND v.NumberValue <= TRY_CONVERT(DECIMAL(18, 4), f.Value) THEN 1 ELSE 0 END = 0)
    );

    SELECT * FROM #Filtered
    ORDER BY
        CASE WHEN @SortAsc = 1 THEN CASE @SortColumn WHEN 'BranchName' THEN BranchName WHEN 'PostDescription' THEN PostDescription END END ASC,
        CASE WHEN @SortAsc = 0 THEN CASE @SortColumn WHEN 'BranchName' THEN BranchName WHEN 'PostDescription' THEN PostDescription END END DESC,
        CASE WHEN @SortAsc = 1 THEN CASE @SortColumn WHEN 'PostNo' THEN PostNo WHEN 'ActiveEmployees' THEN ActiveEmployees
            WHEN 'TotalEmployees' THEN TotalEmployees END END ASC,
        CASE WHEN @SortAsc = 0 THEN CASE @SortColumn WHEN 'PostNo' THEN PostNo WHEN 'ActiveEmployees' THEN ActiveEmployees
            WHEN 'TotalEmployees' THEN TotalEmployees END END DESC,
        BranchName, PostNo
    OFFSET @Offset ROWS FETCH NEXT @PageSize ROWS ONLY;

    SELECT COUNT_BIG(*) AS TotalRows FROM #Filtered;
END
