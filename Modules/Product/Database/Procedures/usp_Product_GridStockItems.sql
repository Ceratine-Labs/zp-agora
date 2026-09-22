/* ============================================================================
 * agora.usp_Product_GridStockItems — the stock recon master listing.
 *
 * Read by:  Setup -> Trading rules -> Stock recon master  (T025)
 * Reads:    agora.vw_StockItem (the override-or-legacy resolution),
 *           agora.vw_StockArea, agora.vw_StockItemPos, agora.vw_StockItemCritical,
 *           agora.StockItemCountStat, agora.Branch
 * Writes:   nothing.
 *
 * The grid procedure template (feature-rules §2), nine parameters, @FiltersJson
 * declared because the questions this screen answers are all filters: "the
 * ARCH lines at this site that nobody has counted in ninety days", "everything
 * priced by Factor", "the critical lines with nothing on hand".
 *
 * ---------------------------------------------------------------------------
 * THE THREE JOINS, AND WHY EACH IS THE SHAPE IT IS
 * ---------------------------------------------------------------------------
 *
 *   · THE COST FILE joins on (BranchId, PosSystem, PosCode) — ALL THREE.
 *     DBF_STDB's own primary key is (SSBranchId, CODE, LOCATION). Joining on
 *     branch and code alone returns 8,161 rows for 7,447 items AND attaches a
 *     different product's cost, category and description to 713 of them:
 *     branch 18's code 999 is a Mitchum roll-on under WINBRANCH, a prawn salad
 *     under AURA and a Clover crate under ARCH. agora.vw_StockItemPos exists
 *     so this join is written once; do not inline DBF_STDB here.
 *
 *   · THE CRITICAL LIST joins on the same three columns, because it is keyed
 *     by POS code rather than by item number.
 *
 *   · THE COUNT ROLLUP joins on (BranchId, StockItemNo) and is a LEFT join
 *     whose ABSENCE IS MEANINGFUL. No row means nobody has ever counted that
 *     line — 4,434 of 7,447 items are in that position and two whole branches
 *     have never counted at all. Nothing here coalesces that to zero.
 *
 * ---------------------------------------------------------------------------
 * GP% IS COMPUTED FROM THE POS FILE'S OWN PAIR, AND THAT IS A DELIBERATE CHOICE
 * ---------------------------------------------------------------------------
 *
 * STDSELL and STDCOST are both EX-VAT, so (STDSELL - STDCOST) / STDSELL needs
 * no VAT rate and cannot be wrong about one. The master's own SellingPrice is
 * INCLUSIVE — for a 'Selling Price' item it is literally STDSELL x 1.15, which
 * the live rows confirm to the cent — so mixing it with STDCOST would overstate
 * GP by the VAT. Both prices are returned side by side (SellingPrice and
 * PosSellPrice) precisely so the divergence is visible where there is one:
 * item 401 at Esikhawini sells at 19.99 against an STDSELL implying 26.99, and
 * that is a real pricing question, not a rounding artefact.
 *
 * ---------------------------------------------------------------------------
 * STATUS IS THE COLUMN PUMPIT CANNOT HAVE
 * ---------------------------------------------------------------------------
 *
 * Retired · Never counted · Not counted in 90 days · Active. The legacy table
 * has no active flag at all, which is why a picker built straight off it
 * offers dead lines; Retired comes from an Agora override, and the two
 * counting states come from the rollup. This column is the point of the
 * screen as much as the prices are.
 *
 *     EXEC agora.usp_Product_GridStockItems @BranchIds = '9', @PageSize = 20;
 * ============================================================================ */

