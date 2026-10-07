/*
 * agora.usp_StockRecon_Reverse — undo a commit, exactly and nothing else.
 *
 * Read by:  Stock recon centre -> a committed run -> Reverse.
 * Reads:    agora.StockReconAmendment, agora.StockReconRun
 * Writes:   agora.StockReconAmendment, agora.StockReconRunLine,
 *           agora.StockReconRun, and in 'live' mode the two counts on
 *           [PumpIT].dbo.STK_StockReconLine — and QtyIssued, back to its
 *           prior, where the commit set a clear duplicate issue to 0 (1 Oct
 *           2026).
 *
 * NOTHING IS WRITTEN OVER A ROW THAT HAS MOVED SINCE (5 Oct 2026). Every row
 * the commit wrote to PumpIT must still hold exactly what the commit left
 * there, or the whole reversal is refused before anything moves, naming the
 * shift and what it holds now: ISSUE_MOVED for a duplicate issue the commit set
 * to 0, COUNT_MOVED for a count it amended. Until then a reversal put the prior
 * counts back over whatever was there — a recount, a hand balancing — and the
 * change was lost without a trace. Journal-mode amendments wrote nothing and
 * are not checked.
 *
 * It puts back the PRIOR pair the commit recorded — not the _Original columns,
 * and not a recomputation. Those are two different things and only one of them
 * is a reversal: if the row had already been amended by hand before this run
 * touched it, restoring the original count would silently throw that away.
 *
 * A journal-mode amendment is reversed too, and reversing it writes nothing to
 * PumpIT — because the commit wrote nothing there either. The mode is taken
 * from the AMENDMENT rather than from the run or from config, so a run
 * committed while the setting was journal cannot be unwound as though it had
 * been live.
 *
 * The reason is required by this procedure rather than by a form rule: it is
 * the only record of why an amendment was undone, and a reversal without one is
 * a hole in the trail the ledger exists to keep.
 *
 * The legacy procedure can undo nothing at all. That is the whole difference.
 */
