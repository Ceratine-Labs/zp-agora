/* ============================================================================
   agora.usp_Utilities_ZeroUsage

   WHAT IT IS FOR
   Meters that have stopped moving, and meters nobody is reading. A run of
   readings with zero usage is either a meter that has failed, a meter nobody
   is really reading — the same number copied down day after day — or a supply
   that is off. A run of days with no reading at all is a meter nobody reads.
   Either is worth a person's attention, and neither shows up in a usage
   total, because zero and nothing both add nothing.

   WHO READS IT
   The utilities screen, straight after a Save and on every visit, for the site
   and month in view ("flagged on capture"). And, once the exception engine
   exists (plan §3.12), the scheduler — which is why each row carries the
   exception-candidate shape: Category, Severity, Title, Detail, Value, Unit and
   a Fingerprint the engine upserts on. Until then nothing stores these; they
   are recomputed from the readings every time, which is also why they can
   never go stale.

   TWO KINDS OF RUN, kept apart because they are different facts:

     'Meter did not move'   consecutive READINGS whose usage is exactly zero,
                            per agora.vw_UtilityReading (Closing - Opening -
                            Prepaid). Measured in DAYS from the last reading at
                            which the meter moved to the latest at which it
                            still had not, so a meter read weekly that did not
                            move in a week is a seven-day run. Days held as
                            0 / 0 in between are skipped over, not counted as
                            movement and not counted as stillness.
     'No reading'           consecutive days PumpIT holds as 0 / 0 — which is
                            NOT a reading (Ryan, PM decision 01a0e312-07a0):
                            the legacy screen writes the rest of the month
                            ahead like that. Measured from the last real
                            reading; for a meter never read at all, from the
                            day before its first row. A 0 / 0 day is never
                            'did not move'.

   A run only counts up to today: the legacy's rows for days that have not
   happened yet are 0 / 0 too, and a meter is not unread in the future.

   HOW LONG BEFORE IT COUNTS is per meter TYPE, from @ZeroRunDaysJson:
   {"default": 3, "L": null, "M": null}. A type set to null is never flagged —
   the generators, which stand still whenever there is no load-shedding (17 of
   the estate's 31 runs in September 2026 were generators). A type not named
   takes the default.

   PARAMETERS
     @BranchId          one site, or 0 for every site (estate-wide, for the
                        scheduler — documented per plan §3.4).
     @DateFrom/@DateTo  a run is reported when it reaches into this window.
                        @DateTo defaults to today and is capped at today;
                        @DateFrom to 30 days before it.
     @ZeroRunDaysJson   the run length per type, above.
     @AllowedBranchIds  CSV of the sites the caller may see at all, for the
                        estate-wide call. Empty means no restriction.

   READ-ONLY.

     EXEC agora.usp_Utilities_ZeroUsage @BranchId = 13, @DateFrom = '2026-08-01',
          @DateTo = '2026-08-31', @ZeroRunDaysJson = N'{"default":3,"L":null,"M":null}';
   ============================================================================ */

