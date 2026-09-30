/* ============================================================================
   agora.usp_Cash_GridZreadsNoCashup

   WHAT IT IS FOR
   Z-reads with no cashup: a till's reading has been allocated to a till, a
   shift and a person, it took money, and nobody has cashed that till up. The
   takings are known; what they were banked as is not.

   WHO READS IT
   Cash → Z-reads with no cashup (app.cash.cashups.nocashup). Head office, and
   the branch for its own site. It replaces the legacy "ZReadings vs Cashups -
   NO Cashup" report, with the same rule: an allocated Z-read, non-zero sales,
   and no BRN_DailyBanking header at its site, till, shift and exact day.

   The allocation is agora.vw_CashZread's effective one (T036), so a Z-read
   allocated in Agora counts here the moment it is saved, and one PumpIT has
   re-allocated since shows where PumpIT now has it. An UNALLOCATED Z-read is
   not here — that is the Unallocated Z-reads queue; a Z-read has to belong to
   a till and shift before it can be missing a cashup.

   The grid procedure template (feature-rules §2), plus @AllowedBranchIds: the
   sites the person may see (NULL = every site); an empty @BranchIds means
   these.

   Result sets: 1 the page, 2 (TotalRows), 3 grand totals (Sales).

   READ-ONLY.

     EXEC agora.usp_Cash_GridZreadsNoCashup @DateFrom = '2026-08-01', @DateTo = '2026-08-31';
   ============================================================================ */
