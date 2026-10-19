/* ============================================================================
   agora.usp_StockRecon_GridVariance — Original vs amended variance

   WHAT IT IS FOR
   After balancing, the variance on every count line two ways: on the ORIGINAL
   quantities (what staff counted and the till sold) and on the AMENDED ones
   (what balancing — Agora's or anybody's by hand — has made of them since), so
   the difference balancing made is visible, line by line and in total, over
   any period (ZRP, 19 Oct 2026: "view variances based on original and amended
   quantities for an extended period … to easily see the difference in figures
   before and after balancing").

   WHO READS IT
   The managers, after a week has been balanced. The grid on Stock → Original
   vs amended variance; the third result set is the strip above it.

   THE ARITHMETIC IS PUMPIT'S OWN, from dbo.sp_SelectStockReconBalancing — the
   Stock Recon Balancing screen this mirrors:

     Original   QtyVar   = Computer_Original - (Open_Original + Issued_Original - Close_Original)
     Amended    VarB4Waste = Computer - (Open + Issued - Close)
                QtyVar   = VarB4Waste where no good waste was recorded; with good
                           waste, VarB4Waste + waste where that is still short,
                           otherwise 0 — waste can explain a short, never make
                           an over
     Both       ValueVar = QtyVar * SellPrice

   Three deliberate differences from that screen, each because it would have
   changed a figure for no reason:
     * Quantities are read as stored. PumpIT converts each to two places first,
       which moves a weighed item's variance by a fraction of a gram.
     * Amended Issued is the stored QtyIssued — the figure the variance is
       worked from. PumpIT displays dbo.fn_GetQtyIssued there while working the
       variance from the column, so its row need not add up.
     * The stock master is read once per line (TOP 1, preferring the line's
       area). PumpIT joins it on branch and item, which counts an item held
       under two locations twice.

   The colour, per side, is PumpIT's: crit (red) where the variance is short,
   good (blue) where it is over by no more than the item's allowance, warn
   (yellow) where it is over by more. An allowance of 0 means none was set, so
   any over is warn — as PumpIT shows it. The amended colour is read AFTER
   waste, from the figure on the screen.

   SIZE, NOT DAYS. The period is free. What bounds it is the number of count
   lines: @MaxLines below, counted on PumpIT's key before anything is built.
   One site is about 11,000 lines a month; every site about 175,000. Change
   the ceiling here if the server is happy with more.

   The grid procedure template (feature-rules §2), nine parameters, plus:
     @AllowedBranchIds  every site the caller may see at all — not the same
                        question as the sites selected. Empty: no restriction.

   Result sets: the page; (TotalRows); one row of totals over every line that
   matched — the footer and the strip above the grid.

   READ-ONLY.

     EXEC agora.usp_StockRecon_GridVariance @BranchIds = '15', @DateFrom = '2026-10-05', @DateTo = '2026-10-07';
   ============================================================================ */

