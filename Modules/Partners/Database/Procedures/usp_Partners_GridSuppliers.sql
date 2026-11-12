/* Setup → Trading rules → Suppliers: the supplier (creditor) master listing (T024).

   One row per supplier per site from PumpIT's BRN_Suppliers
   (agora.vw_PartnerSupplier). The list is group-wide — the same code is the
   same supplier at every site — but each site's copy carries its own POS
   account number and its own cash, stock and active flags, so a row is
   (site, code), exactly as PumpIT keys it. Read-only: nothing here writes
   anything, here or in PumpIT.

   Rows at a branch Agora does not know are left out by the join on
   agora.Branch — on 12 Nov 2026 that is the 32 malformed rows at SSBranchId 0.

   Code series is the first three letters of the code: APS (mostly stock
   suppliers) and APE (mostly expense creditors), measured 12 Nov 2026; anything
   else is 'Other'.

   @BranchIds is the grid's branches selector, already narrowed to the
   caller's granted sites by App\Grid\GridService; NULL means every branch.

   The same filtered set drives the page, the total and the CSV extract.
   Read by Modules/Partners/Grids/SupplierGrid. Deployed by v1__04p. */
CREATE OR ALTER PROCEDURE agora.usp_Partners_GridSuppliers
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

    SELECT s.BranchId,
           b.Name AS BranchName,
           s.SupplierCode,
           s.SupplierName,
           CASE WHEN s.SupplierCode LIKE 'APS-%' THEN N'APS'
                WHEN s.SupplierCode LIKE 'APE-%' THEN N'APE'
                ELSE N'Other' END AS CodeSeries,
           s.AccountNo,
           s.IsStock,
           s.IsCash,
           s.IsActive,
           CASE WHEN s.IsActive = 0 THEN N'Inactive' ELSE N'Active' END AS Status,
           s.CashOrCredit,
           s.ContactPerson,
           s.Department,
           s.Telephone,
           s.CellNo,
           s.EmailAddress,
           s.PosSystemId,
           s.IsSdkLoaded,
           s.CreateDateTime
    INTO #Suppliers
    FROM agora.vw_PartnerSupplier s
    JOIN agora.Branch b ON b.BranchId = s.BranchId
    WHERE (@AllBranches = 1 OR s.BranchId IN (SELECT BranchId FROM @Branches))
      AND (@Search IS NULL
           OR s.SupplierCode = @Search
           OR s.SupplierCode LIKE @Search + '%'
           OR s.SupplierName LIKE '%' + @Search + '%'
           OR s.AccountNo = @Search
           OR s.ContactPerson LIKE '%' + @Search + '%');

    SELECT x.* INTO #Filtered FROM #Suppliers x
    WHERE NOT EXISTS (
        SELECT 1 FROM @Filters f
        CROSS APPLY (SELECT CASE f.[Column]
            WHEN 'BranchName' THEN x.BranchName WHEN 'SupplierCode' THEN x.SupplierCode
            WHEN 'SupplierName' THEN x.SupplierName WHEN 'CodeSeries' THEN x.CodeSeries
            WHEN 'AccountNo' THEN x.AccountNo WHEN 'Status' THEN x.Status
            WHEN 'CashOrCredit' THEN x.CashOrCredit WHEN 'ContactPerson' THEN x.ContactPerson
            WHEN 'Department' THEN x.Department WHEN 'Telephone' THEN x.Telephone
            WHEN 'CellNo' THEN x.CellNo WHEN 'EmailAddress' THEN x.EmailAddress
            WHEN 'IsStock' THEN CASE WHEN x.IsStock = 1 THEN N'Yes' ELSE N'No' END
            WHEN 'IsCash' THEN CASE WHEN x.IsCash = 1 THEN N'Yes' ELSE N'No' END
            WHEN 'IsSdkLoaded' THEN CASE WHEN x.IsSdkLoaded = 1 THEN N'Yes' ELSE N'No' END END AS TextValue,
            CASE f.[Column] WHEN 'PosSystemId' THEN x.PosSystemId END AS NumberValue) v
        WHERE (f.[Column] IN ('BranchName', 'SupplierCode', 'SupplierName', 'AccountNo', 'CashOrCredit',
                              'ContactPerson', 'Department', 'Telephone', 'CellNo', 'EmailAddress')
               AND f.SetJson IS NULL AND
              CASE WHEN f.Op = 'contains' AND v.TextValue LIKE '%' + f.Value + '%' THEN 1
                   WHEN f.Op = 'eq' AND v.TextValue = f.Value THEN 1
                   WHEN f.Op = 'ne' AND ISNULL(v.TextValue, N'') <> f.Value THEN 1
                   WHEN f.Op = 'gt' AND v.TextValue > f.Value THEN 1
                   WHEN f.Op = 'gte' AND v.TextValue >= f.Value THEN 1
                   WHEN f.Op = 'lt' AND v.TextValue < f.Value THEN 1
                   WHEN f.Op = 'lte' AND v.TextValue <= f.Value THEN 1 ELSE 0 END = 0)
           OR (f.SetJson IS NOT NULL AND f.[Column] IN ('BranchName', 'SupplierCode', 'SupplierName', 'CodeSeries',
                                                       'AccountNo', 'Status', 'CashOrCredit', 'Department',
                                                       'IsStock', 'IsCash', 'IsSdkLoaded')
               AND NOT EXISTS (SELECT 1 FROM OPENJSON(f.SetJson) s WHERE s.value = v.TextValue))
           OR (f.[Column] = 'PosSystemId' AND TRY_CONVERT(DECIMAL(18, 4), f.Value) IS NOT NULL AND
              CASE WHEN f.Op IN ('=', 'eq') AND v.NumberValue = TRY_CONVERT(DECIMAL(18, 4), f.Value) THEN 1
                   WHEN f.Op = 'ne' AND ISNULL(v.NumberValue, -1) <> TRY_CONVERT(DECIMAL(18, 4), f.Value) THEN 1
                   WHEN f.Op = 'gt' AND v.NumberValue > TRY_CONVERT(DECIMAL(18, 4), f.Value) THEN 1
                   WHEN f.Op = 'gte' AND v.NumberValue >= TRY_CONVERT(DECIMAL(18, 4), f.Value) THEN 1
                   WHEN f.Op = 'lt' AND v.NumberValue < TRY_CONVERT(DECIMAL(18, 4), f.Value) THEN 1
                   WHEN f.Op = 'lte' AND v.NumberValue <= TRY_CONVERT(DECIMAL(18, 4), f.Value) THEN 1 ELSE 0 END = 0)
    );

    SELECT * FROM #Filtered
    ORDER BY
        CASE WHEN @SortAsc = 1 THEN CASE @SortColumn WHEN 'BranchName' THEN BranchName WHEN 'SupplierName' THEN SupplierName
            WHEN 'AccountNo' THEN AccountNo WHEN 'Department' THEN Department WHEN 'ContactPerson' THEN ContactPerson
            WHEN 'Status' THEN Status WHEN 'CodeSeries' THEN CodeSeries END END ASC,
        CASE WHEN @SortAsc = 0 THEN CASE @SortColumn WHEN 'BranchName' THEN BranchName WHEN 'SupplierName' THEN SupplierName
            WHEN 'AccountNo' THEN AccountNo WHEN 'Department' THEN Department WHEN 'ContactPerson' THEN ContactPerson
            WHEN 'Status' THEN Status WHEN 'CodeSeries' THEN CodeSeries END END DESC,
        CASE WHEN @SortAsc = 1 AND @SortColumn = 'PosSystemId' THEN PosSystemId END ASC,
        CASE WHEN @SortAsc = 0 AND @SortColumn = 'PosSystemId' THEN PosSystemId END DESC,
        CASE WHEN @SortAsc = 1 AND @SortColumn = 'CreateDateTime' THEN CreateDateTime END ASC,
        CASE WHEN @SortAsc = 0 AND @SortColumn = 'CreateDateTime' THEN CreateDateTime END DESC,
        CASE WHEN @SortAsc = 0 AND (@SortColumn IS NULL OR @SortColumn = 'SupplierCode') THEN SupplierCode END DESC,
        SupplierCode, BranchName, BranchId
    OFFSET @Offset ROWS FETCH NEXT @PageSize ROWS ONLY;

    SELECT COUNT_BIG(*) AS TotalRows FROM #Filtered;
END
