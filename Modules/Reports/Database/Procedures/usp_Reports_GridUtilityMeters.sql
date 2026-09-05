/* ============================================================================
   agora.usp_Reports_GridUtilityMeters

   WHAT IT IS FOR
   Utility meter readings by site and meter, with the usage each implies, and a
   flag on the two readings that are always wrong: zero usage, and usage that
   went backwards.

   WHO READS IT
   The branch capturing, and head office watching for a meter nobody is reading.

   ⚠ THE ONE THING TO KNOW ABOUT THIS REPORT
   The customer's own dbo.sp_SelectUtilityTransaction is a SELECT procedure that
   begins with an UPDATE. Every time somebody opens the utilities screen it
   rewrites OpeningReading and Usage across the whole branch, type and date
   range being viewed — a report that mutates the data it reports on.

   AGORA WILL NEVER ISSUE THAT UPDATE. PumpIT is read-only to us, and it would
   be the wrong thing to do even if it were not. So the two figures are DERIVED
   here instead, by the same arithmetic:

       OpeningReading = the previous reading's ClosingReading for this meter
       Usage          = ClosingReading - OpeningReading - PrepaidUnitsPurchased

   The stored values are returned as well, as StoredOpeningReading and
   StoredUsage, so the difference between the two is visible. A row where the
   stored and the derived disagree is a row nobody has opened that screen for
   since it was captured — which is information, not a fault.

   PREVIOUS READING, NOT YESTERDAY
   The legacy UPDATE looks for the reading dated exactly TransactionDate - 1, so
   a meter read weekly gets an opening of zero and a usage equal to the whole
   closing reading. Here it is the previous reading for that meter whenever it
   was, with PreviousReadingDate and GapDays on the row.

   READ-ONLY.

     EXEC agora.usp_Reports_GridUtilityMeters @BranchIds = '7', @DateFrom = '2026-04-01';
   ============================================================================ */

