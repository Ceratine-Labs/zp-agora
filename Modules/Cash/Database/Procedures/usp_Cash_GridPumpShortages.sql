/* ============================================================================
   agora.usp_Cash_GridPumpShortages

   WHAT IT IS FOR
   Pump shortages: what each pump attendant was short, shift by shift — the
   "pump shortages" leg of every cashup. The legacy "Pump Shortages by
   Attendant" report (Daily Banking MOPS, all sites, a date range), and the
   mockup's "Fills the attendant is short on, by attendant and by shift".

   WHO READS IT
   Cash → Pump shortages (app.cash.pumpshortages). Head office across sites,
   and the branch for its own.

   WHERE THE FIGURE COMES FROM, and why it is the cashup's own:
   a row is a line of BRN_DailyBankingEmployees — one attendant on one cashup —
   read through agora.vw_CashupLeg (Channel 'StaffShorts'), which is exactly
   what the cashup adds up as its pump shortages. So the Short on these rows,
   summed for a till and shift, IS that cashup's PumpShortages figure on the
   Daily Banking screen, and Counted says whether the legacy total counts the
   line at all (the attendant must be on BRN_Employee, or PumpIT drops it).
   The deduction schedule reads the same rows as the attendant's short
   (dbo.sp_RPT_CashShortDeductionPerEmployee, PumpAttShort = -AmountShort).

   Each line is placed on the cashup by POSTING an approved staff short to it
   — approval alone does not (T040: Cash → Staff shorts → Post, or the Daily
   Banking screen's pump-shortages leg; in PumpIT, the cashier ticking it on
   the cashup). The line carries the short's StaffShortsNo — every line since
   2025 has one — and the staff short's reason, note, raiser and approver are joined on
   by that number, TOP 1, so they cannot multiply a line. A till balance does
   NOT appear here: it moves a cashup's variance through its Expenses leg.

   The grid procedure template (feature-rules §2), plus @AllowedBranchIds: the
   sites the person may see (NULL = every site); an empty @BranchIds means
   these.

   Result sets: 1 the page, 2 (TotalRows), 3 grand totals (AmountShort,
   CountedShort).

   READ-ONLY.

     EXEC agora.usp_Cash_GridPumpShortages @DateFrom = '2026-09-01', @DateTo = '2026-09-26';
   ============================================================================ */
