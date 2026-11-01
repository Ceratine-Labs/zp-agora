/* Counting areas for head office: effective prepared-food values and their
   confirmation status. Read-only; never materialises or confirms proposals.
   Same filtered set drives the grid, total and CSV. Deployed by v1__14px. */
CREATE OR ALTER PROCEDURE agora.usp_StockRecon_GridCountingAreas
    @BranchIds NVARCHAR(MAX) = NULL,
    @DateFrom DATE = NULL,
    @DateTo DATE = NULL,
    @Search NVARCHAR(200) = NULL,
    @SortColumn NVARCHAR(80) = NULL,
    @SortAsc BIT = 1,
    @Page INT = 1,
    @PageSize INT = 50,
    @FiltersJson NVARCHAR(MAX) = NULL,
    @UserId INT = NULL,
    @Authorized BIT = 0,
    @Workspace NVARCHAR(10) = N'branch'
AS
BEGIN
    SET NOCOUNT ON;
    EXEC agora.usp_StockRecon_AuthorizeCountingArea NULL, @UserId, @Authorized, @Workspace;
    SET @Page = CASE WHEN ISNULL(@Page, 0) < 1 THEN 1 ELSE @Page END;
    SET @PageSize = CASE WHEN ISNULL(@PageSize, 0) BETWEEN 1 AND 100000 THEN @PageSize ELSE 50 END;
    DECLARE @Offset BIGINT = CONVERT(bigint, @Page - 1) * @PageSize;
    DECLARE @Filters TABLE ([Column] nvarchar(80), Op nvarchar(20), Value nvarchar(400), SetJson nvarchar(max));
    INSERT INTO @Filters
    SELECT JSON_VALUE(f.value, '$.column'), JSON_VALUE(f.value, '$.op'), JSON_VALUE(f.value, '$.value'), JSON_QUERY(f.value, '$.in')
    FROM OPENJSON(@FiltersJson) f;

    SELECT f.BranchId, COALESCE(b.Name, CONVERT(nvarchar(20), f.BranchId)) AS BranchName,
           f.AreaNo, f.AreaDescription, f.AreaGroup, f.IsPreparedFood,
           CASE WHEN f.IsConfirmed = 1 THEN N'Confirmed' ELSE N'Proposed' END AS State,
           f.FoodBasis, f.ConfirmedAt, u.UserName AS ConfirmedByName
    INTO #Areas
    FROM agora.vw_StockAreaFood f
    LEFT JOIN agora.Branch b ON b.BranchId = f.BranchId
    LEFT JOIN agora.[User] u ON u.Id = f.ConfirmedBy
    WHERE (NULLIF(LTRIM(RTRIM(@BranchIds)), '') IS NULL OR f.BranchId IN (
               SELECT TRY_CONVERT(int, value) FROM STRING_SPLIT(@BranchIds, ',') WHERE LTRIM(RTRIM(value)) <> ''))
      AND (NOT EXISTS (SELECT 1 FROM agora.UserBranch WHERE UserId = @UserId)
           OR EXISTS (SELECT 1 FROM agora.UserBranch WHERE UserId = @UserId AND BranchId = f.BranchId))
      AND (@Search IS NULL OR f.AreaDescription LIKE '%' + @Search + '%' OR f.AreaGroup LIKE '%' + @Search + '%'
           OR b.Name LIKE '%' + @Search + '%');

    SELECT a.* INTO #Filtered FROM #Areas a
    WHERE NOT EXISTS (
        SELECT 1 FROM @Filters f
        CROSS APPLY (SELECT CASE f.[Column]
            WHEN 'AreaDescription' THEN a.AreaDescription WHEN 'BranchName' THEN a.BranchName
            WHEN 'AreaGroup' THEN a.AreaGroup WHEN 'FoodBasis' THEN a.FoodBasis
            WHEN 'ConfirmedByName' THEN a.ConfirmedByName WHEN 'State' THEN a.State
            WHEN 'IsPreparedFood' THEN CASE WHEN a.IsPreparedFood = 1 THEN N'Yes' ELSE N'No' END END AS TextValue) v
        WHERE (f.[Column] IN ('AreaDescription', 'BranchName', 'AreaGroup', 'FoodBasis', 'ConfirmedByName') AND
              CASE WHEN f.Op = 'contains' AND v.TextValue LIKE '%' + f.Value + '%' THEN 1
                   WHEN f.Op = 'eq' AND v.TextValue = f.Value THEN 1
                   WHEN f.Op = 'ne' AND v.TextValue <> f.Value THEN 1
                   WHEN f.Op = 'gt' AND v.TextValue > f.Value THEN 1
                   WHEN f.Op = 'gte' AND v.TextValue >= f.Value THEN 1
                   WHEN f.Op = 'lt' AND v.TextValue < f.Value THEN 1
                   WHEN f.Op = 'lte' AND v.TextValue <= f.Value THEN 1 ELSE 0 END = 0)
           OR (f.[Column] IN ('State', 'IsPreparedFood') AND NOT EXISTS (SELECT 1 FROM OPENJSON(f.SetJson) s WHERE s.value = v.TextValue))
           OR (f.[Column] = 'AreaNo' AND
              CASE WHEN f.Op = 'eq' AND a.AreaNo = TRY_CONVERT(int, f.Value) THEN 1
                   WHEN f.Op = 'ne' AND a.AreaNo <> TRY_CONVERT(int, f.Value) THEN 1
                   WHEN f.Op = 'gt' AND a.AreaNo > TRY_CONVERT(int, f.Value) THEN 1
                   WHEN f.Op = 'gte' AND a.AreaNo >= TRY_CONVERT(int, f.Value) THEN 1
                   WHEN f.Op = 'lt' AND a.AreaNo < TRY_CONVERT(int, f.Value) THEN 1
                   WHEN f.Op = 'lte' AND a.AreaNo <= TRY_CONVERT(int, f.Value) THEN 1 ELSE 0 END = 0)
           OR (f.[Column] = 'ConfirmedAt' AND
              CASE WHEN f.Op = 'eq' AND CONVERT(date, DATEADD(hour, 2, a.ConfirmedAt)) = TRY_CONVERT(date, f.Value) THEN 1
                   WHEN f.Op = 'ne' AND CONVERT(date, DATEADD(hour, 2, a.ConfirmedAt)) <> TRY_CONVERT(date, f.Value) THEN 1
                   WHEN f.Op = 'gt' AND CONVERT(date, DATEADD(hour, 2, a.ConfirmedAt)) > TRY_CONVERT(date, f.Value) THEN 1
                   WHEN f.Op = 'gte' AND CONVERT(date, DATEADD(hour, 2, a.ConfirmedAt)) >= TRY_CONVERT(date, f.Value) THEN 1
                   WHEN f.Op = 'lt' AND CONVERT(date, DATEADD(hour, 2, a.ConfirmedAt)) < TRY_CONVERT(date, f.Value) THEN 1
                   WHEN f.Op = 'lte' AND CONVERT(date, DATEADD(hour, 2, a.ConfirmedAt)) <= TRY_CONVERT(date, f.Value) THEN 1 ELSE 0 END = 0)
    );
    SELECT * FROM #Filtered
    ORDER BY
        CASE WHEN @SortAsc = 1 THEN CASE ISNULL(@SortColumn, 'AreaDescription') WHEN 'AreaDescription' THEN AreaDescription WHEN 'BranchName' THEN BranchName WHEN 'AreaGroup' THEN AreaGroup END END ASC,
        CASE WHEN @SortAsc = 0 THEN CASE ISNULL(@SortColumn, 'AreaDescription') WHEN 'AreaDescription' THEN AreaDescription WHEN 'BranchName' THEN BranchName WHEN 'AreaGroup' THEN AreaGroup END END DESC,
        CASE WHEN @SortAsc = 1 AND @SortColumn = 'AreaNo' THEN AreaNo END ASC,
        CASE WHEN @SortAsc = 0 AND @SortColumn = 'AreaNo' THEN AreaNo END DESC,
        BranchId, AreaNo
    OFFSET @Offset ROWS FETCH NEXT @PageSize ROWS ONLY;
    SELECT COUNT_BIG(*) AS TotalRows FROM #Filtered;
END
