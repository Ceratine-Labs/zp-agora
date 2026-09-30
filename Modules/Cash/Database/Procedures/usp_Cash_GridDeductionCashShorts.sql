/* ============================================================================
   agora.usp_Cash_GridDeductionCashShorts

   WHAT IT IS FOR
   Head office → Deductions → Cash Short Deductions Per Employee (T041, tab 1):
   the legacy PumpIT report of that name (SS_Report 223, category PAYROLL,
   dbo.sp_RPT_CashShortDeductionPerEmployee), row for row, across sites.

   WHO READS IT
   Cash → Deductions (app.cash.deductions). Payroll and the Ops executive.

   THE ROWS are the ones the legacy lists — every cashier short worse than the
   leniency, and every pump attendant short — plus the one kind the legacy
   never had: a cash-bag short a recon match raised and an approver passed,
   which is never on a cashup (PM decision 01a0e5b5). The arithmetic is
   agora.usp_Cash_DeductionCashShorts, which the payroll build calls too.

   Grand totals (result set 3) carry, beside the column totals, what the tab's
   stat strip reads and the grid does not list: every cashier short before
   leniency, and the ones written off inside it.

   The grid procedure template (feature-rules §2), plus @AllowedBranchIds (the
   sites the person may see; an empty @BranchIds means these) and @Leniency
   (config cash.deductions.cash_leniency). With no dates it reads the last full
   calendar month — a payroll period.

   Result sets: 1 the page, 2 (TotalRows), 3 grand totals.

   READ-ONLY.

     EXEC agora.usp_Cash_GridDeductionCashShorts @BranchIds = '21', @DateFrom = '2026-09-01', @DateTo = '2026-09-30';
   ============================================================================ */
