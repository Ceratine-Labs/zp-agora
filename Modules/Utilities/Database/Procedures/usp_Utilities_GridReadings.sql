/* ============================================================================
   agora.usp_Utilities_GridReadings — Utility meter readings

   WHAT IT IS FOR
   Every reading, across every site the caller may see: the opening it is
   measured from, the closing, prepaid units, usage, cost at tariff, and a
   status that names the readings that are always wrong — zero usage, a
   register that went backwards, a day nobody read.

   WHO READS IT
   The grid under the utilities capture screen, and the `Utility meter
   readings` definition in the report library. Head office uses it to find the
   meter nobody is reading; a site uses it to check its own month.

   The arithmetic is agora.vw_UtilityReading's — one row per meter per day,
   opening = the previous reading whenever it was taken, usage = closing -
   opening - prepaid (the customer's own formula) — so this grid, the capture
   screen and the zero-usage flag cannot disagree about a number. A day PumpIT
   holds as 0 / 0 is not a reading (Ryan, 01a0e312-07a0): it lists as
   'No reading', with no usage and no cost, and the next reading is measured
   past it.

   The grid procedure template (feature-rules §2), nine parameters, plus:
     @TariffsJson       {"B": 2.95, ...} rand per unit by meter type. Passed by
                        the application from config until Settings exists.
     @AllowedBranchIds  every site the caller may see at all, which is not the
                        same question as the sites they SELECTED. Empty means
                        no restriction (the same reading BranchScope applies).

   The window defaults to the month to date. It is the READING date, and a
   window reaching past today shows the rows the legacy screen writes ahead of
   time as 'No reading', because that is what they are.

   CapturedAt is PumpIT's own CreateDateTime — the server's local clock, as the
   legacy has always written it, not Agora's UTC.

   READ-ONLY.

     EXEC agora.usp_Utilities_GridReadings @BranchIds = '8', @DateFrom = '2026-08-01',
          @DateTo = '2026-08-31', @TariffsJson = N'{"B":3.10}';
   ============================================================================ */

