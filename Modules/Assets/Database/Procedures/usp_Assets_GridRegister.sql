/* ============================================================================
 * agora.usp_Assets_GridRegister — the asset register, with what each asset
 * cost, what it has depreciated by, and what it is worth now.
 *
 * Read by:  Setup -> People and assets -> Asset register (HO) and
 *           Assets -> Equipment -> Asset register (branch)   (T057)
 * Reads:    agora.Asset, agora.fn_Assets_Depreciation (the one place the
 *           depreciation arithmetic lives), agora.vw_AssetGroup / vw_AssetType
 *           (the customer's masters), agora.vw_AssetCondition, agora.Branch,
 *           agora.vw_Supplier, agora.AssetRepair / vw_AssetRepairCall.
 * Writes:   nothing.
 *
 * AS AT. @AsAt (default today) is the date the register is valued at. A month
 * is charged at its month end, so "as at 27 September" is valued to 31 August.
 * An asset disposed of on or before @AsAt is off the register and has an NBV
 * of nil; what it carried when it went is in NbvAtDisposal.
 *
 * THE RATES ARE PROVISIONAL. @RatesJson is the SARS wear-and-tear mapping from
 * Modules/Assets/Config/config.php (Ryan, 27 Sep 2026), one period per group,
 * until ZP confirm their own. A group with no period shows no NBV rather than
 * its cost pretending to be one.
 *
 * SCOPE. An asset is in scope when the site it BELONGS to or the site it is
 * AT is one of the sites asked for (@BranchIds; empty = all) and one the
 * caller may see (@AllowedBranchIds; empty = all). So a fryer on loan from
 * Engen Manguzi to Caltex Ulundi is on both sites' lists — which is the point
 * of the "Away from its site" check.
 *
 * Result sets: 1 the page · 2 (TotalRows) · 3 grand totals over the whole
 * filtered set, so the footer is the register's total and not the page's.
 *
 *     EXEC agora.usp_Assets_GridRegister @BranchIds = '8', @RatesJson = N'{"BE":{"life_months":72}}';
 * ============================================================================ */

