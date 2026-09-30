/* ============================================================================
   agora.usp_Cash_GridCashups

   WHAT IT IS FOR
   Cashups — the manager's view. Every cashup in the period at the chosen
   sites: its Z-read, what it actually totals, the variance, the cash bags,
   the cards, the pump shortages, whether the Z-read agrees and where the
   cashup stands. It is the legacy "Daily Banking - Managers View" and the
   mockup's #/op/cashups in one grid, with the queues as a parameter:

     @Show = 'all'       every cashup
             'open'      not yet signed off: Open, or Confirmed on a day PumpIT
                         has not balanced (waiting on a manager's close) —
                         agora.vw_CashupTotals.IsOpen, the rule the Reports
                         "Open cashups" queue uses too
             'disagree'  the Z-read does not agree with the cashup: none
                         allocated, a different figure, or another person's

   WHO READS IT
   Cash → Cashups (app.cash.cashups): head office across sites, a branch
   manager for their own. Every figure comes from agora.vw_CashupTotals, which
   is also what the Daily Banking screen and the two Reports procedures read,
   so the three can never disagree about a variance.

   The grid procedure template (feature-rules §2), plus:
     @Show              see above
     @VarianceOver      only cashups whose variance is beyond this either way
     @AllowedBranchIds  the sites the person may see (NULL = every site). An
                        empty @BranchIds means these, never "every site in the
                        estate"

   Status is one word — Open, Confirmed, Closed, or Day balanced (no Agora
   state, and PumpIT's day is balanced) — and ZreadCheck is one of No Z-read,
   Z-read differs, Other cashier, Agrees (see the view).

   @FiltersJson is a JSON ARRAY of {column, type, op, value | in[]} objects; the
   column is at $.column INSIDE each element, never OPENJSON's [key].

   Result sets: 1 the page, 2 (TotalRows), 3 grand totals over the whole
   filtered set (ZRead, ActualTotal, Variance, CashBags, Cards, StaffShorts).

   READ-ONLY.

     EXEC agora.usp_Cash_GridCashups @BranchIds = '6', @DateFrom = '2026-05-22', @DateTo = '2026-05-22';
   ============================================================================ */
