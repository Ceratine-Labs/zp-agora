/* Customer-defined roles for Setup -> Roles. Historical roles are excluded.
   Counts are independent aggregates, so permissions cannot multiply members.
   One materialised filtered set serves both pagination and CSV totals. */
CREATE OR ALTER PROCEDURE agora.usp_Core_GridRoles
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
    DECLARE @Offset INT = (CASE WHEN @Page < 1 THEN 0 ELSE @Page - 1 END) * @PageSize;
    DECLARE @Filters TABLE ([Column] NVARCHAR(80), [Op] NVARCHAR(20), [Value] NVARCHAR(400));
    INSERT INTO @Filters
    SELECT JSON_VALUE(f.value, '$.column'), ISNULL(JSON_VALUE(f.value, '$.op'), 'contains'), JSON_VALUE(f.value, '$.value')
    FROM OPENJSON(@FiltersJson) f;

    SELECT r.Id, r.Code, r.Name,
           (SELECT COUNT(*) FROM agora.RolePermission rp WHERE rp.RoleId = r.Id) AS PermissionCount,
           (SELECT COUNT(*) FROM agora.RoleMenuItem rm WHERE rm.RoleId = r.Id) AS MenuCount,
           (SELECT COUNT(*) FROM agora.UserRole ur JOIN agora.[User] u ON u.Id = ur.UserId
            WHERE ur.RoleId = r.Id AND u.DeletedAt IS NULL) AS UserCount
    INTO #Roles
    FROM agora.Role r
    WHERE r.IsManaged = 1
      AND (@Search IS NULL OR r.Name LIKE '%' + @Search + '%' OR r.Code LIKE '%' + @Search + '%');

    SELECT r.* INTO #Filtered FROM #Roles r
    WHERE NOT EXISTS (
        SELECT 1 FROM @Filters f
        CROSS APPLY (SELECT CASE f.[Column] WHEN 'Name' THEN r.Name WHEN 'Code' THEN r.Code END AS TextValue,
            CASE f.[Column] WHEN 'PermissionCount' THEN r.PermissionCount WHEN 'MenuCount' THEN r.MenuCount WHEN 'UserCount' THEN r.UserCount END AS NumberValue) v
        WHERE (f.[Column] IN ('Name', 'Code') AND
            CASE WHEN f.Op = 'eq' AND v.TextValue = f.Value THEN 1
                 WHEN f.Op = 'contains' AND v.TextValue LIKE '%' + f.Value + '%' THEN 1
                 WHEN f.Op = 'ne' AND v.TextValue <> f.Value THEN 1
                 WHEN f.Op = 'gt' AND v.TextValue > f.Value THEN 1
                 WHEN f.Op = 'gte' AND v.TextValue >= f.Value THEN 1
                 WHEN f.Op = 'lt' AND v.TextValue < f.Value THEN 1
                 WHEN f.Op = 'lte' AND v.TextValue <= f.Value THEN 1 ELSE 0 END = 0)
        OR (f.[Column] IN ('PermissionCount', 'MenuCount', 'UserCount') AND TRY_CONVERT(DECIMAL(18,4), f.Value) IS NOT NULL AND
            CASE WHEN f.Op = 'gt' AND v.NumberValue > TRY_CONVERT(DECIMAL(18,4), f.Value) THEN 1
                 WHEN f.Op = 'gte' AND v.NumberValue >= TRY_CONVERT(DECIMAL(18,4), f.Value) THEN 1
                 WHEN f.Op = 'lt' AND v.NumberValue < TRY_CONVERT(DECIMAL(18,4), f.Value) THEN 1
                 WHEN f.Op = 'lte' AND v.NumberValue <= TRY_CONVERT(DECIMAL(18,4), f.Value) THEN 1
                 WHEN f.Op = 'ne' AND v.NumberValue <> TRY_CONVERT(DECIMAL(18,4), f.Value) THEN 1
                 WHEN f.Op IN ('=', 'eq') AND v.NumberValue = TRY_CONVERT(DECIMAL(18,4), f.Value) THEN 1 ELSE 0 END = 0)
    );

    SELECT * FROM #Filtered
    ORDER BY
        CASE WHEN @SortAsc = 1 THEN CASE ISNULL(@SortColumn, 'Name') WHEN 'Code' THEN Code WHEN 'Name' THEN Name END END ASC,
        CASE WHEN @SortAsc = 0 THEN CASE @SortColumn WHEN 'Code' THEN Code WHEN 'Name' THEN Name END END DESC,
        CASE WHEN @SortAsc = 1 THEN CASE @SortColumn WHEN 'PermissionCount' THEN PermissionCount WHEN 'MenuCount' THEN MenuCount WHEN 'UserCount' THEN UserCount END END ASC,
        CASE WHEN @SortAsc = 0 THEN CASE @SortColumn WHEN 'PermissionCount' THEN PermissionCount WHEN 'MenuCount' THEN MenuCount WHEN 'UserCount' THEN UserCount END END DESC,
        Id
    OFFSET @Offset ROWS FETCH NEXT @PageSize ROWS ONLY;
    SELECT COUNT_BIG(*) AS TotalRows FROM #Filtered;
END
