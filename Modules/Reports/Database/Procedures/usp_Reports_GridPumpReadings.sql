/* ============================================================================
   agora.usp_Reports_GridPumpReadings

   WHAT IT IS FOR
   The readings as captured, with what each one implies in litres, and the three
   numbers that should agree: the mechanical meter, the electronic meter, and
   what the POS rang up.

   WHO READS IT
   The forecourt controller and head office. Replaces "Pump Readings date
   range", "Pump Readings Capture" and "Pump Readings Sum per day".

   THE CLOCK-OVER
   A mechanical meter is six digits and rolls over. When it does, the close
   reading is SMALLER than the open one and the naive difference is a large
   negative. The customer's own day-end report handles this by adding 1,000,000
   when IsClocked is set — reproduced here. IsClocked is a `bit`; the legacy
   report compares it to -1, which SQL Server converts to 1, so the two agree.
   Written as `= 1` because that is what it means.

   A row where IsClocked is NOT set but the close is below the open is flagged
   as ClockedNotFlagged rather than being silently corrected: an unflagged
   rollover and a transposed capture look identical in the data and only the
   branch knows which it was.

   THE 15-LITRE RULE
   The capture screen flags a pump whose mechanical litres and POS litres differ
   by more than 15. The same threshold is applied here, so the report and the
   screen cannot disagree about what counts as a problem.

   READ-ONLY.

     EXEC agora.usp_Reports_GridPumpReadings @BranchIds = '5', @DateFrom = '2026-09-01';
   ============================================================================ */

CREATE OR ALTER PROCEDURE [agora].[usp_Reports_GridPumpReadings]
    @BranchIds   NVARCHAR(MAX) = NULL,
    @DateFrom    DATE          = NULL,
    @DateTo      DATE          = NULL,
    @Search      NVARCHAR(200) = NULL,
    @SortColumn  NVARCHAR(80)  = NULL,
    @SortAsc     BIT           = 1,
    @Page        INT           = 1,
    @PageSize    INT           = 50