CREATE OR ALTER PROCEDURE [agora].[usp_Cash_GridCashups]
    @BranchIds         NVARCHAR(MAX) = NULL,
    @DateFrom          DATE          = NULL,
    @DateTo            DATE          = NULL,
    @Search            NVARCHAR(200) = NULL,
    @SortColumn        NVARCHAR(80)  = NULL,
    @SortAsc           BIT           = 1,
    @Page              INT           = 1,
    @PageSize          INT           = 50,
    @FiltersJson       NVARCHAR(MAX) = NULL,
    @Show              NVARCHAR(12)  = 'all',
    @VarianceOver      DECIMAL(18,2) = NULL,
    @AllowedBranchIds  NVARCHAR(MAX) = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    SET @Page     = CASE WHEN ISNULL(@Page, 1) < 1 THEN 1 ELSE @Page END;
    SET @PageSize = CASE WHEN ISNULL(@PageSize, 50) BETWEEN 1 AND 100000 THEN @PageSize ELSE 50 END;
    SET @Search   = NULLIF(LTRIM(RTRIM(ISNULL(@Search, ''))), '');
    SET @Show     = CASE WHEN @Show IN ('open', 'disagree') THEN @Show ELSE 'all' END;
    SET @DateTo   = ISNULL(@DateTo, DATEADD(day, -1, CONVERT(date, GETDATE())));
    SET @DateFrom = ISNULL(@DateFrom, DATEADD(day, -6, @DateTo));

    DECLARE @From DATETIME = @DateFrom, @To DATETIME = DATEADD(day, 1, CONVERT(DATETIME, @DateTo));

    /* The empty-string trap: STRING_SPLIT('', ',') is one empty row, and
       TRY_CONVERT(int, '') is 0 rather than NULL. */
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
        t.BranchId, b.Name AS BranchName, t.TransactionDate,
        t.ShiftNo, sh.ShiftDescription, t.TillNo, tl.TillDescription,
        t.EmployeeCode, e.EmployeeName, t.DayEndNo,
        t.ZRead, t.ActualTotal, t.Variance,
        t.CashBags, t.Cards, t.StaffShorts,
        t.Debtors, t.Expenses, t.CashDevices, t.SmartATM, t.DirectDeposits, t.Suppliers, t.FleetCard,
        t.Yumbi, t.YumbiVoucher, t.Infinity, t.Zapper, t.StoredVariance,
        t.ZreadSales, t.ZreadCheck, t.[Status], t.DayBalanced,
        t.ConfirmedAt, cu.UserName AS ConfirmedByName, t.ConfirmNote,
        t.ClosedAt, xu.UserName AS ClosedByName,
        CONVERT(BIT, CASE WHEN t.AgoraStatus IN ('confirmed', 'closed')
                           AND (ABS(ISNULL(t.ConfirmedZRead, 0) - ISNULL(t.ZRead, 0)) > 0.005
                                OR ABS(ISNULL(t.ConfirmedActual, 0) - t.ActualTotal) > 0.005)
                          THEN 1 ELSE 0 END) AS FiguresMoved,
        DATEDIFF(day, t.TransactionDate, CONVERT(date, GETDATE())) AS AgeDays
    INTO #gc_rows
    FROM agora.vw_CashupTotals t
    OUTER APPLY (SELECT TOP 1 x.Name FROM agora.Branch x WHERE x.BranchId = t.BranchId ORDER BY x.Id) b
    OUTER APPLY (SELECT TOP 1 x.TillDescription FROM agora.vw_Till x WHERE x.BranchId = t.BranchId AND x.TillNo = t.TillNo) tl
    OUTER APPLY (SELECT TOP 1 x.ShiftDescription FROM agora.vw_Shift x WHERE x.BranchId = t.BranchId AND x.ShiftNo = t.ShiftNo) sh
    OUTER APPLY (SELECT TOP 1 x.EmployeeName FROM agora.vw_Employee x WHERE x.BranchId = t.BranchId AND x.EmployeeCode = t.EmployeeCode) e
    OUTER APPLY (SELECT TOP 1 u.UserName FROM agora.[User] u WHERE u.Id = t.ConfirmedBy) cu
    OUTER APPLY (SELECT TOP 1 u.UserName FROM agora.[User] u WHERE u.Id = t.ClosedBy) xu
    WHERE t.TransactionAt >= @From AND t.TransactionAt < @To
      AND (@AllBranches = 1 OR t.BranchId IN (SELECT BranchId FROM @Branch))
      AND (@Show = 'all'
           OR (@Show = 'open' AND t.IsOpen = 1)
           OR (@Show = 'disagree' AND t.ZreadCheck <> 'Agrees'))
      AND (@VarianceOver IS NULL OR ABS(t.Variance) > @VarianceOver);

    IF @Search IS NOT NULL
        DELETE FROM #gc_rows
        WHERE NOT (   ISNULL(BranchName, '')       LIKE '%' + @Search + '%'
                   OR ISNULL(EmployeeName, '')     LIKE '%' + @Search + '%'
                   OR ISNULL(EmployeeCode, '')     LIKE '%' + @Search + '%'
                   OR ISNULL(TillDescription, '')  LIKE '%' + @Search + '%'
                   OR ISNULL(DayEndNo, '')         LIKE '%' + @Search + '%'
                   OR ISNULL(ConfirmNote, '')      LIKE '%' + @Search + '%');

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

    /* Header filters. A row survives when no filter rejects it; a NULL cell
       fails a filter rather than slipping past it. */
    DELETE r
    FROM #gc_rows r
    WHERE EXISTS (
        SELECT 1
        FROM @Filter f
        CROSS APPLY (SELECT
            CASE f.[Column]
                WHEN 'BranchName'       THEN r.BranchName
                WHEN 'ShiftDescription' THEN r.ShiftDescription
                WHEN 'TillDescription'  THEN r.TillDescription
                WHEN 'EmployeeName'     THEN r.EmployeeName
                WHEN 'DayEndNo'         THEN r.DayEndNo
                WHEN 'ZreadCheck'       THEN r.ZreadCheck
                WHEN 'Status'           THEN r.[Status]
            END AS T,
            CASE f.[Column]
                WHEN 'ZRead'       THEN CONVERT(DECIMAL(19,4), r.ZRead)
                WHEN 'ActualTotal' THEN CONVERT(DECIMAL(19,4), r.ActualTotal)
                WHEN 'Variance'    THEN CONVERT(DECIMAL(19,4), r.Variance)
                WHEN 'CashBags'    THEN CONVERT(DECIMAL(19,4), r.CashBags)
                WHEN 'Cards'       THEN CONVERT(DECIMAL(19,4), r.Cards)
                WHEN 'StaffShorts' THEN CONVERT(DECIMAL(19,4), r.StaffShorts)
                WHEN 'AgeDays'     THEN CONVERT(DECIMAL(19,4), r.AgeDays)
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
                                  WHEN 'Status'           THEN r.[Status]
                                  WHEN 'ZreadCheck'       THEN r.ZreadCheck
                                  WHEN 'ShiftDescription' THEN r.ShiftDescription
                                  WHEN 'BranchName'       THEN r.BranchName
                              END)
    );

    DECLARE @Offset INT = (@Page - 1) * @PageSize;

    SELECT *
    FROM #gc_rows
    ORDER BY
        CASE WHEN @SortAsc = 1 THEN
            CASE @SortColumn
                WHEN 'BranchName'       THEN BranchName
                WHEN 'ShiftDescription' THEN ShiftDescription
                WHEN 'EmployeeName'     THEN EmployeeName
                WHEN 'Status'           THEN [Status]
                WHEN 'ZreadCheck'       THEN ZreadCheck
            END
        END ASC,
        CASE WHEN @SortAsc = 0 THEN
            CASE @SortColumn
                WHEN 'BranchName'       THEN BranchName
                WHEN 'ShiftDescription' THEN ShiftDescription
                WHEN 'EmployeeName'     THEN EmployeeName
                WHEN 'Status'           THEN [Status]
                WHEN 'ZreadCheck'       THEN ZreadCheck
            END
        END DESC,
        CASE WHEN @SortAsc = 1 THEN
            CASE @SortColumn
                WHEN 'ZRead'       THEN CONVERT(DECIMAL(19,4), ZRead)
                WHEN 'ActualTotal' THEN CONVERT(DECIMAL(19,4), ActualTotal)
                WHEN 'Variance'    THEN CONVERT(DECIMAL(19,4), Variance)
                WHEN 'CashBags'    THEN CONVERT(DECIMAL(19,4), CashBags)
                WHEN 'Cards'       THEN CONVERT(DECIMAL(19,4), Cards)
                WHEN 'StaffShorts' THEN CONVERT(DECIMAL(19,4), StaffShorts)
                WHEN 'TillNo'      THEN CONVERT(DECIMAL(19,4), TillNo)
                WHEN 'AgeDays'     THEN CONVERT(DECIMAL(19,4), AgeDays)
            END
        END ASC,
        CASE WHEN @SortAsc = 0 THEN
            CASE @SortColumn
                WHEN 'ZRead'       THEN CONVERT(DECIMAL(19,4), ZRead)
                WHEN 'ActualTotal' THEN CONVERT(DECIMAL(19,4), ActualTotal)
                WHEN 'Variance'    THEN CONVERT(DECIMAL(19,4), Variance)
                WHEN 'CashBags'    THEN CONVERT(DECIMAL(19,4), CashBags)
                WHEN 'Cards'       THEN CONVERT(DECIMAL(19,4), Cards)
                WHEN 'StaffShorts' THEN CONVERT(DECIMAL(19,4), StaffShorts)
                WHEN 'TillNo'      THEN CONVERT(DECIMAL(19,4), TillNo)
                WHEN 'AgeDays'     THEN CONVERT(DECIMAL(19,4), AgeDays)
            END
        END DESC,
        CASE WHEN @SortAsc = 1 AND @SortColumn = 'TransactionDate' THEN TransactionDate END ASC,
        CASE WHEN @SortAsc = 0 AND @SortColumn = 'TransactionDate' THEN TransactionDate END DESC,
        /* The newest day first, the biggest variance first within it: the
           cashup a manager should look at is at the top. */
        TransactionDate DESC, ABS(Variance) DESC, BranchName, TillNo, ShiftNo
    OFFSET @Offset ROWS FETCH NEXT @PageSize ROWS ONLY;

    SELECT COUNT_BIG(*) AS TotalRows FROM #gc_rows;

    SELECT SUM(ISNULL(ZRead, 0)) AS ZRead, SUM(ActualTotal) AS ActualTotal, SUM(Variance) AS Variance,
           SUM(CashBags) AS CashBags, SUM(Cards) AS Cards, SUM(StaffShorts) AS StaffShorts
    FROM #gc_rows;

    DROP TABLE #gc_rows;
END
