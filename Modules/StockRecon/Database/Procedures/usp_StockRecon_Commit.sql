/*
 * agora.usp_StockRecon_Commit — apply what the operator ticked on one run.
 *
 * Read by:  Stock recon centre -> a run -> the Balance press.
 * Reads:    agora.StockReconRun, agora.StockReconRunLine, agora.vw_StockReconLine
 * Writes:   agora.StockReconAmendment, agora.StockReconRunLine, agora.StockReconRun
 *           and — in 'live' stamp mode ONLY — QtyOpen / QtyClose on
 *           [PumpIT].dbo.STK_StockReconLine.
 *
 * @StampMode  'journal' records every amendment here and writes NOTHING to
 *             PumpIT. The ledger is then the worklist an admin applies, and the
 *             evidence for turning live on. It is the shipped setting.
 *             'live'    additionally writes the two counts back.
 *
 * The mode is PASSED IN rather than read here, so there is exactly one place in
 * the codebase — config('stockrecon.stamp_mode') — that decides whether Agora
 * writes to the customer's estate.
 *
 * WHAT MAKES THIS DIFFERENT FROM THE LEGACY. dbo.sp_UpdateAUTOStockReconBalancing
 * opens a cursor and calls sp_UpdateStockReconLine_QtyOpen_xQtyClose per row.
 * That procedure writes the closing on this shift and the opening on the NEXT
 * one, and works out which shift that is from STK_Area's day/afternoon/night
 * flags — an inference that is wrong the moment a shift is missing from the
 * data. Nothing records what was there before, and because the amendments go
 * to the live columns while the balancing reads the _Original ones, re-running
 * compounds them silently.
 *
 * Here every figure was settled by the preview, both columns of every row are
 * named explicitly rather than inferred from a shift table, and the row's PRIOR
 * pair is written to agora.StockReconAmendment before anything moves — which is
 * what makes agora.usp_StockRecon_Reverse exact. The legacy has no reversal at
 * all.
 *
 * IT RE-CHECKS EVERY ROW FIRST. A preview is a photograph; between the press
 * and the read somebody may have counted again. Any row whose source counts no
 * longer match what the preview saw is SKIPPED and says so, rather than being
 * overwritten with an amendment derived from figures that have gone.
 *
 * @UseOriginalCounts must be the value the PREVIEW ran with, because it decides
 * which pair of columns the re-check compares against. It is replayed from the
 * run's stored parameters by the service, never defaulted at the call site.
 *
 * Result set 1: the status row.
 * Result set 2: one row per candidate, saying what happened to it — so a
 *               screen can explain why four of forty were skipped.
 */
