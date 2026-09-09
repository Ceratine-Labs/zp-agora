/*
 * agora.usp_StockRecon_GridExceptions — what balancing must refuse to hide.
 *
 * Read by:  Stock recon centre -> a run -> Exceptions.
 * Reads:    agora.StockReconRunLine, agora.vw_StockArea, agora.vw_StockMaster,
 *           agora.vw_StockReconShiftEmployee
 * Writes:   nothing.
 *
 * THIS IS THE HALF THAT IS WORTH MORE THAN THE BALANCING. Balancing well makes
 * a second problem visible: a chain whose window ends OVER sold more than it
 * ever received, and no set of closing counts can change that. On the Elephant
 * Coast export 412 of 943 chains were in that state, R136,664 of stock that
 * entered the store without an issue being captured. Chasing it with count
 * amendments only spreads a data fault across innocent shifts.
 *
 * IT READS THE RUN, NOT THE SOURCE, and that is deliberate. The classification
 * needs the same chain arithmetic the balancing does — the running total, the
 * dormant test, the active-shift denominators — and computing it a second time
 * here would put the same rules in two procedures, which is how two screens end
 * up disagreeing about how many exceptions a branch has. A preview writes
 * nothing to the customer's estate, so running one to get the exception report
 * costs nothing but the two seconds it takes.
 *
 * THE DENOMINATORS EXCLUDE DORMANT SHIFTS. C1, C2 and D1 all count against
 * ACTIVE shifts, never raw line count. A chain where nine of twelve shifts
 * never traded is not "short a quarter of the time" — it is short on one of
 * three real shifts, and the rate that goes to a branch has to say so.
 *
 * @FiltersJson is the grid's own array shape: the column name is at $.column
 * inside each element, never the OPENJSON key.
 */
CREATE OR ALTER PROCEDURE [agora].[usp_StockRecon_GridExceptions]
    @BranchIds   NVARCHAR(MAX) = NULL,
    @DateFrom    DATE          = NULL,
    @DateTo      DATE          = NULL,
    @Search      NVARCHAR(200) = NULL,
    @SortColumn  NVARCHAR(80)  = NULL,
    @SortAsc     BIT           = 1,
    @Page        INT           = 1,
    @PageSize    INT           = 50,
    @FiltersJson NVARCHAR(MAX) = NULL,
    @RunId       INT           = NULL