AS
BEGIN
    SET NOCOUNT ON;

    SET @DateTo   = ISNULL(@DateTo, CONVERT(date, GETDATE()));
    SET @DateFrom = ISNULL(@DateFrom, DATEADD(day, -6, @DateTo));
    SET @Page     = CASE WHEN ISNULL(@Page, 1) < 1 THEN 1 ELSE @Page END;
    SET @PageSize = CASE WHEN ISNULL(@PageSize, 50) BETWEEN 1 AND 100000 THEN @PageSize ELSE 50 END;
    SET @Search   = NULLIF(LTRIM(RTRIM(ISNULL(@Search, ''))), '');

    DECLARE @ClockOver decimal(18,6) = 1000000;
    DECLARE @VarianceLitres decimal(18,6) = 15;

    DECLARE @Branch TABLE (BranchId int PRIMARY KEY);

    /* The empty-string trap. STRING_SPLIT('', ',') returns ONE row holding an
       empty string, and TRY_CONVERT(int, '') is 0 — not NULL. Without the
       first predicate below, a caller who passes no branches gets a @Branch
       table containing branch 0, @AllBranches is 0, and every report in this
       module silently returns nothing at all. Caught on the first run. */
    INSERT INTO @Branch (BranchId)
    SELECT DISTINCT TRY_CONVERT(int, LTRIM(RTRIM(s.value)))
    FROM STRING_SPLIT(ISNULL(@BranchIds, ''), ',') s
    WHERE LTRIM(RTRIM(s.value)) <> ''
      AND TRY_CONVERT(int, LTRIM(RTRIM(s.value))) IS NOT NULL;

    DECLARE @AllBranches bit = CASE WHEN EXISTS (SELECT 1 FROM @Branch) THEN 0 ELSE 1 END;

    SELECT
        r.BranchId, r.BranchName, r.ReadingDate, r.PumpNo, r.TankNo,
        r.FuelTypeNo, r.FuelTypeDescription,
        r.OpenReading, r.CloseReading, r.MechLitres,
        r.OpenReadingElectronic, r.CloseReadingElectronic, r.ElecLitres,
        r.POSSales,
        r.MechLitres - r.ElecLitres     AS MechVsElec,
        r.MechLitres - r.POSSales       AS MechVsPos,
        r.IsClocked,
        r.ClockedNotFlagged,
        CASE WHEN r.ClockedNotFlagged = 1                       THEN 'Close below open, not flagged as clocked'
             WHEN ABS(r.MechLitres - r.POSSales) > @VarianceLitres THEN 'Over 15 L against POS'
             WHEN ABS(r.MechLitres - r.ElecLitres) > @VarianceLitres THEN 'Over 15 L, mechanical against electronic'
             ELSE 'Within tolerance' END AS Status,
        r.Imported, r.CapturedAt
    INTO #Rows
    FROM (
        SELECT
            p.BranchId,
            b.Name                          AS BranchName,
            p.ReadingDate,
            p.PumpNo,
            m.TankNo,
            m.FuelTypeNo,
            t.FuelTypeDescription,
            p.OpenReading,
            p.CloseReading,
            /* The captured rollover correction, exactly as the customer's
               day-end report applies it. */
            CONVERT(decimal(18,6), ISNULL(p.CloseReading, 0) - ISNULL(p.OpenReading, 0)
                + CASE WHEN p.IsClocked = 1 THEN @ClockOver ELSE 0 END) AS MechLitres,
            p.OpenReadingElectronic,
            p.CloseReadingElectronic,
            CONVERT(decimal(18,6), ISNULL(p.CloseReadingElectronic, 0) - ISNULL(p.OpenReadingElectronic, 0)
                + CASE WHEN p.IsClocked = 1 THEN @ClockOver ELSE 0 END) AS ElecLitres,
            CONVERT(decimal(18,6), ISNULL(p.POSSales, 0))               AS POSSales,
            p.IsClocked,
            CASE WHEN ISNULL(p.IsClocked, 0) = 0
                  AND ISNULL(p.CloseReading, 0) < ISNULL(p.OpenReading, 0)
                 THEN CONVERT(bit, 1) ELSE CONVERT(bit, 0) END          AS ClockedNotFlagged,
            p.Imported,
            p.CreateDateTime                AS CapturedAt,
            b.Name                          AS SearchBranch,
            t.FuelTypeDescription           AS SearchFuel
        FROM agora.vw_PumpReading p
        JOIN agora.vw_Branch b ON b.BranchId = p.BranchId
        /* LEFT, not INNER. A reading for a pump that has since been removed
           from BRN_Pump still happened and still has litres on it; the legacy
           day-end report uses a RIGHT JOIN to keep exactly these, and an inner
           join here would drop them along with their variance. */
        LEFT JOIN agora.vw_Pump m
               ON m.BranchId = p.BranchId AND m.PumpNo = p.PumpNo
        LEFT JOIN agora.vw_FuelType t
               ON t.BranchId = p.BranchId AND t.FuelTypeNo = m.FuelTypeNo
        WHERE p.ReadingDate >= @DateFrom AND p.ReadingDate < DATEADD(day, 1, @DateTo)
          AND (@AllBranches = 1 OR p.BranchId IN (SELECT BranchId FROM @Branch))
    ) r
    WHERE (@Search IS NULL
           OR r.SearchBranch LIKE '%' + @Search + '%'
           OR r.SearchFuel   LIKE '%' + @Search + '%'
           OR r.PumpNo       LIKE '%' + @Search + '%'
           OR r.TankNo       LIKE '%' + @Search + '%');

    DECLARE @Total bigint = (SELECT COUNT_BIG(*) FROM #Rows);

    SELECT r.BranchId, r.BranchName, r.ReadingDate, r.PumpNo, r.TankNo,
           r.FuelTypeNo, r.FuelTypeDescription,
           r.OpenReading, r.CloseReading, r.MechLitres,
           r.OpenReadingElectronic, r.CloseReadingElectronic, r.ElecLitres,
           r.POSSales, r.MechVsElec, r.MechVsPos,
           r.IsClocked, r.ClockedNotFlagged, r.Status, r.Imported, r.CapturedAt
    FROM #Rows r
    ORDER BY
        CASE WHEN @SortAsc = 1 THEN
            CASE @SortColumn WHEN 'BranchName'          THEN r.BranchName
                             WHEN 'PumpNo'              THEN r.PumpNo
                             WHEN 'TankNo'              THEN r.TankNo
                             WHEN 'FuelTypeDescription' THEN r.FuelTypeDescription
                             WHEN 'Status'              THEN r.Status END END ASC,
        CASE WHEN @SortAsc = 0 THEN
            CASE @SortColumn WHEN 'BranchName'          THEN r.BranchName
                             WHEN 'PumpNo'              THEN r.PumpNo
                             WHEN 'TankNo'              THEN r.TankNo
                             WHEN 'FuelTypeDescription' THEN r.FuelTypeDescription
                             WHEN 'Status'              THEN r.Status END END DESC,
        CASE WHEN @SortAsc = 1 THEN
            CASE @SortColumn WHEN 'MechLitres' THEN r.MechLitres
                             WHEN 'ElecLitres' THEN r.ElecLitres
                             WHEN 'POSSales'   THEN r.POSSales
                             WHEN 'MechVsPos'  THEN r.MechVsPos
                             WHEN 'MechVsElec' THEN r.MechVsElec END END ASC,
        CASE WHEN @SortAsc = 0 THEN
            CASE @SortColumn WHEN 'MechLitres' THEN r.MechLitres
                             WHEN 'ElecLitres' THEN r.ElecLitres
                             WHEN 'POSSales'   THEN r.POSSales
                             WHEN 'MechVsPos'  THEN r.MechVsPos
                             WHEN 'MechVsElec' THEN r.MechVsElec END END DESC,
        CASE WHEN @SortAsc = 1 THEN
            CASE @SortColumn WHEN 'ReadingDate' THEN CONVERT(datetime2, r.ReadingDate) END END ASC,
        CASE WHEN @SortAsc = 0 THEN
            CASE @SortColumn WHEN 'ReadingDate' THEN CONVERT(datetime2, r.ReadingDate) END END DESC,
        r.ReadingDate DESC, r.BranchName, r.PumpNo
    OFFSET (@Page - 1) * @PageSize ROWS FETCH NEXT @PageSize ROWS ONLY;

    SELECT @Total AS TotalRows;
END
