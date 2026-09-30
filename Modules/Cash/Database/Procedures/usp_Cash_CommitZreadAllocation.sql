/* ============================================================================
   agora.usp_Cash_CommitZreadAllocation

   WHAT IT IS FOR
   "Update ZREAD": save what is staged. Every staged Z-read in the scope — one
   site's period, optionally one location, optionally one Z-read (the phone's
   Confirm) — becomes that Z-read's allocation.

   WHAT IT WRITES
     agora.ZreadAllocation                 always
     [PumpIT].dbo.DBF_P3TRANS_ZREAD        @StampMode = 'live' only:
                                           TillNo, ShiftNo, EmployeeCode — and
                                           no other column, on no other table

   THE STAMP (Ryan, 27 Sep 2026, PM decision 01a0e312-06da). The legacy cashup
   and stock screens read TillNo / ShiftNo / EmployeeCode off the Z-read row,
   so through parallel running Agora's allocation is written back to it. It is
   deliberately NARROWER than the legacy screen's dbo.sp_UpdateDBF_P3TRANS_ZREAD:
   no stamp on DBF_P3TRANS_STKRECON, no delete from DBF_COMPUTER_QTY.
   @StampMode comes from the literal config('cash.zread.stamp_mode'); the
   default here is 'journal', so a caller that forgets it writes Agora only.

   ONE ROW OR NONE. Each staged Z-read is resolved against the PumpIT row by its
   full key — SSBranchId, DATE, EOD_CNTR, TERMNUM, LOGFILE, USERID, LOCATION —
   under UPDLOCK, HOLDLOCK in live mode. One that resolves to no row (reloaded
   under another log file) or to two or more (a key that no longer names one
   row) is NOT saved and NOT written: it is set to 'cleared' with a note saying
   which, and counted as skipped. Nothing ever updates 0 or 2+ rows.

   THE AUDIT, in the same transaction, on the rows themselves:
     · the staged row becomes Status 'allocated', with AllocatedAt/By and the
       press's CommitRef;
     · Prior* — what Agora showed the Z-read carrying before: 'agora' with
       SupersedesId when an earlier Agora allocation is replaced, 'legacy' when
       the PumpIT row had one, NULL when nobody had allocated it;
     · StampMode, StampedAt and StampPrior* — the three PumpIT columns EXACTLY
       as the row held them the instant before the stamp (0 stays 0, NULL stays
       NULL). agora.usp_Cash_ReverseZreadCommit restores these;
     · a replaced allocation becomes Status 'superseded' with SupersededAt/By.
       Nothing is deleted — an allocation is a transactional entry.
   When agora.AuditLog / usp_Audit_Write exist (T010) the same transaction
   should write one row per allocation there as well.

   Every timestamp is SYSUTCDATETIME(): the application writes UTC, and the
   recon procedures were moved to UTC on 24 Sep 2026 for the same reason
   (v1__13py).

   NOT DONE HERE, deliberately: "clears the console step" (T036 acceptance).
   There is no day-close spine yet (T029). When usp_DayEnd_GetCloseStatus
   exists it computes "Z-reads allocated" from agora.vw_CashZread.

   Refusals:
     AGORA:CASH_ZREAD_USER        no user
     AGORA:CASH_ZREAD_RANGE       no period
     AGORA:CASH_ZREAD_STAMP_MODE  a stamp mode that is neither journal nor live
     AGORA:CASH_NOTHING_STAGED    returned as Ok = 0: nothing staged in scope
   ============================================================================ */
