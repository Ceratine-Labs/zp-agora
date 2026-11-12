/* Setup → The business → Branches: the branch master listing (T021).

   One row per branch in agora.Branch with its region, brand, format, status,
   POS type and profit centres. Read-only.

   The six administrative entities (IsAdminEntity = 1) are left out unless
   @IncludeAdmin = 1 — they are branches for accounting, not sites anyone
   trades, and the listing's first job is the sites. A person granted specific
   sites (agora.UserBranch) sees only those; no grant means every branch.

   The same filtered set drives the page, the total and the CSV extract.
   Read by Modules/Masters/Grids/BranchMasterGrid. Deployed by v1__02p. */
CREATE OR ALTER PROCEDURE agora.usp_Masters_GridBranches
    @BranchIds NVARCHAR(MAX) = NULL,
    @DateFrom DATE = NULL,
    @DateTo DATE = NULL,
    @Search NVARCHAR(200) = NULL,
    @SortColumn NVARCHAR(80) = NULL,
    @SortAsc BIT = 1,
    @Page INT = 1,
    @PageSize INT = 50,
    @FiltersJson NVARCHAR(MAX) = NULL,
    @IncludeAdmin BIT = 0,
    @UserId INT = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET @Page = CASE WHEN ISNULL(@Page, 0) < 1 THEN 1 ELSE @Page END;
    SET @PageSize = CASE WHEN ISNULL(@PageSize, 0) BETWEEN 1 AND 100000 THEN @PageSize ELSE 50 END;
    SET @Search = NULLIF(LTRIM(RTRIM(@Search)), '');
    DECLARE @Offset BIGINT = CONVERT(BIGINT, @Page - 1) * @PageSize;

    DECLARE @Filters TABLE ([Column] NVARCHAR(80), Op NVARCHAR(20), Value NVARCHAR(400), SetJson NVARCHAR(MAX));
    INSERT INTO @Filters
    SELECT JSON_VALUE(f.value, '$.column'), ISNULL(JSON_VALUE(f.value, '$.op'), 'contains'),
           JSON_VALUE(f.value, '$.value'), JSON_QUERY(f.value, '$.in')
    FROM OPENJSON(@FiltersJson) f;

    SELECT b.BranchId,
           b.Name,
           r.Name  AS RegionName,
           br.Name AS BrandName,
           f.Name  AS FormatName,
           b.Status,
           b.POSType,
           b.IsAdminEntity,
           b.IsActive,
           (SELECT COUNT(*) FROM agora.BranchProfitCentre x WHERE x.BranchId = b.BranchId) AS ProfitCentreCount,
           (SELECT STRING_AGG(pc.Name, N', ') WITHIN GROUP (ORDER BY pc.Name)
            FROM agora.BranchProfitCentre x
            JOIN agora.vw_ProfitCentre pc ON pc.ProfitCentreId = x.ProfitCentreId
            WHERE x.BranchId = b.BranchId) AS ProfitCentres,
           b.LegacyName,
           b.UpdatedAt
    INTO #Branches
    FROM agora.Branch b
    LEFT JOIN agora.Region r   ON r.Id = b.RegionId
    LEFT JOIN agora.Brand br   ON br.Id = b.BrandId
    LEFT JOIN agora.[Format] f ON f.Id = b.FormatId
    WHERE b.DeletedAt IS NULL
      AND (ISNULL(@IncludeAdmin, 0) = 1 OR b.IsAdminEntity = 0)
      AND (NULLIF(LTRIM(RTRIM(@BranchIds)), '') IS NULL OR b.BranchId IN (
               SELECT TRY_CONVERT(INT, value) FROM STRING_SPLIT(@BranchIds, ',') WHERE LTRIM(RTRIM(value)) <> ''))
      AND (NOT EXISTS (SELECT 1 FROM agora.UserBranch WHERE UserId = @UserId)
           OR EXISTS (SELECT 1 FROM agora.UserBranch WHERE UserId = @UserId AND BranchId = b.BranchId))
      AND (@Search IS NULL OR b.Name LIKE '%' + @Search + '%' OR b.LegacyName LIKE '%' + @Search + '%'
           OR r.Name LIKE '%' + @Search + '%' OR br.Name LIKE '%' + @Search + '%' OR f.Name LIKE '%' + @Search + '%'
           OR CONVERT(NVARCHAR(12), b.BranchId) = @Search);

    SELECT x.* INTO #Filtered FROM #Branches x
    WHERE NOT EXISTS (
        SELECT 1 FROM @Filters f
        CROSS APPLY (SELECT CASE f.[Column]
            WHEN 'Name' THEN x.Name WHEN 'RegionName' THEN x.RegionName WHEN 'BrandName' THEN x.BrandName
            WHEN 'FormatName' THEN x.FormatName WHEN 'ProfitCentres' THEN x.ProfitCentres
            WHEN 'LegacyName' THEN x.LegacyName WHEN 'Status' THEN x.Status WHEN 'POSType' THEN x.POSType
            WHEN 'IsAdminEntity' THEN CASE WHEN x.IsAdminEntity = 1 THEN N'Yes' ELSE N'No' END
            WHEN 'IsActive' THEN CASE WHEN x.IsActive = 1 THEN N'Yes' ELSE N'No' END END AS TextValue,
            CASE f.[Column] WHEN 'BranchId' THEN x.BranchId WHEN 'ProfitCentreCount' THEN x.ProfitCentreCount END AS NumberValue) v
        WHERE (f.[Column] IN ('Name', 'RegionName', 'BrandName', 'FormatName', 'ProfitCentres', 'LegacyName') AND f.SetJson IS NULL AND
              CASE WHEN f.Op = 'contains' AND v.TextValue LIKE '%' + f.Value + '%' THEN 1
                   WHEN f.Op = 'eq' AND v.TextValue = f.Value THEN 1
                   WHEN f.Op = 'ne' AND ISNULL(v.TextValue, N'') <> f.Value THEN 1
                   WHEN f.Op = 'gt' AND v.TextValue > f.Value THEN 1
                   WHEN f.Op = 'gte' AND v.TextValue >= f.Value THEN 1
                   WHEN f.Op = 'lt' AND v.TextValue < f.Value THEN 1
                   WHEN f.Op = 'lte' AND v.TextValue <= f.Value THEN 1 ELSE 0 END = 0)
           OR (f.SetJson IS NOT NULL AND f.[Column] IN ('Name', 'RegionName', 'BrandName', 'FormatName', 'LegacyName',
                                                       'Status', 'POSType', 'IsAdminEntity', 'IsActive')
               AND NOT EXISTS (SELECT 1 FROM OPENJSON(f.SetJson) s WHERE s.value = v.TextValue))
           OR (f.[Column] IN ('BranchId', 'ProfitCentreCount') AND TRY_CONVERT(DECIMAL(18, 4), f.Value) IS NOT NULL AND
              CASE WHEN f.Op IN ('=', 'eq') AND v.NumberValue = TRY_CONVERT(DECIMAL(18, 4), f.Value) THEN 1
                   WHEN f.Op = 'ne' AND v.NumberValue <> TRY_CONVERT(DECIMAL(18, 4), f.Value) THEN 1
                   WHEN f.Op = 'gt' AND v.NumberValue > TRY_CONVERT(DECIMAL(18, 4), f.Value) THEN 1
                   WHEN f.Op = 'gte' AND v.NumberValue >= TRY_CONVERT(DECIMAL(18, 4), f.Value) THEN 1
                   WHEN f.Op = 'lt' AND v.NumberValue < TRY_CONVERT(DECIMAL(18, 4), f.Value) THEN 1
                   WHEN f.Op = 'lte' AND v.NumberValue <= TRY_CONVERT(DECIMAL(18, 4), f.Value) THEN 1 ELSE 0 END = 0)
    );

    SELECT * FROM #Filtered
    ORDER BY
        CASE WHEN @SortAsc = 1 THEN CASE @SortColumn WHEN 'Name' THEN Name WHEN 'RegionName' THEN RegionName
            WHEN 'BrandName' THEN BrandName WHEN 'FormatName' THEN FormatName WHEN 'Status' THEN Status
            WHEN 'POSType' THEN POSType WHEN 'LegacyName' THEN LegacyName END END ASC,
        CASE WHEN @SortAsc = 0 THEN CASE @SortColumn WHEN 'Name' THEN Name WHEN 'RegionName' THEN RegionName
            WHEN 'BrandName' THEN BrandName WHEN 'FormatName' THEN FormatName WHEN 'Status' THEN Status
            WHEN 'POSType' THEN POSType WHEN 'LegacyName' THEN LegacyName END END DESC,
        CASE WHEN @SortAsc = 1 THEN CASE @SortColumn WHEN 'ProfitCentreCount' THEN ProfitCentreCount END END ASC,
        CASE WHEN @SortAsc = 0 THEN CASE @SortColumn WHEN 'ProfitCentreCount' THEN ProfitCentreCount END END DESC,
        CASE WHEN @SortAsc = 1 AND @SortColumn = 'UpdatedAt' THEN UpdatedAt END ASC,
        CASE WHEN @SortAsc = 0 AND @SortColumn = 'UpdatedAt' THEN UpdatedAt END DESC,
        CASE WHEN @SortAsc = 0 AND (@SortColumn IS NULL OR @SortColumn = 'BranchId') THEN BranchId END DESC,
        BranchId
    OFFSET @Offset ROWS FETCH NEXT @PageSize ROWS ONLY;

    SELECT COUNT_BIG(*) AS TotalRows FROM #Filtered;
END
