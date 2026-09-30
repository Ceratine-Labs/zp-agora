/* ============================================================================
   agora.usp_Cash_GridStockLossSummary

   WHAT IT IS FOR
   Head office → Deductions → Stock Loss By Employee — Summary (T041, tab 2):
   PumpIT's "Stock Loss Report By Employee" (SS_Report 564, category PAYROLL,
   dbo.sp_RPT_EmployeeStockLossReportPAYROLL @ShowLosses = 1), row for row:
   one row per person and counting area, their share of every recon line that
   lost stock over the period, at the price the line was selling at.

   The loss is attributed to whoever was signed on to the area at the count,
   and shared equally when several were. High Shrinkage Items and the Lotto /
   Electricity / Airtime areas are not here (the latter are the non-integrated
   tabs). The arithmetic is agora.usp_Cash_DeductionStockLoss (@Kind 'stock',
   @Grain 'day' — the summary procedure's grouping), which the detail tab and
   the payroll build call too.

   The grid procedure template (feature-rules §2), plus @AllowedBranchIds. With
   no dates it reads the last full calendar month.

   Result sets: 1 the page, 2 (TotalRows), 3 grand totals.

   READ-ONLY.

     EXEC agora.usp_Cash_GridStockLossSummary @BranchIds = '21', @DateFrom = '2026-09-01', @DateTo = '2026-09-30';
   ============================================================================ */
