/* ============================================================================
   agora.usp_Utilities_Save

   Record meter readings for one site: the closing reading, and any prepaid
   units bought, per meter per day.

   ** ONE OF THE PROCEDURES IN AGORA THAT WRITE TO THE CUSTOMER'S ESTATE.
   ** Approved by Ryan on 27 Sep 2026 (PM decisions 01a0e22a-97be /
   ** 01a0e22a-9aa9). It writes ONE PumpIT table and nothing else:
   **
   **   PumpIT.dbo.BRN_UtilityTransaction
   **     INSERT  SSBranchId, TransactionDate, UtilityType, MeterNo,
   **             OpeningReading, ClosingReading, PrepaidUnitsPurchased,
   **             [Usage], CreateDateTime          (the id is the table's IDENTITY)
   **     UPDATE  ClosingReading, PrepaidUnitsPurchased, OpeningReading, [Usage]
   **
   ** Every row it touches is recorded in agora.UtilityReadingWrite with the
   ** values that were there before, inside the same transaction.
   ** Read this header before changing a line of it.

   WHAT THE LEGACY DID, AND WHAT THIS DOES INSTEAD
   The customer has no save procedure: their client writes the table itself,
   and the identity (1,353,904 against 86,732 rows) says it does so by
   deleting and re-inserting the whole month on every save — writing the days
   that have not happened yet as 0 / 0 while it is at it. Here:

     · a day with no row gets one (INSERT, action `insert`);
     · a day the legacy wrote ahead as 0 / 0 is filled in place (`fill`);
     · a day that already carries a reading is changed only by somebody who
       may amend one, with a reason (`amend`) — a reading is a record of
       something that was seen, and changing it is a correction;
     · a line whose values match what is there is not written at all, so a
       Save that re-sends a whole day rewrites nothing;
     · nothing is ever deleted.

   A blank box on the screen is not a line: the application sends only what
   somebody typed. And a closing of 0 with no prepaid units is refused, not
   written: 0 / 0 is how PumpIT holds a day nobody read, and it is NOT a
   reading (Ryan, PM decision 01a0e312-07a0) — so it cannot be recorded as
   one, and writing it over a real reading would quietly un-read the day.

   TWO CLOCKS, deliberately. Agora's own record (UtilityReadingSave,
   UtilityReadingWrite) is stamped SYSUTCDATETIME(): the application writes
   UTC, and the recon procedures moved to UTC on 24 Sep 2026 (v1__13py).
   PumpIT's CreateDateTime keeps GETDATE() — the customer's table has always
   held the server's local time and its own screens read it that way — and so
   does "today", which is a trading day in South Africa, not in UTC.

   THE STORED Opening AND Usage
   The customer's screens read the stored columns, so they are written — with
   the arithmetic agora.vw_UtilityReading uses (the previous reading's closing,
   whenever it was taken; Usage = Closing - Opening - Prepaid, the customer's
   own formula) and, for a meter's first ever reading, the legacy's own
   convention of an opening of 0. Changing one reading also moves the NEXT
   reading's opening and usage, so that row is brought into line as well
   (`recompute`) — the legacy does the same thing to a whole month every time
   anybody opens its screen; this does it to exactly the rows that changed.

   TWO ROWS FOR ONE DAY
   The customer's key leads with the identity and does not prevent it; live
   holds four such pairs. The NEWEST row is the reading — it is the one
   agora.vw_UtilityReading shows — and it is the one written. The older twin
   is left exactly as it is.

   @WriteMode  must be 'live'. The application passes the literal from
               Modules/Utilities/Config/config.php and does not call this at
               all when it is 'off'; the refusal here is the second lock.
   @AllowAmend 1 when the caller holds utilities.readings.edit. Without it a
               line that would change an existing reading is refused — the
               route and the button check the same permission, and this is the
               one of the three that cannot be bypassed.
   @LinesJson  [{"UtilityType":"B","MeterNo":"Electricity Main",
                 "TransactionDate":"2026-09-26","ClosingReading":"9882.5",
                 "PrepaidUnitsPurchased":"0"}, ...]

   Returns the writer status row (Ok, Code, Message, Id = the save's id).

   Refusals: WRITES_OFF · BRANCH_REQUIRED · UNKNOWN_BRANCH · BAD_LINES ·
             NO_LINES · TOO_MANY_LINES · BAD_LINE · CLOSING_REQUIRED ·
             NEGATIVE_READING · NOT_A_READING · FUTURE_DATE · DUPLICATE_LINE ·
             UNKNOWN_METER ·
             AMEND_NOT_ALLOWED · REASON_REQUIRED
   ============================================================================ */

