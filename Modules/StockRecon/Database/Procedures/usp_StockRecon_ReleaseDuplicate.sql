/*
 * agora.usp_StockRecon_ReleaseDuplicate — let a possible duplicate issue (D2)
 * stop holding its chain back, with a reason.
 *
 * Read by:  Stock recon centre -> a chain -> Release (one shift, or every
 *           possible duplicate on the chain with one reason for the lot).
 * Reads:    agora.StockReconRun, agora.StockReconRunLine, agora.vw_StockReconLine
 * Writes:   agora.StockReconDuplicateRelease — Agora's own ledger. NOTHING in
 *           the customer's estate. A release writes nothing to PumpIT; it lets
 *           the NEXT preview balance the chain, and the commit of that preview
 *           do what it already did before a D2 held anything back.
 *
 * WHY THERE IS A RELEASE AT ALL (Ryan, 5 Oct 2026, PM decision 01a10b21). A
 * chain carrying a D2 is held back from the commit: the same issue was keyed
 * on a neighbouring shift and this one is short by about that much, but the
 * count cannot prove which issue was real. Two ways out: the site corrects the
 * issue in PumpIT — the next preview no longer finds it — or somebody who has
 * checked the issue book, the storeroom count or the delivery note releases it
 * here and says what they found. That is the person's call, so it is theirs by
 * name, with the reason kept.
 *
 * A RELEASE IS KEYED ON THE SHIFT AND THE ISSUE AS KEYED, not on the run. It
 * outlives the run it was pressed on, and every later preview of that shift
 * reads it — but only while PumpIT still holds that issue: re-key the issue
 * and the release no longer matches, the hold returns. One standing release
 * per shift: releasing a shift again at a different issue supersedes the old
 * one (withdrawn, with a note saying so) rather than stacking two.
 *
 * Each line named must be on the run and be a D2 there, and PumpIT must still
 * hold the issue the run read; otherwise the whole call is refused before
 * anything is written. A line already released at that issue is counted and
 * left alone.
 *
 * Refusals: RELEASE_USER · REASON_REQUIRED · NO_RUN · NOTHING_SELECTED ·
 *           NO_LINE · NOT_POSSIBLE_DUPLICATE · ISSUE_MOVED
 *
 * Returns the writer status row (Ok, Code, Message, Id = how many released).
 *
 *   EXEC agora.usp_StockRecon_ReleaseDuplicate @BranchId = 23, @RunId = 41,
 *        @LineIds = '1202,1203', @Reason = 'Issue book shows one issue of 20 on the day shift',
 *        @UserId = 1;
 */