CREATE OR ALTER PROCEDURE [agora].[usp_Cash_GridStockLossSummary]
    @BranchIds         NVARCHAR(MAX) = NULL,
    @DateFrom          DATE          = NULL,
    @DateTo            DATE          = NULL,
    @Search            NVARCHAR(200) = NULL,
    @SortColumn        NVARCHAR(80)  = NULL,
    @SortAsc           BIT           = 1,
    @Page              INT           = 1,
    @PageSize          INT           = 50,
    @FiltersJson       NVARCHAR(MAX) = NULL,
    @AllowedBranchIds  NVARCHAR(MAX) = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    SET @Page     = CASE WHEN ISNULL(@Page, 1) < 1 THEN 1 ELSE @Page END;
    SET @PageSize = CASE WHEN ISNULL(@PageSize, 50) BETWEEN 1 AND 100000 THEN @PageSize ELSE 50 END;
    SET @Search   = NULLIF(LTRIM(RTRIM(ISNULL(@Search, ''))), '');
    SET @DateTo   = ISNULL(@DateTo, EOMONTH(DATEADD(month, -1, CONVERT(DATE, GETDATE()))));
    SET @DateFrom = ISNULL(@DateFrom, DATEFROMPARTS(YEAR(@DateTo), MONTH(@DateTo), 1));

    DECLARE @Scope NVARCHAR(MAX) = NULLIF(LTRIM(RTRIM(ISNULL(@BranchIds, ''))), '');
    IF @Scope IS NULL SET @Scope = NULLIF(LTRIM(RTRIM(ISNULL(@AllowedBranchIds, ''))), '');

    IF OBJECT_ID('tempdb..#StockLoss') IS NOT NULL DROP TABLE #StockLoss;
    CREATE TABLE #StockLoss (
        BranchId INT, TransactionDate DATE, ShiftNo INT NULL, AreaNo INT,
        EmployeeCode NVARCHAR(22) COLLATE DATABASE_DEFAULT, StockItemNo NVARCHAR(20) COLLATE DATABASE_DEFAULT, EmployeeCount INT,
        LossF FLOAT NULL, LossD DECIMAL(38,13) NULL);

    EXEC agora.usp_Cash_DeductionStockLoss @BranchIds = @Scope, @DateFrom = @DateFrom, @DateTo = @DateTo,
         @Kind = 'stock', @Grain = 'day';

    SELECT
        s.BranchId,
        b.Name AS BranchName,
        s.EmployeeCode,
        NULLIF(LTRIM(RTRIM(ISNULL(m.NickName, '') + ' ' + ISNULL(m.Surname, ''))), '') AS EmployeeName,
        p.PostDescription,
        s.AreaNo,
        r.AreaDescription,
        r.AreaGroup,
        CONVERT(INT, COUNT(*)) AS Lines,
        CONVERT(DECIMAL(10,2), SUM(s.LossF)) AS EmployeeTotalLoss
    INTO #sl_rows
    FROM #StockLoss s
    OUTER APPLY (SELECT TOP 1 x.Name FROM agora.Branch x WHERE x.BranchId = s.BranchId ORDER BY x.Id) b
    OUTER APPLY (SELECT TOP 1 x.NickName, x.Surname, x.PostNo FROM agora.vw_Employee x WHERE x.BranchId = s.BranchId AND x.EmployeeCode = s.EmployeeCode) m
    OUTER APPLY (SELECT TOP 1 x.PostDescription FROM agora.vw_EmployeePost x WHERE x.BranchId = s.BranchId AND x.PostNo = m.PostNo) p
    OUTER APPLY (SELECT TOP 1 x.AreaDescription, x.AreaGroup FROM agora.vw_StockArea x WHERE x.BranchId = s.BranchId AND x.AreaNo = s.AreaNo) r
    GROUP BY s.BranchId, b.Name, s.EmployeeCode, m.NickName, m.Surname, p.PostDescription, s.AreaNo, r.AreaDescription, r.AreaGroup;

    IF @Search IS NOT NULL
        DELETE FROM #sl_rows
        WHERE NOT (   ISNULL(BranchName, '')      LIKE '%' + @Search + '%'
                   OR ISNULL(EmployeeName, '')    LIKE '%' + @Search + '%'
                   OR ISNULL(EmployeeCode, '')    LIKE '%' + @Search + '%'
                   OR ISNULL(AreaDescription, '') LIKE '%' + @Search + '%'
                   OR ISNULL(PostDescription, '') LIKE '%' + @Search + '%');

    DECLARE @Filter    TABLE ([Column] NVARCHAR(80) COLLATE DATABASE_DEFAULT, [Type] NVARCHAR(10) COLLATE DATABASE_DEFAULT, [Op] NVARCHAR(20) COLLATE DATABASE_DEFAULT, [Value] NVARCHAR(400) COLLATE DATABASE_DEFAULT);
    DECLARE @FilterSet TABLE ([Column] NVARCHAR(80) COLLATE DATABASE_DEFAULT, [Value] NVARCHAR(400) COLLATE DATABASE_DEFAULT);

    IF @FiltersJson IS NOT NULL AND ISJSON(@FiltersJson) = 1
    BEGIN
        INSERT INTO @Filter ([Column], [Type], [Op], [Value])
        SELECT JSON_VALUE(f.value, '$.column'), ISNULL(JSON_VALUE(f.value, '$.type'), 'text'),
               ISNULL(JSON_VALUE(f.value, '$.op'), 'contains'), JSON_VALUE(f.value, '$.value')
        FROM OPENJSON(@FiltersJson) f
        WHERE JSON_VALUE(f.value, '$.value') IS NOT NULL AND ISNULL(JSON_VALUE(f.value, '$.type'), 'text') <> 'set';

        INSERT INTO @FilterSet ([Column], [Value])
        SELECT JSON_VALUE(f.value, '$.column'), v.value
        FROM OPENJSON(@FiltersJson) f
        CROSS APPLY OPENJSON(f.value, '$.in') v
        WHERE JSON_VALUE(f.value, '$.type') = 'set';
    END

    DELETE r
    FROM #sl_rows r
    WHERE EXISTS (
        SELECT 1
        FROM @Filter f
        CROSS APPLY (SELECT
            CASE f.[Column]
                WHEN 'BranchName'      THEN r.BranchName
                WHEN 'EmployeeName'    THEN r.EmployeeName
                WHEN 'EmployeeCode'    THEN r.EmployeeCode
                WHEN 'PostDescription' THEN r.PostDescription
                WHEN 'AreaDescription' THEN r.AreaDescription
                WHEN 'AreaGroup'       THEN r.AreaGroup
            END AS T,
            CASE f.[Column]
                WHEN 'AreaNo'            THEN CONVERT(DECIMAL(19,4), r.AreaNo)
                WHEN 'Lines'             THEN CONVERT(DECIMAL(19,4), r.Lines)
                WHEN 'EmployeeTotalLoss' THEN CONVERT(DECIMAL(19,4), r.EmployeeTotalLoss)
            END AS N,
            TRY_CONVERT(DECIMAL(19,4), f.[Value]) AS FN
        ) v
        WHERE 1 = CASE
            WHEN f.[Type] = 'number' THEN CASE
                WHEN v.N IS NULL OR v.FN IS NULL THEN 1
                WHEN f.[Op] = 'ne'  AND v.N <> v.FN THEN 0
                WHEN f.[Op] = 'gt'  AND v.N >  v.FN THEN 0
                WHEN f.[Op] = 'gte' AND v.N >= v.FN THEN 0
                WHEN f.[Op] = 'lt'  AND v.N <  v.FN THEN 0
                WHEN f.[Op] = 'lte' AND v.N <= v.FN THEN 0
                WHEN f.[Op] NOT IN ('ne', 'gt', 'gte', 'lt', 'lte') AND v.N = v.FN THEN 0
                ELSE 1 END
            ELSE CASE
                WHEN v.T IS NULL THEN 1
                WHEN f.[Op] = 'eq' AND v.T =  f.[Value] THEN 0
                WHEN f.[Op] = 'ne' AND v.T <> f.[Value] THEN 0
                WHEN f.[Op] NOT IN ('eq', 'ne') AND v.T LIKE '%' + f.[Value] + '%' THEN 0
                ELSE 1 END
        END
    )
    OR EXISTS (
        SELECT 1
        FROM (SELECT DISTINCT [Column] FROM @FilterSet) c
        WHERE NOT EXISTS (
            SELECT 1 FROM @FilterSet s
            WHERE s.[Column] = c.[Column]
              AND s.[Value] = CASE c.[Column]
                                  WHEN 'BranchName' THEN r.BranchName
                                  WHEN 'AreaGroup'  THEN r.AreaGroup
                              END)
    );

    DECLARE @Offset INT = (@Page - 1) * @PageSize;

    SELECT *
    FROM #sl_rows
    ORDER BY
        CASE WHEN @SortAsc = 1 THEN
            CASE @SortColumn
                WHEN 'BranchName'      THEN BranchName
                WHEN 'EmployeeName'    THEN EmployeeName
                WHEN 'EmployeeCode'    THEN EmployeeCode
                WHEN 'PostDescription' THEN PostDescription
                WHEN 'AreaDescription' THEN AreaDescription
                WHEN 'AreaGroup'       THEN AreaGroup
            END
        END ASC,
        CASE WHEN @SortAsc = 0 THEN
            CASE @SortColumn
                WHEN 'BranchName'      THEN BranchName
                WHEN 'EmployeeName'    THEN EmployeeName
                WHEN 'EmployeeCode'    THEN EmployeeCode
                WHEN 'PostDescription' THEN PostDescription
                WHEN 'AreaDescription' THEN AreaDescription
                WHEN 'AreaGroup'       THEN AreaGroup
            END
        END DESC,
        CASE WHEN @SortAsc = 1 THEN
            CASE @SortColumn
                WHEN 'EmployeeTotalLoss' THEN CONVERT(DECIMAL(19,4), EmployeeTotalLoss)
                WHEN 'Lines'             THEN CONVERT(DECIMAL(19,4), Lines)
                WHEN 'AreaNo'            THEN CONVERT(DECIMAL(19,4), AreaNo)
            END
        END ASC,
        CASE WHEN @SortAsc = 0 THEN
            CASE @SortColumn
                WHEN 'EmployeeTotalLoss' THEN CONVERT(DECIMAL(19,4), EmployeeTotalLoss)
                WHEN 'Lines'             THEN CONVERT(DECIMAL(19,4), Lines)
                WHEN 'AreaNo'            THEN CONVERT(DECIMAL(19,4), AreaNo)
            END
        END DESC,
        /* The legacy order: person, then area. */
        EmployeeCode, AreaNo, BranchName
    OFFSET @Offset ROWS FETCH NEXT @PageSize ROWS ONLY;

    SELECT COUNT_BIG(*) AS TotalRows FROM #sl_rows;

    SELECT SUM(EmployeeTotalLoss) AS EmployeeTotalLoss,
           SUM(Lines)             AS Lines,
           COUNT(DISTINCT CONVERT(NVARCHAR(12), BranchId) + '|' + EmployeeCode) AS Employees,
           COUNT(DISTINCT CONVERT(NVARCHAR(12), BranchId) + '|' + CONVERT(NVARCHAR(12), AreaNo)) AS Areas
    FROM #sl_rows;

    DROP TABLE #sl_rows;
    DROP TABLE #StockLoss;
END