CREATE OR ALTER PROCEDURE [agora].[usp_Utilities_Save]
    @BranchId    int,
    @LinesJson   nvarchar(max),
    @WriteMode   varchar(10)   = 'off',
    @AllowAmend  bit           = 0,
    @Reason      nvarchar(300) = NULL,
    @UserId      int           = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    SET @Reason = NULLIF(LTRIM(RTRIM(ISNULL(@Reason, ''))), '');

    IF ISNULL(@WriteMode, 'off') <> 'live'
        THROW 51000, 'AGORA:WRITES_OFF:Recording meter readings is switched off. Nothing was written.', 1;
    IF @BranchId IS NULL
        THROW 51000, 'AGORA:BRANCH_REQUIRED:Meter readings belong to one site.', 1;
    IF NOT EXISTS (SELECT 1 FROM agora.Branch WHERE BranchId = @BranchId)
        THROW 51000, 'AGORA:UNKNOWN_BRANCH:That branch is not in Agora''s branch list.', 1;
    IF ISJSON(@LinesJson) IS NULL OR ISJSON(@LinesJson) = 0
        THROW 51000, 'AGORA:BAD_LINES:The readings did not arrive as a list.', 1;

    DECLARE @Today date = CONVERT(date, GETDATE());

    /* ---- 1. What arrived --------------------------------------------------- */
    DECLARE @Line TABLE (
        Seq                int IDENTITY(1,1) PRIMARY KEY,
        UtilityType           nvarchar(1)   NULL,
        MeterNo               nvarchar(50)  NULL,
        ReadingDate           date          NULL,
        ClosingRaw            nvarchar(60)  NULL,
        ClosingReading        decimal(18,4) NULL,
        PrepaidUnitsPurchased decimal(18,4) NOT NULL DEFAULT 0,
        PrepaidRaw            nvarchar(60)  NULL,
        UtilityTransactionId  int           NULL,
        PriorOpening          decimal(18,4) NULL,
        PriorClosing          decimal(18,4) NULL,
        PriorPrepaid          decimal(18,4) NULL,
        PriorUsage            decimal(18,4) NULL,
        [Action]              varchar(10)   NULL
    );

    INSERT INTO @Line (UtilityType, MeterNo, ReadingDate, ClosingRaw, ClosingReading, PrepaidRaw, PrepaidUnitsPurchased)
    SELECT NULLIF(LTRIM(RTRIM(JSON_VALUE(j.value, '$.UtilityType'))), ''),
           NULLIF(LTRIM(RTRIM(JSON_VALUE(j.value, '$.MeterNo'))), ''),
           TRY_CONVERT(date, JSON_VALUE(j.value, '$.TransactionDate')),
           NULLIF(LTRIM(RTRIM(JSON_VALUE(j.value, '$.ClosingReading'))), ''),
           TRY_CONVERT(decimal(18,4), JSON_VALUE(j.value, '$.ClosingReading')),
           NULLIF(LTRIM(RTRIM(JSON_VALUE(j.value, '$.PrepaidUnitsPurchased'))), ''),
           ISNULL(TRY_CONVERT(decimal(18,4), JSON_VALUE(j.value, '$.PrepaidUnitsPurchased')), 0)
    FROM OPENJSON(@LinesJson) j;

    DECLARE @Lines int = (SELECT COUNT(*) FROM @Line);
    DECLARE @Bad nvarchar(400);

    IF @Lines = 0
        THROW 51000, 'AGORA:NO_LINES:No readings were entered. Type a closing reading against at least one meter.', 1;
    /* A month of every meter at the busiest site is under 400. */
    IF @Lines > 1000
        THROW 51000, 'AGORA:TOO_MANY_LINES:That is more than 1,000 readings in one save. Save a month at a time.', 1;

    IF EXISTS (SELECT 1 FROM @Line WHERE UtilityType IS NULL OR MeterNo IS NULL OR ReadingDate IS NULL)
        THROW 51000, 'AGORA:BAD_LINE:Every reading needs its meter type, its meter and its date.', 1;

    SET @Bad = (SELECT TOP 1 MeterNo + ' on ' + CONVERT(nvarchar(10), ReadingDate, 120) FROM @Line
                WHERE ClosingReading IS NULL ORDER BY Seq);
    IF @Bad IS NOT NULL
    BEGIN
        SET @Bad = 'AGORA:CLOSING_REQUIRED:' + @Bad + ' has no closing reading, or it is not a number. '
                 + 'Prepaid units are recorded against the reading they were bought on.';
        THROW 51000, @Bad, 1;
    END

    /* Prepaid that is not a number is refused rather than read as zero: a
       purchase silently dropped is a usage silently inflated. */
    SET @Bad = (SELECT TOP 1 MeterNo + ' on ' + CONVERT(nvarchar(10), ReadingDate, 120) FROM @Line
                WHERE PrepaidRaw IS NOT NULL AND TRY_CONVERT(decimal(18,4), PrepaidRaw) IS NULL ORDER BY Seq);
    IF @Bad IS NOT NULL
    BEGIN
        SET @Bad = 'AGORA:BAD_LINE:The prepaid units for ' + @Bad + ' are not a number.';
        THROW 51000, @Bad, 1;
    END

    /* A register does not run below zero. Prepaid units MAY be negative: one
       site records its monthly meter reset as minus the opening, and refusing
       it would stop that site capturing at all. */
    SET @Bad = (SELECT TOP 1 MeterNo + ' on ' + CONVERT(nvarchar(10), ReadingDate, 120) FROM @Line
                WHERE ClosingReading < 0 ORDER BY Seq);
    IF @Bad IS NOT NULL
    BEGIN
        SET @Bad = 'AGORA:NEGATIVE_READING:The closing reading for ' + @Bad + ' is below zero. A meter register cannot be.';
        THROW 51000, @Bad, 1;
    END

    /* 0 / 0 is PumpIT's mark for a day nobody read — not a reading. */
    SET @Bad = (SELECT TOP 1 MeterNo + ' on ' + CONVERT(nvarchar(10), ReadingDate, 120) FROM @Line
                WHERE ClosingReading = 0 AND PrepaidUnitsPurchased = 0 ORDER BY Seq);
    IF @Bad IS NOT NULL
    BEGIN
        SET @Bad = 'AGORA:NOT_A_READING:' + @Bad + ' reads 0 with no prepaid units, which is how PumpIT marks a day '
                 + 'nobody read. Leave the box empty if the meter was not read.';
        THROW 51000, @Bad, 1;
    END

    /* The legacy writes the rest of the month ahead of time. A reading for a
       day that has not happened was not read off a meter. */
    SET @Bad = (SELECT TOP 1 MeterNo + ' on ' + CONVERT(nvarchar(10), ReadingDate, 120) FROM @Line
                WHERE ReadingDate > @Today ORDER BY Seq);
    IF @Bad IS NOT NULL
    BEGIN
        SET @Bad = 'AGORA:FUTURE_DATE:' + @Bad + ' is in the future. A reading is recorded on or after the day it was taken.';
        THROW 51000, @Bad, 1;
    END

    SET @Bad = (SELECT TOP 1 MeterNo + ' on ' + CONVERT(nvarchar(10), ReadingDate, 120) FROM @Line
                GROUP BY UtilityType, MeterNo, ReadingDate HAVING COUNT(*) > 1);
    IF @Bad IS NOT NULL
    BEGIN
        SET @Bad = 'AGORA:DUPLICATE_LINE:' + @Bad + ' was sent twice. One reading per meter per day.';
        THROW 51000, @Bad, 1;
    END

    /* The meter must be one this site has. A reading names its meter by
       NAME, so a typo here would start a new series nobody reads. */
    SET @Bad = (SELECT TOP 1 l.MeterNo + ' (' + l.UtilityType + ')' FROM @Line l
                WHERE NOT EXISTS (SELECT 1 FROM agora.vw_UtilityMeter m
                                  WHERE m.BranchId = @BranchId AND m.UtilityType = l.UtilityType
                                    AND m.MeterNo = l.MeterNo)
                ORDER BY l.Seq);
    IF @Bad IS NOT NULL
    BEGIN
        SET @Bad = 'AGORA:UNKNOWN_METER:' + @Bad + ' is not one of this site''s meters in PumpIT.';
        THROW 51000, @Bad, 1;
    END

    DECLARE @Written TABLE (
        UtilityTransactionId int PRIMARY KEY,
        UtilityType nvarchar(1), MeterNo nvarchar(50), ReadingDate date,
        [Action] varchar(10),
        PriorOpening decimal(18,4), PriorClosing decimal(18,4), PriorPrepaid decimal(18,4), PriorUsage decimal(18,4)
    );

    DECLARE @SaveId bigint, @Captured int = 0, @Filled int = 0, @Amended int = 0,
            @Unchanged int = 0, @Recomputed int = 0;

    BEGIN TRY
    BEGIN TRANSACTION;

    /* ---- 2. What is there now, locked --------------------------------------
       The newest row for each meter and day, under UPDLOCK + HOLDLOCK so a
       second Save of the same day waits for this one rather than both reading
       "nothing there" and both inserting. HOLDLOCK is what covers the rows
       that do not exist yet. */
    UPDATE l
       SET l.UtilityTransactionId = x.UtilityTransactionId,
           l.PriorOpening = CONVERT(decimal(18,4), x.OpeningReading),
           l.PriorClosing = CONVERT(decimal(18,4), x.ClosingReading),
           l.PriorPrepaid = CONVERT(decimal(18,4), x.PrepaidUnitsPurchased),
           l.PriorUsage   = CONVERT(decimal(18,4), x.[Usage])
    FROM @Line l
    CROSS APPLY (
        SELECT TOP 1 t.UtilityTransactionId, t.OpeningReading, t.ClosingReading,
                     t.PrepaidUnitsPurchased, t.[Usage]
        FROM [PumpIT].dbo.BRN_UtilityTransaction t WITH (UPDLOCK, HOLDLOCK)
        WHERE t.SSBranchId = @BranchId
          AND t.UtilityType = l.UtilityType
          AND t.MeterNo = l.MeterNo
          AND t.TransactionDate >= CONVERT(datetime, l.ReadingDate)
          AND t.TransactionDate <  DATEADD(day, 1, CONVERT(datetime, l.ReadingDate))
        ORDER BY t.UtilityTransactionId DESC
    ) x;

    /* ---- 3. What each line is ----------------------------------------------
       Compared to four places: 290.74 arrives as 290.7400 and is stored as
       290.74000000000001, and those are the same reading. */
    UPDATE @Line
       SET [Action] = CASE
            WHEN UtilityTransactionId IS NULL THEN 'insert'
            WHEN ABS(ISNULL(PriorClosing, 0) - ClosingReading) < 0.00005
             AND ABS(ISNULL(PriorPrepaid, 0) - PrepaidUnitsPurchased) < 0.00005 THEN 'unchanged'
            WHEN ISNULL(PriorClosing, 0) = 0 AND ISNULL(PriorPrepaid, 0) = 0 THEN 'fill'
            ELSE 'amend'
       END;

    IF EXISTS (SELECT 1 FROM @Line WHERE [Action] = 'amend') AND ISNULL(@AllowAmend, 0) = 0
    BEGIN
        SET @Bad = (SELECT TOP 1 MeterNo + ' on ' + CONVERT(nvarchar(10), ReadingDate, 120)
                           + ' already reads ' + CONVERT(nvarchar(40), CONVERT(decimal(18,2), PriorClosing))
                    FROM @Line WHERE [Action] = 'amend' ORDER BY Seq);
        SET @Bad = 'AGORA:AMEND_NOT_ALLOWED:' + @Bad
                 + '. Changing a reading somebody has already captured needs the utilities.readings.edit permission.';
        THROW 51000, @Bad, 1;
    END

    IF EXISTS (SELECT 1 FROM @Line WHERE [Action] = 'amend') AND @Reason IS NULL
        THROW 51000, 'AGORA:REASON_REQUIRED:Say why a reading that was already captured is being changed. It is kept with the change.', 1;

    SELECT @Captured  = SUM(CASE WHEN [Action] = 'insert'    THEN 1 ELSE 0 END),
           @Filled    = SUM(CASE WHEN [Action] = 'fill'      THEN 1 ELSE 0 END),
           @Amended   = SUM(CASE WHEN [Action] = 'amend'     THEN 1 ELSE 0 END),
           @Unchanged = SUM(CASE WHEN [Action] = 'unchanged' THEN 1 ELSE 0 END)
    FROM @Line;

    IF @Captured + @Filled + @Amended > 0
    BEGIN
        INSERT INTO agora.UtilityReadingSave
            (BranchId, DateFrom, DateTo, LinesSent, Captured, Filled, Amended, Unchanged, Recomputed,
             WriteMode, Reason, CreatedAt, CreatedBy)
        SELECT @BranchId, MIN(ReadingDate), MAX(ReadingDate), @Lines, @Captured, @Filled, @Amended, @Unchanged, 0,
               @WriteMode, @Reason, SYSUTCDATETIME(), @UserId
        FROM @Line;

        SET @SaveId = SCOPE_IDENTITY();

        /* ---- 4. The writes ------------------------------------------------
           Opening and Usage are written as 0 / placeholder here and set
           properly in step 5, once every line of this save is in place — a
           month saved at once has each day's opening in the day before it. */
        INSERT INTO [PumpIT].dbo.BRN_UtilityTransaction
            (SSBranchId, TransactionDate, UtilityType, MeterNo,
             OpeningReading, ClosingReading, PrepaidUnitsPurchased, [Usage], CreateDateTime)
        OUTPUT inserted.UtilityTransactionId, inserted.UtilityType, inserted.MeterNo,
               CONVERT(date, inserted.TransactionDate), 'insert', NULL, NULL, NULL, NULL
        INTO @Written (UtilityTransactionId, UtilityType, MeterNo, ReadingDate, [Action],
                       PriorOpening, PriorClosing, PriorPrepaid, PriorUsage)
        SELECT @BranchId, CONVERT(datetime, l.ReadingDate), l.UtilityType, l.MeterNo,
               0, l.ClosingReading, l.PrepaidUnitsPurchased, 0, GETDATE()
        FROM @Line l
        WHERE l.[Action] = 'insert';

        UPDATE t
           SET t.ClosingReading        = l.ClosingReading,
               t.PrepaidUnitsPurchased = l.PrepaidUnitsPurchased
        FROM [PumpIT].dbo.BRN_UtilityTransaction t
        JOIN @Line l ON l.UtilityTransactionId = t.UtilityTransactionId
        WHERE t.SSBranchId = @BranchId
          AND l.[Action] IN ('fill', 'amend');

        INSERT INTO @Written (UtilityTransactionId, UtilityType, MeterNo, ReadingDate, [Action],
                              PriorOpening, PriorClosing, PriorPrepaid, PriorUsage)
        SELECT l.UtilityTransactionId, l.UtilityType, l.MeterNo, l.ReadingDate, l.[Action],
               l.PriorOpening, l.PriorClosing, l.PriorPrepaid, l.PriorUsage
        FROM @Line l
        WHERE l.[Action] IN ('fill', 'amend');

        /* ---- 5. Opening and Usage, where they moved ------------------------
           The rows just written, and for each meter the first real reading
           AFTER each of them — its opening is the closing that may just have
           changed. Read back through agora.vw_UtilityReading, inside this
           transaction, so the arithmetic is the view's and sees this save. */
        DECLARE @Affected TABLE (UtilityTransactionId int PRIMARY KEY);

        INSERT INTO @Affected (UtilityTransactionId)
        SELECT w.UtilityTransactionId FROM @Written w;

        INSERT INTO @Affected (UtilityTransactionId)
        SELECT DISTINCT nx.UtilityTransactionId
        FROM @Written w
        CROSS APPLY (
            SELECT TOP 1 v.UtilityTransactionId
            FROM agora.vw_UtilityReading v
            WHERE v.BranchId = @BranchId AND v.UtilityType = w.UtilityType AND v.MeterNo = w.MeterNo
              AND v.ReadingDate > w.ReadingDate
              /* The next READING. A 0 / 0 day is not one: it has no usage
                 and nothing is measured from it, so it is left alone. */
              AND v.IsBlank = 0
            ORDER BY v.ReadingDate
        ) nx
        WHERE NOT EXISTS (SELECT 1 FROM @Affected a WHERE a.UtilityTransactionId = nx.UtilityTransactionId);

        DECLARE @Recompute TABLE (
            UtilityTransactionId int PRIMARY KEY,
            UtilityType nvarchar(1), MeterNo nvarchar(50), ReadingDate date,
            NewOpening decimal(18,4), NewUsage decimal(18,4),
            PriorOpening decimal(18,4), PriorUsage decimal(18,4)
        );

        INSERT INTO @Recompute (UtilityTransactionId, UtilityType, MeterNo, ReadingDate,
                                NewOpening, NewUsage, PriorOpening, PriorUsage)
        SELECT v.UtilityTransactionId, v.UtilityType, v.MeterNo, v.ReadingDate,
               /* The legacy's own convention for a meter's first reading:
                  an opening of 0. The view says NULL, which is the truth; the
                  stored column keeps the customer's habit. */
               ISNULL(v.OpeningReading, 0),
               v.ClosingReading - ISNULL(v.OpeningReading, 0) - v.PrepaidUnitsPurchased,
               CONVERT(decimal(18,4), t.OpeningReading),
               CONVERT(decimal(18,4), t.[Usage])
        FROM @Affected a
        JOIN agora.vw_UtilityReading v
          ON v.BranchId = @BranchId AND v.UtilityTransactionId = a.UtilityTransactionId
        JOIN [PumpIT].dbo.BRN_UtilityTransaction t
          ON t.UtilityTransactionId = v.UtilityTransactionId AND t.SSBranchId = @BranchId;

        UPDATE t
           SET t.OpeningReading = r.NewOpening,
               t.[Usage]        = r.NewUsage
        FROM [PumpIT].dbo.BRN_UtilityTransaction t
        JOIN @Recompute r ON r.UtilityTransactionId = t.UtilityTransactionId
        WHERE t.SSBranchId = @BranchId
          AND (   ABS(ISNULL(r.PriorOpening, 0) - r.NewOpening) >= 0.00005 OR r.PriorOpening IS NULL
               OR ABS(ISNULL(r.PriorUsage, 0)   - r.NewUsage)   >= 0.00005 OR r.PriorUsage IS NULL
               OR EXISTS (SELECT 1 FROM @Written w WHERE w.UtilityTransactionId = r.UtilityTransactionId));

        SET @Recomputed = (
            SELECT COUNT(*) FROM @Recompute r
            WHERE NOT EXISTS (SELECT 1 FROM @Written w WHERE w.UtilityTransactionId = r.UtilityTransactionId)
              AND (   ABS(ISNULL(r.PriorOpening, 0) - r.NewOpening) >= 0.00005 OR r.PriorOpening IS NULL
                   OR ABS(ISNULL(r.PriorUsage, 0)   - r.NewUsage)   >= 0.00005 OR r.PriorUsage IS NULL));

        /* ---- 6. The record --------------------------------------------------
           Every PumpIT row this save touched, with what was there before. */
        INSERT INTO agora.UtilityReadingWrite
            (BranchId, SaveId, UtilityTransactionId, UtilityType, MeterNo, ReadingDate, [Action],
             PriorOpening, PriorClosing, PriorPrepaid, PriorUsage,
             NewOpening, NewClosing, NewPrepaid, NewUsage, CreatedAt, CreatedBy)
        SELECT @BranchId, @SaveId, w.UtilityTransactionId, w.UtilityType, w.MeterNo, w.ReadingDate, w.[Action],
               w.PriorOpening, w.PriorClosing, w.PriorPrepaid, w.PriorUsage,
               r.NewOpening, l.ClosingReading, l.PrepaidUnitsPurchased, r.NewUsage,
               SYSUTCDATETIME(), @UserId
        FROM @Written w
        JOIN @Line l ON l.UtilityType = w.UtilityType AND l.MeterNo = w.MeterNo AND l.ReadingDate = w.ReadingDate
        LEFT JOIN @Recompute r ON r.UtilityTransactionId = w.UtilityTransactionId;

        INSERT INTO agora.UtilityReadingWrite
            (BranchId, SaveId, UtilityTransactionId, UtilityType, MeterNo, ReadingDate, [Action],
             PriorOpening, PriorClosing, PriorPrepaid, PriorUsage,
             NewOpening, NewClosing, NewPrepaid, NewUsage, CreatedAt, CreatedBy)
        SELECT @BranchId, @SaveId, r.UtilityTransactionId, r.UtilityType, r.MeterNo, r.ReadingDate, 'recompute',
               r.PriorOpening, CONVERT(decimal(18,4), t.ClosingReading), CONVERT(decimal(18,4), t.PrepaidUnitsPurchased), r.PriorUsage,
               r.NewOpening, CONVERT(decimal(18,4), t.ClosingReading), CONVERT(decimal(18,4), t.PrepaidUnitsPurchased), r.NewUsage,
               SYSUTCDATETIME(), @UserId
        FROM @Recompute r
        JOIN [PumpIT].dbo.BRN_UtilityTransaction t
          ON t.UtilityTransactionId = r.UtilityTransactionId AND t.SSBranchId = @BranchId
        WHERE NOT EXISTS (SELECT 1 FROM @Written w WHERE w.UtilityTransactionId = r.UtilityTransactionId)
          AND (   ABS(ISNULL(r.PriorOpening, 0) - r.NewOpening) >= 0.00005 OR r.PriorOpening IS NULL
               OR ABS(ISNULL(r.PriorUsage, 0)   - r.NewUsage)   >= 0.00005 OR r.PriorUsage IS NULL);

        UPDATE agora.UtilityReadingSave
           SET Recomputed = @Recomputed
         WHERE BranchId = @BranchId AND Id = @SaveId;
    END

    COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;

        /* Re-raised with the original text, so a refusal keeps its
           AGORA:{Code} shape and a fault stays a fault. Nothing has been
           written by the time this returns. */
        DECLARE @Err nvarchar(2048) = ERROR_MESSAGE();
        THROW 51000, @Err, 1;
    END CATCH

    SELECT CONVERT(bit, 1) AS Ok,
           CASE WHEN @SaveId IS NULL THEN 'NOTHING_CHANGED' ELSE 'SAVED' END AS Code,
           CASE WHEN @SaveId IS NULL
                THEN 'Nothing changed — every reading sent already matched what is recorded.'
                ELSE CONCAT(
                    NULLIF(CONCAT(@Captured + @Filled, ' reading', CASE WHEN @Captured + @Filled = 1 THEN '' ELSE 's' END, ' recorded'), '0 readings recorded'),
                    CASE WHEN @Amended > 0 THEN CONCAT(CASE WHEN @Captured + @Filled > 0 THEN ', ' ELSE '' END,
                                                       @Amended, ' changed') ELSE '' END,
                    CASE WHEN @Unchanged > 0 THEN CONCAT(', ', @Unchanged, ' unchanged') ELSE '' END,
                    CASE WHEN @Recomputed > 0 THEN CONCAT('; the following reading''s usage was brought into line on ',
                                                          @Recomputed, ' meter', CASE WHEN @Recomputed = 1 THEN '' ELSE 's' END) ELSE '' END,
                    '.')
           END AS Message,
           @SaveId AS Id;
END