CREATE OR ALTER PROCEDURE [agora].[usp_Product_GridStockItems]
    @BranchIds   NVARCHAR(MAX) = NULL,
    @DateFrom    DATE          = NULL,
    @DateTo      DATE          = NULL,
    @Search      NVARCHAR(200) = NULL,
    @SortColumn  NVARCHAR(80)  = NULL,
    @SortAsc     BIT           = 1,
    @Page        INT           = 1,
    @PageSize    INT           = 50,
    @FiltersJson NVARCHAR(MAX) = NULL
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @Offset INT = (CASE WHEN @Page < 1 THEN 0 ELSE (@Page - 1) END) * @PageSize;

    DECLARE @Branch TABLE (BranchId int PRIMARY KEY);
    INSERT INTO @Branch (BranchId)
    SELECT DISTINCT TRY_CONVERT(int, LTRIM(RTRIM(s.value)))
    FROM STRING_SPLIT(ISNULL(@BranchIds, ''), ',') s
    WHERE LTRIM(RTRIM(s.value)) <> ''
      AND TRY_CONVERT(int, LTRIM(RTRIM(s.value))) IS NOT NULL;

    DECLARE @AllBranches bit = CASE WHEN EXISTS (SELECT 1 FROM @Branch) THEN 0 ELSE 1 END;

    /* Typed header filters, in the shape GridQuery::filtersJson() actually
       sends: a JSON ARRAY whose elements carry their own column name at
       $.column. Reading the OPENJSON key instead is the bug that made every
       filter on Setup -> Users and access narrow nothing at all, silently,
       for weeks — see that procedure's header. */
    DECLARE @Filter TABLE ([Column] nvarchar(80), [Op] nvarchar(20), [Value] nvarchar(400));
    DECLARE @FilterSet TABLE ([Column] nvarchar(80), [Value] nvarchar(400));

    IF @FiltersJson IS NOT NULL
    BEGIN
        INSERT INTO @Filter ([Column], [Op], [Value])
        SELECT JSON_VALUE(f.value, '$.column'),
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

    DECLARE @fDescription NVARCHAR(400) = (SELECT TOP 1 [Value] FROM @Filter WHERE [Column] = 'StockItemDescription'),
            @fPosCode     NVARCHAR(400) = (SELECT TOP 1 [Value] FROM @Filter WHERE [Column] = 'POSCode'),
            @fArea        NVARCHAR(400) = (SELECT TOP 1 [Value] FROM @Filter WHERE [Column] = 'AreaDescription'),
            @fCategory    NVARCHAR(400) = (SELECT TOP 1 [Value] FROM @Filter WHERE [Column] = 'Category'),
            @fItemNo      NVARCHAR(400) = (SELECT TOP 1 [Value] FROM @Filter WHERE [Column] = 'StockItemNo');

    DECLARE @fPosSystemAny bit = CASE WHEN EXISTS (SELECT 1 FROM @FilterSet WHERE [Column] = 'PosSystem')  THEN 1 ELSE 0 END,
            @fPriceTypeAny bit = CASE WHEN EXISTS (SELECT 1 FROM @FilterSet WHERE [Column] = 'PriceType')  THEN 1 ELSE 0 END,
            @fUomAny       bit = CASE WHEN EXISTS (SELECT 1 FROM @FilterSet WHERE [Column] = 'UOMCode')    THEN 1 ELSE 0 END,
            @fStatusAny    bit = CASE WHEN EXISTS (SELECT 1 FROM @FilterSet WHERE [Column] = 'Status')     THEN 1 ELSE 0 END,
            @fSourceAny    bit = CASE WHEN EXISTS (SELECT 1 FROM @FilterSet WHERE [Column] = 'Source')     THEN 1 ELSE 0 END;

    ;WITH items AS (
        SELECT
            i.BranchId,
            b.Name                                  AS BranchName,
            i.StockItemNo,
            /* '10' before '9' is what a text sort gives, and every item
               number here is a number wearing an NVARCHAR(5). Sorted on the
               integer where it converts, on the text where it does not. */
            TRY_CONVERT(int, i.StockItemNo)         AS ItemNoNumeric,
            i.StockItemDescription,
            i.PosSystem,
            i.POSCode,
            i.AreaNo,
            a.AreaDescription,
            a.AreaGroup,
            i.UOMCode,
            i.PriceType,
            i.SellingPrice,
            i.Factor,
            i.IsMonitoredItem,
            i.IsDoCloseQtyCalc,
            i.IsAllowNegativeQtyIssued,
            i.IsAllowNegativeQtyClose,
            i.IsStockItemPreProduction,
            i.IsPreProductionItem,
            i.IsActive,
            i.[Source],

            p.CostPrice,
            p.PosSellPrice,
            p.QtyOnHand,
            p.QtySoldThisMonth,
            NULLIF(p.Category, '')                  AS Category,
            /* L_SOLD is 1900-01-01 for a line the POS has never sold rather
               than NULL, and a grid showing "01 Jan 1900" is worse than one
               showing nothing. */
            NULLIF(p.LastSoldAt, '1900-01-01')      AS LastSoldAt,

            /*
             * Ex-VAT against ex-VAT — see the header — and computed ONLY
             * where both halves are real.
             *
             * The first version guarded the divisor alone, and the live data
             * immediately showed what that costs: 379 items carry a POS row
             * whose STDCOST is zero, so every one of them rendered a flawless
             * GP of 100.00%. A number that looks like an answer and is not is
             * worse than an em dash, and this is a screen people will price
             * from. So: no cost, no GP.
             */
            CASE WHEN p.PosSellPrice > 0 AND p.CostPrice > 0
                 THEN CONVERT(decimal(9,2), (p.PosSellPrice - p.CostPrice) * 100.0 / p.PosSellPrice)
            END                                     AS GpPercent,

            /*
             * Why a GP is missing, or why it should not be trusted. Four real
             * states, measured across the estate on 20 September 2026:
             * 157 items have no POS record at all, 379 have one with no cost,
             * 1,401 have a cost but no POS sell price, and 148 are genuinely
             * selling BELOW cost. The last is a trading exception the report
             * library plans a whole screen for; the first three are the data
             * quality that would otherwise hide behind a blank cell.
             */
            CASE WHEN p.PosCode IS NULL                         THEN 'No POS record'
                 WHEN ISNULL(p.CostPrice, 0) = 0                THEN 'No cost price'
                 WHEN ISNULL(p.PosSellPrice, 0) = 0             THEN 'No POS sell price'
                 WHEN p.CostPrice > p.PosSellPrice              THEN 'Below cost'
            END                                     AS PricingFlag,

            CASE WHEN c.PosCode IS NULL THEN CONVERT(bit, 0) ELSE c.IsActive END AS IsCritical,

            s.LastCountedAt,
            ISNULL(s.CountLines90, 0)               AS CountLines90,

            CASE WHEN i.IsActive = 0              THEN 'Retired'
                 WHEN s.LastCountedAt IS NULL     THEN 'Never counted'
                 WHEN ISNULL(s.CountLines90, 0)= 0 THEN 'Not counted in 90 days'
                 ELSE 'Active' END                 AS [Status]
        FROM [agora].[vw_StockItem] i
        JOIN [agora].[Branch] b
          ON b.BranchId = i.BranchId
        LEFT JOIN [agora].[vw_StockArea] a
          ON a.BranchId = i.BranchId
         AND a.AreaNo = i.AreaNo
        /* All three columns. See the header — two is the wrong product. */
        LEFT JOIN [agora].[vw_StockItemPos] p
          ON p.BranchId = i.BranchId
         AND p.PosSystem = i.PosSystem
         AND p.PosCode = i.POSCode
        LEFT JOIN [agora].[vw_StockItemCritical] c
          ON c.BranchId = i.BranchId
         AND c.PosSystem = i.PosSystem
         AND c.PosCode = i.POSCode
        LEFT JOIN [agora].[StockItemCountStat] s
          ON s.BranchId = i.BranchId
         AND s.StockItemNo = i.StockItemNo
        WHERE (@AllBranches = 1 OR i.BranchId IN (SELECT BranchId FROM @Branch))
    ),
    filtered AS (
        SELECT *
        FROM items x
        WHERE (@Search IS NULL OR @Search = ''
               OR x.StockItemDescription LIKE '%' + @Search + '%'
               OR x.POSCode              LIKE '%' + @Search + '%'
               OR x.StockItemNo          LIKE '%' + @Search + '%'
               OR x.Category             LIKE '%' + @Search + '%')
          AND (@fDescription IS NULL OR x.StockItemDescription LIKE '%' + @fDescription + '%')
          AND (@fPosCode     IS NULL OR x.POSCode              LIKE '%' + @fPosCode + '%')
          AND (@fArea        IS NULL OR x.AreaDescription      LIKE '%' + @fArea + '%')
          AND (@fCategory    IS NULL OR x.Category             LIKE '%' + @fCategory + '%')
          AND (@fItemNo      IS NULL OR x.StockItemNo          LIKE '%' + @fItemNo + '%')
          AND (@fPosSystemAny = 0 OR x.PosSystem IN (SELECT [Value] FROM @FilterSet WHERE [Column] = 'PosSystem'))
          AND (@fPriceTypeAny = 0 OR x.PriceType IN (SELECT [Value] FROM @FilterSet WHERE [Column] = 'PriceType'))
          AND (@fUomAny       = 0 OR x.UOMCode   IN (SELECT [Value] FROM @FilterSet WHERE [Column] = 'UOMCode'))
          AND (@fStatusAny    = 0 OR x.[Status]  IN (SELECT [Value] FROM @FilterSet WHERE [Column] = 'Status'))
          AND (@fSourceAny    = 0 OR x.[Source]  IN (SELECT [Value] FROM @FilterSet WHERE [Column] = 'Source'))
          /* The date window applies to when the line was last COUNTED, which
             is the only date on this screen a person asks a range about. A
             line never counted is outside every window rather than inside
             all of them. */
          AND (@DateFrom IS NULL OR x.LastCountedAt >= @DateFrom)
          AND (@DateTo   IS NULL OR x.LastCountedAt <  DATEADD(DAY, 1, @DateTo))
    )

    SELECT
        f.BranchId,
        f.BranchName,
        f.StockItemNo,
        f.StockItemDescription,
        f.PosSystem,
        f.POSCode,
        f.AreaNo,
        f.AreaDescription,
        f.AreaGroup,
        f.UOMCode,
        f.PriceType,
        f.SellingPrice,
        f.PosSellPrice,
        f.CostPrice,
        f.GpPercent,
        f.PricingFlag,
        f.QtyOnHand,
        f.QtySoldThisMonth,
        f.Category,
        f.LastSoldAt,
        f.IsCritical,
        f.LastCountedAt,
        f.CountLines90,
        f.Factor,
        f.IsMonitoredItem,
        f.IsDoCloseQtyCalc,
        f.IsAllowNegativeQtyIssued,
        f.IsAllowNegativeQtyClose,
        f.IsStockItemPreProduction,
        f.IsPreProductionItem,
        f.IsActive,
        f.[Source],
        f.[Status]
    FROM filtered f
    ORDER BY
        /*
         * SITE FIRST WHEN THE SORT IS THE ITEM NUMBER, and this is not a
         * preference. An item number is per branch: 653 numbers are reused
         * across 22 sites, so with the whole estate in scope an item-number
         * sort puts twenty-two unrelated products called "1" at the top, then
         * twenty-two called "2". The first render of this screen showed
         * exactly that and it was unreadable. Ordering by branch first makes
         * the default view read site by site, which is the only way an item
         * number means anything.
         *
         * It applies ONLY to the default sort. Sorting by GP% or by cost is a
         * question about the whole estate and must not be re-grouped by site.
         */
        CASE WHEN @SortColumn IS NULL OR @SortColumn IN ('', 'StockItemNo')
             THEN f.BranchId END ASC,
        CASE WHEN @SortAsc = 1 THEN
            CASE @SortColumn
                WHEN 'StockItemDescription' THEN f.StockItemDescription
                WHEN 'POSCode'              THEN f.POSCode
                WHEN 'PosSystem'            THEN f.PosSystem
                WHEN 'AreaDescription'      THEN f.AreaDescription
                WHEN 'PriceType'            THEN f.PriceType
                WHEN 'UOMCode'              THEN f.UOMCode
                WHEN 'Category'             THEN f.Category
                WHEN 'Status'               THEN f.[Status]
                WHEN 'Source'               THEN f.[Source]
            END
        END ASC,
        CASE WHEN @SortAsc = 0 THEN
            CASE @SortColumn
                WHEN 'StockItemDescription' THEN f.StockItemDescription
                WHEN 'POSCode'              THEN f.POSCode
                WHEN 'PosSystem'            THEN f.PosSystem
                WHEN 'AreaDescription'      THEN f.AreaDescription
                WHEN 'PriceType'            THEN f.PriceType
                WHEN 'UOMCode'              THEN f.UOMCode
                WHEN 'Category'             THEN f.Category
                WHEN 'Status'               THEN f.[Status]
                WHEN 'Source'               THEN f.[Source]
            END
        END DESC,
        /* The numeric and date sorts separately, so they compare as
           themselves: '10' after '9', not before it. */
        CASE WHEN @SortAsc = 1 AND @SortColumn = 'SellingPrice'  THEN f.SellingPrice  END ASC,
        CASE WHEN @SortAsc = 0 AND @SortColumn = 'SellingPrice'  THEN f.SellingPrice  END DESC,
        CASE WHEN @SortAsc = 1 AND @SortColumn = 'CostPrice'     THEN f.CostPrice     END ASC,
        CASE WHEN @SortAsc = 0 AND @SortColumn = 'CostPrice'     THEN f.CostPrice     END DESC,
        CASE WHEN @SortAsc = 1 AND @SortColumn = 'GpPercent'     THEN f.GpPercent     END ASC,
        CASE WHEN @SortAsc = 0 AND @SortColumn = 'GpPercent'     THEN f.GpPercent     END DESC,
        CASE WHEN @SortAsc = 1 AND @SortColumn = 'QtyOnHand'     THEN f.QtyOnHand     END ASC,
        CASE WHEN @SortAsc = 0 AND @SortColumn = 'QtyOnHand'     THEN f.QtyOnHand     END DESC,
        CASE WHEN @SortAsc = 1 AND @SortColumn = 'LastSoldAt'    THEN f.LastSoldAt    END ASC,
        CASE WHEN @SortAsc = 0 AND @SortColumn = 'LastSoldAt'    THEN f.LastSoldAt    END DESC,
        CASE WHEN @SortAsc = 1 AND @SortColumn = 'LastCountedAt' THEN f.LastCountedAt END ASC,
        CASE WHEN @SortAsc = 0 AND @SortColumn = 'LastCountedAt' THEN f.LastCountedAt END DESC,
        CASE WHEN @SortAsc = 1 AND @SortColumn = 'StockItemNo'   THEN f.ItemNoNumeric END ASC,
        CASE WHEN @SortAsc = 0 AND @SortColumn = 'StockItemNo'   THEN f.ItemNoNumeric END DESC,
        /* The default, and the tie-break under every sort above: site, then
           item number as a number. */
        f.BranchId, f.ItemNoNumeric, f.StockItemNo
    OFFSET @Offset ROWS FETCH NEXT @PageSize ROWS ONLY;

    /*
     * Result set 2: the size of the whole filtered set.
     *
     * IT MUST BE THE SAME SET AS THE PAGE, and the only way that cannot drift
     * is to count the same CTE rather than repeat its predicates. usp_Core_GridUsers
     * repeated them by hand, two were missed, and the footer disagreed with
     * the rows above it while offering pages that were empty.
     */
    ;WITH items AS (
        SELECT
            i.BranchId,
            i.StockItemNo,
            i.StockItemDescription,
            i.PosSystem,
            i.POSCode,
            i.UOMCode,
            i.PriceType,
            i.IsActive,
            i.[Source],
            a.AreaDescription,
            NULLIF(p.Category, '') AS Category,
            s.LastCountedAt,
            ISNULL(s.CountLines90, 0) AS CountLines90,
            CASE WHEN i.IsActive = 0               THEN 'Retired'
                 WHEN s.LastCountedAt IS NULL      THEN 'Never counted'
                 WHEN ISNULL(s.CountLines90, 0) = 0 THEN 'Not counted in 90 days'
                 ELSE 'Active' END AS [Status]
        FROM [agora].[vw_StockItem] i
        JOIN [agora].[Branch] b
          ON b.BranchId = i.BranchId
        LEFT JOIN [agora].[vw_StockArea] a
          ON a.BranchId = i.BranchId AND a.AreaNo = i.AreaNo
        LEFT JOIN [agora].[vw_StockItemPos] p
          ON p.BranchId = i.BranchId AND p.PosSystem = i.PosSystem AND p.PosCode = i.POSCode
        LEFT JOIN [agora].[StockItemCountStat] s
          ON s.BranchId = i.BranchId AND s.StockItemNo = i.StockItemNo
        WHERE (@AllBranches = 1 OR i.BranchId IN (SELECT BranchId FROM @Branch))
    )
    SELECT COUNT_BIG(*) AS TotalRows
    FROM items x
    WHERE (@Search IS NULL OR @Search = ''
           OR x.StockItemDescription LIKE '%' + @Search + '%'
           OR x.POSCode              LIKE '%' + @Search + '%'
           OR x.StockItemNo          LIKE '%' + @Search + '%'
           OR x.Category             LIKE '%' + @Search + '%')
      AND (@fDescription IS NULL OR x.StockItemDescription LIKE '%' + @fDescription + '%')
      AND (@fPosCode     IS NULL OR x.POSCode              LIKE '%' + @fPosCode + '%')
      AND (@fArea        IS NULL OR x.AreaDescription      LIKE '%' + @fArea + '%')
      AND (@fCategory    IS NULL OR x.Category             LIKE '%' + @fCategory + '%')
      AND (@fItemNo      IS NULL OR x.StockItemNo          LIKE '%' + @fItemNo + '%')
      AND (@fPosSystemAny = 0 OR x.PosSystem IN (SELECT [Value] FROM @FilterSet WHERE [Column] = 'PosSystem'))
      AND (@fPriceTypeAny = 0 OR x.PriceType IN (SELECT [Value] FROM @FilterSet WHERE [Column] = 'PriceType'))
      AND (@fUomAny       = 0 OR x.UOMCode   IN (SELECT [Value] FROM @FilterSet WHERE [Column] = 'UOMCode'))
      AND (@fStatusAny    = 0 OR x.[Status]  IN (SELECT [Value] FROM @FilterSet WHERE [Column] = 'Status'))
      AND (@fSourceAny    = 0 OR x.[Source]  IN (SELECT [Value] FROM @FilterSet WHERE [Column] = 'Source'))
      AND (@DateFrom IS NULL OR x.LastCountedAt >= @DateFrom)
      AND (@DateTo   IS NULL OR x.LastCountedAt <  DATEADD(DAY, 1, @DateTo));
END
