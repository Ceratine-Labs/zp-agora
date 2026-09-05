/* ============================================================================
   agora.usp_Reports_GridOvernightLoads

   WHAT IT IS FOR
   Overnight loads by branch and by source system. A failed load is what makes a
   report look wrong the next morning, so this is the first place to look before
   believing anything else on the Today menu.

   WHO READS IT
   Head office, before the day starts.

   HOW IT ANSWERS THE QUESTION
   Not by asking the loader — by counting what landed. The grid is the full
   grid of (branch x day x feed) that SHOULD exist, LEFT JOINed to what does, so
   a feed that did not run appears as a row saying zero rather than as an absence
   nobody notices. That is the whole point: a missing row cannot be seen, and
   every "the numbers were wrong this morning" incident starts with one.

   The six feeds are the six that every downstream report on this menu reads:
   day end, pump readings, fuel input, Z-reads, stock counts and cashups.

   THE SECOND HALF, AND WHY BOTH ARE NEEDED
   SS_Branch carries four per-feed high-water marks (BRN_/STK_/RCN_/NAMOS_
   LastImportDateStamp). They say when a loader last ran; they do not say whether
   last night's run was complete. A loader that runs, finds nothing and stamps
   the date looks identical to a healthy one from the stamp alone. So the stamp
   is shown beside the count, and neither is trusted on its own.

   Branch scope is every ACTIVE branch: an inactive site with no load is not an
   exception, and including it would put permanent red rows on a daily check
   until people stopped reading it.

   READ-ONLY.

     EXEC agora.usp_Reports_GridOvernightLoads @DateFrom = '2026-09-03', @DateTo = '2026-09-03';
   ============================================================================ */