AS
BEGIN
    SET NOCOUNT ON;

    SET @Page     = CASE WHEN ISNULL(@Page, 1) < 1 THEN 1 ELSE @Page END;
    SET @PageSize = CASE WHEN ISNULL(@PageSize, 50) BETWEEN 1 AND 100000 THEN @PageSize ELSE 50 END;
    SET @Search   = NULLIF(LTRIM(RTRIM(ISNULL(@Search, ''))), '');

    DECLARE @Offset INT = (@Page - 1) * @PageSize;

    DECLARE @Branch TABLE (BranchId int PRIMARY KEY);

    INSERT INTO @Branch (BranchId)
    SELECT DISTINCT TRY_CONVERT(int, LTRIM(RTRIM(s.value)))
    FROM STRING_SPLIT(ISNULL(@BranchIds, ''), ',') s
    WHERE LTRIM(RTRIM(s.value)) <> '' AND TRY_CONVERT(int, LTRIM(RTRIM(s.value))) IS NOT NULL;

    DECLARE @AllBranches bit = CASE WHEN EXISTS (SELECT 1 FROM @Branch) THEN 0 ELSE 1 END;

    DECLARE @Filter    TABLE ([Column] nvarchar(80), [Op] nvarchar(20), [Value] nvarchar(400));
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

    DECLARE @fItem    NVARCHAR(400) = (SELECT TOP 1 [Value] FROM @Filter WHERE [Column] = 'ItemDescription'),
            @fItemOp  NVARCHAR(20)  = (SELECT TOP 1 [Op]    FROM @Filter WHERE [Column] = 'ItemDescription'),
            @fArea    NVARCHAR(400) = (SELECT TOP 1 [Value] FROM @Filter WHERE [Column] = 'AreaName'),
            @fAreaOp  NVARCHAR(20)  = (SELECT TOP 1 [Op]    FROM @Filter WHERE [Column] = 'AreaName'),
            @fPos     NVARCHAR(400) = (SELECT TOP 1 [Value] FROM @Filter WHERE [Column] = 'POSCode'),
            @fPosOp   NVARCHAR(20)  = (SELECT TOP 1 [Op]    FROM @Filter WHERE [Column] = 'POSCode'),
            @fEmp     NVARCHAR(400) = (SELECT TOP 1 [Value] FROM @Filter WHERE [Column] = 'EmployeeNames'),
            @fEmpOp   NVARCHAR(20)  = (SELECT TOP 1 [Op]    FROM @Filter WHERE [Column] = 'EmployeeNames');

    DECLARE @fCodeAny bit = CASE WHEN EXISTS (SELECT 1 FROM @FilterSet WHERE [Column] = 'ExceptionCode') THEN 1 ELSE 0 END;

    ;WITH base AS (
        SELECT
            rl.Id,
            rl.BranchId,
            rl.RunId,
            rl.AreaNo,
            ISNULL(rl.AreaDescription,
                   ISNULL(a.AreaDescription, 'Area ' + CONVERT(nvarchar(10), rl.AreaNo))) AS AreaName,
            rl.StockItemNo,
            /* The label the RUN recorded, then the master, then the bare
               number. A run made before v1__14a stored none, so the join
               stays as the fallback rather than being replaced by it. */
            ISNULL(rl.ItemDescription, ISNULL(m.StockItemDescription, rl.StockItemNo)) AS ItemDescription,
            ISNULL(rl.POSCode, m.POSCode)             AS POSCode,
            ISNULL(rl.StockLocation, m.StockLocation) AS StockLocation,
            /* Who was on. The point of it is D1 — a persistent short is the
               one class that can carry a charge, and until now it could only
               name a shift number.

               RESOLVED, not stored-only. This reader fell back to the join for
               the item and the area and NOT for the employee, so the one column
               the class exists for was the one column that stayed blank on a
               run made before v1__14a. EmployeeCodes is the test, not
               EmployeeCount: the count is NOT NULL DEFAULT 0 and so cannot tell
               a run that recorded nothing from a shift nobody was signed on
               to, which must stay 0. */
            CASE WHEN rl.EmployeeCodes IS NOT NULL THEN rl.EmployeeNames
                 ELSE se.EmployeeNames END                                AS EmployeeNames,
            CASE WHEN rl.EmployeeCodes IS NOT NULL THEN rl.EmployeeCount
                 ELSE ISNULL(se.EmployeeCount, 0) END                     AS EmployeeCount,
            rl.TransactionDate,
            rl.ShiftNo,
            rl.ExceptionCode,
            rl.Outcome,
            rl.IsDormant,
            rl.ActiveLen,
            rl.QtyOpen, rl.QtyIssued, rl.QtyClose, rl.QtyPOS, rl.QtyVar,
            rl.ChainNetVar,
            rl.SellPrice,
            /* What the exception is WORTH, which is the column this report is
               ranked by. On an A-class line it is the shortfall the paperwork
               never covered; on a D1 it is the residual short that survives
               balancing. Both at the price the shift was selling at. */
            CONVERT(decimal(18,2), CASE
                WHEN rl.ExceptionCode IN ('A1','A2') THEN
                    CASE WHEN rl.QtyPOS - (rl.QtyOpen + rl.QtyIssued) > 0
                         THEN (rl.QtyPOS - (rl.QtyOpen + rl.QtyIssued)) * ISNULL(rl.SellPrice, 0)
                         ELSE (rl.QtyClose - (rl.QtyOpen + rl.QtyIssued)) * ISNULL(rl.SellPrice, 0) END
                WHEN rl.ExceptionCode IN ('A3','A4') THEN rl.ChainNetVar * ISNULL(rl.SellPrice, 0)
                WHEN rl.QtyVarNew < 0                THEN -rl.QtyVarNew * ISNULL(rl.SellPrice, 0)
                ELSE 0 END)                                                   AS ExceptionValue
        FROM agora.StockReconRunLine rl
        LEFT JOIN agora.vw_StockArea   a ON a.BranchId = rl.BranchId AND a.AreaNo = rl.AreaNo
        LEFT JOIN agora.vw_StockMaster m ON m.BranchId = rl.BranchId AND m.StockItemNo = rl.StockItemNo
        LEFT JOIN agora.vw_StockReconShiftEmployee se
               ON se.BranchId        = rl.BranchId
              AND se.TransactionDate = rl.TransactionDate
              AND se.ShiftNo         = rl.ShiftNo
              AND se.AreaNo          = rl.AreaNo
        WHERE rl.ExceptionCode IS NOT NULL
          AND (@RunId IS NULL OR rl.RunId = @RunId)
          AND (@AllBranches = 1 OR rl.BranchId IN (SELECT BranchId FROM @Branch))
          AND (@DateFrom IS NULL OR rl.TransactionDate >= @DateFrom)
          AND (@DateTo   IS NULL OR rl.TransactionDate <= @DateTo)
    )
    SELECT * INTO #Rows FROM base
    WHERE (@Search IS NULL
           OR base.ItemDescription LIKE '%' + @Search + '%'
           OR base.AreaName        LIKE '%' + @Search + '%'
           OR base.POSCode         LIKE '%' + @Search + '%'
           OR base.StockItemNo     LIKE '%' + @Search + '%'
           OR base.EmployeeNames   LIKE '%' + @Search + '%'
           OR base.Outcome         LIKE '%' + @Search + '%')
      AND (@fItem IS NULL OR (CASE WHEN @fItemOp = 'eq' THEN CASE WHEN base.ItemDescription =        @fItem      THEN 1 ELSE 0 END
                                   ELSE                      CASE WHEN base.ItemDescription LIKE '%'+@fItem+'%' THEN 1 ELSE 0 END END) = 1)
      AND (@fArea IS NULL OR (CASE WHEN @fAreaOp = 'eq' THEN CASE WHEN base.AreaName        =        @fArea      THEN 1 ELSE 0 END
                                   ELSE                      CASE WHEN base.AreaName        LIKE '%'+@fArea+'%' THEN 1 ELSE 0 END END) = 1)
      AND (@fPos  IS NULL OR (CASE WHEN @fPosOp  = 'eq' THEN CASE WHEN base.POSCode         =        @fPos       THEN 1 ELSE 0 END
                                   ELSE                      CASE WHEN base.POSCode         LIKE '%'+@fPos +'%' THEN 1 ELSE 0 END END) = 1)
      AND (@fEmp  IS NULL OR (CASE WHEN @fEmpOp  = 'eq' THEN CASE WHEN base.EmployeeNames   =        @fEmp       THEN 1 ELSE 0 END
                                   ELSE                      CASE WHEN base.EmployeeNames   LIKE '%'+@fEmp +'%' THEN 1 ELSE 0 END END) = 1)
      AND (@fCodeAny = 0 OR base.ExceptionCode IN (SELECT [Value] FROM @FilterSet WHERE [Column] = 'ExceptionCode'));

    SELECT
        Id, RunId, BranchId, AreaNo, AreaName, StockItemNo, ItemDescription, POSCode, StockLocation,
        EmployeeNames, EmployeeCount,
        TransactionDate, ShiftNo, ExceptionCode, Outcome, IsDormant, ActiveLen,
        QtyOpen, QtyIssued, QtyClose, QtyPOS, QtyVar, ChainNetVar, SellPrice, ExceptionValue
    FROM #Rows
    ORDER BY
        CASE WHEN @SortAsc = 1 THEN
            CASE @SortColumn
                WHEN 'ExceptionCode'   THEN ExceptionCode
                WHEN 'AreaName'        THEN AreaName
                WHEN 'ItemDescription' THEN ItemDescription
                WHEN 'POSCode'         THEN POSCode
                WHEN 'EmployeeNames'   THEN EmployeeNames
                WHEN 'Outcome'         THEN Outcome
            END
        END ASC,
        CASE WHEN @SortAsc = 0 THEN
            CASE @SortColumn
                WHEN 'ExceptionCode'   THEN ExceptionCode
                WHEN 'AreaName'        THEN AreaName
                WHEN 'ItemDescription' THEN ItemDescription
                WHEN 'POSCode'         THEN POSCode
                WHEN 'EmployeeNames'   THEN EmployeeNames
                WHEN 'Outcome'         THEN Outcome
            END
        END DESC,
        CASE WHEN @SortAsc = 1 AND @SortColumn = 'TransactionDate' THEN TransactionDate END ASC,
        CASE WHEN @SortAsc = 0 AND @SortColumn = 'TransactionDate' THEN TransactionDate END DESC,
        CASE WHEN @SortAsc = 1 AND @SortColumn = 'QtyVar'          THEN QtyVar END ASC,
        CASE WHEN @SortAsc = 0 AND @SortColumn = 'QtyVar'          THEN QtyVar END DESC,
        CASE WHEN @SortAsc = 1 AND @SortColumn = 'ExceptionValue'  THEN ExceptionValue END ASC,
        CASE WHEN @SortAsc = 0 AND @SortColumn = 'ExceptionValue'  THEN ExceptionValue END DESC,
        /* The default: worst class first, and inside a class the biggest money
           first — which is the order somebody works the list in. */
        ExceptionCode ASC, ExceptionValue DESC, AreaName, ItemDescription, TransactionDate, ShiftNo
    OFFSET @Offset ROWS FETCH NEXT @PageSize ROWS ONLY;

    SELECT COUNT_BIG(*) AS TotalRows FROM #Rows;

    /* Result set 3: the grand totals the grid puts in its footer, over the
       WHOLE filtered set rather than the page — a page total of a nine-page
       answer is a number nobody can use. */
    SELECT CONVERT(decimal(18,2), ISNULL(SUM(ExceptionValue), 0)) AS ExceptionValue
    FROM #Rows;

    DROP TABLE #Rows;
END
