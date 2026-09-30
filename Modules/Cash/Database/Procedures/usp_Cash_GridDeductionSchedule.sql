/* ============================================================================
   agora.usp_Cash_GridDeductionSchedule

   WHAT IT IS FOR
   Head office → Deductions → Payroll (T041): the deduction schedule as BUILT —
   agora.Deduction for every current run (built, or already exported to
   payroll; never superseded) whose period falls inside the one chosen. One
   row per thing that was short: what it was, the allowance it was measured
   against, and what payroll takes. The payroll file is this grid added up per
   person (agora.usp_Cash_ExportPayroll).

   WHO READS IT
   Cash → Deductions → Payroll (app.cash.deductions.payroll).

   The grid procedure template (feature-rules §2), plus @AllowedBranchIds. With
   no dates it reads the last full calendar month.

   Result sets: 1 the page, 2 (TotalRows), 3 grand totals.

   READ-ONLY.

     EXEC agora.usp_Cash_GridDeductionSchedule @BranchIds = '21', @DateFrom = '2026-09-01', @DateTo = '2026-09-30';
   ============================================================================ */
CREATE OR ALTER PROCEDURE [agora].[usp_Cash_GridDeductionSchedule]
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

    DECLARE @Branch TABLE (BranchId INT PRIMARY KEY);
    INSERT INTO @Branch (BranchId)
    SELECT DISTINCT TRY_CONVERT(INT, LTRIM(RTRIM(s.value)))
    FROM STRING_SPLIT(ISNULL(@Scope, ''), ',') s
    WHERE TRY_CONVERT(INT, LTRIM(RTRIM(s.value))) IS NOT NULL;

    DECLARE @All BIT = CASE WHEN EXISTS (SELECT 1 FROM @Branch) THEN 0 ELSE 1 END;

    SELECT
        d.Id,
        d.BranchId,
        b.Name AS BranchName,
        d.RunId,
        r.[Status] AS RunStatus,
        CONVERT(NVARCHAR(30), CONCAT(CONVERT(NVARCHAR(10), r.FromDate, 23), N' – ', CONVERT(NVARCHAR(10), r.ToDate, 23))) AS Period,
        r.ExportedAt,
        d.Source,
        CONVERT(NVARCHAR(30), CASE d.Source
            WHEN 'cashier_short' THEN 'Cashier short'
            WHEN 'pump_short'    THEN 'Pump attendant short'
            WHEN 'cashbag_short' THEN 'Cash-bag short'
            WHEN 'stock_loss'    THEN 'Stock loss'
            WHEN 'nonint_pos'    THEN 'Non-integrated POS' END) AS SourceLabel,
        d.EmployeeCode,
        d.EmployeeName,
        d.PostDescription,
        d.SourceDate AS TransactionDate,
        d.TillNo,
        d.ShiftNo,
        d.AreaNo,
        d.AreaGroup,
        d.SourceRef,
        d.Amount,
        d.Allowance,
        d.Deducted,
        CONVERT(NVARCHAR(12), CASE WHEN d.IsWrittenOff = 1 THEN 'Written off' ELSE 'Deducted' END) AS Outcome,
        d.Note
    INTO #cs_rows
    FROM agora.Deduction d
    JOIN agora.DeductionRun r ON r.BranchId = d.BranchId AND r.Id = d.RunId
    OUTER APPLY (SELECT TOP 1 x.Name FROM agora.Branch x WHERE x.BranchId = d.BranchId ORDER BY x.Id) b
    WHERE r.[Status] IN ('built', 'exported')
      AND r.FromDate >= @DateFrom AND r.ToDate <= @DateTo
      AND (@All = 1 OR d.BranchId IN (SELECT BranchId FROM @Branch));

    IF @Search IS NOT NULL
        DELETE FROM #cs_rows
        WHERE NOT (   ISNULL(BranchName, '')      LIKE '%' + @Search + '%'
                   OR ISNULL(EmployeeName, '')    LIKE '%' + @Search + '%'
                   OR ISNULL(EmployeeCode, '')    LIKE '%' + @Search + '%'
                   OR ISNULL(Note, '')            LIKE '%' + @Search + '%'
                   OR ISNULL(SourceLabel, '')     LIKE '%' + @Search + '%'
                   OR ISNULL(SourceRef, '')       LIKE '%' + @Search + '%'
                   OR ISNULL(AreaGroup, '')       LIKE '%' + @Search + '%');

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
    FROM #cs_rows r
    WHERE EXISTS (
        SELECT 1
        FROM @Filter f
        CROSS APPLY (SELECT
            CASE f.[Column]
                WHEN 'BranchName'       THEN r.BranchName
                WHEN 'EmployeeName'     THEN r.EmployeeName
                WHEN 'EmployeeCode'     THEN r.EmployeeCode
                WHEN 'PostDescription'  THEN r.PostDescription
                WHEN 'AreaGroup'        THEN r.AreaGroup
                WHEN 'SourceRef'        THEN r.SourceRef
                WHEN 'Period'           THEN r.Period
                WHEN 'Note'             THEN r.Note
            END AS T,
            CASE f.[Column]
                WHEN 'Amount'    THEN CONVERT(DECIMAL(19,4), r.Amount)
                WHEN 'Allowance' THEN CONVERT(DECIMAL(19,4), r.Allowance)
                WHEN 'Deducted'  THEN CONVERT(DECIMAL(19,4), r.Deducted)
                WHEN 'TillNo'    THEN CONVERT(DECIMAL(19,4), r.TillNo)
                WHEN 'ShiftNo'   THEN CONVERT(DECIMAL(19,4), r.ShiftNo)
                WHEN 'AreaNo'    THEN CONVERT(DECIMAL(19,4), r.AreaNo)
                WHEN 'RunId'     THEN CONVERT(DECIMAL(19,4), r.RunId)
            END AS N,
            CASE f.[Column] WHEN 'TransactionDate' THEN r.TransactionDate END AS D,
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
                                  WHEN 'SourceLabel' THEN r.SourceLabel
                                  WHEN 'BranchName'  THEN r.BranchName
                                  WHEN 'Outcome'     THEN r.Outcome
                                  WHEN 'RunStatus'   THEN r.RunStatus
                              END)
    );

    DECLARE @Offset INT = (@Page - 1) * @PageSize;

    SELECT *
    FROM #cs_rows
    ORDER BY
        CASE WHEN @SortAsc = 1 THEN
            CASE @SortColumn
                WHEN 'BranchName'       THEN BranchName
                WHEN 'EmployeeName'     THEN EmployeeName
                WHEN 'EmployeeCode'     THEN EmployeeCode
                WHEN 'SourceLabel'      THEN SourceLabel
                WHEN 'AreaGroup'        THEN AreaGroup
                WHEN 'Outcome'          THEN Outcome
            END
        END ASC,
        CASE WHEN @SortAsc = 0 THEN
            CASE @SortColumn
                WHEN 'BranchName'       THEN BranchName
                WHEN 'EmployeeName'     THEN EmployeeName
                WHEN 'EmployeeCode'     THEN EmployeeCode
                WHEN 'SourceLabel'      THEN SourceLabel
                WHEN 'AreaGroup'        THEN AreaGroup
                WHEN 'Outcome'          THEN Outcome
            END
        END DESC,
        CASE WHEN @SortAsc = 1 THEN
            CASE @SortColumn
                WHEN 'Amount'    THEN CONVERT(DECIMAL(19,4), Amount)
                WHEN 'Allowance' THEN CONVERT(DECIMAL(19,4), Allowance)
                WHEN 'Deducted'  THEN CONVERT(DECIMAL(19,4), Deducted)
                WHEN 'TillNo'    THEN CONVERT(DECIMAL(19,4), TillNo)
            END
        END ASC,
        CASE WHEN @SortAsc = 0 THEN
            CASE @SortColumn
                WHEN 'Amount'    THEN CONVERT(DECIMAL(19,4), Amount)
                WHEN 'Allowance' THEN CONVERT(DECIMAL(19,4), Allowance)
                WHEN 'Deducted'  THEN CONVERT(DECIMAL(19,4), Deducted)
                WHEN 'TillNo'    THEN CONVERT(DECIMAL(19,4), TillNo)
            END
        END DESC,
        CASE WHEN @SortAsc = 1 AND @SortColumn = 'TransactionDate' THEN TransactionDate END ASC,
        CASE WHEN @SortAsc = 0 AND @SortColumn = 'TransactionDate' THEN TransactionDate END DESC,
        /* Site, person, then what it was and when. */
        BranchName, EmployeeName, EmployeeCode, Source, TransactionDate, TillNo, ShiftNo, AreaNo
    OFFSET @Offset ROWS FETCH NEXT @PageSize ROWS ONLY;

    SELECT COUNT_BIG(*) AS TotalRows FROM #cs_rows;

    SELECT SUM(Amount)                                                  AS Amount,
           SUM(Allowance)                                               AS Allowance,
           SUM(Deducted)                                                AS Deducted,
           SUM(CASE WHEN Outcome = 'Written off' THEN Amount ELSE 0 END) AS WrittenOffAmount,
           COUNT(DISTINCT CASE WHEN Deducted <> 0 THEN CONVERT(NVARCHAR(12), BranchId) + '|' + EmployeeCode END) AS Employees,
           COUNT(DISTINCT CONVERT(NVARCHAR(12), BranchId) + '|' + CONVERT(NVARCHAR(20), RunId)) AS Runs
    FROM #cs_rows;

    DROP TABLE #cs_rows;
END
