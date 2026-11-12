/* Setup → The business → Profit centres (T022). Read-only.

   The profit centres every branch reports against — PumpIT's SS_ProfitCentre,
   19 rows, group-wide. Fuel is five of them, not one: ULP 95, ULP 93,
   Diesel 50, Diesel 500 and Diesel 10 are each their own centre.

   Beside each centre:
     BranchCount         sites that run it, on Agora's branch master
                         (agora.BranchProfitCentre, Setup → Branches)
     CategoryCount       ACTIVE POS categories mapped to it in PumpIT's
                         SDK_CategoryGLCode (which names the centre by text)
     NoBandCount         of those, how many carry no GP band (MinGP and MaxGP
                         both 0) — invisible to every "below min GP" check
   All three count only the sites the caller may see, and only the branches
   chosen (@BranchIds) when there is a choice.

   @Key (a ProfitCentreId) returns that one centre, then one more result set:
   the sites, with their mapped categories and whether the branch master
   lists the centre.

   @Authorized is the caller's masters.profitcentres.view, from the
   application. The same filtered set drives the page, the total and the CSV
   extract. Read by Modules/Masters/Grids/ProfitCentreGrid. Deployed by
   v1__02pa. Reads agora.vw_ProfitCentre and agora.vw_MasterCategoryGl. */
CREATE OR ALTER PROCEDURE agora.usp_Masters_GridProfitCentres
    @BranchIds NVARCHAR(MAX) = NULL,
    @DateFrom DATE = NULL,
    @DateTo DATE = NULL,
    @Search NVARCHAR(200) = NULL,
    @SortColumn NVARCHAR(80) = NULL,
    @SortAsc BIT = 1,
    @Page INT = 1,
    @PageSize INT = 50,
    @FiltersJson NVARCHAR(MAX) = NULL,
    @Key NVARCHAR(200) = NULL,
    @UserId INT = NULL,
    @Authorized BIT = 0,
    @GroupBranchId INT = 2