CREATE OR ALTER PROCEDURE [agora].[usp_Reports_GridOvernightLoads]
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

    /* One night by default. The grid is branches x days x six feeds, so a month
       across the group is 31 x 31 x 6 — still small, but the question this
       report answers is almost always about last night. */
    SET @DateTo   = ISNULL(@DateTo, CONVERT(date, GETDATE()));
    SET @DateFrom = ISNULL(@DateFrom, @DateTo);
    SET @Page     = CASE WHEN ISNULL(@Page, 1) < 1 THEN 1 ELSE @Page END;
    SET @PageSize = CASE WHEN ISNULL(@PageSize, 50) BETWEEN 1 AND 100000 THEN @PageSize ELSE 50 END;
    SET @Search   = NULLIF(LTRIM(RTRIM(ISNULL(@Search, ''))), '');

    /* A range this report cannot usefully answer is refused rather than run.
       The grid is a product of three dimensions and a year of it is a million
       rows nobody asked for. */
    IF DATEDIFF(day, @DateFrom, @DateTo) > 92
        THROW 51000, 'AGORA:RANGE_TOO_WIDE:Overnight loads covers at most 92 days at a time — it is a grid of branches by days by feeds.', 1;

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

    /* The feeds, and which of the branch's four high-water stamps belongs to
       each. Declared as data rather than as six near-identical blocks. */
    DECLARE @Feed TABLE (FeedKey nvarchar(20) PRIMARY KEY, Feed nvarchar(40), Stamp nvarchar(10), SortOrder int);
    INSERT INTO @Feed (FeedKey, Feed, Stamp, SortOrder) VALUES
        ('dayend',   'Day end',       'BRN', 10),
        ('zread',    'Z-reads',       'BRN', 20),
        ('cashup',   'Cashups',       'BRN', 30),
        ('pump',     'Pump readings', 'BRN', 40),
        ('fuel',     'Fuel input',    'BRN', 50),
        ('stock',    'Stock counts',  'STK', 60);

    /* Count what landed ONCE per feed, over the whole range, and join the
       scaffold to the result.

       The obvious shape — scaffold first, then a correlated count per cell —
       reads better and is unusable: it re-enters six landing tables once for
       every branch x day x feed, which against 378,395 pump readings and 7.1
       million stock recon lines is a scan per cell. Aggregating first turns
       that into six grouped passes whatever the size of the grid. */
    SELECT BranchId, LoadDate, FeedKey, COUNT_BIG(*) AS RowsLoaded, MAX(CreatedAt) AS LastRowAt
    INTO #Loads
    FROM (
        SELECT y.BranchId, CONVERT(date, y.DayEndDate) AS LoadDate, 'dayend' AS FeedKey, y.CreateDateTime AS CreatedAt
        FROM agora.vw_DayEnd y
        WHERE y.DayEndDate >= @DateFrom AND y.DayEndDate < DATEADD(day, 1, @DateTo)

        UNION ALL
        /* DBF_P3TRANS_ZREAD carries no created-at of its own — the loader
           stamps MIST_EOD and nothing else — so LastRowAt is NULL for Z-reads
           by fact, not by oversight. */
        SELECT z.BranchId, CONVERT(date, z.ZReadDate), 'zread', NULL
        FROM agora.vw_ZRead z
        WHERE z.ZReadDate >= @DateFrom AND z.ZReadDate < DATEADD(day, 1, @DateTo)

        UNION ALL
        SELECT k.BranchId, CONVERT(date, k.TransactionDate), 'cashup', k.CreateDateTime
        FROM agora.vw_DailyBanking k
        WHERE k.TransactionDate >= @DateFrom AND k.TransactionDate < DATEADD(day, 1, @DateTo)

        UNION ALL
        SELECT p.BranchId, CONVERT(date, p.ReadingDate), 'pump', p.CreateDateTime
        FROM agora.vw_PumpReading p
        WHERE p.ReadingDate >= @DateFrom AND p.ReadingDate < DATEADD(day, 1, @DateTo)

        UNION ALL
        SELECT i.BranchId, CONVERT(date, i.FuelDate), 'fuel', i.CreateDateTime
        FROM agora.vw_FuelInput i
        WHERE i.FuelDate >= @DateFrom AND i.FuelDate < DATEADD(day, 1, @DateTo)

        UNION ALL
        SELECT s.BranchId, CONVERT(date, s.TransactionDate), 'stock', s.CreateDateTime
        FROM agora.vw_StockRecon s
        WHERE s.TransactionDate >= @DateFrom AND s.TransactionDate < DATEADD(day, 1, @DateTo)
    ) landed
    GROUP BY BranchId, LoadDate, FeedKey;

    /* The scaffold: every branch x day x feed that SHOULD exist. A feed that
       did not run has no row in #Loads, and that is exactly the row this
       report exists to show — an absence cannot be seen, which is how a failed
       load reaches the morning unnoticed. */
    ;WITH Days AS (
        SELECT @DateFrom AS LoadDate
        UNION ALL
        SELECT DATEADD(day, 1, LoadDate) FROM Days WHERE LoadDate < @DateTo
    )
    SELECT
        b.BranchId,
        b.BranchName,
        d.LoadDate,
        f.FeedKey,
        f.Feed,
        f.SortOrder,
        CONVERT(bigint, ISNULL(a.RowsLoaded, 0)) AS RowsLoaded,
        a.LastRowAt,
        CASE f.Stamp WHEN 'STK' THEN b.StockLastImportAt ELSE b.BranchLastImportAt END AS FeedLastStampedAt,
        CASE WHEN ISNULL(a.RowsLoaded, 0) > 0 THEN 'Loaded' ELSE 'Missing' END          AS Status
    INTO #Rows
    FROM agora.vw_BranchImportStatus b
    CROSS JOIN Days d
    CROSS JOIN @Feed f
    LEFT JOIN #Loads a
           ON a.BranchId = b.BranchId
          AND a.LoadDate = d.LoadDate
          AND a.FeedKey  = f.FeedKey
    WHERE b.IsActive = 1
      AND (@AllBranches = 1 OR b.BranchId IN (SELECT BranchId FROM @Branch))
      AND (@Search IS NULL OR b.BranchName LIKE '%' + @Search + '%' OR f.Feed LIKE '%' + @Search + '%')
    OPTION (MAXRECURSION 100);

    DECLARE @Total bigint = (SELECT COUNT_BIG(*) FROM #Rows);

    SELECT r.BranchId, r.BranchName, r.LoadDate, r.FeedKey, r.Feed,
           r.RowsLoaded, r.LastRowAt, r.FeedLastStampedAt, r.Status
    FROM #Rows r
    ORDER BY
        CASE WHEN @SortAsc = 1 THEN
            CASE @SortColumn WHEN 'BranchName' THEN r.BranchName
                             WHEN 'Feed'       THEN r.Feed
                             WHEN 'Status'     THEN r.Status END END ASC,
        CASE WHEN @SortAsc = 0 THEN
            CASE @SortColumn WHEN 'BranchName' THEN r.BranchName
                             WHEN 'Feed'       THEN r.Feed
                             WHEN 'Status'     THEN r.Status END END DESC,
        CASE WHEN @SortAsc = 1 THEN
            CASE @SortColumn WHEN 'RowsLoaded' THEN CONVERT(decimal(38,6), r.RowsLoaded) END END ASC,
        CASE WHEN @SortAsc = 0 THEN
            CASE @SortColumn WHEN 'RowsLoaded' THEN CONVERT(decimal(38,6), r.RowsLoaded) END END DESC,
        CASE WHEN @SortAsc = 1 THEN
            CASE @SortColumn WHEN 'LoadDate'          THEN CONVERT(datetime2, r.LoadDate)
                             WHEN 'LastRowAt'         THEN CONVERT(datetime2, r.LastRowAt)
                             WHEN 'FeedLastStampedAt' THEN CONVERT(datetime2, r.FeedLastStampedAt) END END ASC,
        CASE WHEN @SortAsc = 0 THEN
            CASE @SortColumn WHEN 'LoadDate'          THEN CONVERT(datetime2, r.LoadDate)
                             WHEN 'LastRowAt'         THEN CONVERT(datetime2, r.LastRowAt)
                             WHEN 'FeedLastStampedAt' THEN CONVERT(datetime2, r.FeedLastStampedAt) END END DESC,
        /* Default: the problems first, then by site and feed. */
        r.LoadDate DESC, r.Status, r.BranchName, r.SortOrder
    OFFSET (@Page - 1) * @PageSize ROWS FETCH NEXT @PageSize ROWS ONLY;

    SELECT @Total AS TotalRows;
END
