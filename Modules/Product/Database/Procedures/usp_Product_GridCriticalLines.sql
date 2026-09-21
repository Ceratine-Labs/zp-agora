/* ============================================================================
 * agora.usp_Product_GridCriticalLines — the lines that must never be out.
 *
 * Read by:  Setup -> Trading rules -> Critical lines  (T025)
 * Reads:    agora.vw_StockItemCritical (override-or-legacy), agora.vw_StockItemPos,
 *           agora.vw_StockItem, agora.Branch
 * Writes:   nothing.
 *
 * ---------------------------------------------------------------------------
 * THIS LIST IS KEYED TO THE POS FILE, NOT TO THE STOCK MASTER
 * ---------------------------------------------------------------------------
 *
 * Measured on the live estate, 20 September 2026:
 *
 *   · 1,895 critical lines across 15 of the 22 sites, 1,677 active
 *   · every single one has a row in DBF_STDB
 *   · 602 of them — 32% — have NO row in STK_StockMaster at that site and POS
 *     system. Real products the site sells that nobody counts.
 *
 * So the driving table is the critical list itself, joined to the POS file for
 * what is actually on the shelf, and joined to the stock master only to answer
 * "is this counted". A screen built the other way round would hide a third of
 * the list — including lines that are out of stock, which is the whole point
 * of having the list.
 *
 * ---------------------------------------------------------------------------
 * STATUS IS THE ANSWER THE SCREEN EXISTS FOR
 * ---------------------------------------------------------------------------
 *
 * Out of stock · Low · In stock · Off the list. On 20 September **709 active
 * critical lines were at or below zero on hand** — that is the number this
 * screen is for, and the report library's "Out of stock now" queue is the same
 * question with the filter pre-set.
 *
 * "Low" is on-hand at or below one PACK, not a number somebody picked: the POS
 * file carries PACKSIZE, and a line with less than a pack left is the one worth
 * ordering today. Where PACKSIZE is zero or missing it falls back to 1, which
 * makes Low mean "one or fewer" — still a useful thing to say.
 *
 *     EXEC agora.usp_Product_GridCriticalLines @BranchIds = '8', @PageSize = 20;
 * ============================================================================ */

