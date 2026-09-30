/* ============================================================================
   agora.usp_Cash_ReverseZreadCommit

   WHAT IT IS FOR
   Undo one Update ZREAD press — exactly, and nothing else. The legacy ZREAD
   Update screen has no reversal at all; an allocation typed over another is
   simply gone. Here every press carries a CommitRef, and every allocation it
   saved carries what was there before it.

   WHAT IT DOES, all or nothing, in one transaction:
     · each allocation the press saved becomes Status 'reversed', with
       ReversedAt/By and the reason;
     · where the press stamped PumpIT (the row's StampMode is 'live'), the
       three columns go back to StampPrior* — the values the row held the
       instant before the stamp, 0 as 0 and NULL as NULL;
     · the Agora allocation each one had superseded (SupersedesId) is back in
       force, so Agora and PumpIT both read as they did before the press.

   WHAT IT WRITES
     agora.ZreadAllocation              always
     [PumpIT].dbo.DBF_P3TRANS_ZREAD     TillNo, ShiftNo, EmployeeCode — only on
                                        rows the press itself stamped

   It is deliberately NOT gated by config('cash.zread.stamp_mode') today: a
   press stamped under 'live' must stay reversible after the switch is set back
   to 'journal', and the row's own StampMode says what that press did.

   IT REFUSES, rather than doing half:
     · a press that a LATER press has since superseded in any part — reverse
       that one first, or two priors would claim the same Z-read;
     · a stamped Z-read whose PumpIT row no longer resolves to exactly one row,
       or no longer carries what the press wrote — somebody has allocated it
       again on the legacy screen, and writing the old values over theirs would
       lose a decision nobody here can see.

   Every timestamp is SYSUTCDATETIME().

   Refusals:
     AGORA:CASH_ZREAD_USER           no user
     AGORA:CASH_REVERSE_REASON       no reason — it is the only record of why
     AGORA:CASH_COMMIT_NOT_FOUND     no press with that CommitRef at this site
     AGORA:CASH_COMMIT_REVERSED      already reversed
     AGORA:CASH_COMMIT_SUPERSEDED    a later press replaced part of it
     AGORA:CASH_COMMIT_MOVED         PumpIT no longer carries what it wrote
   ============================================================================ */
