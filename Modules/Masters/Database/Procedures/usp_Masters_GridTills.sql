/* Setup → Sites and forecourt → Tills (T022). Read-only.

   The tills each site cashes up — PumpIT's BRN_Tills, one row per
   (branch, TillNo): 236 on 12 Nov 2026. PosLocation is the POS the till
   belongs to (WINBRANCH, ARCH, AURA, PILOT, NAMOS …).

   LastCashup is the latest BRN_DailyBanking TransactionDate for the till —
   a seek on that table's primary key (SSBranchId, TillNo, …) — so a till
   that has not cashed up in months shows as such. Never joined on TillNo
   alone: a till is always (BranchId, TillNo).

   PER BRANCH. Refuses without @BranchIds (MASTERS_BRANCH). RowKey is the
   TillNo.

   @Authorized is masters.tills.view. The same filtered set drives the page,
   the total and the CSV extract. Read by Modules/Masters/Grids/TillGrid.
   Deployed by v1__02pa. Reads agora.vw_Till and agora.vw_DailyBanking. */
CREATE OR ALTER PROCEDURE agora.usp_Masters_GridTills
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
        THROW 51000, 'AGORA:MASTERS_DENIED:You do not have permission to see the tills.', 1;
    IF NULLIF(LTRIM(RTRIM(@BranchIds)), '') IS NULL
        THROW 51000, 'AGORA:MASTERS_BRANCH:Choose a branch. Tills are numbered per site: till 1 is a different till at every branch.', 1;

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

    SELECT t.BranchId,
           COALESCE(b.Name, CONCAT(N'Branch ', t.BranchId)) AS BranchName,
           CONVERT(NVARCHAR(200), t.TillNo) AS RowKey,
           t.TillNo,
           t.TillDescription,
           t.PosLocation,
           (SELECT MAX(d.TransactionDate) FROM agora.vw_DailyBanking d
            WHERE d.BranchId = t.BranchId AND d.TillNo = t.TillNo) AS LastCashup
    INTO #Rows
    FROM agora.vw_Till t
    JOIN @Visible v ON v.BranchId = t.BranchId
    LEFT JOIN agora.Branch b ON b.BranchId = t.BranchId
    WHERE (@Key IS NULL OR CONVERT(NVARCHAR(200), t.TillNo) = @Key)
      AND (@Search IS NULL OR t.TillDescription LIKE '%' + @Search + '%' OR t.PosLocation LIKE '%' + @Search + '%'
           OR CONVERT(NVARCHAR(12), t.TillNo) = @Search);

    SELECT r.* INTO #Filtered FROM #Rows r
    WHERE NOT EXISTS (
        SELECT 1 FROM @Filters f
        CROSS APPLY (SELECT
            CASE f.[Column] WHEN 'BranchName' THEN CONVERT(NVARCHAR(400), r.BranchName) WHEN 'TillDescription' THEN CONVERT(NVARCHAR(400), r.TillDescription) WHEN 'PosLocation' THEN CONVERT(NVARCHAR(400), r.PosLocation) END AS T,
            CASE f.[Column] WHEN 'TillNo' THEN CONVERT(DECIMAL(18, 4), r.TillNo) END AS N,
            CASE WHEN f.[Column] IN ('TillNo') THEN 1 ELSE 0 END AS IsNum,
            TRY_CONVERT(DECIMAL(18, 4), f.Value) AS V) v
        WHERE f.[Column] IN ('BranchName', 'TillDescription', 'PosLocation', 'TillNo')
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
        CASE WHEN @SortAsc = 1 THEN CASE @SortColumn WHEN 'BranchName' THEN CONVERT(NVARCHAR(400), BranchName) WHEN 'TillDescription' THEN CONVERT(NVARCHAR(400), TillDescription) WHEN 'PosLocation' THEN CONVERT(NVARCHAR(400), PosLocation) END END ASC,
        CASE WHEN @SortAsc = 0 THEN CASE @SortColumn WHEN 'BranchName' THEN CONVERT(NVARCHAR(400), BranchName) WHEN 'TillDescription' THEN CONVERT(NVARCHAR(400), TillDescription) WHEN 'PosLocation' THEN CONVERT(NVARCHAR(400), PosLocation) END END DESC,
        CASE WHEN @SortAsc = 1 THEN CASE @SortColumn WHEN 'TillNo' THEN CONVERT(DECIMAL(18, 4), TillNo) END END ASC,
        CASE WHEN @SortAsc = 0 THEN CASE @SortColumn WHEN 'TillNo' THEN CONVERT(DECIMAL(18, 4), TillNo) END END DESC,
        BranchName, TillNo
    OFFSET @Offset ROWS FETCH NEXT @PageSize ROWS ONLY;

    SELECT COUNT_BIG(*) AS TotalRows FROM #Filtered;
END
