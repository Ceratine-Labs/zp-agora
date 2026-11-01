/* Read-only cumulative litres, explicitly mapped by branch/area/item. The
   customer confirmed totalisers on 25 Oct 2026. Never ABS, scale, repair or
   value a reading. The predecessor is found BEFORE period/search filtering.
   A missing scheduled shift prevents comparison with a single shift's POS.
   Three result sets: page, count, comparable-only totals. */
CREATE OR ALTER PROCEDURE [agora].[usp_StockRecon_GridWaterMeters]
    @BranchIds        NVARCHAR(MAX) = NULL,
    @DateFrom         DATE = NULL,
    @DateTo           DATE = NULL,
    @Search           NVARCHAR(200) = NULL,
    @SortColumn       NVARCHAR(80) = NULL,
    @SortAsc          BIT = 1,
    @Page             INT = 1,
    @PageSize         INT = 50,
    @FiltersJson      NVARCHAR(MAX) = NULL,
    @AllowedBranchIds NVARCHAR(MAX) = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET @DateTo = ISNULL(@DateTo, CONVERT(date, GETDATE()));
    SET @DateFrom = ISNULL(@DateFrom, DATEFROMPARTS(YEAR(@DateTo), MONTH(@DateTo), 1));
    SET @Page = CASE WHEN ISNULL(@Page, 1) < 1 THEN 1 ELSE @Page END;
    SET @PageSize = CASE WHEN ISNULL(@PageSize, 50) BETWEEN 1 AND 100000 THEN @PageSize ELSE 50 END;
    SET @Search = NULLIF(LTRIM(RTRIM(@Search)), '');
    IF @DateFrom > @DateTo
        THROW 51000, 'AGORA:BAD_RANGE:The From date is after the To date.', 1;

    DECLARE @Branch TABLE (BranchId int PRIMARY KEY);
    DECLARE @Allowed TABLE (BranchId int PRIMARY KEY);
    INSERT INTO @Branch SELECT DISTINCT TRY_CONVERT(int, NULLIF(LTRIM(RTRIM(value)), ''))
        FROM STRING_SPLIT(@BranchIds, ',') WHERE TRY_CONVERT(int, NULLIF(LTRIM(RTRIM(value)), '')) IS NOT NULL;
    INSERT INTO @Allowed SELECT DISTINCT TRY_CONVERT(int, NULLIF(LTRIM(RTRIM(value)), ''))
        FROM STRING_SPLIT(@AllowedBranchIds, ',') WHERE TRY_CONVERT(int, NULLIF(LTRIM(RTRIM(value)), '')) IS NOT NULL;
    DECLARE @AllBranches bit = CASE WHEN NULLIF(LTRIM(RTRIM(@BranchIds)), '') IS NULL THEN 1 ELSE 0 END;
    DECLARE @AllAllowed bit = CASE WHEN NULLIF(LTRIM(RTRIM(@AllowedBranchIds)), '') IS NULL THEN 1 ELSE 0 END;

    ;WITH readings AS (
        SELECT l.BranchId, l.AreaNo, l.StockItemNo, CONVERT(date, l.TransactionDate) AS TransactionDate,
               l.ShiftNo, l.QtyOpen_Original AS RawOpening, l.QtyClose_Original AS CurrentReading,
               l.QtyComputer_Original AS POSLitres, l.QtyOpen AS LiveOpening,
               l.QtyClose AS LiveClosing, l.QtyComputer AS LivePOS,
               meter.Label AS MeterLabel, meter.Unit,
               COUNT(*) OVER (PARTITION BY l.BranchId, l.AreaNo, l.StockItemNo,
                              CONVERT(date, l.TransactionDate), l.ShiftNo) AS ReadingCount
        FROM agora.vw_StockReconLine l
        JOIN agora.StockReconMeter meter ON meter.BranchId = l.BranchId AND meter.AreaNo = l.AreaNo
             AND meter.StockItemNo = l.StockItemNo AND meter.IsActive = 1
        WHERE l.TransactionDate < DATEADD(day, 1, @DateTo)
          AND (@AllBranches = 1 OR l.BranchId IN (SELECT BranchId FROM @Branch))
          AND (@AllAllowed = 1 OR l.BranchId IN (SELECT BranchId FROM @Allowed))
    ), intervals AS (
        SELECT *, LAG(CurrentReading) OVER (PARTITION BY BranchId, AreaNo, StockItemNo ORDER BY TransactionDate, ShiftNo) AS PreviousReading,
               LAG(TransactionDate) OVER (PARTITION BY BranchId, AreaNo, StockItemNo ORDER BY TransactionDate, ShiftNo) AS PreviousDate,
               LAG(ShiftNo) OVER (PARTITION BY BranchId, AreaNo, StockItemNo ORDER BY TransactionDate, ShiftNo) AS PreviousShift,
               LAG(ReadingCount) OVER (PARTITION BY BranchId, AreaNo, StockItemNo ORDER BY TransactionDate, ShiftNo) AS PreviousCount
        FROM readings
    )
    SELECT r.*, b.Name AS BranchName, a.AreaDescription,
           master.StockItemDescription, master.UOMCode AS MasterUOM,
           state.StatusCode,
           CONVERT(nvarchar(50), CASE state.StatusCode
               WHEN 'valid' THEN N'Comparable interval'
               WHEN 'missing_baseline' THEN N'Missing baseline'
               WHEN 'missing_reading' THEN N'Missing reading'
               WHEN 'negative_reading' THEN N'Negative reading'
               WHEN 'invalid_baseline' THEN N'Invalid baseline'
               WHEN 'reset' THEN N'Zero reset — check source'
               WHEN 'backward' THEN N'Backward reading — check source'
               WHEN 'reading_gap' THEN N'Missing shift reading'
               WHEN 'unknown_schedule' THEN N'Check shift schedule'
               WHEN 'duplicate' THEN N'Duplicate shift readings'
               WHEN 'missing_pos' THEN N'Missing POS litres'
               WHEN 'negative_pos' THEN N'Negative POS litres' END) AS Status,
           CASE WHEN state.StatusCode = 'valid' THEN volume.Litres END AS MeterLitres,
           CASE WHEN state.StatusCode = 'valid' THEN volume.Litres - CONVERT(decimal(28,6), r.POSLitres) END AS DifferenceLitres
    INTO #Rows
    FROM intervals r
    LEFT JOIN agora.Branch b ON b.BranchId = r.BranchId
    LEFT JOIN agora.vw_StockArea a ON a.BranchId = r.BranchId AND a.AreaNo = r.AreaNo
    OUTER APPLY (SELECT TOP 1 sm.StockItemDescription, sm.UOMCode
        FROM agora.vw_StockMaster sm WHERE sm.BranchId = r.BranchId AND sm.StockItemNo = r.StockItemNo
        ORDER BY CASE WHEN sm.AreaNo = r.AreaNo THEN 0 ELSE 1 END, sm.StockLocation) master
    CROSS APPLY (SELECT CASE WHEN a.NightShift = 1 THEN 3 WHEN a.AfternoonShift = 1 THEN 2
                            WHEN a.DayShift = 1 THEN 1 END AS LastShift,
                        CASE WHEN r.ShiftNo = 3 AND a.AfternoonShift = 1 THEN 2
                             WHEN r.ShiftNo IN (2,3) AND a.DayShift = 1 THEN 1 END AS EarlierShift,
                        CASE WHEN (r.ShiftNo = 1 AND a.DayShift = 1) OR (r.ShiftNo = 2 AND a.AfternoonShift = 1)
                               OR (r.ShiftNo = 3 AND a.NightShift = 1) THEN 1 ELSE 0 END AS Scheduled) schedule
    CROSS APPLY (SELECT CASE
        WHEN r.ReadingCount > 1 THEN 'duplicate'
        WHEN r.CurrentReading IS NULL THEN 'missing_reading'
        WHEN r.CurrentReading < 0 THEN 'negative_reading'
        WHEN r.PreviousDate IS NULL THEN 'missing_baseline'
        WHEN r.PreviousReading IS NULL OR r.PreviousReading < 0 OR r.PreviousCount > 1 THEN 'invalid_baseline'
        WHEN r.CurrentReading = 0 AND r.PreviousReading > 0 THEN 'reset'
        WHEN r.CurrentReading < r.PreviousReading THEN 'backward'
        WHEN schedule.Scheduled = 0 OR schedule.LastShift IS NULL THEN 'unknown_schedule'
        WHEN r.PreviousShift <> ISNULL(schedule.EarlierShift, schedule.LastShift)
          OR r.PreviousDate <> CASE WHEN schedule.EarlierShift IS NOT NULL THEN r.TransactionDate
                                    ELSE DATEADD(day, -1, r.TransactionDate) END THEN 'reading_gap'
        WHEN r.POSLitres IS NULL THEN 'missing_pos'
        WHEN r.POSLitres < 0 THEN 'negative_pos'
        ELSE 'valid' END AS StatusCode) state
    CROSS APPLY (SELECT CONVERT(decimal(28,6), r.CurrentReading) - CONVERT(decimal(28,6), r.PreviousReading) AS Litres) volume
    WHERE r.TransactionDate >= @DateFrom
      AND (@Search IS NULL OR b.Name LIKE '%' + @Search + '%' OR r.MeterLabel LIKE '%' + @Search + '%'
           OR r.StockItemNo LIKE '%' + @Search + '%' OR master.StockItemDescription LIKE '%' + @Search + '%')
    OPTION (RECOMPILE);

    IF @FiltersJson IS NOT NULL AND ISJSON(@FiltersJson) = 1
    BEGIN
        DECLARE @Filter TABLE ([Column] nvarchar(80), [Op] nvarchar(20), [Value] nvarchar(400));
        INSERT INTO @Filter SELECT JSON_VALUE(value, '$.column'), ISNULL(JSON_VALUE(value, '$.op'), 'contains'),
            JSON_VALUE(value, '$.value') FROM OPENJSON(@FiltersJson) WHERE JSON_VALUE(value, '$.value') IS NOT NULL;
        DELETE r FROM #Rows r WHERE EXISTS (
            SELECT 1 FROM @Filter f
            CROSS APPLY (SELECT CASE f.[Column] WHEN 'BranchName' THEN r.BranchName
                WHEN 'MeterLabel' THEN r.MeterLabel WHEN 'StockItemNo' THEN r.StockItemNo
                WHEN 'StockItemDescription' THEN r.StockItemDescription WHEN 'MasterUOM' THEN r.MasterUOM
                WHEN 'AreaDescription' THEN r.AreaDescription END AS Cell) c
            WHERE f.[Column] IN ('BranchName', 'MeterLabel', 'StockItemNo', 'StockItemDescription', 'MasterUOM', 'AreaDescription')
              AND NOT (CASE WHEN f.Op = 'eq' THEN CASE WHEN c.Cell = f.Value THEN 1 ELSE 0 END
                            ELSE CASE WHEN c.Cell LIKE '%' + f.Value + '%' THEN 1 ELSE 0 END END = 1));
        DELETE r FROM #Rows r WHERE EXISTS (
            SELECT 1 FROM @Filter f CROSS APPLY (SELECT TRY_CONVERT(decimal(28,6), f.Value) AS Want,
                CASE f.[Column]
                    WHEN 'ShiftNo' THEN CONVERT(decimal(28,6), r.ShiftNo)
                    WHEN 'PreviousShift' THEN CONVERT(decimal(28,6), r.PreviousShift)
                    WHEN 'PreviousReading' THEN CONVERT(decimal(28,6), r.PreviousReading)
                    WHEN 'CurrentReading' THEN CONVERT(decimal(28,6), r.CurrentReading)
                    WHEN 'MeterLitres' THEN CONVERT(decimal(28,6), r.MeterLitres)
                    WHEN 'POSLitres' THEN CONVERT(decimal(28,6), r.POSLitres)
                    WHEN 'DifferenceLitres' THEN CONVERT(decimal(28,6), r.DifferenceLitres)
                    WHEN 'RawOpening' THEN CONVERT(decimal(28,6), r.RawOpening)
                    WHEN 'LiveOpening' THEN CONVERT(decimal(28,6), r.LiveOpening)
                    WHEN 'LiveClosing' THEN CONVERT(decimal(28,6), r.LiveClosing)
                    WHEN 'LivePOS' THEN CONVERT(decimal(28,6), r.LivePOS)
                END AS Cell) c
            WHERE f.[Column] IN ('ShiftNo', 'PreviousShift', 'PreviousReading', 'CurrentReading', 'MeterLitres', 'POSLitres', 'DifferenceLitres', 'RawOpening', 'LiveOpening', 'LiveClosing', 'LivePOS')
              AND NOT (c.Want IS NOT NULL AND c.Cell IS NOT NULL AND (
                (f.Op = 'eq' AND c.Cell = c.Want) OR (f.Op = 'ne' AND c.Cell <> c.Want) OR
                (f.Op = 'gt' AND c.Cell > c.Want) OR (f.Op = 'gte' AND c.Cell >= c.Want) OR
                (f.Op = 'lt' AND c.Cell < c.Want) OR (f.Op = 'lte' AND c.Cell <= c.Want))));
        DELETE r FROM #Rows r WHERE EXISTS (
            SELECT 1 FROM @Filter f CROSS APPLY (SELECT TRY_CONVERT(date, f.Value) AS Want,
                CASE f.[Column] WHEN 'TransactionDate' THEN r.TransactionDate WHEN 'PreviousDate' THEN r.PreviousDate END AS Cell) c
            WHERE f.[Column] IN ('TransactionDate', 'PreviousDate') AND NOT (c.Want IS NOT NULL AND c.Cell IS NOT NULL AND (
                (f.Op = 'eq' AND c.Cell = c.Want) OR (f.Op = 'ne' AND c.Cell <> c.Want) OR
                (f.Op = 'gt' AND c.Cell > c.Want) OR (f.Op = 'gte' AND c.Cell >= c.Want) OR
                (f.Op = 'lt' AND c.Cell < c.Want) OR (f.Op = 'lte' AND c.Cell <= c.Want))));
        -- Apply the tick list without filtering the predecessor before LAG.
        DELETE r FROM #Rows r WHERE EXISTS (
            SELECT 1 FROM OPENJSON(@FiltersJson) f
            WHERE JSON_VALUE(f.value, '$.column') = 'Status' AND JSON_VALUE(f.value, '$.type') = 'set'
              AND NOT EXISTS (SELECT 1 FROM OPENJSON(f.value, '$.in') v WHERE v.value = r.Status));
    END;

    SELECT BranchId, AreaNo, StockItemNo, TransactionDate, ShiftNo, MeterLabel, Unit,
           BranchName, AreaDescription, StockItemDescription, MasterUOM, StatusCode, Status,
           PreviousDate, PreviousShift, PreviousReading, CurrentReading, MeterLitres, POSLitres, DifferenceLitres,
           RawOpening, LiveOpening, LiveClosing, LivePOS
    FROM #Rows
    ORDER BY
        CASE WHEN @SortAsc = 1 THEN CASE @SortColumn WHEN 'BranchName' THEN BranchName WHEN 'MeterLabel' THEN MeterLabel WHEN 'Status' THEN Status END END ASC,
        CASE WHEN @SortAsc = 0 THEN CASE @SortColumn WHEN 'BranchName' THEN BranchName WHEN 'MeterLabel' THEN MeterLabel WHEN 'Status' THEN Status END END DESC,
        CASE WHEN @SortAsc = 1 AND @SortColumn = 'DifferenceLitres' THEN DifferenceLitres END ASC,
        CASE WHEN @SortAsc = 0 AND @SortColumn = 'DifferenceLitres' THEN DifferenceLitres END DESC,
        CASE WHEN @SortAsc = 1 AND @SortColumn = 'TransactionDate' THEN TransactionDate END ASC,
        CASE WHEN @SortAsc = 0 AND @SortColumn = 'TransactionDate' THEN TransactionDate END DESC,
        BranchId, AreaNo, StockItemNo, TransactionDate, ShiftNo, CurrentReading
    OFFSET (@Page - 1) * @PageSize ROWS FETCH NEXT @PageSize ROWS ONLY;
    SELECT COUNT_BIG(*) AS TotalRows FROM #Rows;
    SELECT COUNT_BIG(*) AS Lines,
           ISNULL(SUM(CASE WHEN StatusCode = 'valid' THEN 1 ELSE 0 END), 0) AS ComparableIntervals,
           ISNULL(SUM(CASE WHEN StatusCode <> 'valid' THEN 1 ELSE 0 END), 0) AS InvalidIntervals,
           SUM(CASE WHEN StatusCode = 'valid' THEN MeterLitres END) AS ComparableMeterLitres,
           SUM(CASE WHEN StatusCode = 'valid' THEN CONVERT(decimal(28,6), POSLitres) END) AS ComparablePOSLitres,
           SUM(DifferenceLitres) AS DifferenceLitres
    FROM #Rows;
END
