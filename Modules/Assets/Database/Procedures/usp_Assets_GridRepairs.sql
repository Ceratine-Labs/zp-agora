/* ============================================================================
 * agora.usp_Assets_GridRepairs — repair job cards, with the comeback flag.
 *
 * Read by:  Setup -> People and assets -> Asset repair (HO) and
 *           Assets -> Equipment -> Repairs (branch)   (T057)
 * Reads:    agora.AssetRepair, agora.vw_AssetRepairCall (the comeback rule),
 *           agora.vw_AssetRepairCost (what the repair cost: the approved
 *           purchase request), agora.Asset, agora.vw_AssetType,
 *           agora.vw_Supplier, agora.Branch.
 * Writes:   nothing.
 *
 * A COMEBACK is a second call on the same asset within 30 days of the call
 * before it (agora.vw_AssetRepairCall — the rule lives there, not here).
 * `PrevSupplierName` is whose repair did not hold, and the comeback counts
 * against THEM in agora.usp_Assets_Comebacks.
 *
 * Result, one word per card, in this order of precedence:
 *   Cancelled  raised in error; not a call, so never a comeback
 *   Comeback   the rule above
 *   Overdue    not back, and the expected return date has passed
 *   Late       back, after the date it was expected
 *   Open       not back yet (or not marked done)
 *   Done       back, on time or with no date promised
 * Late and Overdue are the mockup's second supplier measure; both are
 * computable from dates the legacy form already captured.
 *
 * COST is the approved purchase request's amount. A request that pays for
 * several cards shows on each and says so (SharedBy); the footer total
 * counts each request once.
 *
 * SCOPE. A card is in scope when the asset's own site or the site the repair
 * was at is asked for (@BranchIds; empty = all) and may be seen
 * (@AllowedBranchIds; empty = all). @DateFrom / @DateTo bound the call date;
 * with neither, the last 365 days — a comeback needs the call before it on the
 * same list to be understood.
 *
 * Result sets: 1 the page · 2 (TotalRows) · 3 grand totals.
 * ============================================================================ */