CREATE OR ALTER PROCEDURE [agora].[usp_Cash_CommitZreadAllocation]
    @BranchId    INT,
    @DateFrom    DATE,
    @DateTo      DATE,
    @Location    NVARCHAR(10) = NULL,
    @EodCounter  NVARCHAR(8)  = NULL,
    @TerminalNo  NVARCHAR(3)  = NULL,
    @LogFile     NVARCHAR(10) = NULL,
    @PosUserId   NVARCHAR(6)  = NULL,
    @StampMode   NVARCHAR(10) = 'journal',
    @UserId      INT
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    IF @UserId IS NULL
        THROW 51000, 'AGORA:CASH_ZREAD_USER:An allocation is saved by a person, and none was given.', 1;

    IF @DateFrom IS NULL OR @DateTo IS NULL OR @DateTo < @DateFrom
        THROW 51000, 'AGORA:CASH_ZREAD_RANGE:Give the period to save, from before to.', 1;

    IF @StampMode IS NULL OR @StampMode NOT IN ('journal', 'live')
        THROW 51000, 'AGORA:CASH_ZREAD_STAMP_MODE:The stamp mode must be journal or live.', 1;

    SET @Location = NULLIF(LTRIM(RTRIM(@Location)), '');
    DECLARE @One BIT = CASE WHEN @LogFile IS NOT NULL THEN 1 ELSE 0 END;
    DECLARE @Now DATETIME2(0) = SYSUTCDATETIME();
    DECLARE @Ref UNIQUEIDENTIFIER = NEWID();
    DECLARE @Saved INT = 0, @Missing INT = 0, @Ambiguous INT = 0, @Stamped INT = 0;

    BEGIN TRY
        BEGIN TRANSACTION;

        /* What is staged, locked for the length of the save, with the Agora
           allocation each Z-read carries now. */
        SELECT s.Id, s.BranchId, s.ZReadDate, s.Location, s.EodCounter, s.TerminalNo, s.LogFile, s.PosUserId,
               s.TillNo, s.ShiftNo, s.EmployeeCode,
               cur.Id AS CurrentId, cur.TillNo AS CurTill, cur.ShiftNo AS CurShift, cur.EmployeeCode AS CurEmp,
               CAST(NULL AS INT) AS Hits,
               CAST(NULL AS INT) AS RawTill, CAST(NULL AS INT) AS RawShift, CAST(NULL AS NVARCHAR(22)) AS RawEmp
        INTO #cz_c
        FROM agora.ZreadAllocation s WITH (UPDLOCK, HOLDLOCK)
        OUTER APPLY (
            SELECT TOP 1 a.Id, a.TillNo, a.ShiftNo, a.EmployeeCode
            FROM agora.ZreadAllocation a WITH (UPDLOCK, HOLDLOCK)
            WHERE a.BranchId = s.BranchId AND a.ZReadDate = s.ZReadDate AND a.Location = s.Location
              AND a.TerminalNo = s.TerminalNo AND a.EodCounter = s.EodCounter AND a.LogFile = s.LogFile
              AND a.PosUserId = s.PosUserId AND a.[Status] = 'allocated'
            ORDER BY a.Id DESC
        ) cur
        WHERE s.BranchId = @BranchId
          AND s.[Status] = 'proposed'
          AND s.ZReadDate BETWEEN @DateFrom AND @DateTo
          AND (@Location IS NULL OR s.Location = @Location)
          AND (@One = 0 OR (s.EodCounter = LTRIM(RTRIM(@EodCounter)) AND s.TerminalNo = LTRIM(RTRIM(@TerminalNo))
                            AND s.LogFile = LTRIM(RTRIM(@LogFile)) AND s.PosUserId = LTRIM(RTRIM(@PosUserId))));

        IF NOT EXISTS (SELECT 1 FROM #cz_c)
        BEGIN
            COMMIT TRANSACTION;

            SELECT CAST(0 AS BIT) AS Ok, 'CASH_NOTHING_STAGED' AS Code,
                   CONVERT(NVARCHAR(400), 'Nothing is staged here. Press Auto-allocate, or accept or set a Z-read from its row first.') AS Message,
                   CAST(NULL AS BIGINT) AS Id, 0 AS Saved, 0 AS Skipped, 0 AS Stamped,
                   @StampMode AS StampMode, CAST(NULL AS UNIQUEIDENTIFIER) AS CommitRef;
            RETURN;
        END

        /* Resolve each Z-read to the PumpIT row its full key names — how many
           rows, and what the one row holds now. In live mode the rows are
           locked for update until the transaction ends, so what is recorded as
           the prior is what gets overwritten. */
        IF @StampMode = 'live'
            UPDATE c
               SET Hits = r.Hits, RawTill = r.RawTill, RawShift = r.RawShift, RawEmp = r.RawEmp
            FROM #cz_c c
            CROSS APPLY (
                SELECT COUNT(*) AS Hits, MAX(z.TillNo) AS RawTill, MAX(z.ShiftNo) AS RawShift,
                       MAX(z.EmployeeCode) AS RawEmp
                FROM [PumpIT].dbo.DBF_P3TRANS_ZREAD z WITH (UPDLOCK, HOLDLOCK)
                WHERE z.SSBranchId = c.BranchId
                  AND z.[DATE] >= c.ZReadDate AND z.[DATE] < DATEADD(day, 1, c.ZReadDate)
                  AND LTRIM(RTRIM(z.EOD_CNTR)) = c.EodCounter
                  AND LTRIM(RTRIM(z.TERMNUM))  = c.TerminalNo
                  AND LTRIM(RTRIM(z.LOGFILE))  = c.LogFile
                  AND LTRIM(RTRIM(z.USERID))   = c.PosUserId
                  AND ISNULL(LTRIM(RTRIM(z.[LOCATION])), '') = c.Location
            ) r;
        ELSE
            UPDATE c
               SET Hits = r.Hits, RawTill = r.RawTill, RawShift = r.RawShift, RawEmp = r.RawEmp
            FROM #cz_c c
            CROSS APPLY (
                SELECT COUNT(*) AS Hits, MAX(z.LegacyTillNo) AS RawTill, MAX(z.LegacyShiftNo) AS RawShift,
                       MAX(z.LegacyEmployeeCode) AS RawEmp
                FROM agora.vw_CashZread z
                WHERE z.BranchId = c.BranchId
                  AND z.ZReadAt >= c.ZReadDate AND z.ZReadAt < DATEADD(day, 1, c.ZReadDate)
                  AND z.Location = c.Location AND z.TerminalNo = c.TerminalNo AND z.EodCounter = c.EodCounter
                  AND z.LogFile = c.LogFile AND z.PosUserId = c.PosUserId
            ) r;

        /* Not one row: not saved, not written, and said why. */
        UPDATE a
           SET [Status] = 'cleared',
               Note = CASE WHEN c.Hits = 0
                           THEN 'Not saved: the source Z-read is no longer in the site''s readings.'
                           ELSE 'Not saved: the Z-read''s key names ' + CONVERT(NVARCHAR(10), c.Hits)
                                + ' rows in PumpIT, so there is no telling which one to write.' END,
               UpdatedAt = @Now, UpdatedBy = @UserId
        FROM agora.ZreadAllocation a
        JOIN #cz_c c ON c.BranchId = a.BranchId AND c.Id = a.Id
        WHERE c.Hits <> 1;

        SELECT @Missing = COUNT(CASE WHEN Hits = 0 THEN 1 END), @Ambiguous = COUNT(CASE WHEN Hits > 1 THEN 1 END)
        FROM #cz_c;

        /* The allocation being replaced keeps its row. */
        UPDATE a
           SET [Status] = 'superseded',
               SupersededAt = @Now, SupersededBy = @UserId,
               UpdatedAt = @Now, UpdatedBy = @UserId
        FROM agora.ZreadAllocation a
        JOIN #cz_c c ON c.BranchId = a.BranchId AND c.CurrentId = a.Id
        WHERE c.Hits = 1;

        /* THE STAMP. Three columns, the one row each key named. */
        IF @StampMode = 'live'
        BEGIN
            UPDATE z
               SET z.TillNo       = c.TillNo,
                   z.ShiftNo      = c.ShiftNo,
                   z.EmployeeCode = c.EmployeeCode
            FROM [PumpIT].dbo.DBF_P3TRANS_ZREAD z
            JOIN #cz_c c
              ON z.SSBranchId = c.BranchId
             AND z.[DATE] >= c.ZReadDate AND z.[DATE] < DATEADD(day, 1, c.ZReadDate)
             AND LTRIM(RTRIM(z.EOD_CNTR)) = c.EodCounter
             AND LTRIM(RTRIM(z.TERMNUM))  = c.TerminalNo
             AND LTRIM(RTRIM(z.LOGFILE))  = c.LogFile
             AND LTRIM(RTRIM(z.USERID))   = c.PosUserId
             AND ISNULL(LTRIM(RTRIM(z.[LOCATION])), '') = c.Location
            WHERE c.Hits = 1;

            SET @Stamped = @@ROWCOUNT;

            /* Belt and braces on "never 0 or 2+ rows": every Z-read that
               resolved to one row wrote exactly one, or nothing is kept. */
            IF @Stamped <> (SELECT COUNT(*) FROM #cz_c WHERE Hits = 1)
                THROW 51000, 'AGORA:CASH_ZREAD_STAMP_COUNT:The PumpIT rows written did not match the Z-reads saved, so nothing was saved.', 1;
        END

        UPDATE a
           SET [Status] = 'allocated',
               AllocatedAt = @Now, AllocatedBy = @UserId, CommitRef = @Ref,
               PriorTillNo       = CASE WHEN c.CurrentId IS NOT NULL THEN c.CurTill  ELSE NULLIF(c.RawTill, 0) END,
               PriorShiftNo      = CASE WHEN c.CurrentId IS NOT NULL THEN c.CurShift ELSE NULLIF(c.RawShift, 0) END,
               PriorEmployeeCode = CASE WHEN c.CurrentId IS NOT NULL THEN c.CurEmp
                                        ELSE NULLIF(LTRIM(RTRIM(c.RawEmp)), '') END,
               PriorSource       = CASE WHEN c.CurrentId IS NOT NULL THEN 'agora'
                                        WHEN ISNULL(c.RawTill, 0) <> 0 OR ISNULL(c.RawShift, 0) <> 0
                                             OR ISNULL(LTRIM(RTRIM(c.RawEmp)), '') <> '' THEN 'legacy' END,
               SupersedesId      = c.CurrentId,
               StampMode         = @StampMode,
               StampedAt         = CASE WHEN @StampMode = 'live' THEN @Now END,
               StampPriorTillNo       = CASE WHEN @StampMode = 'live' THEN c.RawTill END,
               StampPriorShiftNo      = CASE WHEN @StampMode = 'live' THEN c.RawShift END,
               StampPriorEmployeeCode = CASE WHEN @StampMode = 'live' THEN c.RawEmp END,
               UpdatedAt = @Now, UpdatedBy = @UserId
        FROM agora.ZreadAllocation a
        JOIN #cz_c c ON c.BranchId = a.BranchId AND c.Id = a.Id
        WHERE c.Hits = 1;

        SET @Saved = @@ROWCOUNT;

        COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        THROW;
    END CATCH;

    SELECT
        CAST(1 AS BIT) AS Ok,
        CASE WHEN @Saved > 0 THEN 'CASH_ZREAD_SAVED' ELSE 'CASH_ZREAD_ALL_SKIPPED' END AS Code,
        CONVERT(NVARCHAR(400),
            'Updated ' + CONVERT(NVARCHAR(10), @Saved) + ' Z-read' + CASE WHEN @Saved = 1 THEN '' ELSE 's' END
            + CASE WHEN @Saved = 0 THEN '.'
                   WHEN @StampMode = 'live' THEN ': saved in Agora and written to PumpIT (till, shift, person).'
                   ELSE ': saved in Agora. The PumpIT stamp is off, so PumpIT was not written.' END
            + CASE WHEN @Missing > 0
                   THEN ' ' + CONVERT(NVARCHAR(10), @Missing) + ' not saved because the reading is no longer there.'
                   ELSE '' END
            + CASE WHEN @Ambiguous > 0
                   THEN ' ' + CONVERT(NVARCHAR(10), @Ambiguous) + ' not saved because the key names more than one PumpIT row.'
                   ELSE '' END) AS Message,
        CAST(NULL AS BIGINT) AS Id,
        @Saved AS Saved,
        @Missing + @Ambiguous AS Skipped,
        @Stamped AS Stamped,
        @StampMode AS StampMode,
        @Ref AS CommitRef;
END
