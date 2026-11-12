/* Setup → Trading rules → Expense codes (T022). Read-only.

   Expense codes, their GL account, VAT treatment, asset flag and who
   approves them — PumpIT's BRN_Expenses, one row per (branch, ExpenseNo):
   4,346 rows over all 31 branches on 12 Nov 2026 (the plan said 3,839).

   The approver is the join that decides whose queue a purchase request lands
   in. On 12 Nov 2026 three people approved every code on the estate, one of
   them 3,565 of the 4,346. IsAsset is 0 on every row — codes named "… ASSET"
   included — so the flag cannot yet tell an asset purchase from an expense.

   PER BRANCH. Refuses without @BranchIds (MASTERS_BRANCH). RowKey is the
   ExpenseNo.

   @Authorized is masters.expenses.view. The same filtered set drives the
   page, the total and the CSV extract. Read by
   Modules/Masters/Grids/ExpenseGrid. Deployed by v1__02pa. Reads
   agora.vw_Expense and agora.vw_ErpUser. */
CREATE OR ALTER PROCEDURE agora.usp_Masters_GridExpenses
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
        THROW 51000, 'AGORA:MASTERS_DENIED:You do not have permission to see the expense codes.', 1;
    IF NULLIF(LTRIM(RTRIM(@BranchIds)), '') IS NULL
        THROW 51000, 'AGORA:MASTERS_BRANCH:Choose a branch. Expense codes are kept per site: expense 60 at one branch is not expense 60 at another.', 1;

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

    SELECT e.BranchId,
           COALESCE(b.Name, CONCAT(N'Branch ', e.BranchId)) AS BranchName,
           CONVERT(NVARCHAR(200), e.ExpenseNo) AS RowKey,
           e.ExpenseNo,
           e.ExpenseDescription,
           e.GLCode,
           e.Vat,
           e.ApproverUserId,
           COALESCE(u.UserName, CONCAT(N'PumpIT user ', e.ApproverUserId)) AS ApproverName,
           CONVERT(BIT, ISNULL(e.IsAsset, 0)) AS IsAsset,
           CONVERT(BIT, ISNULL(e.IsActive, 0)) AS IsActive
    INTO #Rows
    FROM agora.vw_Expense e
    JOIN @Visible v ON v.BranchId = e.BranchId
    LEFT JOIN agora.Branch b ON b.BranchId = e.BranchId
    LEFT JOIN agora.vw_ErpUser u ON u.ErpUserId = e.ApproverUserId
    WHERE (@Key IS NULL OR CONVERT(NVARCHAR(200), e.ExpenseNo) = @Key)
      AND (@Search IS NULL OR e.ExpenseDescription LIKE '%' + @Search + '%' OR e.GLCode LIKE '%' + @Search + '%'
           OR u.UserName LIKE '%' + @Search + '%' OR CONVERT(NVARCHAR(12), e.ExpenseNo) = @Search);

    SELECT r.* INTO #Filtered FROM #Rows r
    WHERE NOT EXISTS (
        SELECT 1 FROM @Filters f
        CROSS APPLY (SELECT
            CASE f.[Column] WHEN 'BranchName' THEN CONVERT(NVARCHAR(400), r.BranchName) WHEN 'ExpenseDescription' THEN CONVERT(NVARCHAR(400), r.ExpenseDescription) WHEN 'GLCode' THEN CONVERT(NVARCHAR(400), r.GLCode) WHEN 'ApproverName' THEN CONVERT(NVARCHAR(400), r.ApproverName) WHEN 'IsAsset' THEN CASE WHEN r.IsAsset = 1 THEN N'Yes' ELSE N'No' END WHEN 'IsActive' THEN CASE WHEN r.IsActive = 1 THEN N'Yes' ELSE N'No' END END AS T,
            CASE f.[Column] WHEN 'ExpenseNo' THEN CONVERT(DECIMAL(18, 4), r.ExpenseNo) WHEN 'Vat' THEN CONVERT(DECIMAL(18, 4), r.Vat) WHEN 'ApproverUserId' THEN CONVERT(DECIMAL(18, 4), r.ApproverUserId) END AS N,
            CASE WHEN f.[Column] IN ('ExpenseNo', 'Vat', 'ApproverUserId') THEN 1 ELSE 0 END AS IsNum,
            TRY_CONVERT(DECIMAL(18, 4), f.Value) AS V) v
        WHERE f.[Column] IN ('BranchName', 'ExpenseDescription', 'GLCode', 'ApproverName', 'ExpenseNo', 'Vat', 'ApproverUserId', 'IsAsset', 'IsActive')
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
        CASE WHEN @SortAsc = 1 THEN CASE @SortColumn WHEN 'BranchName' THEN CONVERT(NVARCHAR(400), BranchName) WHEN 'ExpenseDescription' THEN CONVERT(NVARCHAR(400), ExpenseDescription) WHEN 'GLCode' THEN CONVERT(NVARCHAR(400), GLCode) WHEN 'ApproverName' THEN CONVERT(NVARCHAR(400), ApproverName) END END ASC,
        CASE WHEN @SortAsc = 0 THEN CASE @SortColumn WHEN 'BranchName' THEN CONVERT(NVARCHAR(400), BranchName) WHEN 'ExpenseDescription' THEN CONVERT(NVARCHAR(400), ExpenseDescription) WHEN 'GLCode' THEN CONVERT(NVARCHAR(400), GLCode) WHEN 'ApproverName' THEN CONVERT(NVARCHAR(400), ApproverName) END END DESC,
        CASE WHEN @SortAsc = 1 THEN CASE @SortColumn WHEN 'ExpenseNo' THEN CONVERT(DECIMAL(18, 4), ExpenseNo) WHEN 'Vat' THEN CONVERT(DECIMAL(18, 4), Vat) END END ASC,
        CASE WHEN @SortAsc = 0 THEN CASE @SortColumn WHEN 'ExpenseNo' THEN CONVERT(DECIMAL(18, 4), ExpenseNo) WHEN 'Vat' THEN CONVERT(DECIMAL(18, 4), Vat) END END DESC,
        BranchName, ExpenseNo
    OFFSET @Offset ROWS FETCH NEXT @PageSize ROWS ONLY;

    SELECT COUNT_BIG(*) AS TotalRows FROM #Filtered;
END