CREATE OR ALTER PROCEDURE [agora].[usp_Utilities_ZeroUsage]
    @BranchId          int           = 0,
    @DateFrom          date          = NULL,
    @DateTo            date          = NULL,
    @ZeroRunDaysJson   nvarchar(max) = N'{"default":3}',
    @AllowedBranchIds  nvarchar(max) = NULL
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @Today date = CONVERT(date, GETDATE());

    SET @BranchId = ISNULL(@BranchId, 0);
    SET @DateTo   = CASE WHEN @DateTo IS NULL OR @DateTo > @Today THEN @Today ELSE @DateTo END;
    SET @DateFrom = ISNULL(@DateFrom, DATEADD(day, -30, @DateTo));

    /* The run length per type. Into a table variable before anything compares
       it: OPENJSON's [key] is Latin1_General_BIN2 and throws a collation
       conflict against a CI_AS column. */
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

    /* The empty-string trap: STRING_SPLIT('', ',') is one empty row, and
       TRY_CONVERT(int, '') is 0 — so the blank test comes first. */
    DECLARE @Allowed TABLE (BranchId int PRIMARY KEY);

    INSERT INTO @Allowed (BranchId)
    SELECT DISTINCT TRY_CONVERT(int, LTRIM(RTRIM(s.value)))
    FROM STRING_SPLIT(ISNULL(@AllowedBranchIds, ''), ',') s
    WHERE LTRIM(RTRIM(s.value)) <> ''
      AND TRY_CONVERT(int, LTRIM(RTRIM(s.value))) IS NOT NULL;

    DECLARE @AllAllowed bit = CASE WHEN EXISTS (SELECT 1 FROM @Allowed) THEN 0 ELSE 1 END;

    SELECT r.BranchId, r.UtilityType, r.MeterNo, r.ReadingDate, r.PreviousReadingDate,
           r.ClosingReading, r.[Usage], r.IsBlank
    INTO #R
    FROM agora.vw_UtilityReading r
    WHERE (@BranchId = 0 OR r.BranchId = @BranchId)
      AND (@AllAllowed = 1 OR r.BranchId IN (SELECT BranchId FROM @Allowed))
      AND r.ReadingDate <= @DateTo
    OPTION (RECOMPILE);

    DECLARE @Run TABLE (
        BranchId      int           NOT NULL,
        UtilityType   nvarchar(1)   NOT NULL,
        MeterNo       nvarchar(50)  NOT NULL,
        Kind          nvarchar(20)  NOT NULL,
        RunFrom       date          NULL,
        FirstDate     date          NOT NULL,
        RunTo         date          NOT NULL,
        Readings      int           NOT NULL,
        HeldReading   decimal(18,4) NULL,
        NeverRead     bit           NOT NULL
    );

    /* Gaps and islands, twice. Within one meter's rows in date order, a run is
       a stretch where the row number over ALL the rows and the row number over
       the rows of that state advance together.

       1. Did not move: over REAL readings only, so a 0 / 0 day between two
          readings is invisible to it. */
    ;WITH reading AS (
        SELECT r.*, CASE WHEN r.[Usage] = 0 THEN 1 ELSE 0 END AS IsZero
        FROM #R r
        WHERE r.IsBlank = 0
    ),
    numbered AS (
        SELECT x.*,
               ROW_NUMBER() OVER (PARTITION BY x.BranchId, x.UtilityType, x.MeterNo ORDER BY x.ReadingDate)
             - ROW_NUMBER() OVER (PARTITION BY x.BranchId, x.UtilityType, x.MeterNo, x.IsZero ORDER BY x.ReadingDate)
               AS Island
        FROM reading x
    )
    INSERT INTO @Run (BranchId, UtilityType, MeterNo, Kind, RunFrom, FirstDate, RunTo, Readings, HeldReading, NeverRead)
    SELECT n.BranchId, n.UtilityType, n.MeterNo, 'Meter did not move',
           /* The reading before the first zero: the day the meter last moved,
              and the value it has held since. */
           MIN(n.PreviousReadingDate), MIN(n.ReadingDate), MAX(n.ReadingDate), COUNT(*),
           MAX(n.ClosingReading), 0
    FROM numbered n
    WHERE n.IsZero = 1
    GROUP BY n.BranchId, n.UtilityType, n.MeterNo, n.Island;

    /* 2. No reading: over every row, islands of 0 / 0 days. A blank day's
          PreviousReadingDate is the last real reading before it, the same for
          every day in the island. */
    ;WITH numbered AS (
        SELECT r.*,
               ROW_NUMBER() OVER (PARTITION BY r.BranchId, r.UtilityType, r.MeterNo ORDER BY r.ReadingDate)
             - ROW_NUMBER() OVER (PARTITION BY r.BranchId, r.UtilityType, r.MeterNo, r.IsBlank ORDER BY r.ReadingDate)
               AS Island
        FROM #R r
    )
    INSERT INTO @Run (BranchId, UtilityType, MeterNo, Kind, RunFrom, FirstDate, RunTo, Readings, HeldReading, NeverRead)
    SELECT n.BranchId, n.UtilityType, n.MeterNo, 'No reading',
           ISNULL(MIN(n.PreviousReadingDate), DATEADD(day, -1, MIN(n.ReadingDate))),
           MIN(n.ReadingDate), MAX(n.ReadingDate), COUNT(*), NULL,
           CONVERT(bit, CASE WHEN MIN(n.PreviousReadingDate) IS NULL THEN 1 ELSE 0 END)
    FROM numbered n
    WHERE n.IsBlank = 1
    GROUP BY n.BranchId, n.UtilityType, n.MeterNo, n.Island;

    /* Ongoing means nothing has happened since: for a still meter, its run
       ends at its latest READING; for an unread one, at its latest row. */
    ;WITH latest AS (
        SELECT r.BranchId, r.UtilityType, r.MeterNo,
               MAX(r.ReadingDate)                                   AS LatestRow,
               MAX(CASE WHEN r.IsBlank = 0 THEN r.ReadingDate END)  AS LatestReading
        FROM #R r
        GROUP BY r.BranchId, r.UtilityType, r.MeterNo
    )
    SELECT
        u.BranchId,
        b.Name                                    AS BranchName,
        u.UtilityType,
        y.UtilityTypeDescription,
        u.MeterNo,
        ISNULL(m.MeterDescription, u.MeterNo)     AS MeterDescription,
        u.RunFrom,
        u.RunTo,
        DATEDIFF(day, u.RunFrom, u.RunTo)         AS RunDays,
        u.Readings,
        u.HeldReading,
        u.Kind,
        u.NeverRead,
        th.Threshold                              AS RunThreshold,
        CONVERT(bit, CASE WHEN u.Kind = 'No reading' AND u.RunTo = l.LatestRow THEN 1
                          WHEN u.Kind = 'Meter did not move' AND u.RunTo = l.LatestReading THEN 1
                          ELSE 0 END)             AS IsOngoing,
        /* The exception-candidate shape, plan §3.12. */
        CONVERT(nvarchar(20), 'Utilities')        AS Category,
        CONVERT(nvarchar(10), 'warning')          AS Severity,
        CONCAT(ISNULL(m.MeterDescription, u.MeterNo),
               CASE WHEN u.Kind = 'No reading'
                    THEN CONCAT(' has not been read for ', DATEDIFF(day, u.RunFrom, u.RunTo), ' days')
                    ELSE CONCAT(' has not moved for ', DATEDIFF(day, u.RunFrom, u.RunTo), ' days') END)
                                                  AS Title,
        CASE WHEN u.Kind = 'No reading' AND u.NeverRead = 1
             THEN CONCAT('Never read in PumpIT''s history: every day from ', CONVERT(nvarchar(10), u.FirstDate, 120),
                         ' to ', CONVERT(nvarchar(10), u.RunTo, 120), ' is held as 0 / 0.')
             WHEN u.Kind = 'No reading'
             THEN CONCAT('Last read on ', CONVERT(nvarchar(10), u.RunFrom, 120), '; every day from ',
                         CONVERT(nvarchar(10), u.FirstDate, 120), ' to ', CONVERT(nvarchar(10), u.RunTo, 120),
                         ' is held as 0 / 0 — the days are there, nobody has read the meter.')
             ELSE CONCAT('Held at ', CONVERT(nvarchar(30), CONVERT(decimal(18,2), u.HeldReading)), ' from ',
                         CONVERT(nvarchar(10), u.RunFrom, 120), ' to ', CONVERT(nvarchar(10), u.RunTo, 120),
                         ', across ', u.Readings, ' reading', CASE WHEN u.Readings = 1 THEN '' ELSE 's' END, '.')
        END                                       AS Detail,
        DATEDIFF(day, u.RunFrom, u.RunTo)         AS [Value],
        CONVERT(nvarchar(10), 'days')             AS Unit,
        /* Stable for the life of the run: it names the kind and where the run
           STARTED, so the engine keeps one exception open while it grows, and
           a new run after the meter moves (or is read) again is a new one. */
        CONCAT('utilities.', CASE WHEN u.Kind = 'No reading' THEN 'no-reading' ELSE 'zero-usage' END, ':',
               u.BranchId, ':', u.UtilityType, ':', u.MeterNo, ':',
               CONVERT(nvarchar(10), u.RunFrom, 120)) AS Fingerprint,
        CONVERT(nvarchar(60), 'usp_Utilities_ZeroUsage') AS SourceReport
    FROM @Run u
    JOIN latest l ON l.BranchId = u.BranchId AND l.UtilityType = u.UtilityType AND l.MeterNo = u.MeterNo
    LEFT JOIN @RunDays rd ON rd.UtilityType = u.UtilityType
    CROSS APPLY (SELECT CASE WHEN rd.UtilityType IS NULL THEN @RunDefault ELSE rd.Days END AS Threshold) th
    LEFT JOIN agora.Branch b ON b.BranchId = u.BranchId
    /* Labels, through TOP 1: a label lookup must never change a row count. */
    OUTER APPLY (SELECT TOP 1 mm.MeterDescription FROM agora.vw_UtilityMeter mm
                 WHERE mm.BranchId = u.BranchId AND mm.UtilityType = u.UtilityType AND mm.MeterNo = u.MeterNo
                 ORDER BY mm.UtilityMeterId) m
    OUTER APPLY (SELECT TOP 1 t.UtilityTypeDescription FROM agora.vw_UtilityType t
                 WHERE t.UtilityType = u.UtilityType ORDER BY t.UtilityTypeId) y
    WHERE u.RunFrom IS NOT NULL
      AND th.Threshold IS NOT NULL AND th.Threshold >= 1
      AND DATEDIFF(day, u.RunFrom, u.RunTo) >= th.Threshold
      AND u.RunTo >= @DateFrom
    ORDER BY CASE WHEN (u.Kind = 'No reading' AND u.RunTo = l.LatestRow)
                    OR (u.Kind = 'Meter did not move' AND u.RunTo = l.LatestReading) THEN 0 ELSE 1 END,
             DATEDIFF(day, u.RunFrom, u.RunTo) DESC,
             b.Name, u.UtilityType, u.MeterNo;
END