CREATE OR ALTER PROCEDURE [agora].[usp_StockRecon_ReleaseDuplicate]
    @BranchId  INT,
    @RunId     INT,
    @LineIds   NVARCHAR(MAX),       -- CSV of agora.StockReconRunLine ids on this run
    @Reason    NVARCHAR(400),
    @UserId    INT
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    /* Two issues are the same issue to the gram — the tolerance the preview
       matches a release with. */
    DECLARE @tol DECIMAL(18,4) = 0.0005;
    DECLARE @Msg NVARCHAR(2048);

    SET @Reason = NULLIF(LTRIM(RTRIM(ISNULL(@Reason, N''))), N'');

    IF @UserId IS NULL
        THROW 51000, 'AGORA:RELEASE_USER:A release is made by a person, and none was given.', 1;

    /* NOT TRIVIAL. The reason is the only record of why a short somebody may
       be charged for was let through, and "ok" says nothing a person can check
       later. Ten characters with words in them is the floor, not the standard. */
    IF @Reason IS NULL OR LEN(@Reason) < 10 OR @Reason NOT LIKE N'%[A-Za-z][A-Za-z]%'
        THROW 51000, 'AGORA:REASON_REQUIRED:A release says why, in words somebody can check later: what the issue book, the storeroom count or the delivery note showed. At least 10 characters.', 1;

    IF NOT EXISTS (SELECT 1 FROM agora.StockReconRun WHERE BranchId = @BranchId AND Id = @RunId)
        THROW 51000, 'AGORA:NO_RUN:That run does not exist for this branch.', 1;

    DECLARE @Ask TABLE (Id BIGINT PRIMARY KEY);

    INSERT INTO @Ask (Id)
    SELECT DISTINCT TRY_CONVERT(bigint, LTRIM(RTRIM(s.value)))
    FROM STRING_SPLIT(ISNULL(@LineIds, N''), N',') s
    WHERE TRY_CONVERT(bigint, LTRIM(RTRIM(s.value))) IS NOT NULL;

    IF NOT EXISTS (SELECT 1 FROM @Ask)
        THROW 51000, 'AGORA:NOTHING_SELECTED:No possible duplicate was named to release.', 1;

    IF EXISTS (SELECT 1 FROM @Ask a
               WHERE NOT EXISTS (SELECT 1 FROM agora.StockReconRunLine rl
                                 WHERE rl.BranchId = @BranchId AND rl.RunId = @RunId AND rl.Id = a.Id))
        THROW 51000, 'AGORA:NO_LINE:A shift named is not on this run.', 1;

    /* The lines, resolved once: the shift, the issue the run read, and what
       the preview said about it. */
    DECLARE @Lines TABLE (
        Id BIGINT PRIMARY KEY, [LineNo] INT, AreaNo INT, StockItemNo NVARCHAR(10), TransactionDate DATE, ShiftNo INT,
        QtyIssued DECIMAL(18,3), DupTier NVARCHAR(2), DupTwinDate DATE, DupTwinShift INT, DupReason NVARCHAR(80),
        Label NVARCHAR(200));

    INSERT INTO @Lines (Id, [LineNo], AreaNo, StockItemNo, TransactionDate, ShiftNo, QtyIssued, DupTier,
                        DupTwinDate, DupTwinShift, DupReason, Label)
    SELECT rl.Id, rl.[LineNo], rl.AreaNo, rl.StockItemNo, rl.TransactionDate, rl.ShiftNo, rl.QtyIssued, rl.DupTier,
           rl.DupTwinDate, rl.DupTwinShift, rl.DupReason,
           CONCAT(ISNULL(rl.ItemDescription, rl.StockItemNo), N' on ', CONVERT(char(10), rl.TransactionDate, 23),
                  N' shift ', rl.ShiftNo)
    FROM @Ask a
    JOIN agora.StockReconRunLine rl
      ON rl.BranchId = @BranchId AND rl.RunId = @RunId AND rl.Id = a.Id;

    /* Only a POSSIBLE duplicate. A clear one is the commit's own business, and
       anything else holds nothing back to release. */
    SET @Msg = (
        SELECT TOP 1 CONCAT(N'AGORA:NOT_POSSIBLE_DUPLICATE:Only a possible duplicate issue (D2) is released. ',
                            l.Label, N' is not one',
                            CASE WHEN l.DupTier = N'D1'
                                 THEN N': it is a clear duplicate, which the commit takes out itself.'
                                 ELSE N'.' END,
                            N' Nothing was released.')
        FROM @Lines l
        WHERE ISNULL(l.DupTier, N'') <> N'D2'
        ORDER BY l.[LineNo]);

    IF @Msg IS NOT NULL
        THROW 51000, @Msg, 1;

    /* The release is of the issue as PumpIT holds it. If it has been re-keyed
       since the run was made, the run is describing something that is not
       there any more — and a release at the old issue would never match. */
    SET @Msg = (
        SELECT TOP 1 CONCAT(N'AGORA:ISSUE_MOVED:', l.Label,
                            CASE WHEN s.StockItemNo IS NULL
                                 THEN N' is no longer in PumpIT'
                                 ELSE CONCAT(N' now holds issue ', FORMAT(CONVERT(decimal(18,3), ISNULL(s.QtyIssued, 0)), '0.###'),
                                             N', not the ', FORMAT(l.QtyIssued, '0.###'), N' this run read') END,
                            N'. Preview again before releasing it. Nothing was released.')
        FROM @Lines l
        OUTER APPLY (SELECT TOP 1 v.StockItemNo, v.QtyIssued
                       FROM agora.vw_StockReconLine v
                      WHERE v.BranchId = @BranchId
                        AND CONVERT(date, v.TransactionDate) = l.TransactionDate
                        AND v.ShiftNo = l.ShiftNo AND v.AreaNo = l.AreaNo AND v.StockItemNo = l.StockItemNo) s
        WHERE s.StockItemNo IS NULL
           OR ABS(CONVERT(decimal(18,4), ISNULL(s.QtyIssued, 0)) - l.QtyIssued) > @tol
        ORDER BY l.[LineNo]);

    IF @Msg IS NOT NULL
        THROW 51000, @Msg, 1;

    DECLARE @Released INT = 0,
            @Already  INT = (
                SELECT COUNT(*)
                FROM @Lines l
                WHERE EXISTS (SELECT 1 FROM agora.StockReconDuplicateRelease x
                              WHERE x.BranchId = @BranchId AND x.AreaNo = l.AreaNo AND x.StockItemNo = l.StockItemNo
                                AND x.TransactionDate = l.TransactionDate AND x.ShiftNo = l.ShiftNo
                                AND x.WithdrawnAt IS NULL AND ABS(x.QtyIssued - l.QtyIssued) <= @tol));

    BEGIN TRY
        BEGIN TRANSACTION;

        /* One standing release per shift. A standing one at ANOTHER issue is
           the issue before somebody re-keyed it: it is superseded, withdrawn
           by this person with a note saying why, never deleted. */
        UPDATE x SET
            x.WithdrawnAt  = SYSUTCDATETIME(),
            x.WithdrawnBy  = @UserId,
            x.WithdrawNote = CONCAT(N'Superseded: released again at issue ', FORMAT(l.QtyIssued, '0.###')),
            x.UpdatedAt    = SYSUTCDATETIME(),
            x.UpdatedBy    = @UserId
        FROM agora.StockReconDuplicateRelease x WITH (UPDLOCK, HOLDLOCK)
        JOIN @Lines l
          ON x.AreaNo = l.AreaNo AND x.StockItemNo = l.StockItemNo
         AND x.TransactionDate = l.TransactionDate AND x.ShiftNo = l.ShiftNo
        WHERE x.BranchId = @BranchId
          AND x.WithdrawnAt IS NULL
          AND ABS(x.QtyIssued - l.QtyIssued) > @tol;

        INSERT INTO agora.StockReconDuplicateRelease (
            BranchId, AreaNo, StockItemNo, TransactionDate, ShiftNo, QtyIssued,
            TwinDate, TwinShift, DupReason, Reason, RunId, RunLineId,
            CreatedAt, CreatedBy)
        SELECT @BranchId, l.AreaNo, l.StockItemNo, l.TransactionDate, l.ShiftNo, l.QtyIssued,
               l.DupTwinDate, l.DupTwinShift, l.DupReason, @Reason, @RunId, l.Id,
               SYSUTCDATETIME(), @UserId
        FROM @Lines l
        WHERE NOT EXISTS (SELECT 1 FROM agora.StockReconDuplicateRelease x WITH (UPDLOCK, HOLDLOCK)
                          WHERE x.BranchId = @BranchId AND x.AreaNo = l.AreaNo AND x.StockItemNo = l.StockItemNo
                            AND x.TransactionDate = l.TransactionDate AND x.ShiftNo = l.ShiftNo
                            AND x.WithdrawnAt IS NULL);

        SET @Released = @@ROWCOUNT;

        COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        THROW;
    END CATCH;

    SELECT CONVERT(bit, 1)                                          AS Ok,
           CASE WHEN @Released > 0 THEN 'RELEASED' ELSE 'ALREADY_RELEASED' END AS Code,
           CONVERT(nvarchar(400), CONCAT(
               CASE WHEN @Released > 0
                    THEN CONCAT(@Released, N' possible duplicate ', CASE WHEN @Released = 1 THEN N'issue' ELSE N'issues' END,
                                N' released')
                    ELSE N'Nothing new released' END,
               CASE WHEN @Already > 0 THEN CONCAT(N' (', @Already, N' already released)') ELSE N'' END,
               N'. This run still holds the chain back — preview again, and the chain commits if nothing else stops it.'))
                                                                    AS [Message],
           CONVERT(bigint, @Released)                               AS Id;
END
