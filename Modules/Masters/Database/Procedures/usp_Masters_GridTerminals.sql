/* Setup → Sites and forecourt → Merchant and terminal numbers (T022).
   Read-only.

   The numbers a site's settlements arrive under, from three PumpIT tables:
     ABSA merchant          SS_Branch_ABSA_MerchantNo        (62, 12 Nov 2026)
     Smart ATM terminal     SS_Branch_SMARTATM_TerminalNo    (46)
     Cash device terminal   SS_Branch_CASHDEVICE_TerminalNo  (10)
   unioned in agora.vw_MasterTerminal. The cash-device table records no
   active flag, so Active reads "Not recorded" there rather than claiming one.

   PER BRANCH. Refuses without @BranchIds (MASTERS_BRANCH). RowKey is
   TerminalKey ('ABSA:12', 'SMARTATM:3', 'CASHDEVICE:7'): the kind and that
   table's own id.

   @Authorized is masters.terminals.view. The same filtered set drives the
   page, the total and the CSV extract. Read by
   Modules/Masters/Grids/TerminalGrid. Deployed by v1__02pa. Reads
   agora.vw_MasterTerminal. */
CREATE OR ALTER PROCEDURE agora.usp_Masters_GridTerminals
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
        THROW 51000, 'AGORA:MASTERS_DENIED:You do not have permission to see the merchant and terminal numbers.', 1;
    IF NULLIF(LTRIM(RTRIM(@BranchIds)), '') IS NULL
        THROW 51000, 'AGORA:MASTERS_BRANCH:Choose a branch. Merchant and terminal numbers belong to a site.', 1;

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
           CONVERT(NVARCHAR(200), t.TerminalKey) AS RowKey,
           t.Kind,
           t.Number,
           t.[Description],
           CASE WHEN t.IsActive IS NULL THEN N'Not recorded' WHEN t.IsActive = 1 THEN N'Yes' ELSE N'No' END AS Active
    INTO #Rows
    FROM agora.vw_MasterTerminal t
    JOIN @Visible v ON v.BranchId = t.BranchId
    LEFT JOIN agora.Branch b ON b.BranchId = t.BranchId
    WHERE (@Key IS NULL OR t.TerminalKey = @Key)
      AND (@Search IS NULL OR t.Number LIKE '%' + @Search + '%' OR t.[Description] LIKE '%' + @Search + '%');

    SELECT r.* INTO #Filtered FROM #Rows r
    WHERE NOT EXISTS (
        SELECT 1 FROM @Filters f
        CROSS APPLY (SELECT
            CASE f.[Column] WHEN 'BranchName' THEN CONVERT(NVARCHAR(400), r.BranchName) WHEN 'Number' THEN CONVERT(NVARCHAR(400), r.Number) WHEN 'Description' THEN CONVERT(NVARCHAR(400), r.Description) WHEN 'Kind' THEN r.Kind WHEN 'Active' THEN r.Active END AS T,
            CONVERT(DECIMAL(18, 4), NULL) AS N,
            CASE WHEN f.[Column] IN ('') THEN 1 ELSE 0 END AS IsNum,
            TRY_CONVERT(DECIMAL(18, 4), f.Value) AS V) v
        WHERE f.[Column] IN ('BranchName', 'Number', 'Description', 'Kind', 'Active')
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
        CASE WHEN @SortAsc = 1 THEN CASE @SortColumn WHEN 'BranchName' THEN CONVERT(NVARCHAR(400), BranchName) WHEN 'Kind' THEN CONVERT(NVARCHAR(400), Kind) WHEN 'Number' THEN CONVERT(NVARCHAR(400), Number) WHEN 'Description' THEN CONVERT(NVARCHAR(400), Description) WHEN 'Active' THEN CONVERT(NVARCHAR(400), Active) END END ASC,
        CASE WHEN @SortAsc = 0 THEN CASE @SortColumn WHEN 'BranchName' THEN CONVERT(NVARCHAR(400), BranchName) WHEN 'Kind' THEN CONVERT(NVARCHAR(400), Kind) WHEN 'Number' THEN CONVERT(NVARCHAR(400), Number) WHEN 'Description' THEN CONVERT(NVARCHAR(400), Description) WHEN 'Active' THEN CONVERT(NVARCHAR(400), Active) END END DESC,
        BranchName, Kind, Number
    OFFSET @Offset ROWS FETCH NEXT @PageSize ROWS ONLY;

    SELECT COUNT_BIG(*) AS TotalRows FROM #Filtered;
END