CREATE OR ALTER PROCEDURE [agora].[usp_Assets_GridRegister]
    @BranchIds        NVARCHAR(MAX) = NULL,
    @DateFrom         DATE          = NULL,
    @DateTo           DATE          = NULL,
    @Search           NVARCHAR(200) = NULL,
    @SortColumn       NVARCHAR(80)  = NULL,
    @SortAsc          BIT           = 1,
    @Page             INT           = 1,
    @PageSize         INT           = 50,
    @FiltersJson      NVARCHAR(MAX) = NULL,
    @RatesJson        NVARCHAR(MAX) = NULL,
    @AsAt             DATE          = NULL,
    @AllowedBranchIds NVARCHAR(MAX) = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    SET @AsAt     = ISNULL(@AsAt, CONVERT(date, GETDATE()));
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
    WHERE LTRIM(RTRIM(s.value)) <> ''   -- TRY_CONVERT(int, '') is 0, not NULL
      AND TRY_CONVERT(int, LTRIM(RTRIM(s.value))) IS NOT NULL;

    DECLARE @AllBranches bit = CASE WHEN EXISTS (SELECT 1 FROM @Branch)  THEN 0 ELSE 1 END;
    DECLARE @AllAllowed  bit = CASE WHEN EXISTS (SELECT 1 FROM @Allowed) THEN 0 ELSE 1 END;

    /* Typed header filters, in the shape GridQuery::filtersJson() sends: a JSON
       ARRAY whose elements carry their own column name at $.column. */
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

    /* ---- every asset in scope, whole, once ----------------------------- */
    SELECT a.BranchId,
           a.Id                                              AS AssetId,
           a.AssetNo,
           a.AssetGroupCode,
           g.AssetGroupDescription,
           a.AssetTypeCode,
           t.AssetTypeDescription,
           a.Make,
           a.Model,
           a.SerialNo,
           a.[Description],
           ob.Name                                           AS BranchName,
           a.LocatedBranchId,
           lb.Name                                           AS LocatedAt,
           a.AssetOwner,
           a.SupplierCode,
           sp.SupplierName,
           a.DatePurchased,
           a.DateAllocated,
           a.Cost,
           d.LifeMonths,
           d.MonthsCharged,
           d.MonthlyCharge,
           d.Accumulated,
           d.Nbv,
           d.ChargeForMonth,
           d.NbvAtDisposal,
           d.ProfitOnDisposal,
           CASE d.DepreciationState
                WHEN 'NO_RATE'           THEN 'No rate'
                WHEN 'NOT_STARTED'       THEN 'Not started'
                WHEN 'FULLY_DEPRECIATED' THEN 'Fully depreciated'
                WHEN 'STOPPED'           THEN 'Off the register'
                ELSE 'Depreciating' END                      AS Depreciation,
           c.Label                                           AS Condition,
           CASE a.[Status] WHEN 'IN_SERVICE'  THEN 'In service'
                           WHEN 'AT_REPAIR'   THEN 'At repair'
                           WHEN 'DISPOSED'    THEN 'Disposed of'
                           WHEN 'WRITTEN_OFF' THEN 'Written off'
                           ELSE a.[Status] END               AS [Status],
           CASE a.[Status] WHEN 'IN_SERVICE'  THEN 'good'
                           WHEN 'AT_REPAIR'   THEN 'warn'
                           ELSE 'neutral' END                AS StatusTone,
           /* The check the legacy register never made: is the asset where
              its own site thinks it is? */
           CASE WHEN a.[Status] IN ('DISPOSED', 'WRITTEN_OFF') THEN 'Off the register'
                WHEN a.[Status] = 'AT_REPAIR'                  THEN 'Away at repair'
                WHEN a.LocatedBranchId <> a.BranchId           THEN 'Away from its site'
                ELSE 'In place' END                          AS [Check],
           CASE WHEN a.[Status] IN ('DISPOSED', 'WRITTEN_OFF') THEN 'neutral'
                WHEN a.[Status] = 'AT_REPAIR'                  THEN 'warn'
                WHEN a.LocatedBranchId <> a.BranchId           THEN 'serious'
                ELSE 'good' END                              AS CheckTone,
           ISNULL(rc.OpenJobCards, 0)                        AS OpenJobCards,
           ISNULL(rc.JobCards, 0)                            AS JobCards,
           ISNULL(rc.Comebacks, 0)                           AS Comebacks,
           a.DisposedOn,
           a.DisposalProceeds
    INTO #rows
    FROM agora.Asset a
    JOIN agora.fn_Assets_Depreciation(@RatesJson, @AsAt) d
      ON d.BranchId = a.BranchId AND d.AssetId = a.Id
    LEFT JOIN agora.vw_AssetGroup g
           ON g.AssetGroupCode = a.AssetGroupCode
    /* (group, type): CE28 exists under two groups on live. */
    LEFT JOIN agora.vw_AssetType t
           ON t.AssetGroupCode = a.AssetGroupCode AND t.AssetTypeCode = a.AssetTypeCode
    LEFT JOIN agora.vw_AssetCondition c
           ON c.Condition = a.Condition
    LEFT JOIN agora.Branch ob ON ob.BranchId = a.BranchId
    LEFT JOIN agora.Branch lb ON lb.BranchId = a.LocatedBranchId
    /* A label lookup that cannot fan: TOP 1, whatever the supplier table
       turns out to hold for one (site, code). */
    OUTER APPLY (SELECT TOP 1 s.SupplierName FROM agora.vw_Supplier s
                  WHERE s.BranchId = a.BranchId AND s.SupplierCode = a.SupplierCode) sp
    OUTER APPLY (SELECT SUM(CASE WHEN r.DateActualReturn IS NULL THEN 1 ELSE 0 END) AS OpenJobCards,
                        COUNT(*) AS JobCards,
                        SUM(CONVERT(int, k.IsComeback)) AS Comebacks
                   FROM agora.AssetRepair r
                   JOIN agora.vw_AssetRepairCall k ON k.BranchId = r.BranchId AND k.Id = r.Id
                  WHERE r.BranchId = a.BranchId AND r.AssetId = a.Id) rc
    WHERE a.DeletedAt IS NULL
      AND EXISTS (SELECT 1 FROM (VALUES (a.BranchId), (a.LocatedBranchId)) s (b)
                   WHERE (@AllBranches = 1 OR s.b IN (SELECT BranchId FROM @Branch))
                     AND (@AllAllowed  = 1 OR s.b IN (SELECT BranchId FROM @Allowed)))
      AND (@DateFrom IS NULL OR a.DatePurchased >= @DateFrom)
      AND (@DateTo   IS NULL OR a.DatePurchased <= @DateTo);

    /* ---- search and header filters, applied once --------------------- */
    SELECT r.*
    INTO #f
    FROM #rows r
    WHERE (@Search IS NULL
           OR r.AssetNo LIKE '%' + @Search + '%' OR r.Make LIKE '%' + @Search + '%'
           OR r.Model LIKE '%' + @Search + '%' OR r.SerialNo LIKE '%' + @Search + '%'
           OR r.[Description] LIKE '%' + @Search + '%' OR r.AssetTypeDescription LIKE '%' + @Search + '%'
           OR r.LocatedAt LIKE '%' + @Search + '%' OR r.BranchName LIKE '%' + @Search + '%')
      /* Every typed filter must hold. */
      AND NOT EXISTS (
            SELECT 1
            FROM @Filter f
            CROSS APPLY (SELECT
                CASE f.[Column]
                     WHEN 'AssetNo'              THEN r.AssetNo
                     WHEN 'AssetGroupDescription' THEN r.AssetGroupDescription
                     WHEN 'AssetTypeDescription' THEN r.AssetTypeDescription
                     WHEN 'Make'                 THEN r.Make
                     WHEN 'Model'                THEN r.Model
                     WHEN 'SerialNo'             THEN r.SerialNo
                     WHEN 'LocatedAt'            THEN r.LocatedAt
                     WHEN 'BranchName'           THEN r.BranchName
                     WHEN 'AssetOwner'           THEN r.AssetOwner
                     WHEN 'SupplierName'         THEN r.SupplierName
                END AS Txt,
                CASE f.[Column]
                     WHEN 'Cost'           THEN r.Cost
                     WHEN 'Accumulated'    THEN r.Accumulated
                     WHEN 'Nbv'            THEN r.Nbv
                     WHEN 'ChargeForMonth' THEN r.ChargeForMonth
                     WHEN 'JobCards'       THEN r.JobCards
                     WHEN 'Comebacks'      THEN r.Comebacks
                END AS Num,
                CASE f.[Column]
                     WHEN 'DatePurchased' THEN r.DatePurchased
                     WHEN 'DateAllocated' THEN r.DateAllocated
                END AS Dt,
                TRY_CONVERT(decimal(18,4), f.[Value]) AS NumV,
                TRY_CONVERT(date, f.[Value])          AS DtV) v
            WHERE 1 = CASE
                WHEN f.[Type] = 'number' THEN CASE
                    WHEN v.NumV IS NULL THEN 0   -- a figure nobody can read filters nothing
                    WHEN v.Num IS NULL  THEN 1   -- a blank never satisfies a comparison
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
      /* Every tick list must hold. */
      AND NOT EXISTS (
            SELECT 1
            FROM (SELECT DISTINCT [Column] FROM @FilterSet) s
            CROSS APPLY (SELECT CASE s.[Column]
                                     WHEN 'Status'       THEN r.[Status]
                                     WHEN 'Condition'    THEN r.Condition
                                     WHEN 'Check'        THEN r.[Check]
                                     WHEN 'Depreciation' THEN r.Depreciation
                                     WHEN 'AssetGroupCode' THEN r.AssetGroupCode
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
                WHEN 'AssetNo'              THEN f.AssetNo
                WHEN 'AssetGroupCode'       THEN f.AssetGroupCode
                WHEN 'AssetTypeDescription' THEN f.AssetTypeDescription
                WHEN 'Make'                 THEN f.Make
                WHEN 'Model'                THEN f.Model
                WHEN 'LocatedAt'            THEN f.LocatedAt
                WHEN 'BranchName'           THEN f.BranchName
                WHEN 'AssetOwner'           THEN f.AssetOwner
                WHEN 'Status'               THEN f.[Status]
                WHEN 'Condition'            THEN f.Condition
                WHEN 'Check'                THEN f.[Check]
            END
        END ASC,
        CASE WHEN @SortAsc = 0 THEN
            CASE @SortColumn
                WHEN 'AssetNo'              THEN f.AssetNo
                WHEN 'AssetGroupCode'       THEN f.AssetGroupCode
                WHEN 'AssetTypeDescription' THEN f.AssetTypeDescription
                WHEN 'Make'                 THEN f.Make
                WHEN 'Model'                THEN f.Model
                WHEN 'LocatedAt'            THEN f.LocatedAt
                WHEN 'BranchName'           THEN f.BranchName
                WHEN 'AssetOwner'           THEN f.AssetOwner
                WHEN 'Status'               THEN f.[Status]
                WHEN 'Condition'            THEN f.Condition
                WHEN 'Check'                THEN f.[Check]
            END
        END DESC,
        CASE WHEN @SortAsc = 1 AND @SortColumn = 'Cost'          THEN f.Cost          END ASC,
        CASE WHEN @SortAsc = 0 AND @SortColumn = 'Cost'          THEN f.Cost          END DESC,
        CASE WHEN @SortAsc = 1 AND @SortColumn = 'Nbv'           THEN f.Nbv           END ASC,
        CASE WHEN @SortAsc = 0 AND @SortColumn = 'Nbv'           THEN f.Nbv           END DESC,
        CASE WHEN @SortAsc = 1 AND @SortColumn = 'Accumulated'   THEN f.Accumulated   END ASC,
        CASE WHEN @SortAsc = 0 AND @SortColumn = 'Accumulated'   THEN f.Accumulated   END DESC,
        CASE WHEN @SortAsc = 1 AND @SortColumn = 'Comebacks'     THEN f.Comebacks     END ASC,
        CASE WHEN @SortAsc = 0 AND @SortColumn = 'Comebacks'     THEN f.Comebacks     END DESC,
        CASE WHEN @SortAsc = 1 AND @SortColumn = 'DatePurchased' THEN f.DatePurchased END ASC,
        CASE WHEN @SortAsc = 0 AND @SortColumn = 'DatePurchased' THEN f.DatePurchased END DESC,
        f.BranchName, f.AssetGroupCode, f.AssetNo
    OFFSET @Offset ROWS FETCH NEXT @PageSize ROWS ONLY;

    SELECT COUNT_BIG(*) AS TotalRows FROM #f;

    SELECT SUM(f.Cost)           AS Cost,
           SUM(f.Accumulated)    AS Accumulated,
           SUM(f.Nbv)            AS Nbv,
           SUM(f.ChargeForMonth) AS ChargeForMonth,
           SUM(f.JobCards)       AS JobCards,
           SUM(f.Comebacks)      AS Comebacks
    FROM #f f;
END