CREATE OR ALTER PROCEDURE [agora].[usp_Cash_GridDeductionCashShorts]
    @BranchIds         NVARCHAR(MAX) = NULL,
    @DateFrom          DATE          = NULL,
    @DateTo            DATE          = NULL,
    @Search            NVARCHAR(200) = NULL,
    @SortColumn        NVARCHAR(80)  = NULL,
    @SortAsc           BIT           = 1,
    @Page              INT           = 1,
    @PageSize          INT           = 50,
    @FiltersJson       NVARCHAR(MAX) = NULL,
    @AllowedBranchIds  NVARCHAR(MAX) = NULL,
    @Leniency          DECIMAL(18,2) = 3.00
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

    IF OBJECT_ID('tempdb..#CashShort') IS NOT NULL DROP TABLE #CashShort;
    CREATE TABLE #CashShort (
        BranchId INT, Source VARCHAR(16) COLLATE DATABASE_DEFAULT, EmployeeCode NVARCHAR(22) COLLATE DATABASE_DEFAULT,
        TransactionDate DATE, TransactionAt DATETIME, TillNo INT, ShiftNo INT,
        ZReading MONEY NULL, PumpAttShort MONEY, CashierShort MONEY,
        LeniencyAllowance MONEY, Deduction MONEY, IsWrittenOff BIT,
        DayEndNo NVARCHAR(20) COLLATE DATABASE_DEFAULT NULL, StaffShortsNo INT NULL, Note NVARCHAR(400) COLLATE DATABASE_DEFAULT NULL);

    EXEC agora.usp_Cash_DeductionCashShorts @BranchIds = @Scope, @DateFrom = @DateFrom, @DateTo = @DateTo, @Leniency = @Leniency;

    /* What the stat strip reads and the list leaves out, before any filter. */
    DECLARE @AllCashierShorts MONEY, @WrittenOffShifts INT, @WrittenOffAmount MONEY;
    SELECT @AllCashierShorts = SUM(CASE WHEN Source = 'cashier_short' THEN CashierShort ELSE 0 END),
           @WrittenOffShifts = SUM(CASE WHEN IsWrittenOff = 1 THEN 1 ELSE 0 END),
           @WrittenOffAmount = SUM(CASE WHEN IsWrittenOff = 1 THEN CashierShort ELSE 0 END)
    FROM #CashShort;

    SELECT
        c.BranchId,
        b.Name AS BranchName,
        c.Source,
        CONVERT(NVARCHAR(30), CASE c.Source
            WHEN 'cashier_short' THEN 'Cashier short'
            WHEN 'pump_short'    THEN 'Pump attendant short'
            WHEN 'cashbag_short' THEN 'Cash-bag short' END) AS SourceLabel,
        c.EmployeeCode,
        NULLIF(LTRIM(RTRIM(RTRIM(ISNULL(m.FirstName, '')) + ' ' + RTRIM(ISNULL(m.Surname, '')))), '') AS EmployeeName,
        c.TransactionDate,
        c.TillNo, tl.TillDescription,
        c.ShiftNo, sh.ShiftDescription,
        c.ZReading, c.PumpAttShort, c.CashierShort, c.LeniencyAllowance, c.Deduction,
        c.DayEndNo, c.StaffShortsNo, c.Note
    INTO #cs_rows
    FROM #CashShort c
    OUTER APPLY (SELECT TOP 1 x.Name FROM agora.Branch x WHERE x.BranchId = c.BranchId ORDER BY x.Id) b
    OUTER APPLY (SELECT TOP 1 x.FirstName, x.Surname FROM agora.vw_Employee x WHERE x.BranchId = c.BranchId AND x.EmployeeCode = c.EmployeeCode) m
    OUTER APPLY (SELECT TOP 1 x.TillDescription FROM agora.vw_Till x WHERE x.BranchId = c.BranchId AND x.TillNo = c.TillNo) tl
    OUTER APPLY (SELECT TOP 1 x.ShiftDescription FROM agora.vw_Shift x WHERE x.BranchId = c.BranchId AND x.ShiftNo = c.ShiftNo) sh
    WHERE c.Deduction <> 0;

    IF @Search IS NOT NULL
        DELETE FROM #cs_rows
        WHERE NOT (   ISNULL(BranchName, '')      LIKE '%' + @Search + '%'
                   OR ISNULL(EmployeeName, '')    LIKE '%' + @Search + '%'
                   OR ISNULL(EmployeeCode, '')    LIKE '%' + @Search + '%'
                   OR ISNULL(TillDescription, '') LIKE '%' + @Search + '%'
                   OR ISNULL(Note, '')            LIKE '%' + @Search + '%'
                   OR ISNULL(SourceLabel, '')     LIKE '%' + @Search + '%'
                   OR CONVERT(NVARCHAR(12), StaffShortsNo) = @Search);

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
                WHEN 'TillDescription'  THEN r.TillDescription
                WHEN 'ShiftDescription' THEN r.ShiftDescription
                WHEN 'DayEndNo'         THEN r.DayEndNo
                WHEN 'Note'             THEN r.Note
            END AS T,
            CASE f.[Column]
                WHEN 'ZReading'          THEN CONVERT(DECIMAL(19,4), r.ZReading)
                WHEN 'PumpAttShort'      THEN CONVERT(DECIMAL(19,4), r.PumpAttShort)
                WHEN 'CashierShort'      THEN CONVERT(DECIMAL(19,4), r.CashierShort)
                WHEN 'LeniencyAllowance' THEN CONVERT(DECIMAL(19,4), r.LeniencyAllowance)
                WHEN 'Deduction'         THEN CONVERT(DECIMAL(19,4), r.Deduction)
                WHEN 'TillNo'            THEN CONVERT(DECIMAL(19,4), r.TillNo)
                WHEN 'ShiftNo'           THEN CONVERT(DECIMAL(19,4), r.ShiftNo)
                WHEN 'StaffShortsNo'     THEN CONVERT(DECIMAL(19,4), r.StaffShortsNo)
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
                                  WHEN 'SourceLabel'      THEN r.SourceLabel
                                  WHEN 'BranchName'       THEN r.BranchName
                                  WHEN 'ShiftDescription' THEN r.ShiftDescription
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
                WHEN 'TillDescription'  THEN TillDescription
                WHEN 'ShiftDescription' THEN ShiftDescription
            END
        END ASC,
        CASE WHEN @SortAsc = 0 THEN
            CASE @SortColumn
                WHEN 'BranchName'       THEN BranchName
                WHEN 'EmployeeName'     THEN EmployeeName
                WHEN 'EmployeeCode'     THEN EmployeeCode
                WHEN 'SourceLabel'      THEN SourceLabel
                WHEN 'TillDescription'  THEN TillDescription
                WHEN 'ShiftDescription' THEN ShiftDescription
            END
        END DESC,
        CASE WHEN @SortAsc = 1 THEN
            CASE @SortColumn
                WHEN 'ZReading'     THEN CONVERT(DECIMAL(19,4), ZReading)
                WHEN 'PumpAttShort' THEN CONVERT(DECIMAL(19,4), PumpAttShort)
                WHEN 'CashierShort' THEN CONVERT(DECIMAL(19,4), CashierShort)
                WHEN 'Deduction'    THEN CONVERT(DECIMAL(19,4), Deduction)
                WHEN 'TillNo'       THEN CONVERT(DECIMAL(19,4), TillNo)
            END
        END ASC,
        CASE WHEN @SortAsc = 0 THEN
            CASE @SortColumn
                WHEN 'ZReading'     THEN CONVERT(DECIMAL(19,4), ZReading)
                WHEN 'PumpAttShort' THEN CONVERT(DECIMAL(19,4), PumpAttShort)
                WHEN 'CashierShort' THEN CONVERT(DECIMAL(19,4), CashierShort)
                WHEN 'Deduction'    THEN CONVERT(DECIMAL(19,4), Deduction)
                WHEN 'TillNo'       THEN CONVERT(DECIMAL(19,4), TillNo)
            END
        END DESC,
        CASE WHEN @SortAsc = 1 AND @SortColumn = 'TransactionDate' THEN TransactionDate END ASC,
        CASE WHEN @SortAsc = 0 AND @SortColumn = 'TransactionDate' THEN TransactionDate END DESC,
        /* The legacy order: by name, then day. */
        EmployeeName, TransactionDate, BranchName, TillNo, ShiftNo, Source
    OFFSET @Offset ROWS FETCH NEXT @PageSize ROWS ONLY;

    SELECT COUNT_BIG(*) AS TotalRows FROM #cs_rows;

    SELECT SUM(PumpAttShort)                               AS PumpAttShort,
           SUM(CashierShort)                               AS CashierShort,
           SUM(Deduction)                                  AS Deduction,
           ISNULL(@AllCashierShorts, 0)                    AS AllCashierShorts,
           ISNULL(@WrittenOffShifts, 0)                    AS WrittenOffShifts,
           ISNULL(@WrittenOffAmount, 0)                    AS WrittenOffAmount,
           COUNT(DISTINCT CONVERT(NVARCHAR(12), BranchId) + '|' + EmployeeCode) AS Employees
    FROM #cs_rows;

    DROP TABLE #cs_rows;
    DROP TABLE #CashShort;
END