CREATE OR ALTER PROCEDURE [agora].[usp_Assets_GridRepairs]
    @BranchIds        NVARCHAR(MAX) = NULL,
    @DateFrom         DATE          = NULL,
    @DateTo           DATE          = NULL,
    @Search           NVARCHAR(200) = NULL,
    @SortColumn       NVARCHAR(80)  = NULL,
    @SortAsc          BIT           = 1,
    @Page             INT           = 1,
    @PageSize         INT           = 50,
    @FiltersJson      NVARCHAR(MAX) = NULL,
    @AllowedBranchIds NVARCHAR(MAX) = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE @Today date = CONVERT(date, GETDATE());

    IF @DateFrom IS NULL AND @DateTo IS NULL
        SET @DateFrom = DATEADD(DAY, -365, @Today);

    SET @Page     = CASE WHEN ISNULL(@Page, 1) < 1 THEN 1 ELSE @Page END;
    SET @PageSize = CASE WHEN ISNULL(@PageSize, 50) BETWEEN 1 AND 100000 THEN @PageSize ELSE 50 END;
    SET @Search   = NULLIF(LTRIM(RTRIM(ISNULL(@Search, ''))), '');

    DECLARE @Branch  TABLE (BranchId int PRIMARY KEY);
    DECLARE @Allowed TABLE (BranchId int PRIMARY KEY);

    INSERT INTO @Branch (BranchId)
    SELECT DISTINCT TRY_CONVERT(int, LTRIM(RTRIM(s.value)))
    FROM STRING_SPLIT(ISNULL(@BranchIds, ''), ',') s
    WHERE LTRIM(RTRIM(s.value)) <> ''   -- TRY_CONVERT(int, '') is 0, not NULL
      AND TRY_CONVERT(int, LTRIM(RTRIM(s.value))) IS NOT NULL;

    INSERT INTO @Allowed (BranchId)
    SELECT DISTINCT TRY_CONVERT(int, LTRIM(RTRIM(s.value)))
    FROM STRING_SPLIT(ISNULL(@AllowedBranchIds, ''), ',') s
    WHERE LTRIM(RTRIM(s.value)) <> ''
      AND TRY_CONVERT(int, LTRIM(RTRIM(s.value))) IS NOT NULL;

    DECLARE @AllBranches bit = CASE WHEN EXISTS (SELECT 1 FROM @Branch)  THEN 0 ELSE 1 END;
    DECLARE @AllAllowed  bit = CASE WHEN EXISTS (SELECT 1 FROM @Allowed) THEN 0 ELSE 1 END;

    DECLARE @Filter    TABLE ([Column] nvarchar(80), [Type] nvarchar(10), [Op] nvarchar(20), [Value] nvarchar(400));
    DECLARE @FilterSet TABLE ([Column] nvarchar(80), [Value] nvarchar(400));

    IF @FiltersJson IS NOT NULL AND ISJSON(@FiltersJson) = 1
    BEGIN
        INSERT INTO @Filter ([Column], [Type], [Op], [Value])
        SELECT JSON_VALUE(f.value, '$.column'),
               ISNULL(JSON_VALUE(f.value, '$.type'), 'text'),
               ISNULL(JSON_VALUE(f.value, '$.op'), 'contains'),
               JSON_VALUE(f.value, '$.value')
        FROM OPENJSON(@FiltersJson) f
        WHERE JSON_VALUE(f.value, '$.value') IS NOT NULL;

        INSERT INTO @FilterSet ([Column], [Value])
        SELECT JSON_VALUE(f.value, '$.column'), v.value
        FROM OPENJSON(@FiltersJson) f
        CROSS APPLY OPENJSON(f.value, '$.in') v
        WHERE JSON_VALUE(f.value, '$.type') = 'set';
    END

    SELECT r.BranchId,
           r.Id                                     AS RepairId,
           r.JobCardNo,
           r.AssetId,
           a.AssetNo,
           a.Make,
           a.Model,
           t.AssetTypeDescription,
           ob.Name                                  AS BranchName,
           r.SiteBranchId,
           sb.Name                                  AS SiteName,
           r.CallDate,
           r.Problem,
           r.SupplierCode,
           sp.SupplierName,
           r.SupplierReference,
           r.TransactionNo,
           c.RequestState,
           c.RepairCost,
           c.SharedBy,
           r.DateExpectedReturn,
           r.DateActualReturn,
           CASE WHEN r.IsCancelled = 0
                THEN DATEDIFF(DAY, r.CallDate, ISNULL(r.DateActualReturn, @Today)) END AS DaysOut,
           k.PrevJobCardNo,
           k.PrevCallDate,
           k.DaysSincePrevious,
           pp.SupplierName                          AS PrevSupplierName,
           CASE WHEN k.IsComeback = 1 THEN 'Yes' ELSE 'No' END AS IsComeback,
           x.Result,
           CASE x.Result WHEN 'Cancelled' THEN 'neutral'
                         WHEN 'Comeback'  THEN 'warn'
                         WHEN 'Overdue'   THEN 'serious'
                         WHEN 'Late'      THEN 'serious'
                         WHEN 'Open'      THEN 'neutral'
                         ELSE 'good' END            AS ResultTone,
           r.CancelReason
    INTO #rows
    FROM agora.AssetRepair r
    JOIN agora.Asset a ON a.BranchId = r.BranchId AND a.Id = r.AssetId
    LEFT JOIN agora.vw_AssetRepairCall k ON k.BranchId = r.BranchId AND k.Id = r.Id
    LEFT JOIN agora.vw_AssetRepairCost c ON c.BranchId = r.BranchId AND c.Id = r.Id
    LEFT JOIN agora.vw_AssetType t ON t.AssetGroupCode = a.AssetGroupCode AND t.AssetTypeCode = a.AssetTypeCode
    LEFT JOIN agora.Branch ob ON ob.BranchId = r.BranchId
    LEFT JOIN agora.Branch sb ON sb.BranchId = r.SiteBranchId
    OUTER APPLY (SELECT TOP 1 s.SupplierName FROM agora.vw_Supplier s
                  WHERE s.BranchId = r.SiteBranchId AND s.SupplierCode = r.SupplierCode) sp
    OUTER APPLY (SELECT TOP 1 s.SupplierName FROM agora.vw_Supplier s
                  WHERE s.BranchId = k.PrevSiteBranchId AND s.SupplierCode = k.PrevSupplierCode) pp
    CROSS APPLY (SELECT CASE
                     WHEN r.IsCancelled = 1 THEN 'Cancelled'
                     WHEN k.IsComeback = 1  THEN 'Comeback'
                     WHEN r.DateActualReturn IS NULL AND r.DateExpectedReturn < @Today THEN 'Overdue'
                     WHEN r.DateActualReturn > r.DateExpectedReturn THEN 'Late'
                     WHEN r.DateActualReturn IS NULL THEN 'Open'
                     ELSE 'Done' END AS Result) x
    WHERE EXISTS (SELECT 1 FROM (VALUES (r.BranchId), (r.SiteBranchId)) s (b)
                   WHERE (@AllBranches = 1 OR s.b IN (SELECT BranchId FROM @Branch))
                     AND (@AllAllowed  = 1 OR s.b IN (SELECT BranchId FROM @Allowed)))
      AND (@DateFrom IS NULL OR r.CallDate >= @DateFrom)
      AND (@DateTo   IS NULL OR r.CallDate <= @DateTo);

    SELECT r.*
    INTO #f
    FROM #rows r
    WHERE (@Search IS NULL
           OR r.JobCardNo LIKE '%' + @Search + '%' OR r.AssetNo LIKE '%' + @Search + '%'
           OR r.Problem LIKE '%' + @Search + '%' OR r.SupplierName LIKE '%' + @Search + '%'
           OR r.TransactionNo LIKE '%' + @Search + '%' OR r.SupplierReference LIKE '%' + @Search + '%'
           OR r.SiteName LIKE '%' + @Search + '%')
      AND NOT EXISTS (
            SELECT 1
            FROM @Filter f
            CROSS APPLY (SELECT
                CASE f.[Column]
                     WHEN 'JobCardNo'            THEN r.JobCardNo
                     WHEN 'AssetNo'              THEN r.AssetNo
                     WHEN 'AssetTypeDescription' THEN r.AssetTypeDescription
                     WHEN 'SiteName'             THEN r.SiteName
                     WHEN 'Problem'              THEN r.Problem
                     WHEN 'SupplierName'         THEN r.SupplierName
                     WHEN 'TransactionNo'        THEN r.TransactionNo
                     WHEN 'PrevSupplierName'     THEN r.PrevSupplierName
                END AS Txt,
                CASE f.[Column]
                     WHEN 'RepairCost'        THEN r.RepairCost
                     WHEN 'DaysOut'           THEN r.DaysOut
                     WHEN 'DaysSincePrevious' THEN r.DaysSincePrevious
                END AS Num,
                CASE f.[Column]
                     WHEN 'CallDate'           THEN r.CallDate
                     WHEN 'DateExpectedReturn' THEN r.DateExpectedReturn
                     WHEN 'DateActualReturn'   THEN r.DateActualReturn
                END AS Dt,
                TRY_CONVERT(decimal(18,4), f.[Value]) AS NumV,
                TRY_CONVERT(date, f.[Value])          AS DtV) v
            WHERE 1 = CASE
                WHEN f.[Type] = 'number' THEN CASE
                    WHEN v.NumV IS NULL THEN 0
                    WHEN v.Num IS NULL  THEN 1
                    WHEN f.[Op] = 'eq'  AND v.Num =  v.NumV THEN 0
                    WHEN f.[Op] = 'ne'  AND v.Num <> v.NumV THEN 0
                    WHEN f.[Op] = 'gt'  AND v.Num >  v.NumV THEN 0
                    WHEN f.[Op] = 'gte' AND v.Num >= v.NumV THEN 0
                    WHEN f.[Op] = 'lt'  AND v.Num <  v.NumV THEN 0
                    WHEN f.[Op] = 'lte' AND v.Num <= v.NumV THEN 0
                    ELSE 1 END
                WHEN f.[Type] = 'date' THEN CASE
                    WHEN v.DtV IS NULL THEN 0
                    WHEN v.Dt IS NULL  THEN 1
                    WHEN f.[Op] = 'eq'  AND v.Dt =  v.DtV THEN 0
                    WHEN f.[Op] = 'ne'  AND v.Dt <> v.DtV THEN 0
                    WHEN f.[Op] = 'gt'  AND v.Dt >  v.DtV THEN 0
                    WHEN f.[Op] = 'gte' AND v.Dt >= v.DtV THEN 0
                    WHEN f.[Op] = 'lt'  AND v.Dt <  v.DtV THEN 0
                    WHEN f.[Op] = 'lte' AND v.Dt <= v.DtV THEN 0
                    ELSE 1 END
                ELSE CASE
                    WHEN f.[Op] = 'eq' AND v.Txt = f.[Value] THEN 0
                    WHEN f.[Op] = 'ne' AND ISNULL(v.Txt, '') <> f.[Value] THEN 0
                    WHEN f.[Op] NOT IN ('eq', 'ne') AND v.Txt LIKE '%' + f.[Value] + '%' THEN 0
                    ELSE 1 END
                END
      )
      AND NOT EXISTS (
            SELECT 1
            FROM (SELECT DISTINCT [Column] FROM @FilterSet) s
            CROSS APPLY (SELECT CASE s.[Column]
                                     WHEN 'Result'       THEN r.Result
                                     WHEN 'IsComeback'   THEN r.IsComeback
                                     WHEN 'RequestState' THEN r.RequestState
                                END AS Txt) v
            WHERE v.Txt IS NULL
               OR v.Txt NOT IN (SELECT x.[Value] FROM @FilterSet x WHERE x.[Column] = s.[Column])
      );

    DECLARE @Offset int = (@Page - 1) * @PageSize;

    SELECT f.*
    FROM #f f
    ORDER BY
        CASE WHEN @SortAsc = 1 THEN
            CASE @SortColumn
                WHEN 'JobCardNo'    THEN f.JobCardNo
                WHEN 'AssetNo'      THEN f.AssetNo
                WHEN 'SiteName'     THEN f.SiteName
                WHEN 'SupplierName' THEN f.SupplierName
                WHEN 'Result'       THEN f.Result
            END
        END ASC,
        CASE WHEN @SortAsc = 0 THEN
            CASE @SortColumn
                WHEN 'JobCardNo'    THEN f.JobCardNo
                WHEN 'AssetNo'      THEN f.AssetNo
                WHEN 'SiteName'     THEN f.SiteName
                WHEN 'SupplierName' THEN f.SupplierName
                WHEN 'Result'       THEN f.Result
            END
        END DESC,
        CASE WHEN @SortAsc = 1 AND @SortColumn = 'CallDate'   THEN f.CallDate   END ASC,
        CASE WHEN @SortAsc = 0 AND @SortColumn = 'CallDate'   THEN f.CallDate   END DESC,
        CASE WHEN @SortAsc = 1 AND @SortColumn = 'RepairCost' THEN f.RepairCost END ASC,
        CASE WHEN @SortAsc = 0 AND @SortColumn = 'RepairCost' THEN f.RepairCost END DESC,
        CASE WHEN @SortAsc = 1 AND @SortColumn = 'DaysOut'    THEN f.DaysOut    END ASC,
        CASE WHEN @SortAsc = 0 AND @SortColumn = 'DaysOut'    THEN f.DaysOut    END DESC,
        f.CallDate DESC, f.RepairId DESC
    OFFSET @Offset ROWS FETCH NEXT @PageSize ROWS ONLY;

    SELECT COUNT_BIG(*) AS TotalRows FROM #f;

    /* Cost counts each purchase request ONCE, however many cards it pays for. */
    SELECT (SELECT SUM(q.RepairCost)
              FROM (SELECT DISTINCT f.SiteBranchId, f.TransactionNo, f.RepairCost
                      FROM #f f WHERE f.TransactionNo IS NOT NULL AND f.Result <> 'Cancelled') q) AS RepairCost,
           (SELECT COUNT(*) FROM #f f WHERE f.IsComeback = 'Yes')                              AS Comebacks;
END