CREATE OR ALTER PROCEDURE [agora].[usp_StockRecon_Reverse]
    @RunId     INT,
    @BranchId  INT,
    @Reason    NVARCHAR(300),
    @UserId    INT = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    SET @Reason = NULLIF(LTRIM(RTRIM(ISNULL(@Reason, ''))), '');

    IF @Reason IS NULL
        THROW 51000, 'AGORA:REASON_REQUIRED:A reversal has to say why.', 1;

    IF NOT EXISTS (SELECT 1 FROM agora.StockReconRun WHERE BranchId = @BranchId AND Id = @RunId)
        THROW 51000, 'AGORA:NO_RUN:That run does not exist for this branch.', 1;

    IF NOT EXISTS (SELECT 1 FROM agora.StockReconAmendment
                   WHERE BranchId = @BranchId AND RunId = @RunId AND [State] = 'active')
        THROW 51000, 'AGORA:NOTHING_TO_REVERSE:This run has no amendment left standing.', 1;

    /*
     * A DUPLICATE ISSUE THE COMMIT SET TO 0 goes back to what it was — but
     * only if the row still holds exactly what the commit left there: the
     * issue, and the two counts. An issue somebody has re-keyed since, or a
     * count re-taken, is not written over: the reversal stops before anything
     * moves, and says which. As usp_Recon_Reverse does for a forced FNB
     * match (ADJUST_MOVED). Ryan's go-ahead for the write, 1 Oct 2026.
     */
    DECLARE @Moved NVARCHAR(2048) = (
        SELECT TOP 1 CONCAT(N'AGORA:ISSUE_MOVED:', ISNULL(rl.ItemDescription, a.StockItemNo),
                            N' on ', CONVERT(char(10), a.TransactionDate, 23), N' shift ', a.ShiftNo,
                            CASE WHEN l.StockItemNo IS NULL
                                 THEN N' is no longer in PumpIT'
                                 ELSE CONCAT(N' has changed since this run changed its issue (it now holds issue ',
                                             FORMAT(CONVERT(decimal(18,3), ISNULL(l.QtyIssued, 0)), '0.###'),
                                             N', opening ', FORMAT(CONVERT(decimal(18,3), ISNULL(l.QtyOpen, 0)), '0.###'),
                                             N', closing ', FORMAT(CONVERT(decimal(18,3), ISNULL(l.QtyClose, 0)), '0.###'), N')') END,
                            N'. Putting the old issue back would write over that change, so nothing was reversed. Correct it in PumpIT first.')
        FROM agora.StockReconAmendment a
        LEFT JOIN agora.StockReconRunLine rl
               ON rl.BranchId = a.BranchId AND rl.Id = a.RunLineId
        LEFT JOIN [PumpIT].dbo.STK_StockReconLine l
               ON l.SSBranchId = a.BranchId
              AND CONVERT(date, l.TransactionDate) = a.TransactionDate
              AND l.ShiftNo = a.ShiftNo AND l.AreaNo = a.AreaNo AND l.StockItemNo = a.StockItemNo
        WHERE a.BranchId = @BranchId AND a.RunId = @RunId
          AND a.[State] = 'active' AND a.StampMode = 'live'
          AND a.NewQtyIssued IS NOT NULL
          AND (   l.StockItemNo IS NULL
               OR ABS(CONVERT(decimal(18,4), ISNULL(l.QtyIssued, 0)) - a.NewQtyIssued) > 0.005
               OR ABS(CONVERT(decimal(18,4), ISNULL(l.QtyOpen,   0)) - a.NewQtyOpen)   > 0.005
               OR ABS(CONVERT(decimal(18,4), ISNULL(l.QtyClose,  0)) - a.NewQtyClose)  > 0.005)
        ORDER BY a.TransactionDate, a.ShiftNo, a.AreaNo, a.StockItemNo);

    IF @Moved IS NOT NULL
        THROW 51000, @Moved, 1;

    /*
     * AND A COUNT THE COMMIT AMENDED, the same way: the prior pair goes back
     * only over the pair the commit wrote. A row recounted or balanced by hand
     * since is not written over; the reversal stops before anything moves.
     */
    SET @Moved = (
        SELECT TOP 1 CONCAT(N'AGORA:COUNT_MOVED:', ISNULL(rl.ItemDescription, a.StockItemNo),
                            N' on ', CONVERT(char(10), a.TransactionDate, 23), N' shift ', a.ShiftNo,
                            CASE WHEN l.StockItemNo IS NULL
                                 THEN N' is no longer in PumpIT'
                                 ELSE CONCAT(N' has changed since this run amended its counts (it now holds opening ',
                                             FORMAT(CONVERT(decimal(18,3), ISNULL(l.QtyOpen, 0)), '0.###'),
                                             N', closing ', FORMAT(CONVERT(decimal(18,3), ISNULL(l.QtyClose, 0)), '0.###'),
                                             N'; the run left opening ', FORMAT(a.NewQtyOpen, '0.###'),
                                             N', closing ', FORMAT(a.NewQtyClose, '0.###'), N')') END,
                            N'. Putting the old counts back would write over that change, so nothing was reversed. Correct it in PumpIT first.')
        FROM agora.StockReconAmendment a
        LEFT JOIN agora.StockReconRunLine rl
               ON rl.BranchId = a.BranchId AND rl.Id = a.RunLineId
        LEFT JOIN [PumpIT].dbo.STK_StockReconLine l
               ON l.SSBranchId = a.BranchId
              AND CONVERT(date, l.TransactionDate) = a.TransactionDate
              AND l.ShiftNo = a.ShiftNo AND l.AreaNo = a.AreaNo AND l.StockItemNo = a.StockItemNo
        WHERE a.BranchId = @BranchId AND a.RunId = @RunId
          AND a.[State] = 'active' AND a.StampMode = 'live'
          AND (   l.StockItemNo IS NULL
               OR ABS(CONVERT(decimal(18,4), ISNULL(l.QtyOpen,  0)) - a.NewQtyOpen)  > 0.005
               OR ABS(CONVERT(decimal(18,4), ISNULL(l.QtyClose, 0)) - a.NewQtyClose) > 0.005)
        ORDER BY a.TransactionDate, a.ShiftNo, a.AreaNo, a.StockItemNo);

    IF @Moved IS NOT NULL
        THROW 51000, @Moved, 1;

    /* The live half first, so a failure there leaves the ledger saying the
       amendment is still active — which is true, and is the safe direction to
       be wrong in. Only amendments the commit actually wrote to PumpIT. The
       issue only where the commit wrote it. */
    UPDATE l
       SET l.QtyOpen   = a.PriorQtyOpen,
           l.QtyClose  = a.PriorQtyClose,
           l.QtyIssued = CASE WHEN a.NewQtyIssued IS NOT NULL THEN a.PriorQtyIssued ELSE l.QtyIssued END
    FROM [PumpIT].dbo.STK_StockReconLine l
    JOIN agora.StockReconAmendment a
      ON l.SSBranchId = a.BranchId
     AND CONVERT(date, l.TransactionDate) = a.TransactionDate
     AND l.ShiftNo = a.ShiftNo AND l.AreaNo = a.AreaNo AND l.StockItemNo = a.StockItemNo
    WHERE a.BranchId = @BranchId AND a.RunId = @RunId
      AND a.[State] = 'active' AND a.StampMode = 'live';

    DECLARE @Reversed INT;

    UPDATE agora.StockReconAmendment
       SET [State]     = 'reversed',
           ReversedAt  = SYSDATETIME(),
           ReversedBy  = @UserId,
           UpdatedAt   = SYSDATETIME(),
           UpdatedBy   = @UserId
    WHERE BranchId = @BranchId AND RunId = @RunId AND [State] = 'active';

    SET @Reversed = @@ROWCOUNT;

    /* The proposals go back to pending. The run keeps its arithmetic, so it can
       be committed again once whatever caused the reversal is dealt with. */
    UPDATE agora.StockReconRunLine
       SET CommitState = 'pending',
           BlockReason = NULL,
           UpdatedAt   = SYSDATETIME(),
           UpdatedBy   = @UserId
    WHERE BranchId = @BranchId AND RunId = @RunId AND CommitState = 'committed';

    UPDATE agora.StockReconRun
       SET [Status]        = 'reversed',
           ReversedAt      = SYSDATETIME(),
           ReversedBy      = @UserId,
           ReversalReason  = @Reason,
           UpdatedAt       = SYSDATETIME(),
           UpdatedBy       = @UserId
    WHERE BranchId = @BranchId AND Id = @RunId;

    SELECT CONVERT(bit, 1) AS Ok,
           'REVERSED'      AS Code,
           CONVERT(nvarchar(20), @Reversed) + ' amendment(s) put back to what they held before.' AS [Message],
           CONVERT(bigint, @RunId) AS Id;
END
