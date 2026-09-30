/* ============================================================================
   agora.usp_Utilities_PrepareReadings

   WHAT IT IS FOR
   The capture grid for one site: every meter the site has, for every day in
   the range, with what is already recorded, the opening each reading is
   measured from, the usage and the cost at tariff — and a second result set
   totalling the same range per meter, which is the month figure the screen
   leads with.

   WHO READS IT
   Capture -> Utility meters (head office) and Raise -> Utility reading (the
   branch). One day on a phone; a month on a desk.

   A PORT OF TWO LEGACY PROCEDURES, and of neither one's side effects:

   dbo.sp_CaptureUtilityTransaction built the grid — one row per meter per day
   — by DROPPING AND RE-CREATING A PERMANENT TABLE, dbo._TempUtilityTransaction,
   on every call. Two people opening the screen at once share it. Here the grid
   is a set, built in a table variable and returned.

   dbo.sp_SelectUtilityTransaction showed what was captured — and BEGINS WITH
   AN UPDATE that rewrites OpeningReading and Usage over the whole range being
   looked at, every time anybody looks. This procedure reads. It writes nothing
   anywhere, and the stored figures are returned beside the derived ones so a
   row the legacy has not recomputed is visible as exactly that.

   THE ARITHMETIC is agora.vw_UtilityReading's and nobody else's:

       Opening = the meter's previous READING's closing, whenever it was taken
       Usage   = Closing - Opening - PrepaidUnitsPurchased     (the customer's
                 own formula, sign for sign — see the view for why the opening
                 is not "yesterday")
       A day PumpIT holds as 0 / 0 is NOT a reading (Ryan, 01a0e312-07a0): the
       legacy screen writes the rest of the month ahead like that. It has no
       usage, it is never an opening, and it shows as 'No reading'.
       Cost    = Usage x the tariff for the meter's TYPE, from @TariffsJson.
                 No tariff, no cost: NULL, which the screen draws as a dash.

   Over a range the usages telescope, so the month's total is
       last reading - the reading before the month - the month's prepaid units
   which is the hand check this was accepted against. Blank days drop out of
   it on both ends: the month starts from the last real reading before it and
   ends at the last real reading in it.

   PARAMETERS
     @BranchId      the site. Required — a capture grid is one site's.
     @DateFrom      first day. @DateTo defaults to it (one day).
     @UtilityType   one meter type, or NULL for every meter at the site.
     @TariffsJson   {"B": 2.95, "R": 31.4, ...} — rand per unit by type code.
                    Machinery the application passes from config (Settings,
                    once T026 exists); callable from SSMS with any rates.
     @ZeroRunDaysJson  how many days unmoved before a line is flagged, per
                    meter type: {"default": 3, "L": null, "M": null}. A type
                    set to null is never flagged (generators stand still
                    whenever there is no load-shedding); a type not named
                    takes the default.

   RESULT SETS
     1. One row per meter per day.
     2. One row per meter: the range's opening, closing, prepaid, usage, cost.
     3. The last time readings covering this range were recorded THROUGH
        AGORA, and by whom — PumpIT has no user column, so this is the only
        place that can say. CreatedAt is UTC, like every Agora timestamp.

   READ-ONLY.

     EXEC agora.usp_Utilities_PrepareReadings @BranchId = 8,
          @DateFrom = '2026-08-01', @DateTo = '2026-08-31',
          @TariffsJson = N'{"B":3.10,"S":28.50}';

   Refusals: BRANCH_REQUIRED · DATE_REQUIRED · BAD_RANGE · RANGE_TOO_LONG
   ============================================================================ */

CREATE OR ALTER PROCEDURE [agora].[usp_Utilities_PrepareReadings]
    @BranchId     int,
    @DateFrom     date,
    @DateTo       date          = NULL,
    @UtilityType  nvarchar(1)   = NULL,
    @TariffsJson  nvarchar(max) = NULL,
    @ZeroRunDaysJson nvarchar(max) = N'{"default":3}'
