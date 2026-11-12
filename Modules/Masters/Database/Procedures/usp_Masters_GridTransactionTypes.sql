/* Setup → Sites and forecourt → Transaction types (T022). Read-only.

   The purchase-request transaction types — PumpIT's BRN_TransactionType,
   one row per (branch, TypeCode): 62 on 12 Nov 2026, two types (01 cash,
   02 credit) at each of 31 branches. NumberPrefix (AlpNum) starts the
   document number and LastNumber (HighNum) is the last one issued under it,
   per site.

   PER BRANCH. Refuses without @BranchIds (MASTERS_BRANCH). RowKey is the
   TypeCode.

   @Authorized is masters.transactiontypes.view. The same filtered set drives
   the page, the total and the CSV extract. Read by
   Modules/Masters/Grids/TransactionTypeGrid. Deployed by v1__02pa. Reads
   agora.vw_MasterTransactionType. */
CREATE OR ALTER PROCEDURE agora.usp_Masters_GridTransactionTypes
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
        THROW 51000, 'AGORA:MASTERS_DENIED:You do not have permission to see the transaction types.', 1;
    IF NULLIF(LTRIM(RTRIM(@BranchIds)), '') IS NULL
        THROW 51000, 'AGORA:MASTERS_BRANCH:Choose a branch. Transaction types are kept per site, each with its own document counter.', 1;

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
           CONVERT(NVARCHAR(200), t.TypeCode) AS RowKey,
           t.TypeCode,
           t.TypeDescription,
           t.NumberPrefix,
           t.LastNumber,
           t.CreateDateTime
    INTO #Rows
    FROM agora.vw_MasterTransactionType t
    JOIN @Visible v ON v.BranchId = t.BranchId
    LEFT JOIN agora.Branch b ON b.BranchId = t.BranchId
    WHERE (@Key IS NULL OR CONVERT(NVARCHAR(200), t.TypeCode) = @Key)
      AND (@Search IS NULL OR t.TypeDescription LIKE '%' + @Search + '%' OR t.TypeCode = @Search OR t.NumberPrefix = @Search);

    SELECT r.* INTO #Filtered FROM #Rows r
    WHERE NOT EXISTS (
        SELECT 1 FROM @Filters f
        CROSS APPLY (SELECT
            CASE f.[Column] WHEN 'BranchName' THEN CONVERT(NVARCHAR(400), r.BranchName) WHEN 'TypeCode' THEN CONVERT(NVARCHAR(400), r.TypeCode) WHEN 'TypeDescription' THEN CONVERT(NVARCHAR(400), r.TypeDescription) WHEN 'NumberPrefix' THEN CONVERT(NVARCHAR(400), r.NumberPrefix) END AS T,
            CASE f.[Column] WHEN 'LastNumber' THEN CONVERT(DECIMAL(18, 4), r.LastNumber) END AS N,
            CASE WHEN f.[Column] IN ('LastNumber') THEN 1 ELSE 0 END AS IsNum,
            TRY_CONVERT(DECIMAL(18, 4), f.Value) AS V) v
        WHERE f.[Column] IN ('BranchName', 'TypeCode', 'TypeDescription', 'NumberPrefix', 'LastNumber')
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
        CASE WHEN @SortAsc = 1 THEN CASE @SortColumn WHEN 'BranchName' THEN CONVERT(NVARCHAR(400), BranchName) WHEN 'TypeCode' THEN CONVERT(NVARCHAR(400), TypeCode) WHEN 'TypeDescription' THEN CONVERT(NVARCHAR(400), TypeDescription) WHEN 'NumberPrefix' THEN CONVERT(NVARCHAR(400), NumberPrefix) END END ASC,
        CASE WHEN @SortAsc = 0 THEN CASE @SortColumn WHEN 'BranchName' THEN CONVERT(NVARCHAR(400), BranchName) WHEN 'TypeCode' THEN CONVERT(NVARCHAR(400), TypeCode) WHEN 'TypeDescription' THEN CONVERT(NVARCHAR(400), TypeDescription) WHEN 'NumberPrefix' THEN CONVERT(NVARCHAR(400), NumberPrefix) END END DESC,
        CASE WHEN @SortAsc = 1 THEN CASE @SortColumn WHEN 'LastNumber' THEN CONVERT(DECIMAL(18, 4), LastNumber) END END ASC,
        CASE WHEN @SortAsc = 0 THEN CASE @SortColumn WHEN 'LastNumber' THEN CONVERT(DECIMAL(18, 4), LastNumber) END END DESC,
        BranchName, TypeCode
    OFFSET @Offset ROWS FETCH NEXT @PageSize ROWS ONLY;

    SELECT COUNT_BIG(*) AS TotalRows FROM #Filtered;
END
