/* Setup → Trading rules → Account customers: the debtor master listing (T024).

   One row per account customer per site from PumpIT's BRN_Customers
   (agora.vw_PartnerCustomer), with what the till knows about the same account
   beside it. Read-only: nothing here writes anything, here or in PumpIT.

   TWO MASTERS FOR ONE ACCOUNT. BRN_Customers is PumpIT's list; DebtorID on it
   is the POS account number, which the till's own debtor master
   (DBF_CLDB_CUSTOMER, read through the Debtors module's agora.vw_DebtorAccount,
   already one row per account) is keyed on. The credit limit and the cell
   number are filled on the till's side and empty on PumpIT's (12 Nov 2026:
   286 till limits and 80 till cell numbers against 0 and 0 in BRN_Customers),
   so both are returned and the grid says which is which.

   WHERE A STATEMENT GOES is the Debtors module's agora.DebtorContact — Agora's
   own address list, edited on the debtor's statement. Read here, never
   written: this module does not keep a second copy of it.

   @IncludeControls = 1 adds the payment-control accounts (IsMOP — Cash
   Deposits, Zapper, Pumpit Control …, about 14 a site), which are how PumpIT
   books a method of payment, not trading partners.

   @BranchIds is the grid's branches selector, already narrowed to the
   caller's granted sites by App\Grid\GridService; NULL means every branch.
   Rows at a branch Agora does not know are left out by the join on
   agora.Branch.

   The same filtered set drives the page, the total and the CSV extract.
   Read by Modules/Partners/Grids/CustomerGrid. Deployed by v1__04p. */
CREATE OR ALTER PROCEDURE agora.usp_Partners_GridCustomers
    @BranchIds NVARCHAR(MAX) = NULL,
    @DateFrom DATE = NULL,
    @DateTo DATE = NULL,
    @Search NVARCHAR(200) = NULL,
    @SortColumn NVARCHAR(80) = NULL,
    @SortAsc BIT = 1,
    @Page INT = 1,
    @PageSize INT = 50,
    @FiltersJson NVARCHAR(MAX) = NULL,
    @IncludeControls BIT = 0