AS
BEGIN
    SET NOCOUNT ON;

    IF @BranchId IS NULL
        THROW 51000, 'AGORA:BRANCH_REQUIRED:Meter readings belong to one site. Choose the site first.', 1;
    IF @DateFrom IS NULL
        THROW 51000, 'AGORA:DATE_REQUIRED:Say which day to capture.', 1;

    SET @DateTo      = ISNULL(@DateTo, @DateFrom);
    SET @UtilityType = NULLIF(LTRIM(RTRIM(ISNULL(@UtilityType, ''))), '');

    IF @DateTo < @DateFrom
        THROW 51000, 'AGORA:BAD_RANGE:The last day is before the first.', 1;
    /* A quarter. The legacy screen prepares one calendar month; anything much
       longer is a report, and the readings grid is the report. */
    IF DATEDIFF(day, @DateFrom, @DateTo) > 92
        THROW 51000, 'AGORA:RANGE_TOO_LONG:Prepare at most three months at a time. The readings grid below covers any range.', 1;

    DECLARE @Today date = CONVERT(date, GETDATE());

    /* ---- the tariffs ------------------------------------------------------
       An object keyed by type code. A key that is not one character is not a
       type code and is ignored; a value that is not a number is no tariff. */
    DECLARE @Tariff TABLE (UtilityType nvarchar(1) PRIMARY KEY, Rate decimal(18,4) NULL);

    IF ISJSON(@TariffsJson) = 1
        INSERT INTO @Tariff (UtilityType, Rate)
        SELECT j.[key], TRY_CONVERT(decimal(18,4), j.[value])
        FROM OPENJSON(@TariffsJson) j
        WHERE LEN(j.[key]) = 1;

    /* ---- the zero-usage run length, per type ------------------------------
       Inserted into a table variable before anything compares it: OPENJSON's
       [key] is Latin1_General_BIN2, and compared straight to a CI_AS column it
       throws a collation conflict. */
    DECLARE @RunDefault int = 3;
    DECLARE @RunDays TABLE (UtilityType nvarchar(1) PRIMARY KEY, Days int NULL);

    IF ISJSON(@ZeroRunDaysJson) = 1
    BEGIN
        IF EXISTS (SELECT 1 FROM OPENJSON(@ZeroRunDaysJson) j WHERE j.[key] = 'default')
            SELECT @RunDefault = CASE WHEN j.[type] = 0 THEN NULL ELSE TRY_CONVERT(int, j.[value]) END
            FROM OPENJSON(@ZeroRunDaysJson) j WHERE j.[key] = 'default';

        INSERT INTO @RunDays (UtilityType, Days)
        SELECT j.[key], CASE WHEN j.[type] = 0 THEN NULL ELSE TRY_CONVERT(int, j.[value]) END
        FROM OPENJSON(@ZeroRunDaysJson) j
        WHERE LEN(j.[key]) = 1;
    END

    /* ---- the meters -------------------------------------------------------
       One row per (type, meter NAME). The customer's key on BRN_UtilityMeter
       leads with the identity, so nothing stops the same meter being entered
       twice; a reading names its meter by MeterNo, so two master rows for one
       name would double every line below. The lowest id wins. The type's
       description is a label lookup and goes through TOP 1 for the same
       reason: a label must never be able to change a row count. */
    DECLARE @Meter TABLE (
        UtilityType            nvarchar(1)  NOT NULL,
        MeterNo                nvarchar(50) NOT NULL,
        UtilityMeterId         int          NULL,
        MeterDescription       nvarchar(50) NULL,
        UtilityTypeDescription nvarchar(50) NULL,
        /* NULL = this type is never flagged for standing still. */
        ZeroRunThreshold       int          NULL,
        PRIMARY KEY (UtilityType, MeterNo)
    );

    INSERT INTO @Meter (UtilityType, MeterNo, UtilityMeterId, MeterDescription, UtilityTypeDescription, ZeroRunThreshold)
    SELECT m.UtilityType, m.MeterNo, m.UtilityMeterId, m.MeterDescription, y.UtilityTypeDescription,
           CASE WHEN rd.UtilityType IS NULL THEN @RunDefault ELSE rd.Days END
    FROM (
        SELECT mm.UtilityType, mm.MeterNo, mm.UtilityMeterId, mm.MeterDescription,
               ROW_NUMBER() OVER (PARTITION BY mm.UtilityType, mm.MeterNo ORDER BY mm.UtilityMeterId) AS rn
        FROM agora.vw_UtilityMeter mm
        WHERE mm.BranchId = @BranchId
          AND (@UtilityType IS NULL OR mm.UtilityType = @UtilityType)
    ) m
    OUTER APPLY (
        SELECT TOP 1 t.UtilityTypeDescription
        FROM agora.vw_UtilityType t
        WHERE t.UtilityType = m.UtilityType
        ORDER BY t.UtilityTypeId
    ) y
    LEFT JOIN @RunDays rd ON rd.UtilityType = m.UtilityType
    WHERE m.rn = 1;

    /* ---- the days ---------------------------------------------------------
       A set, where the legacy walked a WHILE loop. At most 93. */
    DECLARE @Day TABLE (ReadingDate date PRIMARY KEY);

    INSERT INTO @Day (ReadingDate)
    SELECT DATEADD(day, n.i, @DateFrom)
    FROM (
        SELECT a.d * 10 + b.d AS i
        FROM (VALUES (0),(1),(2),(3),(4),(5),(6),(7),(8),(9)) a(d)
        CROSS JOIN (VALUES (0),(1),(2),(3),(4),(5),(6),(7),(8),(9)) b(d)
    ) n
    WHERE n.i <= DATEDIFF(day, @DateFrom, @DateTo);

    /* ---- the site's readings, once ----------------------------------------
       Everything up to the end of the range, because the opening of the first
       day and the start of a zero run both lie before it. One site's history
       is a few thousand rows; the view's window functions see only this
       site's rows, because the BranchId predicate is pushed beneath them. */
    SELECT r.UtilityTransactionId, r.UtilityType, r.MeterNo, r.ReadingDate,
           r.OpeningReading, r.PreviousReadingDate, r.ClosingReading,
           r.PrepaidUnitsPurchased, r.[Usage], r.IsBlank,
           r.StoredOpeningReading, r.StoredUsage, r.RowsForDay, r.CreateDateTime
    INTO #R
    FROM agora.vw_UtilityReading r
    WHERE r.BranchId = @BranchId
      AND r.ReadingDate <= @DateTo
      AND (@UtilityType IS NULL OR r.UtilityType = @UtilityType);

    CREATE CLUSTERED INDEX IX_R ON #R (UtilityType, MeterNo, ReadingDate);

    /* ---- set 1: the grid --------------------------------------------------- */
    DECLARE @Line TABLE (
        ReadingDate            date          NOT NULL,
        UtilityType            nvarchar(1)   NOT NULL,
        MeterNo                nvarchar(50)  NOT NULL,
        UtilityMeterId         int           NULL,
        MeterDescription       nvarchar(50)  NULL,
        UtilityTypeDescription nvarchar(50)  NULL,
        UtilityTransactionId   int           NULL,
        IsCaptured             bit           NOT NULL,
        IsBlank                bit           NOT NULL,
        OpeningReading         decimal(18,4) NULL,
        PreviousReadingDate    date          NULL,
        ClosingReading         decimal(18,4) NULL,
        PrepaidUnitsPurchased  decimal(18,4) NULL,
        [Usage]                decimal(18,4) NULL,
        Tariff                 decimal(18,4) NULL,
        Cost                   decimal(18,2) NULL,
        LastMovedDate          date          NULL,
        ZeroRunDays            int           NULL,
        StoredOpeningReading   decimal(18,4) NULL,
        StoredUsage            decimal(18,4) NULL,
        RowsForDay             int           NULL,
        CapturedAt             datetime      NULL,
        PRIMARY KEY (UtilityType, MeterNo, ReadingDate)
    );

    INSERT INTO @Line (
        ReadingDate, UtilityType, MeterNo, UtilityMeterId, MeterDescription, UtilityTypeDescription,
        UtilityTransactionId, IsCaptured, IsBlank, OpeningReading, PreviousReadingDate,
        ClosingReading, PrepaidUnitsPurchased, [Usage], Tariff, Cost, LastMovedDate, ZeroRunDays,
        StoredOpeningReading, StoredUsage, RowsForDay, CapturedAt)
    SELECT
        d.ReadingDate, m.UtilityType, m.MeterNo, m.UtilityMeterId, m.MeterDescription, m.UtilityTypeDescription,
        r.UtilityTransactionId,
        /* Captured means somebody READ the meter: a row the legacy screen
           wrote ahead of the day as 0 / 0 is a row, not a reading. */
        CONVERT(bit, CASE WHEN r.UtilityTransactionId IS NOT NULL AND r.IsBlank = 0 THEN 1 ELSE 0 END),
        CONVERT(bit, ISNULL(r.IsBlank, 0)),
        /* For a day with a reading this is exactly the view's opening; for a
           day without one, or one held as 0 / 0, it is the opening that day
           WOULD be measured from — what the capture screen shows beside the
           empty box. */
        prev.ClosingReading,
        prev.ReadingDate,
        r.ClosingReading,
        r.PrepaidUnitsPurchased,
        r.[Usage],
        t.Rate,
        CONVERT(decimal(18,2), r.[Usage] * t.Rate),
        moved.ReadingDate,
        CASE WHEN r.[Usage] = 0 THEN DATEDIFF(day, moved.ReadingDate, d.ReadingDate) END,
        r.StoredOpeningReading,
        r.StoredUsage,
        r.RowsForDay,
        r.CreateDateTime
    FROM @Meter m
    CROSS JOIN @Day d
    /* #R holds one row per meter per day — the view dedupes — so this join
       cannot fan out. */
    LEFT JOIN #R r
           ON r.UtilityType = m.UtilityType AND r.MeterNo = m.MeterNo AND r.ReadingDate = d.ReadingDate
    OUTER APPLY (
        SELECT TOP 1 p.ClosingReading, p.ReadingDate
        FROM #R p
        WHERE p.UtilityType = m.UtilityType AND p.MeterNo = m.MeterNo AND p.ReadingDate < d.ReadingDate
          AND p.IsBlank = 0
        ORDER BY p.ReadingDate DESC
    ) prev
    /* The last reading at which the meter MOVED — or its first reading ever,
       which has no usage. A zero run is measured from there, so a meter read
       weekly that did not move all week is seven days still, not one. A 0 / 0
       day is not a reading, so it neither moves the meter nor stops it. */
    OUTER APPLY (
        SELECT TOP 1 q.ReadingDate
        FROM #R q
        WHERE q.UtilityType = m.UtilityType AND q.MeterNo = m.MeterNo AND q.ReadingDate < d.ReadingDate
          AND q.IsBlank = 0
          AND (q.[Usage] IS NULL OR q.[Usage] <> 0)
        ORDER BY q.ReadingDate DESC
    ) moved
    LEFT JOIN @Tariff t ON t.UtilityType = m.UtilityType;

    SELECT
        @BranchId AS BranchId,
        l.ReadingDate AS TransactionDate,
        l.UtilityType, l.UtilityTypeDescription,
        l.UtilityMeterId, l.MeterNo, l.MeterDescription,
        l.UtilityTransactionId, l.IsCaptured, l.IsBlank,
        l.PreviousReadingDate, l.OpeningReading, l.ClosingReading, l.PrepaidUnitsPurchased,
        l.[Usage], l.Tariff, l.Cost,
        l.LastMovedDate, l.ZeroRunDays,
        m.ZeroRunThreshold,
        CONVERT(bit, CASE WHEN l.ZeroRunDays >= m.ZeroRunThreshold THEN 1 ELSE 0 END) AS IsZeroRun,
        CASE
            WHEN l.ReadingDate > @Today            THEN 'Not due yet'
            WHEN l.UtilityTransactionId IS NULL    THEN 'Not captured'
            WHEN l.IsBlank = 1                     THEN 'No reading'
            WHEN l.OpeningReading IS NULL          THEN 'First reading'
            WHEN l.[Usage] < 0                     THEN 'Reading went backwards'
            WHEN l.[Usage] = 0                     THEN 'Zero usage'
            ELSE 'Normal'
        END AS [Status],
        l.StoredOpeningReading, l.StoredUsage,
        /* Whether PumpIT's stored figures disagree with the derived ones. A
           row nobody has opened the legacy screen for since it was captured
           will — which is information, not a fault. */
        CONVERT(bit, CASE
            WHEN l.UtilityTransactionId IS NULL OR l.IsBlank = 1 THEN 0
            WHEN ABS(ISNULL(l.StoredOpeningReading, 0) - ISNULL(l.OpeningReading, 0)) > 0.005 THEN 1
            WHEN l.[Usage] IS NOT NULL AND ABS(ISNULL(l.StoredUsage, 0) - l.[Usage]) > 0.005 THEN 1
            ELSE 0 END) AS StoredDisagrees,
        l.RowsForDay,
        l.CapturedAt
    FROM @Line l
    JOIN @Meter m ON m.UtilityType = l.UtilityType AND m.MeterNo = l.MeterNo
    ORDER BY l.ReadingDate, l.UtilityType, l.MeterNo;

    /* ---- set 2: the range, per meter -------------------------------------
       Summed from set 1, not recomputed — so the month figure and the lines
       beneath it are the same numbers. Opening is the reading the range
       starts from (the opening of its first READ day); Closing is its last
       real reading — never a 0 / 0 day the legacy wrote ahead. */
    SELECT
        @BranchId AS BranchId,
        m.UtilityType, m.UtilityTypeDescription, m.UtilityMeterId, m.MeterNo, m.MeterDescription,
        @DateFrom AS DateFrom,
        @DateTo   AS DateTo,
        (SELECT COUNT(*) FROM @Line x
          WHERE x.UtilityType = m.UtilityType AND x.MeterNo = m.MeterNo AND x.ReadingDate <= @Today) AS DaysDue,
        (SELECT COUNT(*) FROM @Line x
          WHERE x.UtilityType = m.UtilityType AND x.MeterNo = m.MeterNo AND x.IsCaptured = 1)      AS DaysCaptured,
        /* Days up to today PumpIT holds as 0 / 0 — not read yet. Not
           readings, so they are in neither total; counted so a month short of
           readings says why. */
        (SELECT COUNT(*) FROM @Line x
          WHERE x.UtilityType = m.UtilityType AND x.MeterNo = m.MeterNo AND x.IsBlank = 1
            AND x.ReadingDate <= @Today)                                                             AS BlankDays,
        f.OpeningReading,
        f.PreviousReadingDate AS OpeningDate,
        z.ClosingReading,
        z.ReadingDate         AS ClosingDate,
        s.PrepaidUnitsPurchased,
        s.[Usage],
        t.Rate AS Tariff,
        CONVERT(decimal(18,2), s.[Usage] * t.Rate) AS Cost,
        /* In the WHERE rather than inside a SUM: an aggregate may not mix an
           outer column with an inner one. */
        (SELECT COUNT(*) FROM @Line x
          WHERE x.UtilityType = m.UtilityType AND x.MeterNo = m.MeterNo
            AND x.ZeroRunDays >= m.ZeroRunThreshold)                                                 AS ZeroRunLines,
        m.ZeroRunThreshold
    FROM @Meter m
    OUTER APPLY (
        SELECT TOP 1 x.OpeningReading, x.PreviousReadingDate
        FROM @Line x
        WHERE x.UtilityType = m.UtilityType AND x.MeterNo = m.MeterNo AND x.IsCaptured = 1
        ORDER BY x.ReadingDate
    ) f
    OUTER APPLY (
        SELECT TOP 1 x.ClosingReading, x.ReadingDate
        FROM @Line x
        WHERE x.UtilityType = m.UtilityType AND x.MeterNo = m.MeterNo AND x.IsCaptured = 1
        ORDER BY x.ReadingDate DESC
    ) z
    OUTER APPLY (
        SELECT SUM(x.PrepaidUnitsPurchased) AS PrepaidUnitsPurchased,
               SUM(x.[Usage])               AS [Usage]
        FROM @Line x
        WHERE x.UtilityType = m.UtilityType AND x.MeterNo = m.MeterNo
    ) s
    LEFT JOIN @Tariff t ON t.UtilityType = m.UtilityType
    ORDER BY m.UtilityType, m.MeterNo;

    /* ---- set 3: the last Agora save that covers this range ---------------
       Read from Agora's own record, because BRN_UtilityTransaction cannot say
       who wrote a row. UTC, like every timestamp Agora writes. */
    SELECT TOP 1
        v.Id            AS SaveId,
        v.CreatedAt,
        v.CreatedBy,
        u.UserName      AS CreatedByName,
        v.DateFrom, v.DateTo,
        v.Captured, v.Filled, v.Amended, v.Recomputed
    FROM agora.UtilityReadingSave v
    LEFT JOIN agora.[User] u ON u.Id = v.CreatedBy
    WHERE v.BranchId = @BranchId
      AND v.DateFrom <= @DateTo AND v.DateTo >= @DateFrom
    ORDER BY v.Id DESC;
END
