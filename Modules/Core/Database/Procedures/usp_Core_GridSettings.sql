/* Setup → The business → System defaults and thresholds: the listing (T026).

   One row per setting KEY: its definition, the group value (BranchId =
   @GroupBranchId), how many sites carry their own value, and what reads it.
   Agora's own thresholds first, then the copies of PumpIT's SS_SysDefaults and
   BRN_Config (key pumpit.*), which are read-only. Read-only.

   Overrides counts only the sites the caller is granted (agora.UserBranch; no
   grant means every branch). A key PumpIT kept per branch only may have no
   group row; its group value is then empty and its definition is its first
   site row's.

   @Authorized is the caller's setup.settings.view, resolved in PHP; without it
   the procedure refuses, so the CSV extract cannot be reached around the
   route. The same filtered set drives the page, the total and the extract.
   Read by Modules/Core/Grids/SettingGrid. Deployed by v1__01pj. */
CREATE OR ALTER PROCEDURE agora.usp_Core_GridSettings
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
    @GroupBranchId INT = 2
AS
BEGIN
    SET NOCOUNT ON;

    IF ISNULL(@Authorized, 0) <> 1
        THROW 51000, 'AGORA:SETTING_DENIED:You do not have permission to see the system defaults.', 1;

    SET @Page = CASE WHEN ISNULL(@Page, 0) < 1 THEN 1 ELSE @Page END;
    SET @PageSize = CASE WHEN ISNULL(@PageSize, 0) BETWEEN 1 AND 100000 THEN @PageSize ELSE 50 END;
    SET @Search = NULLIF(LTRIM(RTRIM(@Search)), '');
    DECLARE @Offset BIGINT = CONVERT(BIGINT, @Page - 1) * @PageSize;

    DECLARE @Granted TABLE (BranchId INT PRIMARY KEY);
    INSERT INTO @Granted SELECT DISTINCT BranchId FROM agora.UserBranch WHERE UserId = @UserId;
    DECLARE @All BIT = CASE WHEN EXISTS (SELECT 1 FROM @Granted) THEN 0 ELSE 1 END;

    DECLARE @Filters TABLE ([Column] NVARCHAR(80), Op NVARCHAR(20), Value NVARCHAR(400), SetJson NVARCHAR(MAX));
    INSERT INTO @Filters
    SELECT JSON_VALUE(f.value, '$.column'), ISNULL(JSON_VALUE(f.value, '$.op'), 'contains'),
           JSON_VALUE(f.value, '$.value'), JSON_QUERY(f.value, '$.in')
    FROM OPENJSON(@FiltersJson) f;

    ;WITH Keys AS (
        SELECT s.*, ROW_NUMBER() OVER (PARTITION BY s.SettingKey
                    ORDER BY CASE WHEN s.BranchId = @GroupBranchId THEN 0 ELSE 1 END, s.BranchId) AS Pick
        FROM agora.Setting s
        WHERE s.DeletedAt IS NULL
    )
    SELECT k.SettingKey,
           LEFT(k.SettingKey, CHARINDEX(N'.', k.SettingKey + N'.') - 1) AS Module,
           k.Description,
           k.Type,
           CASE WHEN k.BranchId = @GroupBranchId THEN k.Value END AS GroupValue,
           (SELECT COUNT(*) FROM agora.Setting o
            WHERE o.SettingKey = k.SettingKey AND o.DeletedAt IS NULL AND o.BranchId <> @GroupBranchId
              AND (@All = 1 OR o.BranchId IN (SELECT BranchId FROM @Granted))) AS Overrides,
           k.AllowsBranch,
           k.UsedBy,
           CONVERT(BIT, CASE WHEN k.UsedBy IS NULL THEN 0 ELSE 1 END) AS InUse,
           k.Source,
           k.LegacySource,
           k.UpdatedAt,
           CASE WHEN k.SettingKey LIKE N'pumpit.%' THEN 1 ELSE 0 END AS SortGroup
    INTO #Settings
    FROM Keys k
    WHERE k.Pick = 1
      AND (@Search IS NULL OR k.SettingKey LIKE '%' + @Search + '%' OR k.Description LIKE '%' + @Search + '%'
           OR k.UsedBy LIKE '%' + @Search + '%' OR k.LegacySource LIKE '%' + @Search + '%');

    SELECT x.* INTO #Filtered FROM #Settings x
    WHERE NOT EXISTS (
        SELECT 1 FROM @Filters f
        CROSS APPLY (SELECT CASE f.[Column]
            WHEN 'SettingKey' THEN x.SettingKey WHEN 'Description' THEN x.Description WHEN 'GroupValue' THEN x.GroupValue
            WHEN 'UsedBy' THEN x.UsedBy WHEN 'LegacySource' THEN x.LegacySource
            WHEN 'Module' THEN x.Module WHEN 'Type' THEN x.Type WHEN 'Source' THEN x.Source
            WHEN 'AllowsBranch' THEN CASE WHEN x.AllowsBranch = 1 THEN N'Yes' ELSE N'No' END
            WHEN 'InUse' THEN CASE WHEN x.InUse = 1 THEN N'Yes' ELSE N'No' END END AS TextValue,
            CASE f.[Column] WHEN 'Overrides' THEN x.Overrides END AS NumberValue) v
        WHERE (f.[Column] IN ('SettingKey', 'Description', 'GroupValue', 'UsedBy', 'LegacySource') AND f.SetJson IS NULL AND
              CASE WHEN f.Op = 'contains' AND v.TextValue LIKE '%' + f.Value + '%' THEN 1
                   WHEN f.Op = 'eq' AND v.TextValue = f.Value THEN 1
                   WHEN f.Op = 'ne' AND ISNULL(v.TextValue, N'') <> f.Value THEN 1
                   WHEN f.Op = 'gt' AND v.TextValue > f.Value THEN 1
                   WHEN f.Op = 'gte' AND v.TextValue >= f.Value THEN 1
                   WHEN f.Op = 'lt' AND v.TextValue < f.Value THEN 1
                   WHEN f.Op = 'lte' AND v.TextValue <= f.Value THEN 1 ELSE 0 END = 0)
           OR (f.SetJson IS NOT NULL AND f.[Column] IN ('SettingKey', 'Module', 'Type', 'Source', 'AllowsBranch', 'InUse')
               AND NOT EXISTS (SELECT 1 FROM OPENJSON(f.SetJson) s WHERE s.value = v.TextValue))
           OR (f.[Column] = 'Overrides' AND TRY_CONVERT(DECIMAL(18, 4), f.Value) IS NOT NULL AND
              CASE WHEN f.Op IN ('=', 'eq') AND v.NumberValue = TRY_CONVERT(DECIMAL(18, 4), f.Value) THEN 1
                   WHEN f.Op = 'ne' AND v.NumberValue <> TRY_CONVERT(DECIMAL(18, 4), f.Value) THEN 1
                   WHEN f.Op = 'gt' AND v.NumberValue > TRY_CONVERT(DECIMAL(18, 4), f.Value) THEN 1
                   WHEN f.Op = 'gte' AND v.NumberValue >= TRY_CONVERT(DECIMAL(18, 4), f.Value) THEN 1
                   WHEN f.Op = 'lt' AND v.NumberValue < TRY_CONVERT(DECIMAL(18, 4), f.Value) THEN 1
                   WHEN f.Op = 'lte' AND v.NumberValue <= TRY_CONVERT(DECIMAL(18, 4), f.Value) THEN 1 ELSE 0 END = 0)
    );

    SELECT SettingKey, Module, Description, Type, GroupValue, Overrides, AllowsBranch, UsedBy, InUse, Source,
           LegacySource, UpdatedAt
    FROM #Filtered
    ORDER BY
        CASE WHEN @SortAsc = 1 THEN CASE @SortColumn WHEN 'Module' THEN Module WHEN 'Type' THEN Type
            WHEN 'Source' THEN Source WHEN 'UsedBy' THEN UsedBy WHEN 'Description' THEN Description END END ASC,
        CASE WHEN @SortAsc = 0 THEN CASE @SortColumn WHEN 'Module' THEN Module WHEN 'Type' THEN Type
            WHEN 'Source' THEN Source WHEN 'UsedBy' THEN UsedBy WHEN 'Description' THEN Description END END DESC,
        CASE WHEN @SortAsc = 1 AND @SortColumn = 'Overrides' THEN Overrides END ASC,
        CASE WHEN @SortAsc = 0 AND @SortColumn = 'Overrides' THEN Overrides END DESC,
        CASE WHEN @SortAsc = 1 AND @SortColumn = 'UpdatedAt' THEN UpdatedAt END ASC,
        CASE WHEN @SortAsc = 0 AND @SortColumn = 'UpdatedAt' THEN UpdatedAt END DESC,
        CASE WHEN @SortAsc = 0 AND (@SortColumn IS NULL OR @SortColumn = 'SettingKey') THEN SettingKey END DESC,
        SortGroup,
        SettingKey
    OFFSET @Offset ROWS FETCH NEXT @PageSize ROWS ONLY;

    SELECT COUNT_BIG(*) AS TotalRows FROM #Filtered;
END
