/* Setup → The business → Categories and GP bands (T022). Read-only.

   Every POS category, the standard category and profit centre it maps into,
   the supplier and sales GL behind it, and the GP and days-cover band it is
   held to — PumpIT's SDK_CategoryGLCode, one row per (branch, POS system,
   POS category): 1,060 rows on 12 Nov 2026 (the plan said 815).

   GpBand is 'Missing' where MinGP and MaxGP are both 0. A category with no
   band is invisible to every check that keys off it — a "below min GP"
   report simply never returns its lines. 507 of the 861 active categories
   carried none on 12 Nov 2026. CoverBand is the same test on the days-cover
   pair.

   PER BRANCH. Refuses without @BranchIds (MASTERS_BRANCH): a category is a
   different row at every site. Rows are limited to the branches chosen,
   within the caller's grants. RowKey is 'POSSystemId:Category'.

   POSSystem names the POSSystemId — the till system the category's sales
   arrive from — as MIST_Main.dbo.POSSystems does on the customer's instance
   (12 Nov 2026; PumpIT's sp_Insert_SDK_CategoryGLCode comments 1–7 the
   same): 1 Aura, 2 Arch, 3 Gaap, 4 Namos, 5 Winbranch, 6 Manual capture,
   7 Arch Liquor, 8 Pilot, 9 None, 10 Nights Bridge. Any other id shows as
   itself.

   @Key returns that one category, then the same POS category at the caller's
   other sites, so a band can be compared across the estate.

   @Authorized is masters.categorygl.view. The same filtered set drives the
   page, the total and the CSV extract. Read by
   Modules/Masters/Grids/CategoryGlGrid. Deployed by v1__02pa, and again by v1__02pd. Reads
   agora.vw_MasterCategoryGl. */
CREATE OR ALTER PROCEDURE agora.usp_Masters_GridCategoryGlCodes
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
    @Authorized BIT = 0