CREATE OR ALTER PROCEDURE [agora].[usp_Utilities_GridReadings]
    @BranchIds        NVARCHAR(MAX) = NULL,
    @DateFrom         DATE          = NULL,
    @DateTo           DATE          = NULL,
    @Search           NVARCHAR(200) = NULL,
    @SortColumn       NVARCHAR(80)  = NULL,
    @SortAsc          BIT           = 1,
    @Page             INT           = 1,
    @PageSize         INT           = 50,
    @FiltersJson      NVARCHAR(MAX) = NULL,
    @TariffsJson      NVARCHAR(MAX) = NULL,
    @AllowedBranchIds NVARCHAR(MAX) = NULL
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @Today date = CONVERT(date, GETDATE());

    SET @DateTo   = ISNULL(@DateTo, @Today);
    SET @DateFrom = ISNULL(@DateFrom, DATEFROMPARTS(YEAR(@DateTo), MONTH(@DateTo), 1));
    SET @Page     = CASE WHEN ISNULL(@Page, 1) < 1 THEN 1 ELSE @Page END;
    SET @PageSize = CASE WHEN ISNULL(@PageSize, 50) BETWEEN 1 AND 100000 THEN @PageSize ELSE 50 END;
    SET @Search   = NULLIF(LTRIM(RTRIM(ISNULL(@Search, ''))), '');

    /* The empty-string trap, twice: STRING_SPLIT('', ',') is one empty row and
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

    DECLARE @Tariff TABLE (UtilityType nvarchar(1) PRIMARY KEY, Rate decimal(18,4) NULL);

    IF ISJSON(@TariffsJson) = 1
        INSERT INTO @Tariff (UtilityType, Rate)
        SELECT j.[key], TRY_CONVERT(decimal(18,4), j.[value])
        FROM OPENJSON(@TariffsJson) j
        WHERE LEN(j.[key]) = 1;

    /* The header filters, in the shape the grid sends: a JSON ARRAY whose
       elements carry the column at $.column. See docs/grid.md. */
    DECLARE @Filter    TABLE ([Column] nvarchar(80), [Op] nvarchar(20), [Value] nvarchar(400));
    DECLARE @FilterSet TABLE ([Column] nvarchar(80), [Value] nvarchar(400));

    IF @FiltersJson IS NOT NULL AND ISJSON(@FiltersJson) = 1
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

    DECLARE @fBranch   nvarchar(400) = (SELECT TOP 1 [Value] FROM @Filter WHERE [Column] = 'BranchName'),
            @fBranchOp nvarchar(20)  = (SELECT TOP 1 [Op]    FROM @Filter WHERE [Column] = 'BranchName'),
            @fMeter    nvarchar(400) = (SELECT TOP 1 [Value] FROM @Filter WHERE [Column] = 'MeterDescription'),
            @fMeterOp  nvarchar(20)  = (SELECT TOP 1 [Op]    FROM @Filter WHERE [Column] = 'MeterDescription'),
            @fType     nvarchar(400) = (SELECT TOP 1 [Value] FROM @Filter WHERE [Column] = 'UtilityTypeDescription'),
            @fTypeOp   nvarchar(20)  = (SELECT TOP 1 [Op]    FROM @Filter WHERE [Column] = 'UtilityTypeDescription'),
            /* Numbers and the date compare as themselves. A value that does
               not parse matches nothing — a filter must fail closed, never
               quietly let everything through. */
            @fUsageOp  nvarchar(20)  = (SELECT TOP 1 [Op]    FROM @Filter WHERE [Column] = 'Usage'),
            @fUsage    decimal(18,4) = (SELECT TOP 1 TRY_CONVERT(decimal(18,4), [Value]) FROM @Filter WHERE [Column] = 'Usage'),
            @fCostOp   nvarchar(20)  = (SELECT TOP 1 [Op]    FROM @Filter WHERE [Column] = 'Cost'),
            @fCost     decimal(18,4) = (SELECT TOP 1 TRY_CONVERT(decimal(18,4), [Value]) FROM @Filter WHERE [Column] = 'Cost'),
            @fDateOp   nvarchar(20)  = (SELECT TOP 1 [Op]    FROM @Filter WHERE [Column] = 'TransactionDate'),
            @fDate     date          = (SELECT TOP 1 TRY_CONVERT(date, [Value]) FROM @Filter WHERE [Column] = 'TransactionDate');

    DECLARE @fStatusAny bit = CASE WHEN EXISTS (SELECT 1 FROM @FilterSet WHERE [Column] = 'Status') THEN 1 ELSE 0 END;

    ;WITH base AS (
        SELECT
            r.UtilityTransactionId,
            r.BranchId,
            b.Name                                   AS BranchName,
            r.ReadingDate                            AS TransactionDate,
            r.UtilityType,
            y.UtilityTypeDescription,
            r.MeterNo,
            ISNULL(m.MeterDescription, r.MeterNo)    AS MeterDescription,
            r.PreviousReadingDate,
            DATEDIFF(day, r.PreviousReadingDate, r.ReadingDate) AS GapDays,
            CONVERT(decimal(18,2), r.OpeningReading)          AS OpeningReading,
            CONVERT(decimal(18,2), r.ClosingReading)          AS ClosingReading,
            CONVERT(decimal(18,2), r.PrepaidUnitsPurchased)   AS PrepaidUnitsPurchased,
            CONVERT(decimal(18,2), r.[Usage])                 AS [Usage],
            t.Rate                                            AS Tariff,
            CONVERT(decimal(18,2), r.[Usage] * t.Rate)        AS Cost,
            CONVERT(decimal(18,2), r.StoredOpeningReading)    AS StoredOpeningReading,
            CONVERT(decimal(18,2), r.StoredUsage)             AS StoredUsage,
            CONVERT(bit, CASE
                /* A blank day's stored figures are whatever the legacy screen
                   left; there is no reading for them to disagree with. */
                WHEN r.IsBlank = 1 THEN 0
                WHEN ABS(ISNULL(r.StoredOpeningReading, 0) - ISNULL(r.OpeningReading, 0)) > 0.005 THEN 1
                WHEN r.[Usage] IS NOT NULL AND ABS(ISNULL(r.StoredUsage, 0) - r.[Usage]) > 0.005 THEN 1
                ELSE 0 END)                                   AS StoredDisagrees,
            r.RowsForDay,
            CASE
                WHEN r.IsBlank = 1               THEN 'No reading'
                WHEN r.OpeningReading IS NULL    THEN 'First reading'
                WHEN r.[Usage] < 0               THEN 'Reading went backwards'
                WHEN r.[Usage] = 0               THEN 'Zero usage'
                ELSE 'Normal'
            END                                               AS [Status],
            r.CreateDateTime                                  AS CapturedAt
        FROM agora.vw_UtilityReading r
        LEFT JOIN agora.Branch b ON b.BranchId = r.BranchId
        /* Labels, through TOP 1. BRN_UtilityMeter's key leads with its
           identity, so a second master row for one meter is possible, and a
           label lookup must never be able to change a row count. */
        OUTER APPLY (SELECT TOP 1 mm.MeterDescription FROM agora.vw_UtilityMeter mm
                     WHERE mm.BranchId = r.BranchId AND mm.UtilityType = r.UtilityType AND mm.MeterNo = r.MeterNo
                     ORDER BY mm.UtilityMeterId) m
        OUTER APPLY (SELECT TOP 1 ty.UtilityTypeDescription FROM agora.vw_UtilityType ty
                     WHERE ty.UtilityType = r.UtilityType ORDER BY ty.UtilityTypeId) y
        LEFT JOIN @Tariff t ON t.UtilityType = r.UtilityType
        WHERE r.ReadingDate >= @DateFrom AND r.ReadingDate <= @DateTo
          AND (@AllBranches = 1 OR r.BranchId IN (SELECT BranchId FROM @Branch))
          AND (@AllAllowed  = 1 OR r.BranchId IN (SELECT BranchId FROM @Allowed))
    )
    /* Built once, then paged AND counted from the same rows, so the footer
       can never disagree with the page. */
    SELECT *
    INTO #Rows
    FROM base
    WHERE (@Search IS NULL
           OR base.BranchName             LIKE '%' + @Search + '%'
           OR base.MeterDescription       LIKE '%' + @Search + '%'
           OR base.MeterNo                LIKE '%' + @Search + '%'
           OR base.UtilityTypeDescription LIKE '%' + @Search + '%'
           OR base.[Status]               LIKE '%' + @Search + '%')
      AND (@fBranch IS NULL OR (CASE WHEN @fBranchOp = 'eq' THEN CASE WHEN base.BranchName = @fBranch THEN 1 ELSE 0 END
                                     ELSE CASE WHEN base.BranchName LIKE '%' + @fBranch + '%' THEN 1 ELSE 0 END END) = 1)
      AND (@fMeter IS NULL OR (CASE WHEN @fMeterOp = 'eq' THEN CASE WHEN base.MeterDescription = @fMeter THEN 1 ELSE 0 END
                                    ELSE CASE WHEN base.MeterDescription LIKE '%' + @fMeter + '%' THEN 1 ELSE 0 END END) = 1)
      AND (@fType IS NULL OR (CASE WHEN @fTypeOp = 'eq' THEN CASE WHEN base.UtilityTypeDescription = @fType THEN 1 ELSE 0 END
                                   ELSE CASE WHEN base.UtilityTypeDescription LIKE '%' + @fType + '%' THEN 1 ELSE 0 END END) = 1)
      AND (@fUsageOp IS NULL OR (base.[Usage] IS NOT NULL AND @fUsage IS NOT NULL AND (
                (@fUsageOp = 'eq'  AND base.[Usage] =  @fUsage) OR (@fUsageOp = 'ne'  AND base.[Usage] <> @fUsage) OR
                (@fUsageOp = 'gt'  AND base.[Usage] >  @fUsage) OR (@fUsageOp = 'gte' AND base.[Usage] >= @fUsage) OR
                (@fUsageOp = 'lt'  AND base.[Usage] <  @fUsage) OR (@fUsageOp = 'lte' AND base.[Usage] <= @fUsage))))
      AND (@fCostOp IS NULL OR (base.Cost IS NOT NULL AND @fCost IS NOT NULL AND (
                (@fCostOp = 'eq'  AND base.Cost =  @fCost) OR (@fCostOp = 'ne'  AND base.Cost <> @fCost) OR
                (@fCostOp = 'gt'  AND base.Cost >  @fCost) OR (@fCostOp = 'gte' AND base.Cost >= @fCost) OR
                (@fCostOp = 'lt'  AND base.Cost <  @fCost) OR (@fCostOp = 'lte' AND base.Cost <= @fCost))))
      AND (@fDateOp IS NULL OR (@fDate IS NOT NULL AND (
                (@fDateOp = 'eq'  AND base.TransactionDate =  @fDate) OR (@fDateOp = 'ne'  AND base.TransactionDate <> @fDate) OR
                (@fDateOp = 'gt'  AND base.TransactionDate >  @fDate) OR (@fDateOp = 'gte' AND base.TransactionDate >= @fDate) OR
                (@fDateOp = 'lt'  AND base.TransactionDate <  @fDate) OR (@fDateOp = 'lte' AND base.TransactionDate <= @fDate))))
      AND (@fStatusAny = 0 OR base.[Status] IN (SELECT [Value] FROM @FilterSet WHERE [Column] = 'Status'))
    OPTION (RECOMPILE);

    DECLARE @Total bigint = (SELECT COUNT_BIG(*) FROM #Rows);

    SELECT UtilityTransactionId, BranchId, BranchName, TransactionDate, UtilityType, UtilityTypeDescription,
           MeterNo, MeterDescription, PreviousReadingDate, GapDays,
           OpeningReading, ClosingReading, PrepaidUnitsPurchased, [Usage], Tariff, Cost,
           StoredOpeningReading, StoredUsage, StoredDisagrees, RowsForDay, [Status], CapturedAt
    FROM #Rows
    ORDER BY
        /* Text and numbers sorted apart so each compares as itself. */
        CASE WHEN @SortAsc = 1 THEN
            CASE @SortColumn WHEN 'BranchName'             THEN BranchName
                             WHEN 'MeterDescription'       THEN MeterDescription
                             WHEN 'UtilityTypeDescription' THEN UtilityTypeDescription
                             WHEN 'Status'                 THEN [Status] END END ASC,
        CASE WHEN @SortAsc = 0 THEN
            CASE @SortColumn WHEN 'BranchName'             THEN BranchName
                             WHEN 'MeterDescription'       THEN MeterDescription
                             WHEN 'UtilityTypeDescription' THEN UtilityTypeDescription
                             WHEN 'Status'                 THEN [Status] END END DESC,
        CASE WHEN @SortAsc = 1 THEN
            CASE @SortColumn WHEN 'Usage'          THEN CONVERT(decimal(38,6), [Usage])
                             WHEN 'Cost'           THEN CONVERT(decimal(38,6), Cost)
                             WHEN 'ClosingReading' THEN CONVERT(decimal(38,6), ClosingReading)
                             WHEN 'GapDays'        THEN CONVERT(decimal(38,6), GapDays) END END ASC,
        CASE WHEN @SortAsc = 0 THEN
            CASE @SortColumn WHEN 'Usage'          THEN CONVERT(decimal(38,6), [Usage])
                             WHEN 'Cost'           THEN CONVERT(decimal(38,6), Cost)
                             WHEN 'ClosingReading' THEN CONVERT(decimal(38,6), ClosingReading)
                             WHEN 'GapDays'        THEN CONVERT(decimal(38,6), GapDays) END END DESC,
        /* Written as an arm (WHEN 'TransactionDate') like every other sort, so
           the report library's contract test finds it. */
        CASE WHEN @SortAsc = 1 THEN CASE @SortColumn WHEN 'TransactionDate' THEN TransactionDate END END ASC,
        CASE WHEN @SortAsc = 0 THEN CASE @SortColumn WHEN 'TransactionDate' THEN TransactionDate END END DESC,
        TransactionDate DESC, BranchName, UtilityType, MeterNo
    OFFSET (@Page - 1) * @PageSize ROWS FETCH NEXT @PageSize ROWS ONLY;

    SELECT @Total AS TotalRows;
END