CREATE OR ALTER PROCEDURE [agora].[usp_Cash_GridZreadsNoCashup]
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
    SET @DateTo   = ISNULL(@DateTo, DATEADD(day, -1, CONVERT(date, GETDATE())));
    SET @DateFrom = ISNULL(@DateFrom, DATEADD(day, -29, @DateTo));

    DECLARE @From DATETIME = @DateFrom, @To DATETIME = DATEADD(day, 1, CONVERT(DATETIME, @DateTo));

    DECLARE @Branch TABLE (BranchId INT PRIMARY KEY);

    INSERT INTO @Branch (BranchId)
    SELECT DISTINCT TRY_CONVERT(int, LTRIM(RTRIM(s.value)))
    FROM STRING_SPLIT(ISNULL(@BranchIds, ''), ',') s
    WHERE LTRIM(RTRIM(s.value)) <> '' AND TRY_CONVERT(int, LTRIM(RTRIM(s.value))) IS NOT NULL;

    IF NOT EXISTS (SELECT 1 FROM @Branch)
        INSERT INTO @Branch (BranchId)
        SELECT DISTINCT TRY_CONVERT(int, LTRIM(RTRIM(s.value)))
        FROM STRING_SPLIT(ISNULL(@AllowedBranchIds, ''), ',') s
        WHERE LTRIM(RTRIM(s.value)) <> '' AND TRY_CONVERT(int, LTRIM(RTRIM(s.value))) IS NOT NULL;

    DECLARE @AllBranches BIT = CASE WHEN EXISTS (SELECT 1 FROM @Branch) THEN 0 ELSE 1 END;

    SELECT
        z.BranchId, b.Name AS BranchName, z.ZReadDate, z.Location, z.TerminalNo, z.EodCounter, z.LogFile, z.PosUserId,
        CONVERT(MONEY, z.Sales) AS Sales,
        z.TillNo, tl.TillDescription, z.ShiftNo, sh.ShiftDescription, z.EmployeeCode, e.EmployeeName,
        z.AllocatedBy,
        DATEDIFF(day, z.ZReadDate, CONVERT(date, GETDATE())) AS AgeDays
    INTO #gn_rows
    FROM agora.vw_CashZread z
    OUTER APPLY (SELECT TOP 1 x.Name FROM agora.Branch x WHERE x.BranchId = z.BranchId ORDER BY x.Id) b
    OUTER APPLY (SELECT TOP 1 x.TillDescription FROM agora.vw_Till x WHERE x.BranchId = z.BranchId AND x.TillNo = z.TillNo) tl
    OUTER APPLY (SELECT TOP 1 x.ShiftDescription FROM agora.vw_Shift x WHERE x.BranchId = z.BranchId AND x.ShiftNo = z.ShiftNo) sh
    OUTER APPLY (SELECT TOP 1 x.EmployeeName FROM agora.vw_Employee x WHERE x.BranchId = z.BranchId AND x.EmployeeCode = z.EmployeeCode) e
    WHERE z.ZReadAt >= @From AND z.ZReadAt < @To
      AND (@AllBranches = 1 OR z.BranchId IN (SELECT BranchId FROM @Branch))
      AND z.IsAllocated = 1
      AND ISNULL(z.Sales, 0) <> 0
      AND NOT EXISTS (SELECT 1 FROM agora.vw_Cashup c
                      WHERE c.BranchId = z.BranchId AND c.TillNo = z.TillNo AND c.ShiftNo = z.ShiftNo
                        AND c.TransactionAt = z.ZReadAt);

    IF @Search IS NOT NULL
        DELETE FROM #gn_rows
        WHERE NOT (   ISNULL(BranchName, '')      LIKE '%' + @Search + '%'
                   OR ISNULL(EmployeeName, '')    LIKE '%' + @Search + '%'
                   OR ISNULL(EmployeeCode, '')    LIKE '%' + @Search + '%'
                   OR ISNULL(TillDescription, '') LIKE '%' + @Search + '%'
                   OR ISNULL(PosUserId, '')       LIKE '%' + @Search + '%'
                   OR ISNULL(EodCounter, '')      LIKE '%' + @Search + '%');

    DECLARE @Filter    TABLE ([Column] NVARCHAR(80), [Type] NVARCHAR(10), [Op] NVARCHAR(20), [Value] NVARCHAR(400));
    DECLARE @FilterSet TABLE ([Column] NVARCHAR(80), [Value] NVARCHAR(400));

    IF @FiltersJson IS NOT NULL AND ISJSON(@FiltersJson) = 1
    BEGIN
        INSERT INTO @Filter ([Column], [Type], [Op], [Value])
        SELECT JSON_VALUE(f.value, '$.column'),
               ISNULL(JSON_VALUE(f.value, '$.type'), 'text'),
               ISNULL(JSON_VALUE(f.value, '$.op'), 'contains'),
               JSON_VALUE(f.value, '$.value')
        FROM OPENJSON(@FiltersJson) f
        WHERE JSON_VALUE(f.value, '$.value') IS NOT NULL
          AND ISNULL(JSON_VALUE(f.value, '$.type'), 'text') <> 'set';

        INSERT INTO @FilterSet ([Column], [Value])
        SELECT JSON_VALUE(f.value, '$.column'), v.value
        FROM OPENJSON(@FiltersJson) f
        CROSS APPLY OPENJSON(f.value, '$.in') v
        WHERE JSON_VALUE(f.value, '$.type') = 'set';
    END

    DELETE r
    FROM #gn_rows r
    WHERE EXISTS (
        SELECT 1
        FROM @Filter f
        CROSS APPLY (SELECT
            CASE f.[Column]
                WHEN 'BranchName'       THEN r.BranchName
                WHEN 'Location'         THEN r.Location
                WHEN 'TerminalNo'       THEN r.TerminalNo
                WHEN 'EodCounter'       THEN r.EodCounter
                WHEN 'PosUserId'        THEN r.PosUserId
                WHEN 'TillDescription'  THEN r.TillDescription
                WHEN 'ShiftDescription' THEN r.ShiftDescription
                WHEN 'EmployeeName'     THEN r.EmployeeName
            END AS T,
            CASE f.[Column]
                WHEN 'Sales'   THEN CONVERT(DECIMAL(19,4), r.Sales)
                WHEN 'AgeDays' THEN CONVERT(DECIMAL(19,4), r.AgeDays)
            END AS N,
            CASE f.[Column] WHEN 'ZReadDate' THEN r.ZReadDate END AS D,
            TRY_CONVERT(DECIMAL(19,4), f.[Value]) AS FN,
            TRY_CONVERT(DATE, f.[Value]) AS FD
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
            WHEN f.[Type] = 'date' THEN CASE
                WHEN v.D IS NULL OR v.FD IS NULL THEN 1
                WHEN f.[Op] = 'ne'  AND v.D <> v.FD THEN 0
                WHEN f.[Op] = 'gt'  AND v.D >  v.FD THEN 0
                WHEN f.[Op] = 'gte' AND v.D >= v.FD THEN 0
                WHEN f.[Op] = 'lt'  AND v.D <  v.FD THEN 0
                WHEN f.[Op] = 'lte' AND v.D <= v.FD THEN 0
                WHEN f.[Op] NOT IN ('ne', 'gt', 'gte', 'lt', 'lte') AND v.D = v.FD THEN 0
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
                                  WHEN 'Location'         THEN r.Location
                                  WHEN 'ShiftDescription' THEN r.ShiftDescription
                                  WHEN 'BranchName'       THEN r.BranchName
                              END)
    );

    DECLARE @Offset INT = (@Page - 1) * @PageSize;

    SELECT *
    FROM #gn_rows
    ORDER BY
        CASE WHEN @SortAsc = 1 THEN
            CASE @SortColumn
                WHEN 'BranchName'   THEN BranchName
                WHEN 'Location'     THEN Location
                WHEN 'EmployeeName' THEN EmployeeName
                WHEN 'EodCounter'   THEN EodCounter
            END
        END ASC,
        CASE WHEN @SortAsc = 0 THEN
            CASE @SortColumn
                WHEN 'BranchName'   THEN BranchName
                WHEN 'Location'     THEN Location
                WHEN 'EmployeeName' THEN EmployeeName
                WHEN 'EodCounter'   THEN EodCounter
            END
        END DESC,
        CASE WHEN @SortAsc = 1 THEN
            CASE @SortColumn
                WHEN 'Sales'   THEN CONVERT(DECIMAL(19,4), Sales)
                WHEN 'AgeDays' THEN CONVERT(DECIMAL(19,4), AgeDays)
                WHEN 'TillNo'  THEN CONVERT(DECIMAL(19,4), TillNo)
            END
        END ASC,
        CASE WHEN @SortAsc = 0 THEN
            CASE @SortColumn
                WHEN 'Sales'   THEN CONVERT(DECIMAL(19,4), Sales)
                WHEN 'AgeDays' THEN CONVERT(DECIMAL(19,4), AgeDays)
                WHEN 'TillNo'  THEN CONVERT(DECIMAL(19,4), TillNo)
            END
        END DESC,
        CASE WHEN @SortAsc = 1 AND @SortColumn = 'ZReadDate' THEN ZReadDate END ASC,
        CASE WHEN @SortAsc = 0 AND @SortColumn = 'ZReadDate' THEN ZReadDate END DESC,
        ZReadDate DESC, BranchName, TillNo, ShiftNo, TerminalNo, LogFile
    OFFSET @Offset ROWS FETCH NEXT @PageSize ROWS ONLY;

    SELECT COUNT_BIG(*) AS TotalRows FROM #gn_rows;

    SELECT SUM(Sales) AS Sales FROM #gn_rows;

    DROP TABLE #gn_rows;
END