CREATE OR ALTER PROCEDURE [agora].[usp_Cash_ReverseZreadCommit]
    @BranchId   INT,
    @CommitRef  UNIQUEIDENTIFIER,
    @Reason     NVARCHAR(300),
    @UserId     INT
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    IF @UserId IS NULL
        THROW 51000, 'AGORA:CASH_ZREAD_USER:A reversal is made by a person, and none was given.', 1;

    SET @Reason = NULLIF(LTRIM(RTRIM(@Reason)), '');

    IF @Reason IS NULL
        THROW 51000, 'AGORA:CASH_REVERSE_REASON:Say why. It is the only record of why an allocation was undone.', 1;

    DECLARE @Now DATETIME2(0) = SYSUTCDATETIME();
    DECLARE @Reversed INT = 0, @Unstamped INT = 0, @Restored INT = 0;

    BEGIN TRY
        BEGIN TRANSACTION;

        SELECT a.Id, a.BranchId, a.ZReadDate, a.Location, a.EodCounter, a.TerminalNo, a.LogFile, a.PosUserId,
               a.TillNo, a.ShiftNo, a.EmployeeCode, a.[Status], a.StampMode, a.SupersedesId,
               a.StampPriorTillNo, a.StampPriorShiftNo, a.StampPriorEmployeeCode,
               CAST(NULL AS INT) AS Hits, CAST(0 AS BIT) AS Moved
        INTO #rz
        FROM agora.ZreadAllocation a WITH (UPDLOCK, HOLDLOCK)
        WHERE a.BranchId = @BranchId AND a.CommitRef = @CommitRef;

        IF NOT EXISTS (SELECT 1 FROM #rz)
            THROW 51000, 'AGORA:CASH_COMMIT_NOT_FOUND:No Update ZREAD press with that reference at this site.', 1;

        IF NOT EXISTS (SELECT 1 FROM #rz WHERE [Status] <> 'reversed')
            THROW 51000, 'AGORA:CASH_COMMIT_REVERSED:That press has already been reversed.', 1;

        IF EXISTS (SELECT 1 FROM #rz WHERE [Status] = 'superseded')
        BEGIN
            DECLARE @Later NVARCHAR(400) = 'AGORA:CASH_COMMIT_SUPERSEDED:'
                + CONVERT(NVARCHAR(10), (SELECT COUNT(*) FROM #rz WHERE [Status] = 'superseded'))
                + ' of its Z-reads have been allocated again by a later press. Reverse that press first.';
            THROW 51000, @Later, 1;
        END

        /* The rows this press stamped, locked, and checked: still one row, and
           still carrying what the press wrote. */
        UPDATE r
           SET Hits = x.Hits,
               Moved = CASE WHEN x.Hits = 1 AND x.TillNo = r.TillNo AND x.ShiftNo = r.ShiftNo
                                 AND LTRIM(RTRIM(x.EmployeeCode)) = r.EmployeeCode THEN 0 ELSE 1 END
        FROM #rz r
        CROSS APPLY (
            SELECT COUNT(*) AS Hits, MAX(z.TillNo) AS TillNo, MAX(z.ShiftNo) AS ShiftNo, MAX(z.EmployeeCode) AS EmployeeCode
            FROM [PumpIT].dbo.DBF_P3TRANS_ZREAD z WITH (UPDLOCK, HOLDLOCK)
            WHERE z.SSBranchId = r.BranchId
              AND z.[DATE] >= r.ZReadDate AND z.[DATE] < DATEADD(day, 1, r.ZReadDate)
              AND LTRIM(RTRIM(z.EOD_CNTR)) = r.EodCounter
              AND LTRIM(RTRIM(z.TERMNUM))  = r.TerminalNo
              AND LTRIM(RTRIM(z.LOGFILE))  = r.LogFile
              AND LTRIM(RTRIM(z.USERID))   = r.PosUserId
              AND ISNULL(LTRIM(RTRIM(z.[LOCATION])), '') = r.Location
        ) x
        WHERE r.[Status] = 'allocated' AND r.StampMode = 'live';

        IF EXISTS (SELECT 1 FROM #rz WHERE Moved = 1)
        BEGIN
            DECLARE @Moved NVARCHAR(400) = 'AGORA:CASH_COMMIT_MOVED:'
                + CONVERT(NVARCHAR(10), (SELECT COUNT(*) FROM #rz WHERE Moved = 1))
                + ' of its Z-reads no longer carry in PumpIT what this press wrote — allocated again on the legacy screen, or reloaded. Nothing was reversed.';
            THROW 51000, @Moved, 1;
        END

        /* PumpIT first, from the priors this press recorded. */
        UPDATE z
           SET z.TillNo       = r.StampPriorTillNo,
               z.ShiftNo      = r.StampPriorShiftNo,
               z.EmployeeCode = r.StampPriorEmployeeCode
        FROM [PumpIT].dbo.DBF_P3TRANS_ZREAD z
        JOIN #rz r
          ON z.SSBranchId = r.BranchId
         AND z.[DATE] >= r.ZReadDate AND z.[DATE] < DATEADD(day, 1, r.ZReadDate)
         AND LTRIM(RTRIM(z.EOD_CNTR)) = r.EodCounter
         AND LTRIM(RTRIM(z.TERMNUM))  = r.TerminalNo
         AND LTRIM(RTRIM(z.LOGFILE))  = r.LogFile
         AND LTRIM(RTRIM(z.USERID))   = r.PosUserId
         AND ISNULL(LTRIM(RTRIM(z.[LOCATION])), '') = r.Location
        WHERE r.[Status] = 'allocated' AND r.StampMode = 'live' AND r.Hits = 1;

        SET @Unstamped = @@ROWCOUNT;

        IF @Unstamped <> (SELECT COUNT(*) FROM #rz WHERE [Status] = 'allocated' AND StampMode = 'live')
            THROW 51000, 'AGORA:CASH_ZREAD_STAMP_COUNT:The PumpIT rows restored did not match the Z-reads stamped, so nothing was reversed.', 1;

        /* The press's allocations step aside... */
        UPDATE a
           SET [Status] = 'reversed',
               ReversedAt = @Now, ReversedBy = @UserId, ReversalReason = @Reason,
               UpdatedAt = @Now, UpdatedBy = @UserId
        FROM agora.ZreadAllocation a
        JOIN #rz r ON r.BranchId = a.BranchId AND r.Id = a.Id
        WHERE r.[Status] = 'allocated';

        SET @Reversed = @@ROWCOUNT;

        /* ...and what each had replaced is back in force. */
        UPDATE prev
           SET [Status] = 'allocated',
               SupersededAt = NULL, SupersededBy = NULL,
               Note = LEFT('Back in force: the press that replaced it was reversed (' + @Reason + ').', 200),
               UpdatedAt = @Now, UpdatedBy = @UserId
        FROM agora.ZreadAllocation prev
        JOIN #rz r ON r.BranchId = prev.BranchId AND r.SupersedesId = prev.Id
        WHERE r.[Status] = 'allocated' AND prev.[Status] = 'superseded';

        SET @Restored = @@ROWCOUNT;

        COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        THROW;
    END CATCH;

    SELECT
        CAST(1 AS BIT) AS Ok,
        'CASH_COMMIT_REVERSED' AS Code,
        CONVERT(NVARCHAR(400),
            'Reversed ' + CONVERT(NVARCHAR(10), @Reversed) + ' allocation' + CASE WHEN @Reversed = 1 THEN '' ELSE 's' END + '.'
            + CASE WHEN @Unstamped > 0
                   THEN ' PumpIT is back to what it held before, on ' + CONVERT(NVARCHAR(10), @Unstamped) + ' Z-read'
                        + CASE WHEN @Unstamped = 1 THEN '' ELSE 's' END + '.'
                   ELSE ' Nothing had been written to PumpIT, so nothing there moved.' END
            + CASE WHEN @Restored > 0
                   THEN ' ' + CONVERT(NVARCHAR(10), @Restored) + ' earlier allocation' + CASE WHEN @Restored = 1 THEN ' is' ELSE 's are' END
                        + ' back in force.'
                   ELSE '' END) AS Message,
        CAST(NULL AS BIGINT) AS Id,
        @Reversed AS Reversed,
        @Unstamped AS Unstamped,
        @Restored AS Restored;
END