CREATE OR ALTER PROCEDURE [agora].[usp_Reports_GridUtilityMeters]
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
    SET @DateFrom = ISNULL(@DateFrom, DATEADD(day, -30, @DateTo));
    SET @Page     = CASE WHEN ISNULL(@Page, 1) < 1 THEN 1 ELSE @Page END;
    SET @PageSize = CASE WHEN ISNULL(@PageSize, 50) BETWEEN 1 AND 100000 THEN @PageSize ELSE 50 END;
    SET @Search   = NULLIF(LTRIM(RTRIM(ISNULL(@Search, ''))), '');

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

    /* Reach back beyond the window for the previous reading, for the same
       reason the fuel report does: without it the first row of every report
       looks like a meter that ran from zero. */
    DECLARE @ReadFrom date = DATEADD(day, -120, @DateFrom);

    ;WITH Readings AS (
        SELECT t.UtilityTransactionId, t.BranchId, t.UtilityType, t.MeterNo,
               CONVERT(date, t.TransactionDate) AS TransactionDate,
               t.ClosingReading, t.PrepaidUnitsPurchased,
               t.StoredOpeningReading, t.StoredUsage, t.CreateDateTime,
               /* Partitioned by METER, not by branch and type: a site with two
                  electricity meters is two series, and merging them produces a
                  usage that alternates between the two readings. */
               LAG(t.ClosingReading) OVER (
                   PARTITION BY t.BranchId, t.UtilityType, t.MeterNo
                   ORDER BY t.TransactionDate, t.UtilityTransactionId)          AS PreviousClosing,
               LAG(CONVERT(date, t.TransactionDate)) OVER (
                   PARTITION BY t.BranchId, t.UtilityType, t.MeterNo
                   ORDER BY t.TransactionDate, t.UtilityTransactionId)          AS PreviousReadingDate
        FROM agora.vw_UtilityTransaction t
        WHERE t.TransactionDate >= @ReadFrom AND t.TransactionDate < DATEADD(day, 1, @DateTo)
          AND (@AllBranches = 1 OR t.BranchId IN (SELECT BranchId FROM @Branch))
    )
    SELECT
        d.UtilityTransactionId,
        d.BranchId,
        b.Name                                      AS BranchName,
        d.TransactionDate,
        d.UtilityType,
        y.UtilityTypeDescription,
        d.MeterNo,
        m.MeterDescription,
        d.PreviousReadingDate,
        DATEDIFF(day, d.PreviousReadingDate, d.TransactionDate) AS GapDays,
        CONVERT(decimal(18,2), d.PreviousClosing)   AS OpeningReading,
        CONVERT(decimal(18,2), d.ClosingReading)    AS ClosingReading,
        CONVERT(decimal(18,2), d.PrepaidUnitsPurchased) AS PrepaidUnitsPurchased,
        CONVERT(decimal(18,2),
            ISNULL(d.ClosingReading, 0) - ISNULL(d.PreviousClosing, 0)
          - ISNULL(d.PrepaidUnitsPurchased, 0))     AS Usage,
        CONVERT(decimal(18,2), d.StoredOpeningReading) AS StoredOpeningReading,
        CONVERT(decimal(18,2), d.StoredUsage)          AS StoredUsage,
        CASE WHEN ABS(ISNULL(d.StoredUsage, 0)
                    - (ISNULL(d.ClosingReading, 0) - ISNULL(d.PreviousClosing, 0)
                       - ISNULL(d.PrepaidUnitsPurchased, 0))) > 0.005
             THEN CONVERT(bit, 1) ELSE CONVERT(bit, 0) END AS StoredDisagrees,
        CASE WHEN d.PreviousClosing IS NULL THEN 'First reading in range'
             WHEN ISNULL(d.ClosingReading, 0) < ISNULL(d.PreviousClosing, 0) THEN 'Reading went backwards'
             WHEN ISNULL(d.ClosingReading, 0) - ISNULL(d.PreviousClosing, 0)
                - ISNULL(d.PrepaidUnitsPurchased, 0) = 0 THEN 'Zero usage'
             ELSE 'Normal' END                      AS Status,
        d.CreateDateTime                            AS CapturedAt
    INTO #Rows
    FROM Readings d
    JOIN agora.vw_Branch b ON b.BranchId = d.BranchId
    LEFT JOIN agora.vw_UtilityType y ON y.UtilityType = d.UtilityType
    LEFT JOIN agora.vw_UtilityMeter m
           ON m.BranchId = d.BranchId AND m.UtilityType = d.UtilityType AND m.MeterNo = d.MeterNo
    WHERE d.TransactionDate >= @DateFrom AND d.TransactionDate <= @DateTo
      AND (@Search IS NULL
           OR b.Name                  LIKE '%' + @Search + '%'
           OR d.MeterNo               LIKE '%' + @Search + '%'
           OR m.MeterDescription      LIKE '%' + @Search + '%'
           OR y.UtilityTypeDescription LIKE '%' + @Search + '%');

    DECLARE @Total bigint = (SELECT COUNT_BIG(*) FROM #Rows);

    SELECT r.UtilityTransactionId, r.BranchId, r.BranchName, r.TransactionDate,
           r.UtilityType, r.UtilityTypeDescription, r.MeterNo, r.MeterDescription,
           r.PreviousReadingDate, r.GapDays,
           r.OpeningReading, r.ClosingReading, r.PrepaidUnitsPurchased, r.Usage,
           r.StoredOpeningReading, r.StoredUsage, r.StoredDisagrees, r.Status, r.CapturedAt
    FROM #Rows r
    ORDER BY
        CASE WHEN @SortAsc = 1 THEN
            CASE @SortColumn WHEN 'BranchName'             THEN r.BranchName
                             WHEN 'MeterNo'                THEN r.MeterNo
                             WHEN 'MeterDescription'       THEN r.MeterDescription
                             WHEN 'UtilityTypeDescription' THEN r.UtilityTypeDescription
                             WHEN 'Status'                 THEN r.Status END END ASC,
        CASE WHEN @SortAsc = 0 THEN
            CASE @SortColumn WHEN 'BranchName'             THEN r.BranchName
                             WHEN 'MeterNo'                THEN r.MeterNo
                             WHEN 'MeterDescription'       THEN r.MeterDescription
                             WHEN 'UtilityTypeDescription' THEN r.UtilityTypeDescription
                             WHEN 'Status'                 THEN r.Status END END DESC,
        CASE WHEN @SortAsc = 1 THEN
            CASE @SortColumn WHEN 'Usage'          THEN CONVERT(decimal(38,6), r.Usage)
                             WHEN 'ClosingReading' THEN CONVERT(decimal(38,6), r.ClosingReading)
                             WHEN 'GapDays'        THEN CONVERT(decimal(38,6), r.GapDays) END END ASC,
        CASE WHEN @SortAsc = 0 THEN
            CASE @SortColumn WHEN 'Usage'          THEN CONVERT(decimal(38,6), r.Usage)
                             WHEN 'ClosingReading' THEN CONVERT(decimal(38,6), r.ClosingReading)
                             WHEN 'GapDays'        THEN CONVERT(decimal(38,6), r.GapDays) END END DESC,
        CASE WHEN @SortAsc = 1 THEN
            CASE @SortColumn WHEN 'TransactionDate' THEN CONVERT(datetime2, r.TransactionDate) END END ASC,
        CASE WHEN @SortAsc = 0 THEN
            CASE @SortColumn WHEN 'TransactionDate' THEN CONVERT(datetime2, r.TransactionDate) END END DESC,
        r.TransactionDate DESC, r.BranchName, r.UtilityType, r.MeterNo
    OFFSET (@Page - 1) * @PageSize ROWS FETCH NEXT @PageSize ROWS ONLY;

    SELECT @Total AS TotalRows;
END