AS
BEGIN
    SET NOCOUNT ON;
    SET @Page = CASE WHEN ISNULL(@Page, 0) < 1 THEN 1 ELSE @Page END;
    SET @PageSize = CASE WHEN ISNULL(@PageSize, 0) BETWEEN 1 AND 100000 THEN @PageSize ELSE 50 END;
    SET @Search = NULLIF(LTRIM(RTRIM(@Search)), '');
    DECLARE @Offset BIGINT = CONVERT(BIGINT, @Page - 1) * @PageSize;

    DECLARE @Branches TABLE (BranchId INT PRIMARY KEY);
    INSERT INTO @Branches
    SELECT DISTINCT TRY_CONVERT(INT, value) FROM STRING_SPLIT(@BranchIds, ',')
    WHERE TRY_CONVERT(INT, value) IS NOT NULL;
    DECLARE @AllBranches BIT = CASE WHEN NULLIF(LTRIM(RTRIM(@BranchIds)), '') IS NULL THEN 1 ELSE 0 END;

    DECLARE @Filters TABLE ([Column] NVARCHAR(80), Op NVARCHAR(20), Value NVARCHAR(400), SetJson NVARCHAR(MAX));
    INSERT INTO @Filters
    SELECT JSON_VALUE(f.value, '$.column'), ISNULL(JSON_VALUE(f.value, '$.op'), 'contains'),
           JSON_VALUE(f.value, '$.value'), JSON_QUERY(f.value, '$.in')
    FROM OPENJSON(@FiltersJson) f;

    SELECT c.BranchId,
           b.Name AS BranchName,
           c.CustomerCode,
           c.CustomerName,
           c.AccountNo,
           c.GroupAccountNo,
           CASE WHEN c.IsPaymentControl = 1 THEN N'Payment control' ELSE N'Account' END AS Kind,
           c.IsPaymentControl,
           CONVERT(bit, CASE WHEN a.AccountNo IS NULL THEN 0 ELSE 1 END) AS OnTill,
           a.AccountName AS TillName,
           a.CreditLimit AS TillCreditLimit,
           c.CreditLimit,
           a.Cellphone AS TillCellNo,
           c.CellNo,
           COALESCE(a.Contact, c.ContactPerson) AS ContactPerson,
           ISNULL(a.IsBlocked, CONVERT(bit, 0)) AS IsBlocked,
           d.Email AS StatementEmail,
           ISNULL(d.AutoSend, CONVERT(bit, 0)) AS OnMonthlyRun,
           c.VatNo,
           c.GlCode,
           c.PosSystemId,
           c.CreateDateTime
    INTO #Customers
    FROM agora.vw_PartnerCustomer c
    JOIN agora.Branch b ON b.BranchId = c.BranchId
    LEFT JOIN agora.vw_DebtorAccount a ON a.BranchId = c.BranchId AND a.AccountNo = c.AccountNo
    LEFT JOIN agora.DebtorContact d ON d.BranchId = c.BranchId AND d.AccountNo = c.AccountNo
    WHERE (@AllBranches = 1 OR c.BranchId IN (SELECT BranchId FROM @Branches))
      AND (ISNULL(@IncludeControls, 0) = 1 OR c.IsPaymentControl = 0)
      AND (@Search IS NULL
           OR c.CustomerCode = @Search
           OR c.CustomerCode LIKE @Search + '%'
           OR c.CustomerName LIKE '%' + @Search + '%'
           OR c.AccountNo = @Search
           OR a.AccountName LIKE '%' + @Search + '%');

    SELECT x.* INTO #Filtered FROM #Customers x
    WHERE NOT EXISTS (
        SELECT 1 FROM @Filters f
        CROSS APPLY (SELECT CASE f.[Column]
            WHEN 'BranchName' THEN x.BranchName WHEN 'CustomerCode' THEN x.CustomerCode
            WHEN 'CustomerName' THEN x.CustomerName WHEN 'AccountNo' THEN x.AccountNo
            WHEN 'GroupAccountNo' THEN x.GroupAccountNo WHEN 'Kind' THEN x.Kind
            WHEN 'TillName' THEN x.TillName WHEN 'TillCellNo' THEN x.TillCellNo WHEN 'CellNo' THEN x.CellNo
            WHEN 'ContactPerson' THEN x.ContactPerson WHEN 'StatementEmail' THEN x.StatementEmail
            WHEN 'VatNo' THEN x.VatNo WHEN 'GlCode' THEN x.GlCode
            WHEN 'OnTill' THEN CASE WHEN x.OnTill = 1 THEN N'Yes' ELSE N'No' END
            WHEN 'IsBlocked' THEN CASE WHEN x.IsBlocked = 1 THEN N'Yes' ELSE N'No' END
            WHEN 'OnMonthlyRun' THEN CASE WHEN x.OnMonthlyRun = 1 THEN N'Yes' ELSE N'No' END END AS TextValue,
            CASE f.[Column] WHEN 'TillCreditLimit' THEN x.TillCreditLimit WHEN 'CreditLimit' THEN x.CreditLimit
                            WHEN 'PosSystemId' THEN x.PosSystemId END AS NumberValue) v
        WHERE (f.[Column] IN ('BranchName', 'CustomerCode', 'CustomerName', 'AccountNo', 'GroupAccountNo', 'TillName',
                              'TillCellNo', 'CellNo', 'ContactPerson', 'StatementEmail', 'VatNo', 'GlCode')
               AND f.SetJson IS NULL AND
              CASE WHEN f.Op = 'contains' AND v.TextValue LIKE '%' + f.Value + '%' THEN 1
                   WHEN f.Op = 'eq' AND v.TextValue = f.Value THEN 1
                   WHEN f.Op = 'ne' AND ISNULL(v.TextValue, N'') <> f.Value THEN 1
                   WHEN f.Op = 'gt' AND v.TextValue > f.Value THEN 1
                   WHEN f.Op = 'gte' AND v.TextValue >= f.Value THEN 1
                   WHEN f.Op = 'lt' AND v.TextValue < f.Value THEN 1
                   WHEN f.Op = 'lte' AND v.TextValue <= f.Value THEN 1 ELSE 0 END = 0)
           OR (f.SetJson IS NOT NULL AND f.[Column] IN ('BranchName', 'CustomerCode', 'CustomerName', 'AccountNo',
                                                       'GroupAccountNo', 'Kind', 'OnTill', 'IsBlocked', 'OnMonthlyRun', 'GlCode')
               AND NOT EXISTS (SELECT 1 FROM OPENJSON(f.SetJson) s WHERE s.value = v.TextValue))
           OR (f.[Column] IN ('TillCreditLimit', 'CreditLimit', 'PosSystemId') AND TRY_CONVERT(DECIMAL(18, 4), f.Value) IS NOT NULL AND
              CASE WHEN f.Op IN ('=', 'eq') AND v.NumberValue = TRY_CONVERT(DECIMAL(18, 4), f.Value) THEN 1
                   WHEN f.Op = 'ne' AND ISNULL(v.NumberValue, -1) <> TRY_CONVERT(DECIMAL(18, 4), f.Value) THEN 1
                   WHEN f.Op = 'gt' AND v.NumberValue > TRY_CONVERT(DECIMAL(18, 4), f.Value) THEN 1
                   WHEN f.Op = 'gte' AND v.NumberValue >= TRY_CONVERT(DECIMAL(18, 4), f.Value) THEN 1
                   WHEN f.Op = 'lt' AND v.NumberValue < TRY_CONVERT(DECIMAL(18, 4), f.Value) THEN 1
                   WHEN f.Op = 'lte' AND v.NumberValue <= TRY_CONVERT(DECIMAL(18, 4), f.Value) THEN 1 ELSE 0 END = 0)
    );

    SELECT * FROM #Filtered
    ORDER BY
        CASE WHEN @SortAsc = 1 THEN CASE @SortColumn WHEN 'BranchName' THEN BranchName WHEN 'CustomerCode' THEN CustomerCode
            WHEN 'CustomerName' THEN CustomerName WHEN 'AccountNo' THEN AccountNo WHEN 'Kind' THEN Kind
            WHEN 'TillName' THEN TillName WHEN 'StatementEmail' THEN StatementEmail END END ASC,
        CASE WHEN @SortAsc = 0 THEN CASE @SortColumn WHEN 'BranchName' THEN BranchName WHEN 'CustomerCode' THEN CustomerCode
            WHEN 'CustomerName' THEN CustomerName WHEN 'AccountNo' THEN AccountNo WHEN 'Kind' THEN Kind
            WHEN 'TillName' THEN TillName WHEN 'StatementEmail' THEN StatementEmail END END DESC,
        CASE WHEN @SortAsc = 1 THEN CASE @SortColumn WHEN 'TillCreditLimit' THEN TillCreditLimit WHEN 'CreditLimit' THEN CreditLimit END END ASC,
        CASE WHEN @SortAsc = 0 THEN CASE @SortColumn WHEN 'TillCreditLimit' THEN TillCreditLimit WHEN 'CreditLimit' THEN CreditLimit END END DESC,
        CASE WHEN @SortAsc = 1 AND @SortColumn = 'CreateDateTime' THEN CreateDateTime END ASC,
        CASE WHEN @SortAsc = 0 AND @SortColumn = 'CreateDateTime' THEN CreateDateTime END DESC,
        BranchName, CustomerCode, BranchId
    OFFSET @Offset ROWS FETCH NEXT @PageSize ROWS ONLY;

    SELECT COUNT_BIG(*) AS TotalRows FROM #Filtered;
END
