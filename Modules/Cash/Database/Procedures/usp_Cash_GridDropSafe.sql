/* ============================================================================
   agora.usp_Cash_GridDropSafe

   WHAT IT IS FOR
   Drop safe bags: every bag dropped into a site's manual safe, why, who
   dropped it and who witnessed it, and whether it has been collected — the
   mockup's Drop Safe grid and PumpIT's drop safe report
   (dbo.sp_SelectDropSafe), across sites.

   WHO READS IT
   Cash → Drop safe bags (app.cash.dropsafe.bags). Head office across sites,
   and the branch for its own.

   ONE ROW PER BAG (agora.vw_DropSafeBag). Status is PumpIT's own: 'In the
   safe' while CollectionId is 0, 'Collected' once it names a collection —
   whose security-company bag (DBagNo) and day come with it, TOP 1 by the
   collection's id, so nothing can multiply a bag. Banked says whether the bag
   is on a cashup (IsSelectedForDailyBanking). NeedsReason flags a bag over
   @Threshold with no reason — flagged, never hidden. Names are looked up TOP 1
   per (BranchId, EmployeeCode). CapturedIn is 'Agora' where the bag as it
   stands was written by agora.usp_Cash_SaveDropSafe (agora.DropSafeEvent).

   The period is the bag's drop day.

   The grid procedure template (feature-rules §2), plus @AllowedBranchIds (the
   sites the person may see; NULL = every site — an empty @BranchIds means
   these) and @Threshold (cash.dropsafe.reason_threshold).

   Result sets: 1 the page, 2 (TotalRows), 3 grand totals (Amount,
   InSafeAmount, InSafeBags).

   READ-ONLY.

     EXEC agora.usp_Cash_GridDropSafe @BranchIds = '3', @DateFrom = '2026-09-01', @DateTo = '2026-09-27';
   ============================================================================ */