CREATE OR ALTER PROCEDURE [agora].[usp_StockRecon_GridVariance]
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

    DECLARE @MaxLines bigint = 250000;
    DECLARE @Today    date   = CONVERT(date, GETDATE());

    SET @DateTo   = ISNULL(@DateTo, @Today);
    SET @DateFrom = ISNULL(@DateFrom, DATEFROMPARTS(YEAR(@DateTo), MONTH(@DateTo), 1));
    SET @Page     = CASE WHEN ISNULL(@Page, 1) < 1 THEN 1 ELSE @Page END;
    SET @PageSize = CASE WHEN ISNULL(@PageSize, 50) BETWEEN 1 AND 100000 THEN @PageSize ELSE 50 END;
    SET @Search   = NULLIF(LTRIM(RTRIM(ISNULL(@Search, ''))), '');

    IF @DateFrom > @DateTo
        THROW 51000, 'AGORA:BAD_RANGE:The From date is after the To date.', 1;

    /* The empty-string trap: STRING_SPLIT('', ',') is one empty row and
       TRY_CONVERT(int, '') is 0, so a caller who selected nothing would get
       branch 0 and an empty grid. */
    DECLARE @Branch  TABLE (BranchId int PRIMARY KEY);
    DECLARE @Allowed TABLE (BranchId int PRIMARY KEY);

    INSERT INTO @Branch (BranchId)
    SELECT DISTINCT TRY_CONVERT(int, LTRIM(RTRIM(s.value)))
    FROM STRING_SPLIT(ISNULL(@BranchIds, ''), ',') s
    WHERE LTRIM(RTRIM(s.value)) <> '' AND TRY_CONVERT(int, LTRIM(RTRIM(s.value))) IS NOT NULL;

    INSERT INTO @Allowed (BranchId)
    SELECT DISTINCT TRY_CONVERT(int, LTRIM(RTRIM(s.value)))
    FROM STRING_SPLIT(ISNULL(@AllowedBranchIds, ''), ',') s
    WHERE LTRIM(RTRIM(s.value)) <> '' AND TRY_CONVERT(int, LTRIM(RTRIM(s.value))) IS NOT NULL;

    DECLARE @AllBranches bit = CASE WHEN EXISTS (SELECT 1 FROM @Branch)  THEN 0 ELSE 1 END;
    DECLARE @AllAllowed  bit = CASE WHEN EXISTS (SELECT 1 FROM @Allowed) THEN 0 ELSE 1 END;

    /* TransactionDate is a datetime in PumpIT: a half-open range, so a line
       stamped with a time on the last day is not lost. */
    DECLARE @Until datetime = DATEADD(day, 1, CONVERT(datetime, @DateTo));

    DECLARE @Lines bigint = (
        SELECT COUNT_BIG(*)
        FROM agora.vw_StockReconLine l
        WHERE l.TransactionDate >= @DateFrom AND l.TransactionDate < @Until
          AND (@AllBranches = 1 OR l.BranchId IN (SELECT BranchId FROM @Branch))
          AND (@AllAllowed  = 1 OR l.BranchId IN (SELECT BranchId FROM @Allowed)));

    IF @Lines > @MaxLines
    BEGIN
        DECLARE @TooMany nvarchar(400) = CONCAT(N'AGORA:TOO_MANY_LINES:', FORMAT(@Lines, 'N0'),
            N' count lines in that period, and this view reads up to ', FORMAT(@MaxLines, 'N0'),
            N'. Pick fewer sites or a shorter period.');
        THROW 51000, @TooMany, 1;
    END;

    SELECT
        l.BranchId,
        b.Name                                                AS BranchName,
        CONVERT(date, l.TransactionDate)                      AS TransactionDate,
        l.ShiftNo,
        l.AreaNo,
        a.AreaDescription,
        m.StockLocation,
        m.POSCode,
        l.StockItemNo,
        m.StockItemDescription,
        m.UOMCode,
        CONVERT(decimal(18,2), ISNULL(l.SellPrice, 0))        AS SellPrice,
        CONVERT(decimal(18,4), ISNULL(m.QtyVarAllowance, 0))  AS QtyVarAllowance,

        q.QtyOpenOriginal, q.QtyIssuedOriginal, q.QtyCloseOriginal, q.QtyComputerOriginal,
        v.QtyVarOriginal,
        CONVERT(decimal(18,2), v.QtyVarOriginal * ISNULL(l.SellPrice, 0)) AS ValueVarOriginal,
        CASE WHEN v.QtyVarOriginal < 0 THEN 'crit'
             WHEN v.QtyVarOriginal > 0 AND v.QtyVarOriginal <= ISNULL(m.QtyVarAllowance, 0) THEN 'good'
             WHEN v.QtyVarOriginal > 0 THEN 'warn' END        AS OriginalTone,

        q.QtyOpen, q.QtyIssued, q.QtyClose, q.QtyComputer,
        v.QtyVarB4Waste,
        q.QtyGoodWaste,
        x.QtyVar,
        CONVERT(decimal(18,2), x.QtyVar * ISNULL(l.SellPrice, 0))         AS ValueVar,
        CASE WHEN x.QtyVar < 0 THEN 'crit'
             WHEN x.QtyVar > 0 AND x.QtyVar <= ISNULL(m.QtyVarAllowance, 0) THEN 'good'
             WHEN x.QtyVar > 0 THEN 'warn' END                AS AmendedTone,

        /* What balancing moved: amended less original. */
        x.QtyVar - v.QtyVarOriginal                           AS QtyVarMoved,
        CONVERT(decimal(18,2), x.QtyVar * ISNULL(l.SellPrice, 0))
          - CONVERT(decimal(18,2), v.QtyVarOriginal * ISNULL(l.SellPrice, 0)) AS ValueVarMoved,
        CASE WHEN q.QtyOpen <> q.QtyOpenOriginal OR q.QtyIssued <> q.QtyIssuedOriginal
               OR q.QtyClose <> q.QtyCloseOriginal OR q.QtyComputer <> q.QtyComputerOriginal
             THEN 'Amended' ELSE 'As counted' END             AS Balancing
    INTO #Rows
    FROM agora.vw_StockReconLine l
    LEFT JOIN agora.Branch b ON b.BranchId = l.BranchId
    LEFT JOIN agora.vw_StockArea a ON a.BranchId = l.BranchId AND a.AreaNo = l.AreaNo
    /* OUTER APPLY, not a join: the master is keyed by branch AND Location, so a
       join on the item alone counts an item held under two locations twice. */
    OUTER APPLY (SELECT TOP 1 mm.StockItemDescription, mm.UOMCode, mm.StockLocation, mm.POSCode, mm.QtyVarAllowance
                   FROM agora.vw_StockMaster mm
                  WHERE mm.BranchId = l.BranchId AND mm.StockItemNo = l.StockItemNo
                  ORDER BY CASE WHEN mm.AreaNo = l.AreaNo THEN 0 ELSE 1 END, mm.StockLocation) m
    /* STK_StockWasteLine's primary key is these five columns, so this cannot
       fan out. */
    LEFT JOIN agora.vw_StockWasteLine w
           ON w.BranchId = l.BranchId AND w.TransactionDate = l.TransactionDate AND w.ShiftNo = l.ShiftNo
          AND w.AreaNo = l.AreaNo AND w.StockItemNo = l.StockItemNo
    CROSS APPLY (SELECT
        CONVERT(decimal(18,4), ISNULL(l.QtyOpen_Original, 0))     AS QtyOpenOriginal,
        CONVERT(decimal(18,4), ISNULL(l.QtyIssued_Original, 0))   AS QtyIssuedOriginal,
        CONVERT(decimal(18,4), ISNULL(l.QtyClose_Original, 0))    AS QtyCloseOriginal,
        CONVERT(decimal(18,4), ISNULL(l.QtyComputer_Original, 0)) AS QtyComputerOriginal,
        CONVERT(decimal(18,4), ISNULL(l.QtyOpen, 0))              AS QtyOpen,
        CONVERT(decimal(18,4), ISNULL(l.QtyIssued, 0))            AS QtyIssued,
        CONVERT(decimal(18,4), ISNULL(l.QtyClose, 0))             AS QtyClose,
        CONVERT(decimal(18,4), ISNULL(l.QtyComputer, 0))          AS QtyComputer,
        CONVERT(decimal(18,4), ISNULL(w.QtyGoodWaste, 0))         AS QtyGoodWaste) q
    CROSS APPLY (SELECT
        q.QtyComputerOriginal - (q.QtyOpenOriginal + q.QtyIssuedOriginal - q.QtyCloseOriginal) AS QtyVarOriginal,
        q.QtyComputer - (q.QtyOpen + q.QtyIssued - q.QtyClose)                                 AS QtyVarB4Waste) v
    CROSS APPLY (SELECT CASE
        WHEN q.QtyGoodWaste = 0                       THEN v.QtyVarB4Waste
        WHEN v.QtyVarB4Waste + q.QtyGoodWaste < 0     THEN v.QtyVarB4Waste + q.QtyGoodWaste
        ELSE CONVERT(decimal(18,4), 0) END AS QtyVar) x
    WHERE l.TransactionDate >= @DateFrom AND l.TransactionDate < @Until
      AND (@AllBranches = 1 OR l.BranchId IN (SELECT BranchId FROM @Branch))
      AND (@AllAllowed  = 1 OR l.BranchId IN (SELECT BranchId FROM @Allowed))
      AND (@Search IS NULL
           OR b.Name                  LIKE '%' + @Search + '%'
           OR m.StockItemDescription  LIKE '%' + @Search + '%'
           OR CONVERT(nvarchar(40), l.StockItemNo) LIKE '%' + @Search + '%'
           OR m.POSCode               LIKE '%' + @Search + '%'
           OR a.AreaDescription       LIKE '%' + @Search + '%')
    OPTION (RECOMPILE);

    /* The header filters, in the shape the grid sends: a JSON ARRAY whose
       elements carry the column at $.column. A row that fails one is taken out
       of #Rows, so the page, the count and the totals all answer the same
       question. A value that does not parse matches nothing: a filter fails
       closed, never quietly lets everything through. */
    IF @FiltersJson IS NOT NULL AND ISJSON(@FiltersJson) = 1
    BEGIN
        DECLARE @Filter    TABLE ([Column] nvarchar(80), [Op] nvarchar(20), [Value] nvarchar(400));
        DECLARE @FilterSet TABLE ([Column] nvarchar(80), [Value] nvarchar(400));

        INSERT INTO @Filter ([Column], [Op], [Value])
        SELECT JSON_VALUE(f.value, '$.column'),
               ISNULL(JSON_VALUE(f.value, '$.op'), 'contains'),
               JSON_VALUE(f.value, '$.value')
        FROM OPENJSON(@FiltersJson) f
        WHERE JSON_VALUE(f.value, '$.value') IS NOT NULL;

        INSERT INTO @FilterSet ([Column], [Value])
        SELECT JSON_VALUE(f.value, '$.column'), s.value
        FROM OPENJSON(@FiltersJson) f
        CROSS APPLY OPENJSON(f.value, '$.in') s
        WHERE JSON_VALUE(f.value, '$.type') = 'set';

        -- Text: contains, or eq for an exact match.
        DELETE r FROM #Rows r
        WHERE EXISTS (
            SELECT 1 FROM @Filter f
            CROSS APPLY (SELECT CASE f.[Column]
                WHEN 'BranchName'           THEN r.BranchName
                WHEN 'AreaDescription'      THEN r.AreaDescription
                WHEN 'StockItemDescription' THEN r.StockItemDescription
                WHEN 'POSCode'              THEN r.POSCode
                WHEN 'StockItemNo'          THEN CONVERT(nvarchar(40), r.StockItemNo)
                WHEN 'StockLocation'        THEN r.StockLocation END AS Cell) c
            WHERE f.[Column] IN ('BranchName', 'AreaDescription', 'StockItemDescription', 'POSCode', 'StockItemNo', 'StockLocation')
              AND NOT (CASE WHEN f.[Op] = 'eq' THEN CASE WHEN c.Cell = f.[Value] THEN 1 ELSE 0 END
                            ELSE CASE WHEN c.Cell LIKE '%' + f.[Value] + '%' THEN 1 ELSE 0 END END = 1));

        -- Numbers: eq ne gt gte lt lte.
        DELETE r FROM #Rows r
        WHERE EXISTS (
            SELECT 1 FROM @Filter f
            CROSS APPLY (SELECT TRY_CONVERT(decimal(18,4), f.[Value]) AS Want,
                                CASE f.[Column]
                                    WHEN 'QtyVarOriginal'   THEN CONVERT(decimal(18,4), r.QtyVarOriginal)
                                    WHEN 'ValueVarOriginal' THEN CONVERT(decimal(18,4), r.ValueVarOriginal)
                                    WHEN 'QtyVar'           THEN CONVERT(decimal(18,4), r.QtyVar)
                                    WHEN 'ValueVar'         THEN CONVERT(decimal(18,4), r.ValueVar)
                                    WHEN 'ValueVarMoved'    THEN CONVERT(decimal(18,4), r.ValueVarMoved)
                                    WHEN 'ShiftNo'          THEN CONVERT(decimal(18,4), r.ShiftNo) END AS Cell) c
            WHERE f.[Column] IN ('QtyVarOriginal', 'ValueVarOriginal', 'QtyVar', 'ValueVar', 'ValueVarMoved', 'ShiftNo')
              AND NOT (c.Want IS NOT NULL AND c.Cell IS NOT NULL AND (
                       (f.[Op] = 'eq'  AND c.Cell =  c.Want) OR (f.[Op] = 'ne'  AND c.Cell <> c.Want) OR
                       (f.[Op] = 'gt'  AND c.Cell >  c.Want) OR (f.[Op] = 'gte' AND c.Cell >= c.Want) OR
                       (f.[Op] = 'lt'  AND c.Cell <  c.Want) OR (f.[Op] = 'lte' AND c.Cell <= c.Want))));

        -- The date.
        DELETE r FROM #Rows r
        WHERE EXISTS (
            SELECT 1 FROM @Filter f
            CROSS APPLY (SELECT TRY_CONVERT(date, f.[Value]) AS Want) c
            WHERE f.[Column] = 'TransactionDate'
              AND NOT (c.Want IS NOT NULL AND (
                       (f.[Op] = 'eq'  AND r.TransactionDate =  c.Want) OR (f.[Op] = 'ne'  AND r.TransactionDate <> c.Want) OR
                       (f.[Op] = 'gt'  AND r.TransactionDate >  c.Want) OR (f.[Op] = 'gte' AND r.TransactionDate >= c.Want) OR
                       (f.[Op] = 'lt'  AND r.TransactionDate <  c.Want) OR (f.[Op] = 'lte' AND r.TransactionDate <= c.Want))));

        -- The tick-list.
        IF EXISTS (SELECT 1 FROM @FilterSet WHERE [Column] = 'Balancing')
            DELETE r FROM #Rows r
            WHERE r.Balancing NOT IN (SELECT [Value] FROM @FilterSet WHERE [Column] = 'Balancing');
    END;

    DECLARE @Total bigint = (SELECT COUNT_BIG(*) FROM #Rows);

    SELECT BranchId, BranchName, TransactionDate, ShiftNo, AreaNo, AreaDescription, StockLocation, POSCode,
           StockItemNo, StockItemDescription, UOMCode, SellPrice, QtyVarAllowance,
           QtyOpenOriginal, QtyIssuedOriginal, QtyCloseOriginal, QtyComputerOriginal, QtyVarOriginal, ValueVarOriginal,
           OriginalTone,
           QtyOpen, QtyIssued, QtyClose, QtyComputer, QtyVarB4Waste, QtyGoodWaste, QtyVar, ValueVar, AmendedTone,
           QtyVarMoved, ValueVarMoved, Balancing
    FROM #Rows
    ORDER BY
        /* Text, numbers and the date sorted apart so each compares as itself. */
        CASE WHEN @SortAsc = 1 THEN
            CASE @SortColumn WHEN 'BranchName'           THEN BranchName
                             WHEN 'AreaDescription'      THEN AreaDescription
                             WHEN 'StockItemDescription' THEN StockItemDescription
                             WHEN 'POSCode'              THEN POSCode
                             WHEN 'Balancing'            THEN Balancing END END ASC,
        CASE WHEN @SortAsc = 0 THEN
            CASE @SortColumn WHEN 'BranchName'           THEN BranchName
                             WHEN 'AreaDescription'      THEN AreaDescription
                             WHEN 'StockItemDescription' THEN StockItemDescription
                             WHEN 'POSCode'              THEN POSCode
                             WHEN 'Balancing'            THEN Balancing END END DESC,
        CASE WHEN @SortAsc = 1 THEN
            CASE @SortColumn WHEN 'QtyVarOriginal'   THEN CONVERT(decimal(38,6), QtyVarOriginal)
                             WHEN 'ValueVarOriginal' THEN CONVERT(decimal(38,6), ValueVarOriginal)
                             WHEN 'QtyVar'           THEN CONVERT(decimal(38,6), QtyVar)
                             WHEN 'ValueVar'         THEN CONVERT(decimal(38,6), ValueVar)
                             WHEN 'ValueVarMoved'    THEN CONVERT(decimal(38,6), ValueVarMoved)
                             WHEN 'SellPrice'        THEN CONVERT(decimal(38,6), SellPrice) END END ASC,
        CASE WHEN @SortAsc = 0 THEN
            CASE @SortColumn WHEN 'QtyVarOriginal'   THEN CONVERT(decimal(38,6), QtyVarOriginal)
                             WHEN 'ValueVarOriginal' THEN CONVERT(decimal(38,6), ValueVarOriginal)
                             WHEN 'QtyVar'           THEN CONVERT(decimal(38,6), QtyVar)
                             WHEN 'ValueVar'         THEN CONVERT(decimal(38,6), ValueVar)
                             WHEN 'ValueVarMoved'    THEN CONVERT(decimal(38,6), ValueVarMoved)
                             WHEN 'SellPrice'        THEN CONVERT(decimal(38,6), SellPrice) END END DESC,
        CASE WHEN @SortAsc = 1 THEN CASE @SortColumn WHEN 'TransactionDate' THEN TransactionDate END END ASC,
        CASE WHEN @SortAsc = 0 THEN CASE @SortColumn WHEN 'TransactionDate' THEN TransactionDate END END DESC,
        /* PumpIT's own order: each item's shifts in sequence, so a short and
           the over balancing moved it onto read together. */
        BranchName, AreaNo, TRY_CONVERT(bigint, StockItemNo), StockItemNo, TransactionDate, ShiftNo
    OFFSET (@Page - 1) * @PageSize ROWS FETCH NEXT @PageSize ROWS ONLY;

    SELECT @Total AS TotalRows;

    /* Every line that matched, not the page: the footer and the strip. Short
       and over are PumpIT's Value Var Short and Value Var Over — the negative
       and the positive values added apart. */
    SELECT
        COUNT_BIG(*)                                                         AS Lines,
        SUM(CASE WHEN Balancing = 'Amended' THEN 1 ELSE 0 END)               AS LinesAmended,
        SUM(QtyVarOriginal)                                                  AS QtyVarOriginal,
        SUM(ValueVarOriginal)                                                AS ValueVarOriginal,
        SUM(CASE WHEN ValueVarOriginal < 0 THEN ValueVarOriginal ELSE 0 END) AS ValueShortOriginal,
        SUM(CASE WHEN ValueVarOriginal > 0 THEN ValueVarOriginal ELSE 0 END) AS ValueOverOriginal,
        SUM(QtyComputerOriginal)                                             AS QtyComputerOriginal,
        SUM(QtyVar)                                                          AS QtyVar,
        SUM(ValueVar)                                                        AS ValueVar,
        SUM(CASE WHEN ValueVar < 0 THEN ValueVar ELSE 0 END)                 AS ValueShort,
        SUM(CASE WHEN ValueVar > 0 THEN ValueVar ELSE 0 END)                 AS ValueOver,
        SUM(QtyComputer)                                                     AS QtyComputer,
        SUM(QtyVarMoved)                                                     AS QtyVarMoved,
        SUM(ValueVarMoved)                                                   AS ValueVarMoved
    FROM #Rows;
END
