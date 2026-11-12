/* Setup → The business → Standard categories (T022). Read-only.

   The group-standard category each POS category maps into, and the two GL
   accounts behind it — PumpIT's SS_StandardCategory, 54 rows, group-wide.

   Beside each:
     SharedGlCount   how many OTHER standard categories post to the same
                     supplier/sales GL pair. Twenty share 51010209 / 41010209
                     (measured 12 Nov 2026):
                     fine for the ledger, useless for margin analysis, which
                     is why category GP is read off the POS category.
     CategoryCount   ACTIVE POS categories mapped to it (SDK_CategoryGLCode,
                     by name), at the sites in scope
     BranchCount     sites mapping at least one active category to it
     GlMismatchCount mapped categories whose own supplier or sales GL differs
                     from the standard's (0 on the estate, 12 Nov 2026)
     ProfitCentres   the profit centres those categories sit under

   @Key (a StandardCategoryId) returns that one, then its mapped POS
   categories site by site.

   @Authorized is masters.standardcategories.view. The same filtered set drives
   the page, the total and the CSV extract. Read by
   Modules/Masters/Grids/StandardCategoryGrid. Deployed by v1__02pa. Reads
   agora.vw_MasterStandardCategory and agora.vw_MasterCategoryGl. */
CREATE OR ALTER PROCEDURE agora.usp_Masters_GridStandardCategories
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
        THROW 51000, 'AGORA:MASTERS_DENIED:You do not have permission to see the standard categories.', 1;

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

    SELECT @GroupBranchId AS BranchId,
           CONVERT(NVARCHAR(200), s.StandardCategoryId) AS RowKey,
           s.StandardCategoryId,
           s.StandardCategory,
           s.SupplierGLCode,
           s.SalesGLCode,
           (SELECT COUNT(*) FROM agora.vw_MasterStandardCategory o
            WHERE o.StandardCategoryId <> s.StandardCategoryId
              AND o.SupplierGLCode = s.SupplierGLCode AND o.SalesGLCode = s.SalesGLCode) AS SharedGlCount,
           m.CategoryCount,
           m.BranchCount,
           (SELECT COUNT(*) FROM agora.vw_MasterCategoryGl g JOIN @Visible v ON v.BranchId = g.BranchId
            WHERE g.StandardCategory = s.StandardCategory AND g.IsActive = 1
              AND (ISNULL(g.SupplierGLCode, N'') <> ISNULL(s.SupplierGLCode, N'')
                   OR ISNULL(g.SalesGLCode, N'') <> ISNULL(s.SalesGLCode, N''))) AS GlMismatchCount,
           (SELECT STRING_AGG(CONVERT(NVARCHAR(4000), p.ProfitCentre), N', ') WITHIN GROUP (ORDER BY p.ProfitCentre)
            FROM (SELECT DISTINCT g.ProfitCentre FROM agora.vw_MasterCategoryGl g JOIN @Visible v ON v.BranchId = g.BranchId
                  WHERE g.StandardCategory = s.StandardCategory AND g.IsActive = 1 AND g.ProfitCentre IS NOT NULL) p) AS ProfitCentres,
           s.IsActive
    INTO #Rows
    FROM agora.vw_MasterStandardCategory s
    OUTER APPLY (
        SELECT COUNT(*) AS CategoryCount,
               COUNT(DISTINCT g.BranchId) AS BranchCount
        FROM agora.vw_MasterCategoryGl g JOIN @Visible v ON v.BranchId = g.BranchId
        WHERE g.StandardCategory = s.StandardCategory AND g.IsActive = 1
    ) m
    WHERE (@Key IS NULL OR CONVERT(NVARCHAR(200), s.StandardCategoryId) = @Key)
      AND (@Search IS NULL OR s.StandardCategory LIKE '%' + @Search + '%'
           OR s.SupplierGLCode LIKE '%' + @Search + '%' OR s.SalesGLCode LIKE '%' + @Search + '%');

    SELECT r.* INTO #Filtered FROM #Rows r
    WHERE NOT EXISTS (
        SELECT 1 FROM @Filters f
        CROSS APPLY (SELECT
            CASE f.[Column] WHEN 'StandardCategory' THEN CONVERT(NVARCHAR(400), r.StandardCategory) WHEN 'SupplierGLCode' THEN CONVERT(NVARCHAR(400), r.SupplierGLCode) WHEN 'SalesGLCode' THEN CONVERT(NVARCHAR(400), r.SalesGLCode) WHEN 'ProfitCentres' THEN CONVERT(NVARCHAR(400), r.ProfitCentres) WHEN 'IsActive' THEN CASE WHEN r.IsActive = 1 THEN N'Yes' ELSE N'No' END END AS T,
            CASE f.[Column] WHEN 'StandardCategoryId' THEN CONVERT(DECIMAL(18, 4), r.StandardCategoryId) WHEN 'SharedGlCount' THEN CONVERT(DECIMAL(18, 4), r.SharedGlCount) WHEN 'CategoryCount' THEN CONVERT(DECIMAL(18, 4), r.CategoryCount) WHEN 'BranchCount' THEN CONVERT(DECIMAL(18, 4), r.BranchCount) WHEN 'GlMismatchCount' THEN CONVERT(DECIMAL(18, 4), r.GlMismatchCount) END AS N,
            CASE WHEN f.[Column] IN ('StandardCategoryId', 'SharedGlCount', 'CategoryCount', 'BranchCount', 'GlMismatchCount') THEN 1 ELSE 0 END AS IsNum,
            TRY_CONVERT(DECIMAL(18, 4), f.Value) AS V) v
        WHERE f.[Column] IN ('StandardCategory', 'SupplierGLCode', 'SalesGLCode', 'ProfitCentres', 'StandardCategoryId', 'SharedGlCount', 'CategoryCount', 'BranchCount', 'GlMismatchCount', 'IsActive')
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
        CASE WHEN @SortAsc = 1 THEN CASE @SortColumn WHEN 'StandardCategory' THEN CONVERT(NVARCHAR(400), StandardCategory) WHEN 'SupplierGLCode' THEN CONVERT(NVARCHAR(400), SupplierGLCode) WHEN 'SalesGLCode' THEN CONVERT(NVARCHAR(400), SalesGLCode) END END ASC,
        CASE WHEN @SortAsc = 0 THEN CASE @SortColumn WHEN 'StandardCategory' THEN CONVERT(NVARCHAR(400), StandardCategory) WHEN 'SupplierGLCode' THEN CONVERT(NVARCHAR(400), SupplierGLCode) WHEN 'SalesGLCode' THEN CONVERT(NVARCHAR(400), SalesGLCode) END END DESC,
        CASE WHEN @SortAsc = 1 THEN CASE @SortColumn WHEN 'StandardCategoryId' THEN CONVERT(DECIMAL(18, 4), StandardCategoryId) WHEN 'SharedGlCount' THEN CONVERT(DECIMAL(18, 4), SharedGlCount) WHEN 'CategoryCount' THEN CONVERT(DECIMAL(18, 4), CategoryCount) WHEN 'BranchCount' THEN CONVERT(DECIMAL(18, 4), BranchCount) WHEN 'GlMismatchCount' THEN CONVERT(DECIMAL(18, 4), GlMismatchCount) END END ASC,
        CASE WHEN @SortAsc = 0 THEN CASE @SortColumn WHEN 'StandardCategoryId' THEN CONVERT(DECIMAL(18, 4), StandardCategoryId) WHEN 'SharedGlCount' THEN CONVERT(DECIMAL(18, 4), SharedGlCount) WHEN 'CategoryCount' THEN CONVERT(DECIMAL(18, 4), CategoryCount) WHEN 'BranchCount' THEN CONVERT(DECIMAL(18, 4), BranchCount) WHEN 'GlMismatchCount' THEN CONVERT(DECIMAL(18, 4), GlMismatchCount) END END DESC,
        StandardCategoryId
    OFFSET @Offset ROWS FETCH NEXT @PageSize ROWS ONLY;

    SELECT COUNT_BIG(*) AS TotalRows FROM #Filtered;

    -- One standard category: the POS categories mapped into it, site by site.
    IF @Key IS NOT NULL
    BEGIN
        DECLARE @Name NVARCHAR(80) = (SELECT TOP 1 StandardCategory FROM #Filtered);

        SELECT COALESCE(b.Name, CONCAT(N'Branch ', g.BranchId)) AS [Site],
               g.POSSystemId AS [POS system],
               g.Category AS [POS category],
               g.ProfitCentre AS [Profit centre],
               CASE WHEN g.IsActive = 1 THEN N'Yes' ELSE N'No' END AS [Active],
               CASE WHEN ISNULL(g.MinGP, 0) = 0 AND ISNULL(g.MaxGP, 0) = 0 THEN N'No band'
                    ELSE CONCAT(CONVERT(DECIMAL(9, 2), g.MinGP), N' – ', CONVERT(DECIMAL(9, 2), g.MaxGP), N'%') END AS [GP band]
        FROM agora.vw_MasterCategoryGl g
        JOIN @Visible v ON v.BranchId = g.BranchId
        LEFT JOIN agora.Branch b ON b.BranchId = g.BranchId
        WHERE g.StandardCategory = @Name
        ORDER BY b.Name, g.POSSystemId, g.Category;
    END
END