CREATE OR ALTER PROCEDURE [agora].[usp_Cash_GridDropSafe]
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
    @Threshold         MONEY         = 2000.00
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE @Today DATE = CONVERT(DATE, GETDATE());

    SET @Page      = CASE WHEN ISNULL(@Page, 1) < 1 THEN 1 ELSE @Page END;
    SET @PageSize  = CASE WHEN ISNULL(@PageSize, 50) BETWEEN 1 AND 100000 THEN @PageSize ELSE 50 END;
    SET @Search    = NULLIF(LTRIM(RTRIM(ISNULL(@Search, ''))), '');
    SET @DateTo    = ISNULL(@DateTo, @Today);
    SET @DateFrom  = ISNULL(@DateFrom, DATEADD(day, -29, @DateTo));
    SET @Threshold = ISNULL(@Threshold, 2000.00);

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
        d.BranchId,
        b.Name                                                         AS BranchName,
        CONVERT(DATE, d.DropDate)                                      AS DropDate,
        d.DropTime,
        LTRIM(RTRIM(d.BagNo))                                          AS BagNo,
        d.Amount,
        r.ReasonForManualBag,
        NULLIF(LTRIM(RTRIM(d.ReasonIfAmountMoreThanR2000)), '')        AS LimitReason,
        NULLIF(LTRIM(RTRIM(d.RefNoFaultReported)), '')                 AS RefNoFaultReported,
        LTRIM(RTRIM(d.CashierCode))                                    AS CashierCode,
        k.EmployeeName                                                 AS CashierName,
        LTRIM(RTRIM(d.ManagerCode))                                    AS ManagerCode,
        m.EmployeeName                                                 AS ManagerName,
        CASE WHEN ISNULL(d.CollectionId, 0) = 0 THEN 'In the safe' ELSE 'Collected' END AS [Status],
        NULLIF(CONVERT(BIGINT, d.CollectionId), 0)                     AS CollectionId,
        NULLIF(LTRIM(RTRIM(c.DBagNo)), '')                             AS DBagNo,
        CONVERT(DATE, c.CollectionDate)                                AS CollectionDate,
        CASE WHEN d.IsSelectedForDailyBanking = 1 THEN 'On a cashup' ELSE 'Not on a cashup' END AS Banked,
        CONVERT(BIT, CASE WHEN d.Amount > @Threshold AND ISNULL(LTRIM(RTRIM(d.ReasonIfAmountMoreThanR2000)), '') = '' THEN 1 ELSE 0 END) AS NeedsReason,
        d.CreateDateTime                                               AS CapturedAt,
        CASE WHEN a.Id IS NULL THEN 'PumpIT' ELSE 'Agora' END          AS CapturedIn,
        DATEDIFF(day, d.DropDate, @Today)                              AS AgeDays
    INTO #ds_rows
    FROM agora.vw_DropSafeBag d
    OUTER APPLY (SELECT TOP 1 x.Name FROM agora.Branch x WHERE x.BranchId = d.BranchId ORDER BY x.Id) b
    OUTER APPLY (SELECT TOP 1 x.ReasonForManualBag FROM agora.vw_DropSafeReason x
                 WHERE x.ReasonForManualBagId = d.ReasonForManualBagId) r
    OUTER APPLY (SELECT TOP 1 x.EmployeeName FROM agora.vw_Employee x
                 WHERE x.BranchId = d.BranchId AND x.EmployeeCode = d.CashierCode) k
    OUTER APPLY (SELECT TOP 1 x.EmployeeName FROM agora.vw_Employee x
                 WHERE x.BranchId = d.BranchId AND x.EmployeeCode = d.ManagerCode) m
    OUTER APPLY (SELECT TOP 1 x.DBagNo, x.CollectionDate FROM agora.vw_DropSafeCollectionHeader x
                 WHERE x.BranchId = d.BranchId AND x.CollectionId = d.CollectionId AND ISNULL(d.CollectionId, 0) <> 0
                 ORDER BY x.CollectionId) c
    OUTER APPLY (SELECT TOP 1 e.Id FROM agora.DropSafeEvent e
                 WHERE e.BranchId = d.BranchId AND e.BagNo = d.BagNo AND e.[Event] IN ('dropped', 'edited')
                 ORDER BY e.Id DESC) a
    WHERE d.DropDate >= @From AND d.DropDate < @To
      AND (@AllBranches = 1 OR d.BranchId IN (SELECT BranchId FROM @Branch));

    IF @Search IS NOT NULL
        DELETE FROM #ds_rows
        WHERE NOT (   ISNULL(BranchName, '')         LIKE '%' + @Search + '%'
                   OR ISNULL(BagNo, '')              LIKE '%' + @Search + '%'
                   OR ISNULL(DBagNo, '')             LIKE '%' + @Search + '%'
                   OR ISNULL(CashierName, '')        LIKE '%' + @Search + '%'
                   OR ISNULL(ManagerName, '')        LIKE '%' + @Search + '%'
                   OR ISNULL(CashierCode, '')        LIKE '%' + @Search + '%'
                   OR ISNULL(ReasonForManualBag, '') LIKE '%' + @Search + '%'
                   OR ISNULL(LimitReason, '')        LIKE '%' + @Search + '%'
                   OR ISNULL(RefNoFaultReported, '') LIKE '%' + @Search + '%');

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
    FROM #ds_rows r
    WHERE EXISTS (
        SELECT 1
        FROM @Filter f
        CROSS APPLY (SELECT
            CASE f.[Column]
                WHEN 'BranchName'         THEN r.BranchName
                WHEN 'BagNo'              THEN r.BagNo
                WHEN 'DropTime'           THEN r.DropTime
                WHEN 'ReasonForManualBag' THEN r.ReasonForManualBag
                WHEN 'LimitReason'        THEN r.LimitReason
                WHEN 'RefNoFaultReported' THEN r.RefNoFaultReported
                WHEN 'CashierName'        THEN r.CashierName
                WHEN 'CashierCode'        THEN r.CashierCode
                WHEN 'ManagerName'        THEN r.ManagerName
                WHEN 'ManagerCode'        THEN r.ManagerCode
                WHEN 'DBagNo'             THEN r.DBagNo
            END AS T,
            CASE f.[Column]
                WHEN 'Amount'       THEN CONVERT(DECIMAL(19,4), r.Amount)
                WHEN 'CollectionId' THEN CONVERT(DECIMAL(19,4), r.CollectionId)
                WHEN 'AgeDays'      THEN CONVERT(DECIMAL(19,4), r.AgeDays)
            END AS N,
            CASE f.[Column]
                WHEN 'DropDate'       THEN r.DropDate
                WHEN 'CollectionDate' THEN r.CollectionDate
            END AS D,
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
                                  WHEN 'BranchName'         THEN r.BranchName
                                  WHEN 'Status'             THEN r.[Status]
                                  WHEN 'Banked'             THEN r.Banked
                                  WHEN 'CapturedIn'         THEN r.CapturedIn
                                  WHEN 'ReasonForManualBag' THEN r.ReasonForManualBag
                                  WHEN 'NeedsReason'        THEN CASE WHEN r.NeedsReason = 1 THEN 'Needs a reason' ELSE 'Has one, or needs none' END
                              END)
    );

    DECLARE @Offset INT = (@Page - 1) * @PageSize;

    SELECT *
    FROM #ds_rows
    ORDER BY
        CASE WHEN @SortAsc = 1 THEN
            CASE @SortColumn
                WHEN 'BranchName'         THEN BranchName
                WHEN 'BagNo'              THEN BagNo
                WHEN 'DropTime'           THEN DropTime
                WHEN 'ReasonForManualBag' THEN ReasonForManualBag
                WHEN 'LimitReason'        THEN LimitReason
                WHEN 'CashierName'        THEN CashierName
                WHEN 'ManagerName'        THEN ManagerName
                WHEN 'Status'             THEN [Status]
                WHEN 'DBagNo'             THEN DBagNo
                WHEN 'Banked'             THEN Banked
                WHEN 'CapturedIn'         THEN CapturedIn
            END
        END ASC,
        CASE WHEN @SortAsc = 0 THEN
            CASE @SortColumn
                WHEN 'BranchName'         THEN BranchName
                WHEN 'BagNo'              THEN BagNo
                WHEN 'DropTime'           THEN DropTime
                WHEN 'ReasonForManualBag' THEN ReasonForManualBag
                WHEN 'LimitReason'        THEN LimitReason
                WHEN 'CashierName'        THEN CashierName
                WHEN 'ManagerName'        THEN ManagerName
                WHEN 'Status'             THEN [Status]
                WHEN 'DBagNo'             THEN DBagNo
                WHEN 'Banked'             THEN Banked
                WHEN 'CapturedIn'         THEN CapturedIn
            END
        END DESC,
        CASE WHEN @SortAsc = 1 THEN
            CASE @SortColumn
                WHEN 'Amount'       THEN CONVERT(DECIMAL(19,4), Amount)
                WHEN 'CollectionId' THEN CONVERT(DECIMAL(19,4), CollectionId)
                WHEN 'AgeDays'      THEN CONVERT(DECIMAL(19,4), AgeDays)
            END
        END ASC,
        CASE WHEN @SortAsc = 0 THEN
            CASE @SortColumn
                WHEN 'Amount'       THEN CONVERT(DECIMAL(19,4), Amount)
                WHEN 'CollectionId' THEN CONVERT(DECIMAL(19,4), CollectionId)
                WHEN 'AgeDays'      THEN CONVERT(DECIMAL(19,4), AgeDays)
            END
        END DESC,
        CASE WHEN @SortAsc = 1 THEN
            CASE @SortColumn
                WHEN 'DropDate'       THEN DropDate
                WHEN 'CollectionDate' THEN CollectionDate
            END
        END ASC,
        CASE WHEN @SortAsc = 0 THEN
            CASE @SortColumn
                WHEN 'DropDate'       THEN DropDate
                WHEN 'CollectionDate' THEN CollectionDate
            END
        END DESC,
        /* In the safe first — they are the ones still to be handed over. */
        CASE WHEN [Status] = 'In the safe' THEN 0 ELSE 1 END, DropDate DESC, DropTime DESC, BranchName, BagNo
    OFFSET @Offset ROWS FETCH NEXT @PageSize ROWS ONLY;

    SELECT COUNT_BIG(*) AS TotalRows FROM #ds_rows;

    SELECT CONVERT(MONEY, SUM(Amount)) AS Amount,
           CONVERT(MONEY, SUM(CASE WHEN [Status] = 'In the safe' THEN Amount ELSE 0 END)) AS InSafeAmount,
           ISNULL(SUM(CASE WHEN [Status] = 'In the safe' THEN 1 ELSE 0 END), 0) AS InSafeBags
    FROM #ds_rows;

    DROP TABLE #ds_rows;
END