CREATE OR ALTER PROCEDURE [agora].[usp_Cash_GridPumpShortages]
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
        g.BranchId, b.Name AS BranchName, g.TransactionDate,
        g.TillNo, tl.TillDescription, g.ShiftNo, sh.ShiftDescription,
        g.Key1 AS EmployeeCode,
        NULLIF(g.Counterparty, '') AS EmployeeName,
        CONVERT(BIT, ISNULL(e.IsPumpAttendant, 0)) AS IsPumpAttendant,
        g.Amount AS AmountShort,
        g.InActualTotal AS Counted,
        CONVERT(MONEY, CASE WHEN g.InActualTotal = 1 THEN g.Amount ELSE 0 END) AS CountedShort,
        NULLIF(TRY_CONVERT(INT, g.Key2), 0) AS StaffShortsNo,
        COALESCE(NULLIF(LTRIM(RTRIM(LEFT(s.Reason, 200))), ''), NULLIF(g.Detail, '')) AS Reason,
        NULLIF(LTRIM(RTRIM(LEFT(s.Note, 400))), '') AS Note,
        ru.UserName AS RaisedByName,
        s.CreateDateTime AS RaisedAt,
        au.UserName AS ApprovedByName,
        s.ApprovedDate AS ApprovedAt,
        h.EmployeeCode AS CashierCode,
        he.EmployeeName AS CashierName
    INTO #ps_rows
    FROM agora.vw_CashupLeg g
    OUTER APPLY (SELECT TOP 1 x.Name FROM agora.Branch x WHERE x.BranchId = g.BranchId ORDER BY x.Id) b
    OUTER APPLY (SELECT TOP 1 x.TillDescription FROM agora.vw_Till x WHERE x.BranchId = g.BranchId AND x.TillNo = g.TillNo) tl
    OUTER APPLY (SELECT TOP 1 x.ShiftDescription FROM agora.vw_Shift x WHERE x.BranchId = g.BranchId AND x.ShiftNo = g.ShiftNo) sh
    OUTER APPLY (SELECT TOP 1 x.IsPumpAttendant FROM agora.vw_Employee x WHERE x.BranchId = g.BranchId AND x.EmployeeCode = g.Key1) e
    OUTER APPLY (SELECT TOP 1 x.Reason, x.Note, x.CreatedByUserId, x.CreateDateTime, x.ApprovedByUserId, x.ApprovedDate
                 FROM agora.vw_StaffShort x
                 WHERE x.BranchId = g.BranchId AND x.StaffShortsNo = TRY_CONVERT(INT, g.Key2) AND TRY_CONVERT(INT, g.Key2) <> 0
                 ORDER BY x.CreateDateTime DESC) s
    OUTER APPLY (SELECT TOP 1 u.UserName FROM agora.vw_ErpUser u WHERE u.ErpUserId = s.CreatedByUserId) ru
    OUTER APPLY (SELECT TOP 1 u.UserName FROM agora.vw_ErpUser u WHERE u.ErpUserId = NULLIF(s.ApprovedByUserId, 0)) au
    OUTER APPLY (SELECT TOP 1 x.EmployeeCode FROM agora.vw_Cashup x
                 WHERE x.BranchId = g.BranchId AND x.TillNo = g.TillNo AND x.ShiftNo = g.ShiftNo AND x.TransactionAt = g.TransactionAt) h
    OUTER APPLY (SELECT TOP 1 x.EmployeeName FROM agora.vw_Employee x WHERE x.BranchId = g.BranchId AND x.EmployeeCode = h.EmployeeCode) he
    WHERE g.Channel = 'StaffShorts'
      AND g.TransactionAt >= @From AND g.TransactionAt < @To
      AND (@AllBranches = 1 OR g.BranchId IN (SELECT BranchId FROM @Branch));

    IF @Search IS NOT NULL
        DELETE FROM #ps_rows
        WHERE NOT (   ISNULL(BranchName, '')      LIKE '%' + @Search + '%'
                   OR ISNULL(EmployeeName, '')    LIKE '%' + @Search + '%'
                   OR ISNULL(EmployeeCode, '')    LIKE '%' + @Search + '%'
                   OR ISNULL(TillDescription, '') LIKE '%' + @Search + '%'
                   OR ISNULL(Reason, '')          LIKE '%' + @Search + '%'
                   OR ISNULL(Note, '')            LIKE '%' + @Search + '%'
                   OR ISNULL(CashierName, '')     LIKE '%' + @Search + '%'
                   OR CONVERT(NVARCHAR(12), StaffShortsNo) = @Search);

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
    FROM #ps_rows r
    WHERE EXISTS (
        SELECT 1
        FROM @Filter f
        CROSS APPLY (SELECT
            CASE f.[Column]
                WHEN 'BranchName'       THEN r.BranchName
                WHEN 'TillDescription'  THEN r.TillDescription
                WHEN 'ShiftDescription' THEN r.ShiftDescription
                WHEN 'EmployeeName'     THEN r.EmployeeName
                WHEN 'EmployeeCode'     THEN r.EmployeeCode
                WHEN 'Reason'           THEN r.Reason
                WHEN 'Note'             THEN r.Note
                WHEN 'CashierName'      THEN r.CashierName
                WHEN 'RaisedByName'     THEN r.RaisedByName
                WHEN 'ApprovedByName'   THEN r.ApprovedByName
            END AS T,
            CASE f.[Column]
                WHEN 'AmountShort'   THEN CONVERT(DECIMAL(19,4), r.AmountShort)
                WHEN 'StaffShortsNo' THEN CONVERT(DECIMAL(19,4), r.StaffShortsNo)
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
                                  WHEN 'BranchName'       THEN r.BranchName
                                  WHEN 'ShiftDescription' THEN r.ShiftDescription
                                  WHEN 'Counted'          THEN CASE WHEN r.Counted = 1 THEN 'Counted' ELSE 'Not counted' END
                                  WHEN 'IsPumpAttendant'  THEN CASE WHEN r.IsPumpAttendant = 1 THEN 'Pump attendant' ELSE 'Not flagged' END
                              END)
    );

    DECLARE @Offset INT = (@Page - 1) * @PageSize;

    SELECT *
    FROM #ps_rows
    ORDER BY
        CASE WHEN @SortAsc = 1 THEN
            CASE @SortColumn
                WHEN 'BranchName'       THEN BranchName
                WHEN 'EmployeeName'     THEN EmployeeName
                WHEN 'ShiftDescription' THEN ShiftDescription
                WHEN 'Reason'           THEN Reason
                WHEN 'CashierName'      THEN CashierName
            END
        END ASC,
        CASE WHEN @SortAsc = 0 THEN
            CASE @SortColumn
                WHEN 'BranchName'       THEN BranchName
                WHEN 'EmployeeName'     THEN EmployeeName
                WHEN 'ShiftDescription' THEN ShiftDescription
                WHEN 'Reason'           THEN Reason
                WHEN 'CashierName'      THEN CashierName
            END
        END DESC,
        CASE WHEN @SortAsc = 1 THEN
            CASE @SortColumn
                WHEN 'AmountShort'   THEN CONVERT(DECIMAL(19,4), AmountShort)
                WHEN 'StaffShortsNo' THEN CONVERT(DECIMAL(19,4), StaffShortsNo)
                WHEN 'TillNo'        THEN CONVERT(DECIMAL(19,4), TillNo)
            END
        END ASC,
        CASE WHEN @SortAsc = 0 THEN
            CASE @SortColumn
                WHEN 'AmountShort'   THEN CONVERT(DECIMAL(19,4), AmountShort)
                WHEN 'StaffShortsNo' THEN CONVERT(DECIMAL(19,4), StaffShortsNo)
                WHEN 'TillNo'        THEN CONVERT(DECIMAL(19,4), TillNo)
            END
        END DESC,
        CASE WHEN @SortAsc = 1 AND @SortColumn = 'TransactionDate' THEN TransactionDate END ASC,
        CASE WHEN @SortAsc = 0 AND @SortColumn = 'TransactionDate' THEN TransactionDate END DESC,
        TransactionDate DESC, BranchName, TillNo, ShiftNo, EmployeeCode
    OFFSET @Offset ROWS FETCH NEXT @PageSize ROWS ONLY;

    SELECT COUNT_BIG(*) AS TotalRows FROM #ps_rows;

    SELECT SUM(AmountShort) AS AmountShort, SUM(CountedShort) AS CountedShort FROM #ps_rows;

    DROP TABLE #ps_rows;
END