CREATE OR ALTER PROCEDURE [agora].[usp_Product_GridCriticalLines]
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

    /* Typed header filters, in the shape GridQuery::filtersJson() sends: a
       JSON ARRAY whose elements carry their own column name at $.column. */
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

    DECLARE @fDescription NVARCHAR(400) = (SELECT TOP 1 [Value] FROM @Filter WHERE [Column] = 'Description'),
            @fPosCode     NVARCHAR(400) = (SELECT TOP 1 [Value] FROM @Filter WHERE [Column] = 'PosCode'),
            @fCategory    NVARCHAR(400) = (SELECT TOP 1 [Value] FROM @Filter WHERE [Column] = 'Category');

    DECLARE @fPosSystemAny bit = CASE WHEN EXISTS (SELECT 1 FROM @FilterSet WHERE [Column] = 'PosSystem') THEN 1 ELSE 0 END,
            @fStatusAny    bit = CASE WHEN EXISTS (SELECT 1 FROM @FilterSet WHERE [Column] = 'Status')    THEN 1 ELSE 0 END,
            @fCountedAny   bit = CASE WHEN EXISTS (SELECT 1 FROM @FilterSet WHERE [Column] = 'IsCounted') THEN 1 ELSE 0 END,
            @fSourceAny    bit = CASE WHEN EXISTS (SELECT 1 FROM @FilterSet WHERE [Column] = 'Source')    THEN 1 ELSE 0 END;

    ;WITH lines AS (
        SELECT
            c.BranchId,
            b.Name                                   AS BranchName,
            c.PosSystem,
            c.PosCode,
            /* The critical list carries its own description and the POS file
               carries one too, and they disagree often enough to matter. The
               list's is what the buyer wrote; the POS file's is what the till
               prints. Show the list's, fall back to the till's. */
            ISNULL(c.Description, p.PosDescription)  AS Description,
            ISNULL(c.Category, p.Category)           AS Category,
            c.IsActive,
            c.[Source],

            p.QtyOnHand,
            p.CostPrice,
            p.PosSellPrice,
            p.PackSize,
            NULLIF(p.LastSoldAt, '1900-01-01')       AS LastSoldAt,

            /* Is this line counted at all? A third of the list is not. */
            i.StockItemNo,
            CASE WHEN i.StockItemNo IS NULL THEN CONVERT(bit, 0) ELSE CONVERT(bit, 1) END AS IsCounted,
            a.AreaDescription,

            CASE
                WHEN c.IsActive = 0 THEN 'Off the list'
                WHEN p.QtyOnHand IS NULL THEN 'No POS record'
                WHEN p.QtyOnHand <= 0 THEN 'Out of stock'
                /* One pack or less. PACKSIZE is the POS file's own, and a
                   zero or missing one falls back to 1 rather than making
                   every line look low. */
                WHEN p.QtyOnHand <= CASE WHEN ISNULL(p.PackSize, 0) > 0 THEN p.PackSize ELSE 1 END THEN 'Low'
                ELSE 'In stock'
            END                                      AS [Status]
        FROM [agora].[vw_StockItemCritical] c
        JOIN [agora].[Branch] b
          ON b.BranchId = c.BranchId
        /* All three columns, as everywhere in this module. */
        LEFT JOIN [agora].[vw_StockItemPos] p
          ON p.BranchId = c.BranchId
         AND p.PosSystem = c.PosSystem
         AND p.PosCode = c.PosCode
        LEFT JOIN [agora].[vw_StockItem] i
          ON i.BranchId = c.BranchId
         AND i.PosSystem = c.PosSystem
         AND i.POSCode = c.PosCode
        LEFT JOIN [agora].[vw_StockArea] a
          ON a.BranchId = i.BranchId
         AND a.AreaNo = i.AreaNo
        WHERE (@AllBranches = 1 OR c.BranchId IN (SELECT BranchId FROM @Branch))
    ),
    filtered AS (
        SELECT *
        FROM lines x
        WHERE (@Search IS NULL OR @Search = ''
               OR x.Description LIKE '%' + @Search + '%'
               OR x.PosCode     LIKE '%' + @Search + '%'
               OR x.Category    LIKE '%' + @Search + '%')
          AND (@fDescription IS NULL OR x.Description LIKE '%' + @fDescription + '%')
          AND (@fPosCode     IS NULL OR x.PosCode     LIKE '%' + @fPosCode + '%')
          AND (@fCategory    IS NULL OR x.Category    LIKE '%' + @fCategory + '%')
          AND (@fPosSystemAny = 0 OR x.PosSystem IN (SELECT [Value] FROM @FilterSet WHERE [Column] = 'PosSystem'))
          AND (@fStatusAny    = 0 OR x.[Status]  IN (SELECT [Value] FROM @FilterSet WHERE [Column] = 'Status'))
          AND (@fSourceAny    = 0 OR x.[Source]  IN (SELECT [Value] FROM @FilterSet WHERE [Column] = 'Source'))
          AND (@fCountedAny   = 0 OR (CASE WHEN x.IsCounted = 1 THEN 'Yes' ELSE 'No' END)
                                     IN (SELECT [Value] FROM @FilterSet WHERE [Column] = 'IsCounted'))
          AND (@DateFrom IS NULL OR x.LastSoldAt >= @DateFrom)
          AND (@DateTo   IS NULL OR x.LastSoldAt <  DATEADD(DAY, 1, @DateTo))
    )

    SELECT
        f.BranchId,
        f.BranchName,
        f.PosSystem,
        f.PosCode,
        f.Description,
        f.Category,
        f.QtyOnHand,
        f.PackSize,
        f.CostPrice,
        f.PosSellPrice,
        f.LastSoldAt,
        f.StockItemNo,
        f.IsCounted,
        f.AreaDescription,
        f.IsActive,
        f.[Source],
        f.[Status]
    FROM filtered f
    ORDER BY
        /*
         * OUT OF STOCK FIRST BY DEFAULT, and this is the one place in the
         * module where the default order is an opinion rather than an index.
         * A critical-lines screen sorted alphabetically makes somebody scroll
         * to find the emergency; 709 of the 1,677 active lines were at or
         * below zero when this was written, and those are the rows the person
         * opened the screen for.
         */
        CASE WHEN @SortColumn IS NULL OR @SortColumn = '' THEN
            CASE f.[Status] WHEN 'Out of stock' THEN 0
                            WHEN 'Low' THEN 1
                            WHEN 'No POS record' THEN 2
                            WHEN 'In stock' THEN 3
                            ELSE 4 END
        END ASC,
        CASE WHEN @SortAsc = 1 THEN
            CASE @SortColumn
                WHEN 'Description'     THEN f.Description
                WHEN 'PosCode'         THEN f.PosCode
                WHEN 'PosSystem'       THEN f.PosSystem
                WHEN 'Category'        THEN f.Category
                WHEN 'Status'          THEN f.[Status]
                WHEN 'AreaDescription' THEN f.AreaDescription
                WHEN 'BranchName'      THEN f.BranchName
            END
        END ASC,
        CASE WHEN @SortAsc = 0 THEN
            CASE @SortColumn
                WHEN 'Description'     THEN f.Description
                WHEN 'PosCode'         THEN f.PosCode
                WHEN 'PosSystem'       THEN f.PosSystem
                WHEN 'Category'        THEN f.Category
                WHEN 'Status'          THEN f.[Status]
                WHEN 'AreaDescription' THEN f.AreaDescription
                WHEN 'BranchName'      THEN f.BranchName
            END
        END DESC,
        CASE WHEN @SortAsc = 1 AND @SortColumn = 'QtyOnHand'  THEN f.QtyOnHand  END ASC,
        CASE WHEN @SortAsc = 0 AND @SortColumn = 'QtyOnHand'  THEN f.QtyOnHand  END DESC,
        CASE WHEN @SortAsc = 1 AND @SortColumn = 'LastSoldAt' THEN f.LastSoldAt END ASC,
        CASE WHEN @SortAsc = 0 AND @SortColumn = 'LastSoldAt' THEN f.LastSoldAt END DESC,
        f.BranchId, f.Description, f.PosCode
    OFFSET @Offset ROWS FETCH NEXT @PageSize ROWS ONLY;

    /* Result set 2: the same set, counted by counting the CTE rather than by
       repeating its predicates — which is the only version that cannot drift
       apart from the page above it. */
    ;WITH lines AS (
        SELECT
            c.BranchId,
            ISNULL(c.Description, p.PosDescription) AS Description,
            c.PosSystem,
            c.PosCode,
            ISNULL(c.Category, p.Category)          AS Category,
            c.[Source],
            p.QtyOnHand,
            p.PackSize,
            NULLIF(p.LastSoldAt, '1900-01-01')      AS LastSoldAt,
            CASE WHEN i.StockItemNo IS NULL THEN CONVERT(bit, 0) ELSE CONVERT(bit, 1) END AS IsCounted,
            CASE
                WHEN c.IsActive = 0 THEN 'Off the list'
                WHEN p.QtyOnHand IS NULL THEN 'No POS record'
                WHEN p.QtyOnHand <= 0 THEN 'Out of stock'
                WHEN p.QtyOnHand <= CASE WHEN ISNULL(p.PackSize, 0) > 0 THEN p.PackSize ELSE 1 END THEN 'Low'
                ELSE 'In stock'
            END AS [Status]
        FROM [agora].[vw_StockItemCritical] c
        JOIN [agora].[Branch] b ON b.BranchId = c.BranchId
        LEFT JOIN [agora].[vw_StockItemPos] p
          ON p.BranchId = c.BranchId AND p.PosSystem = c.PosSystem AND p.PosCode = c.PosCode
        LEFT JOIN [agora].[vw_StockItem] i
          ON i.BranchId = c.BranchId AND i.PosSystem = c.PosSystem AND i.POSCode = c.PosCode
        WHERE (@AllBranches = 1 OR c.BranchId IN (SELECT BranchId FROM @Branch))
    )
    SELECT COUNT_BIG(*) AS TotalRows
    FROM lines x
    WHERE (@Search IS NULL OR @Search = ''
           OR x.Description LIKE '%' + @Search + '%'
           OR x.PosCode     LIKE '%' + @Search + '%'
           OR x.Category    LIKE '%' + @Search + '%')
      AND (@fDescription IS NULL OR x.Description LIKE '%' + @fDescription + '%')
      AND (@fPosCode     IS NULL OR x.PosCode     LIKE '%' + @fPosCode + '%')
      AND (@fCategory    IS NULL OR x.Category    LIKE '%' + @fCategory + '%')
      AND (@fPosSystemAny = 0 OR x.PosSystem IN (SELECT [Value] FROM @FilterSet WHERE [Column] = 'PosSystem'))
      AND (@fStatusAny    = 0 OR x.[Status]  IN (SELECT [Value] FROM @FilterSet WHERE [Column] = 'Status'))
      AND (@fSourceAny    = 0 OR x.[Source]  IN (SELECT [Value] FROM @FilterSet WHERE [Column] = 'Source'))
      AND (@fCountedAny   = 0 OR (CASE WHEN x.IsCounted = 1 THEN 'Yes' ELSE 'No' END)
                                 IN (SELECT [Value] FROM @FilterSet WHERE [Column] = 'IsCounted'))
      AND (@DateFrom IS NULL OR x.LastSoldAt >= @DateFrom)
      AND (@DateTo   IS NULL OR x.LastSoldAt <  DATEADD(DAY, 1, @DateTo));
END
