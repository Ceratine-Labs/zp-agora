/* Setup → Sites and forecourt → Bank accounts (T022). Read-only.

   The account each site banks each method of payment into — the customer's
   own bankrecon.BankAccount (145 rows, September 2026), per Ryan's decision
   of 12 Nov 2026 (PM decision 01a1ceda-3523). BRN_BankAccountSetup is not
   read: its rows are daily copies of one account, last written in 2017.

   Rows marked IsDeleted are left out unless the Deleted filter asks for them
   (@FiltersJson on IsDeleted); they are listed, never hidden from an extract
   that asks.

   PER BRANCH. Refuses without @BranchIds (MASTERS_BRANCH). RowKey is the
   BankAccountId.

   @Authorized is masters.bankaccounts.view. The same filtered set drives the
   page, the total and the CSV extract. Read by
   Modules/Masters/Grids/BankAccountGrid. Deployed by v1__02pc. Reads
   agora.vw_MasterBankAccount. */
CREATE OR ALTER PROCEDURE agora.usp_Masters_GridBankAccounts
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
        THROW 51000, 'AGORA:MASTERS_DENIED:You do not have permission to see the bank accounts.', 1;
    IF NULLIF(LTRIM(RTRIM(@BranchIds)), '') IS NULL
        THROW 51000, 'AGORA:MASTERS_BRANCH:Choose a branch. Bank accounts are set up per site and method of payment.', 1;

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

    DECLARE @WithDeleted BIT = CASE WHEN EXISTS (SELECT 1 FROM @Filters WHERE [Column] = 'IsDeleted') THEN 1 ELSE 0 END;

    SELECT a.BranchId,
           COALESCE(b.Name, CONCAT(N'Branch ', a.BranchId)) AS BranchName,
           CONVERT(NVARCHAR(200), a.BankAccountId) AS RowKey,
           a.BankAccountId,
           a.PaymentType,
           a.MethodOfPaymentId,
           a.BankName,
           a.AccountName,
           a.AccountNumber,
           a.IsActive,
           a.IsDeleted,
           a.CreatedAt,
           a.ModifiedAt
    INTO #Rows
    FROM agora.vw_MasterBankAccount a
    JOIN @Visible v ON v.BranchId = a.BranchId
    LEFT JOIN agora.Branch b ON b.BranchId = a.BranchId
    WHERE (a.IsDeleted = 0 OR @WithDeleted = 1 OR @Key IS NOT NULL)
      AND (@Key IS NULL OR CONVERT(NVARCHAR(200), a.BankAccountId) = @Key)
      AND (@Search IS NULL OR a.AccountNumber LIKE '%' + @Search + '%' OR a.AccountName LIKE '%' + @Search + '%'
           OR a.BankName LIKE '%' + @Search + '%' OR a.PaymentType LIKE '%' + @Search + '%');

    SELECT r.* INTO #Filtered FROM #Rows r
    WHERE NOT EXISTS (
        SELECT 1 FROM @Filters f
        CROSS APPLY (SELECT
            CASE f.[Column] WHEN 'BranchName' THEN CONVERT(NVARCHAR(400), r.BranchName) WHEN 'BankName' THEN CONVERT(NVARCHAR(400), r.BankName) WHEN 'AccountName' THEN CONVERT(NVARCHAR(400), r.AccountName) WHEN 'AccountNumber' THEN CONVERT(NVARCHAR(400), r.AccountNumber) WHEN 'PaymentType' THEN r.PaymentType WHEN 'IsActive' THEN CASE WHEN r.IsActive = 1 THEN N'Yes' ELSE N'No' END WHEN 'IsDeleted' THEN CASE WHEN r.IsDeleted = 1 THEN N'Yes' ELSE N'No' END END AS T,
            CASE f.[Column] WHEN 'BankAccountId' THEN CONVERT(DECIMAL(18, 4), r.BankAccountId) WHEN 'MethodOfPaymentId' THEN CONVERT(DECIMAL(18, 4), r.MethodOfPaymentId) END AS N,
            CASE WHEN f.[Column] IN ('BankAccountId', 'MethodOfPaymentId') THEN 1 ELSE 0 END AS IsNum,
            TRY_CONVERT(DECIMAL(18, 4), f.Value) AS V) v
        WHERE f.[Column] IN ('BranchName', 'BankName', 'AccountName', 'AccountNumber', 'BankAccountId', 'MethodOfPaymentId', 'PaymentType', 'IsActive', 'IsDeleted')
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
        CASE WHEN @SortAsc = 1 THEN CASE @SortColumn WHEN 'BranchName' THEN CONVERT(NVARCHAR(400), BranchName) WHEN 'PaymentType' THEN CONVERT(NVARCHAR(400), PaymentType) WHEN 'BankName' THEN CONVERT(NVARCHAR(400), BankName) WHEN 'AccountName' THEN CONVERT(NVARCHAR(400), AccountName) WHEN 'AccountNumber' THEN CONVERT(NVARCHAR(400), AccountNumber) END END ASC,
        CASE WHEN @SortAsc = 0 THEN CASE @SortColumn WHEN 'BranchName' THEN CONVERT(NVARCHAR(400), BranchName) WHEN 'PaymentType' THEN CONVERT(NVARCHAR(400), PaymentType) WHEN 'BankName' THEN CONVERT(NVARCHAR(400), BankName) WHEN 'AccountName' THEN CONVERT(NVARCHAR(400), AccountName) WHEN 'AccountNumber' THEN CONVERT(NVARCHAR(400), AccountNumber) END END DESC,
        CASE WHEN @SortAsc = 1 THEN CASE @SortColumn WHEN 'BankAccountId' THEN CONVERT(DECIMAL(18, 4), BankAccountId) END END ASC,
        CASE WHEN @SortAsc = 0 THEN CASE @SortColumn WHEN 'BankAccountId' THEN CONVERT(DECIMAL(18, 4), BankAccountId) END END DESC,
        BranchName, PaymentType
    OFFSET @Offset ROWS FETCH NEXT @PageSize ROWS ONLY;

    SELECT COUNT_BIG(*) AS TotalRows FROM #Filtered;
END