CREATE OR ALTER PROCEDURE [agora].[usp_StockRecon_Commit]
    @RunId             INT,
    @BranchId          INT,
    @StampMode         NVARCHAR(20)  = 'journal',
    @UseOriginalCounts BIT           = 1,
    @UserId            INT           = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE @eps DECIMAL(18,4) = 0.005;

    IF @StampMode NOT IN ('journal', 'live')
        THROW 51000, 'AGORA:BAD_STAMP_MODE:The stamp mode must be journal or live.', 1;

    DECLARE @Status NVARCHAR(20) = (
        SELECT [Status] FROM agora.StockReconRun WHERE BranchId = @BranchId AND Id = @RunId);

    IF @Status IS NULL
        THROW 51000, 'AGORA:NO_RUN:That run does not exist for this branch.', 1;

    IF @Status = 'committed'
        THROW 51000, 'AGORA:RUN_COMMITTED:This run has already been committed. Reverse it first.', 1;

    IF @Status NOT IN ('previewed', 'reversed')
        THROW 51000, 'AGORA:RUN_NOT_READY:Only a previewed run can be committed.', 1;

    /* A SKIP IS THE OUTCOME OF THE LAST ATTEMPT, NOT A PERMANENT STATE.

       Everything that skips a row is about the world as it stood a moment ago
       — the counts were re-taken, another run is holding an amendment on this
       shift. Reverse that run, or re-preview, and the row becomes writable
       again. Leaving it marked 'skipped' means the second press finds no
       candidates at all and refuses with NOTHING_SELECTED, which reads as
       "you ticked nothing" about a screen full of ticks. So the slate is
       cleared first and the re-check decides again. */
    UPDATE agora.StockReconRunLine
       SET CommitState = 'pending', BlockReason = NULL
    WHERE BranchId = @BranchId AND RunId = @RunId AND CommitState = 'skipped';

    /* The candidates: ticked, actually moves something, and not already dealt
       with. A tick on a row that does not move is not an error and is not a
       candidate — the preview only ever writes one on a row that does. */
    IF OBJECT_ID('tempdb..#cand') IS NOT NULL DROP TABLE #cand;

    SELECT
        rl.Id, rl.[LineNo], rl.AreaNo, rl.StockItemNo, rl.TransactionDate, rl.ShiftNo,
        rl.QtyOpen, rl.QtyClose, rl.QtyOpenNew, rl.QtyCloseNew,
        CONVERT(nvarchar(200), NULL) AS SkipReason
    INTO #cand
    FROM agora.StockReconRunLine rl
    WHERE rl.BranchId    = @BranchId
      AND rl.RunId       = @RunId
      AND rl.Selected    = 1
      AND rl.WouldAmend  = 1
      AND rl.ChainBlocked = 0
      AND rl.CommitState = 'pending';

    IF NOT EXISTS (SELECT 1 FROM #cand)
        THROW 51000, 'AGORA:NOTHING_SELECTED:Nothing on this run is ticked and amendable.', 1;

    /* ---------------------------------------------------------- the re-check
       The source row still has to be there, and still has to hold the counts
       the preview read. Compared against the _Original pair or the live pair
       depending on how the preview was run — the same choice, replayed. */
    UPDATE c SET SkipReason = 'The recon line is no longer in the source for this shift.'
    FROM #cand c
    WHERE NOT EXISTS (
        SELECT 1 FROM agora.vw_StockReconLine l
        WHERE l.BranchId = @BranchId
          AND CONVERT(date, l.TransactionDate) = c.TransactionDate
          AND l.ShiftNo = c.ShiftNo AND l.AreaNo = c.AreaNo AND l.StockItemNo = c.StockItemNo);

    UPDATE c SET SkipReason = 'The counts on this shift have changed since the preview.'
    FROM #cand c
    WHERE c.SkipReason IS NULL
      AND EXISTS (
        SELECT 1 FROM agora.vw_StockReconLine l
        WHERE l.BranchId = @BranchId
          AND CONVERT(date, l.TransactionDate) = c.TransactionDate
          AND l.ShiftNo = c.ShiftNo AND l.AreaNo = c.AreaNo AND l.StockItemNo = c.StockItemNo
          AND (ABS(CONVERT(decimal(18,4), ISNULL(CASE WHEN @UseOriginalCounts = 1
                        THEN l.QtyOpen_Original  ELSE l.QtyOpen  END, 0)) - c.QtyOpen)  > @eps
            OR ABS(CONVERT(decimal(18,4), ISNULL(CASE WHEN @UseOriginalCounts = 1
                        THEN l.QtyClose_Original ELSE l.QtyClose END, 0)) - c.QtyClose) > @eps));

    /* This shift already carries an amendment nobody has reversed. Two live
       amendments on one row means two "prior" values and no way to know which
       is the real one, so the second is refused rather than stacked. */
    UPDATE c SET SkipReason = 'This shift already carries an amendment from another run. Reverse that first.'
    FROM #cand c
    WHERE c.SkipReason IS NULL
      AND EXISTS (
        SELECT 1 FROM agora.StockReconAmendment a
        WHERE a.BranchId = @BranchId AND a.[State] = 'active'
          AND a.AreaNo = c.AreaNo AND a.StockItemNo = c.StockItemNo
          AND a.TransactionDate = c.TransactionDate AND a.ShiftNo = c.ShiftNo);

    /* ------------------------------------------------------- ONE TRANSACTION
       Everything below writes, and from here on it is all-or-nothing.

       This matters more since live writes were enabled (8 Sep 2026), because
       the work now spans TWO DATABASES: the amendment record lands in Agora
       and the counts land in PumpIT. Without a transaction those are separate
       autocommits, so a failure on the second — a deadlock, a row lock, a
       permission — leaves agora.StockReconAmendment claiming a prior pair for
       counts that never moved. A reversal would then write those "prior"
       values over counts nobody had touched, which is worse than the failure
       it was recovering from.

       XACT_ABORT is on, so any error dooms the transaction and the CATCH
       rolls the whole thing back and re-raises with the code intact. */
    BEGIN TRY
    BEGIN TRANSACTION;

    /* --------------------------------------------------------- the amendment
       Written in BOTH modes. In journal mode this IS the output: the list an
       admin works, with the prior pair beside the new one. */
    INSERT INTO agora.StockReconAmendment (
        BranchId, RunId, RunLineId, AreaNo, StockItemNo, TransactionDate, ShiftNo,
        PriorQtyOpen, PriorQtyClose, NewQtyOpen, NewQtyClose, StampMode, [State],
        CreatedAt, CreatedBy)
    SELECT
        @BranchId, @RunId, c.Id, c.AreaNo, c.StockItemNo, c.TransactionDate, c.ShiftNo,
        /* The PRIOR pair is what is in the source RIGHT NOW — the live columns,
           never the _Original ones, because those are not what a reversal has
           to put back. */
        CONVERT(decimal(18,3), ISNULL(l.QtyOpen, 0)),
        CONVERT(decimal(18,3), ISNULL(l.QtyClose, 0)),
        c.QtyOpenNew, c.QtyCloseNew, @StampMode, 'active',
        SYSDATETIME(), @UserId
    FROM #cand c
    JOIN agora.vw_StockReconLine l
      ON l.BranchId = @BranchId
     AND CONVERT(date, l.TransactionDate) = c.TransactionDate
     AND l.ShiftNo = c.ShiftNo AND l.AreaNo = c.AreaNo AND l.StockItemNo = c.StockItemNo
    WHERE c.SkipReason IS NULL;

    /* ------------------------------------------------------------- the write
       Live mode only, and both columns of the row are named. QtyIssued is
       deliberately untouched: balancing amends counts, and amending an issue
       changes T itself — which is capturing a missing issue, a different act
       that has to stay visible as one. */
    IF @StampMode = 'live'
    BEGIN
        UPDATE l
           SET l.QtyOpen  = c.QtyOpenNew,
               l.QtyClose = c.QtyCloseNew
        FROM [PumpIT].dbo.STK_StockReconLine l
        JOIN #cand c
          ON l.SSBranchId = @BranchId
         AND CONVERT(date, l.TransactionDate) = c.TransactionDate
         AND l.ShiftNo = c.ShiftNo AND l.AreaNo = c.AreaNo AND l.StockItemNo = c.StockItemNo
        WHERE c.SkipReason IS NULL;
    END

    /* --------------------------------------------------------- the run lines */
    UPDATE rl
       SET rl.CommitState = 'committed',
           rl.BlockReason = NULL,
           rl.UpdatedAt   = SYSDATETIME(),
           rl.UpdatedBy   = @UserId
    FROM agora.StockReconRunLine rl
    JOIN #cand c ON c.Id = rl.Id
    WHERE rl.BranchId = @BranchId AND c.SkipReason IS NULL;

    UPDATE rl
       SET rl.CommitState = 'skipped',
           rl.BlockReason = c.SkipReason,
           rl.UpdatedAt   = SYSDATETIME(),
           rl.UpdatedBy   = @UserId
    FROM agora.StockReconRunLine rl
    JOIN #cand c ON c.Id = rl.Id
    WHERE rl.BranchId = @BranchId AND c.SkipReason IS NOT NULL;

    DECLARE @Written INT = (SELECT COUNT(*) FROM #cand WHERE SkipReason IS NULL),
            @Skipped INT = (SELECT COUNT(*) FROM #cand WHERE SkipReason IS NOT NULL);

    DECLARE @Units DECIMAL(18,3) = (
        SELECT ISNULL(SUM(ABS(rl.AmendClose)), 0)
        FROM agora.StockReconRunLine rl
        JOIN #cand c ON c.Id = rl.Id
        WHERE rl.BranchId = @BranchId AND c.SkipReason IS NULL);

    /* ------------------------------------------------------------ the header
       'committed' when SOMETHING was written, even if other rows were skipped:
       the run HAS been acted on, and the skipped rows say so individually. A
       run that reported itself as still previewed after writing thirty
       amendments would invite a second press.

       BUT NOT WHEN NOTHING WAS WRITTEN. A run marked committed with zero rows
       and zero amendments is the worst of both readings — it cannot be pressed
       again (the guard above refuses a committed run) and there is nothing to
       reverse, so the work is simply stranded. It stays previewed, the caller
       gets ALL_SKIPPED, and the skipped rows carry the reason. Found on the
       first real double-press, 8 September 2026: run 9 sat committed with
       nothing written because run 8 already held the amendments. */
    IF @Written > 0
    BEGIN
        UPDATE agora.StockReconRun
           SET [Status]       = 'committed',
               StampMode      = @StampMode,
               CommittedAt    = SYSDATETIME(),
               CommittedBy    = @UserId,
               CommittedRows  = @Written,
               CommittedUnits = @Units,
               UpdatedAt      = SYSDATETIME(),
               UpdatedBy      = @UserId
        WHERE BranchId = @BranchId AND Id = @RunId;
    END

    COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;

        /* Re-raised rather than swallowed, and with the original text, so a
           refusal keeps its AGORA:{Code} shape and a fault stays a fault.
           Nothing has been written by the time this returns. */
        DECLARE @Err NVARCHAR(2048) = ERROR_MESSAGE();
        THROW 51000, @Err, 1;
    END CATCH

    /* The reason the operator is actually shown when nothing went through.
       "Every ticked shift had moved since the preview" is one of three
       possible answers and was the wrong one two times out of three — the
       common case is another run still holding the amendment. Reporting the
       reason the rows actually carry is the difference between a message that
       tells somebody what to do next and one that sends them looking for a
       recount that never happened. */
    DECLARE @Why NVARCHAR(200) = (
        SELECT TOP 1 SkipReason FROM #cand WHERE SkipReason IS NOT NULL
        GROUP BY SkipReason ORDER BY COUNT(*) DESC);

    SELECT CONVERT(bit, CASE WHEN @Written > 0 THEN 1 ELSE 0 END) AS Ok,
           CASE WHEN @Written > 0 THEN 'COMMITTED' ELSE 'ALL_SKIPPED' END AS Code,
           CASE WHEN @Written = 0
                THEN 'Nothing was written. ' + ISNULL(@Why, 'Every ticked shift was skipped.')
                     + ' The run is still open, so it can be pressed again once that is dealt with.'
                ELSE CONVERT(nvarchar(20), @Written) + ' shift(s) amended'
                     + CASE WHEN @Skipped > 0
                            THEN ', ' + CONVERT(nvarchar(20), @Skipped) + ' skipped because they had moved'
                            ELSE '' END
                     + CASE WHEN @StampMode = 'live'
                            THEN ', written to PumpIT.'
                            ELSE ', recorded in Agora only — PumpIT was not touched.' END
           END AS [Message],
           CONVERT(bigint, @RunId) AS Id;

    SELECT c.Id AS RunLineId, c.[LineNo], c.AreaNo, c.StockItemNo, c.TransactionDate, c.ShiftNo,
           CASE WHEN c.SkipReason IS NULL THEN 'committed' ELSE 'skipped' END AS Result,
           c.SkipReason
    FROM #cand c
    ORDER BY c.[LineNo];

    DROP TABLE #cand;
END