AS
BEGIN
    SET NOCOUNT ON;

    IF ISNULL(@Authorized, 0) <> 1
        THROW 51000, 'AGORA:MASTERS_DENIED:You do not have permission to see the category GL codes.', 1;
    IF NULLIF(LTRIM(RTRIM(@BranchIds)), '') IS NULL
        THROW 51000, 'AGORA:MASTERS_BRANCH:Choose a branch. POS categories are kept per site: the same category text is a different row, with a different band, at every branch.', 1;

    SET @Page = CASE WHEN ISNULL(@Page, 0) < 1 THEN 1 ELSE @Page END;
    SET @PageSize = CASE WHEN ISNULL(@PageSize, 0) BETWEEN 1 AND 100000 THEN @PageSize ELSE 50 END;
    SET @Search = NULLIF(LTRIM(RTRIM(@Search)), '');
    SET @Key = NULLIF(LTRIM(RTRIM(@Key)), '');
    DECLARE @Offset BIGINT = CONVERT(BIGINT, @Page - 1) * @PageSize;

    -- The sites in scope: the branches chosen, within the caller's grants
    -- (agora.UserBranch; no grant rows means every branch).
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

    SELECT g.BranchId,
           COALESCE(b.Name, CONCAT(N'Branch ', g.BranchId)) AS BranchName,
           CONVERT(NVARCHAR(200), CONCAT(g.POSSystemId, N':', g.Category)) AS RowKey,
           g.POSSystemId,
           CONVERT(NVARCHAR(40), CASE g.POSSystemId WHEN 1 THEN N'Aura' WHEN 2 THEN N'Arch' WHEN 3 THEN N'Gaap' WHEN 4 THEN N'Namos'
                WHEN 5 THEN N'Winbranch' WHEN 6 THEN N'Manual capture' WHEN 7 THEN N'Arch Liquor' WHEN 8 THEN N'Pilot'
                WHEN 9 THEN N'None' WHEN 10 THEN N'Nights Bridge' ELSE CONCAT(N'POS system ', g.POSSystemId) END) AS POSSystem,
           g.Category,
           g.StandardCategory,
           g.ProfitCentre,
           g.SupplierGLCode,
           g.SalesGLCode,
           g.MinGP,
           g.MaxGP,
           g.MinDaysCover,
           g.MaxDaysCover,
           CASE WHEN ISNULL(g.MinGP, 0) = 0 AND ISNULL(g.MaxGP, 0) = 0 THEN N'Missing' ELSE N'Set' END AS GpBand,
           CASE WHEN ISNULL(g.MinDaysCover, 0) = 0 AND ISNULL(g.MaxDaysCover, 0) = 0 THEN N'Missing' ELSE N'Set' END AS CoverBand,
           g.IsActive
    INTO #Rows
    FROM agora.vw_MasterCategoryGl g
    JOIN @Visible v ON v.BranchId = g.BranchId
    LEFT JOIN agora.Branch b ON b.BranchId = g.BranchId
    WHERE (@Key IS NULL OR CONCAT(g.POSSystemId, N':', g.Category) = @Key)
      AND (@Search IS NULL OR g.Category LIKE '%' + @Search + '%' OR g.StandardCategory LIKE '%' + @Search + '%'
           OR g.ProfitCentre LIKE '%' + @Search + '%' OR g.SupplierGLCode LIKE '%' + @Search + '%'
           OR g.SalesGLCode LIKE '%' + @Search + '%');

    SELECT r.* INTO #Filtered FROM #Rows r
    WHERE NOT EXISTS (
        SELECT 1 FROM @Filters f
        CROSS APPLY (SELECT
            CASE f.[Column] WHEN 'BranchName' THEN CONVERT(NVARCHAR(400), r.BranchName) WHEN 'POSSystem' THEN CONVERT(NVARCHAR(400), r.POSSystem) WHEN 'Category' THEN CONVERT(NVARCHAR(400), r.Category) WHEN 'StandardCategory' THEN CONVERT(NVARCHAR(400), r.StandardCategory) WHEN 'ProfitCentre' THEN CONVERT(NVARCHAR(400), r.ProfitCentre) WHEN 'SupplierGLCode' THEN CONVERT(NVARCHAR(400), r.SupplierGLCode) WHEN 'SalesGLCode' THEN CONVERT(NVARCHAR(400), r.SalesGLCode) WHEN 'GpBand' THEN r.GpBand WHEN 'CoverBand' THEN r.CoverBand WHEN 'IsActive' THEN CASE WHEN r.IsActive = 1 THEN N'Yes' ELSE N'No' END END AS T,
            CASE f.[Column] WHEN 'POSSystemId' THEN CONVERT(DECIMAL(18, 4), r.POSSystemId) WHEN 'MinGP' THEN CONVERT(DECIMAL(18, 4), r.MinGP) WHEN 'MaxGP' THEN CONVERT(DECIMAL(18, 4), r.MaxGP) WHEN 'MinDaysCover' THEN CONVERT(DECIMAL(18, 4), r.MinDaysCover) WHEN 'MaxDaysCover' THEN CONVERT(DECIMAL(18, 4), r.MaxDaysCover) END AS N,
            CASE WHEN f.[Column] IN ('POSSystemId', 'MinGP', 'MaxGP', 'MinDaysCover', 'MaxDaysCover') THEN 1 ELSE 0 END AS IsNum,
            TRY_CONVERT(DECIMAL(18, 4), f.Value) AS V) v
        WHERE f.[Column] IN ('BranchName', 'POSSystem', 'Category', 'StandardCategory', 'ProfitCentre', 'SupplierGLCode', 'SalesGLCode', 'POSSystemId', 'MinGP', 'MaxGP', 'MinDaysCover', 'MaxDaysCover', 'GpBand', 'CoverBand', 'IsActive')
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
        CASE WHEN @SortAsc = 1 THEN CASE @SortColumn WHEN 'BranchName' THEN CONVERT(NVARCHAR(400), BranchName) WHEN 'POSSystem' THEN CONVERT(NVARCHAR(400), POSSystem) WHEN 'Category' THEN CONVERT(NVARCHAR(400), Category) WHEN 'StandardCategory' THEN CONVERT(NVARCHAR(400), StandardCategory) WHEN 'ProfitCentre' THEN CONVERT(NVARCHAR(400), ProfitCentre) WHEN 'SupplierGLCode' THEN CONVERT(NVARCHAR(400), SupplierGLCode) WHEN 'SalesGLCode' THEN CONVERT(NVARCHAR(400), SalesGLCode) WHEN 'GpBand' THEN CONVERT(NVARCHAR(400), GpBand) WHEN 'CoverBand' THEN CONVERT(NVARCHAR(400), CoverBand) END END ASC,
        CASE WHEN @SortAsc = 0 THEN CASE @SortColumn WHEN 'BranchName' THEN CONVERT(NVARCHAR(400), BranchName) WHEN 'POSSystem' THEN CONVERT(NVARCHAR(400), POSSystem) WHEN 'Category' THEN CONVERT(NVARCHAR(400), Category) WHEN 'StandardCategory' THEN CONVERT(NVARCHAR(400), StandardCategory) WHEN 'ProfitCentre' THEN CONVERT(NVARCHAR(400), ProfitCentre) WHEN 'SupplierGLCode' THEN CONVERT(NVARCHAR(400), SupplierGLCode) WHEN 'SalesGLCode' THEN CONVERT(NVARCHAR(400), SalesGLCode) WHEN 'GpBand' THEN CONVERT(NVARCHAR(400), GpBand) WHEN 'CoverBand' THEN CONVERT(NVARCHAR(400), CoverBand) END END DESC,
        CASE WHEN @SortAsc = 1 THEN CASE @SortColumn WHEN 'POSSystemId' THEN CONVERT(DECIMAL(18, 4), POSSystemId) WHEN 'MinGP' THEN CONVERT(DECIMAL(18, 4), MinGP) WHEN 'MaxGP' THEN CONVERT(DECIMAL(18, 4), MaxGP) WHEN 'MinDaysCover' THEN CONVERT(DECIMAL(18, 4), MinDaysCover) WHEN 'MaxDaysCover' THEN CONVERT(DECIMAL(18, 4), MaxDaysCover) END END ASC,
        CASE WHEN @SortAsc = 0 THEN CASE @SortColumn WHEN 'POSSystemId' THEN CONVERT(DECIMAL(18, 4), POSSystemId) WHEN 'MinGP' THEN CONVERT(DECIMAL(18, 4), MinGP) WHEN 'MaxGP' THEN CONVERT(DECIMAL(18, 4), MaxGP) WHEN 'MinDaysCover' THEN CONVERT(DECIMAL(18, 4), MinDaysCover) WHEN 'MaxDaysCover' THEN CONVERT(DECIMAL(18, 4), MaxDaysCover) END END DESC,
        BranchName, POSSystemId, Category
    OFFSET @Offset ROWS FETCH NEXT @PageSize ROWS ONLY;

    SELECT COUNT_BIG(*) AS TotalRows FROM #Filtered;

    -- One category: the same POS category at the caller's other sites.
    IF @Key IS NOT NULL
    BEGIN
        DECLARE @System INT = (SELECT TOP 1 POSSystemId FROM #Filtered);
        DECLARE @Category NVARCHAR(80) = (SELECT TOP 1 Category FROM #Filtered);
        DECLARE @Here INT = (SELECT TOP 1 BranchId FROM #Filtered);

        SELECT COALESCE(b.Name, CONCAT(N'Branch ', g.BranchId)) AS [Site],
               g.StandardCategory AS [Standard category],
               g.ProfitCentre AS [Profit centre],
               CASE WHEN ISNULL(g.MinGP, 0) = 0 AND ISNULL(g.MaxGP, 0) = 0 THEN N'Missing'
                    ELSE CONCAT(CONVERT(DECIMAL(9, 2), g.MinGP), N' – ', CONVERT(DECIMAL(9, 2), g.MaxGP), N'%') END AS [GP band],
               CASE WHEN ISNULL(g.MinDaysCover, 0) = 0 AND ISNULL(g.MaxDaysCover, 0) = 0 THEN N'Missing'
                    ELSE CONCAT(CONVERT(DECIMAL(9, 1), g.MinDaysCover), N' – ', CONVERT(DECIMAL(9, 1), g.MaxDaysCover), N' days') END AS [Days cover],
               CASE WHEN g.IsActive = 1 THEN N'Yes' ELSE N'No' END AS [Active]
        FROM agora.vw_MasterCategoryGl g
        LEFT JOIN agora.Branch b ON b.BranchId = g.BranchId
        WHERE g.POSSystemId = @System AND g.Category = @Category AND g.BranchId <> @Here
          AND (NOT EXISTS (SELECT 1 FROM agora.UserBranch WHERE UserId = @UserId)
               OR EXISTS (SELECT 1 FROM agora.UserBranch u WHERE u.UserId = @UserId AND u.BranchId = g.BranchId))
        ORDER BY b.Name;
    END
END