AS
BEGIN
    SET NOCOUNT ON;

    IF ISNULL(@Authorized, 0) <> 1
        THROW 51000, 'AGORA:MASTERS_DENIED:You do not have permission to see the profit centres.', 1;

    SET @Page = CASE WHEN ISNULL(@Page, 0) < 1 THEN 1 ELSE @Page END;
    SET @PageSize = CASE WHEN ISNULL(@PageSize, 0) BETWEEN 1 AND 100000 THEN @PageSize ELSE 50 END;
    SET @Search = NULLIF(LTRIM(RTRIM(@Search)), '');
    SET @Key = NULLIF(LTRIM(RTRIM(@Key)), '');
    DECLARE @Offset BIGINT = CONVERT(BIGINT, @Page - 1) * @PageSize;

    -- The sites the counts are over: the caller's grants, narrowed to the
    -- branches chosen. No grant rows means every branch.
    DECLARE @Visible TABLE (BranchId INT PRIMARY KEY);
    INSERT INTO @Visible (BranchId)
    SELECT b.BranchId FROM agora.Branch b
    WHERE b.DeletedAt IS NULL
      AND (NULLIF(LTRIM(RTRIM(@BranchIds)), '') IS NULL OR b.BranchId IN (
               SELECT TRY_CONVERT(INT, value) FROM STRING_SPLIT(@BranchIds, ',') WHERE LTRIM(RTRIM(value)) <> ''))
      AND (NOT EXISTS (SELECT 1 FROM agora.UserBranch WHERE UserId = @UserId)
           OR EXISTS (SELECT 1 FROM agora.UserBranch u WHERE u.UserId = @UserId AND u.BranchId = b.BranchId));

    DECLARE @Filters TABLE ([Column] NVARCHAR(80), Op NVARCHAR(20), Value NVARCHAR(400), SetJson NVARCHAR(MAX));
    INSERT INTO @Filters
    SELECT JSON_VALUE(f.value, '$.column'), ISNULL(JSON_VALUE(f.value, '$.op'), 'contains'),
           JSON_VALUE(f.value, '$.value'), JSON_QUERY(f.value, '$.in')
    FROM OPENJSON(@FiltersJson) f;

    SELECT @GroupBranchId AS BranchId,
           CONVERT(NVARCHAR(200), p.ProfitCentreId) AS RowKey,
           p.ProfitCentreId,
           p.Name,
           p.IsActive,
           (SELECT COUNT(*) FROM agora.BranchProfitCentre x JOIN @Visible v ON v.BranchId = x.BranchId
            WHERE x.ProfitCentreId = p.ProfitCentreId) AS BranchCount,
           (SELECT COUNT(*) FROM agora.vw_MasterCategoryGl g JOIN @Visible v ON v.BranchId = g.BranchId
            WHERE g.ProfitCentre = p.Name AND g.IsActive = 1) AS CategoryCount,
           (SELECT COUNT(*) FROM agora.vw_MasterCategoryGl g JOIN @Visible v ON v.BranchId = g.BranchId
            WHERE g.ProfitCentre = p.Name AND g.IsActive = 1 AND ISNULL(g.MinGP, 0) = 0 AND ISNULL(g.MaxGP, 0) = 0) AS NoBandCount,
           (SELECT STRING_AGG(CONVERT(NVARCHAR(4000), s.StandardCategory), N', ') WITHIN GROUP (ORDER BY s.StandardCategory)
            FROM (SELECT DISTINCT g.StandardCategory FROM agora.vw_MasterCategoryGl g JOIN @Visible v ON v.BranchId = g.BranchId
                  WHERE g.ProfitCentre = p.Name AND g.IsActive = 1 AND g.StandardCategory IS NOT NULL) s) AS StandardCategories
    INTO #Rows
    FROM agora.vw_ProfitCentre p
    WHERE (@Key IS NULL OR CONVERT(NVARCHAR(200), p.ProfitCentreId) = @Key)
      AND (@Search IS NULL OR p.Name LIKE '%' + @Search + '%' OR CONVERT(NVARCHAR(12), p.ProfitCentreId) = @Search);

    SELECT r.* INTO #Filtered FROM #Rows r
    WHERE NOT EXISTS (
        SELECT 1 FROM @Filters f
        CROSS APPLY (SELECT
            CASE f.[Column] WHEN 'Name' THEN r.Name WHEN 'StandardCategories' THEN r.StandardCategories
                WHEN 'IsActive' THEN CASE WHEN r.IsActive = 1 THEN N'Yes' ELSE N'No' END END AS T,
            CASE f.[Column] WHEN 'ProfitCentreId' THEN r.ProfitCentreId WHEN 'BranchCount' THEN r.BranchCount
                WHEN 'CategoryCount' THEN r.CategoryCount WHEN 'NoBandCount' THEN r.NoBandCount END AS N,
            CASE WHEN f.[Column] IN ('ProfitCentreId', 'BranchCount', 'CategoryCount', 'NoBandCount') THEN 1 ELSE 0 END AS IsNum,
            TRY_CONVERT(DECIMAL(18, 4), f.Value) AS V) v
        WHERE f.[Column] IN ('Name', 'StandardCategories', 'IsActive', 'ProfitCentreId', 'BranchCount', 'CategoryCount', 'NoBandCount')
          AND CASE
                WHEN f.SetJson IS NOT NULL THEN CASE WHEN EXISTS (SELECT 1 FROM OPENJSON(f.SetJson) s WHERE s.value = v.T) THEN 1 ELSE 0 END
                WHEN v.IsNum = 1 THEN CASE
                    WHEN v.V IS NULL THEN 1
                    WHEN f.Op IN ('eq', '=') THEN IIF(v.N = v.V, 1, 0)
                    WHEN f.Op = 'ne' THEN IIF(v.N <> v.V, 1, 0)
                    WHEN f.Op = 'gt' THEN IIF(v.N > v.V, 1, 0)
                    WHEN f.Op = 'gte' THEN IIF(v.N >= v.V, 1, 0)
                    WHEN f.Op = 'lt' THEN IIF(v.N < v.V, 1, 0)
                    WHEN f.Op = 'lte' THEN IIF(v.N <= v.V, 1, 0) ELSE 1 END
                ELSE CASE
                    WHEN f.Op = 'contains' THEN IIF(v.T LIKE '%' + f.Value + '%', 1, 0)
                    WHEN f.Op = 'eq' THEN IIF(v.T = f.Value, 1, 0)
                    WHEN f.Op = 'ne' THEN IIF(ISNULL(v.T, N'') <> f.Value, 1, 0)
                    WHEN f.Op = 'gt' THEN IIF(v.T > f.Value, 1, 0)
                    WHEN f.Op = 'gte' THEN IIF(v.T >= f.Value, 1, 0)
                    WHEN f.Op = 'lt' THEN IIF(v.T < f.Value, 1, 0)
                    WHEN f.Op = 'lte' THEN IIF(v.T <= f.Value, 1, 0) ELSE 1 END
              END = 0
    );

    SELECT * FROM #Filtered
    ORDER BY
        CASE WHEN @SortAsc = 1 THEN CASE @SortColumn WHEN 'Name' THEN Name END END ASC,
        CASE WHEN @SortAsc = 0 THEN CASE @SortColumn WHEN 'Name' THEN Name END END DESC,
        CASE WHEN @SortAsc = 1 THEN CASE @SortColumn WHEN 'BranchCount' THEN BranchCount WHEN 'CategoryCount' THEN CategoryCount
            WHEN 'NoBandCount' THEN NoBandCount WHEN 'ProfitCentreId' THEN ProfitCentreId END END ASC,
        CASE WHEN @SortAsc = 0 THEN CASE @SortColumn WHEN 'BranchCount' THEN BranchCount WHEN 'CategoryCount' THEN CategoryCount
            WHEN 'NoBandCount' THEN NoBandCount WHEN 'ProfitCentreId' THEN ProfitCentreId END END DESC,
        ProfitCentreId
    OFFSET @Offset ROWS FETCH NEXT @PageSize ROWS ONLY;

    SELECT COUNT_BIG(*) AS TotalRows FROM #Filtered;

    -- One centre: the sites that run it or map a category to it.
    IF @Key IS NOT NULL
    BEGIN
        DECLARE @Name NVARCHAR(80) = (SELECT TOP 1 Name FROM #Filtered);

        SELECT COALESCE(b.Name, CONCAT(N'Branch ', s.BranchId)) AS [Site],
               CASE WHEN EXISTS (SELECT 1 FROM agora.BranchProfitCentre x WHERE x.BranchId = s.BranchId
                                 AND x.ProfitCentreId = TRY_CONVERT(INT, @Key)) THEN N'Yes' ELSE N'No' END AS [On the branch master],
               s.Categories AS [Active POS categories],
               s.NoBand AS [Without a GP band]
        FROM (
            SELECT v.BranchId,
                   SUM(CASE WHEN g.IsActive = 1 THEN 1 ELSE 0 END) AS Categories,
                   SUM(CASE WHEN g.IsActive = 1 AND ISNULL(g.MinGP, 0) = 0 AND ISNULL(g.MaxGP, 0) = 0 THEN 1 ELSE 0 END) AS NoBand
            FROM @Visible v
            LEFT JOIN agora.vw_MasterCategoryGl g ON g.BranchId = v.BranchId AND g.ProfitCentre = @Name
            GROUP BY v.BranchId
        ) s
        LEFT JOIN agora.Branch b ON b.BranchId = s.BranchId
        WHERE s.Categories > 0
           OR EXISTS (SELECT 1 FROM agora.BranchProfitCentre x WHERE x.BranchId = s.BranchId AND x.ProfitCentreId = TRY_CONVERT(INT, @Key))
        ORDER BY b.Name;
    END
END
