/*
 * agora.usp_StockRecon_ReverseChain — undo ONE item's chain inside a committed
 * run, exactly and nothing else (12 Nov 2026).
 *
 * Read by:  Stock recon centre -> a committed run -> a chain -> Reverse this item.
 * Reads:    agora.StockReconAmendment, agora.StockReconRun, agora.StockReconRunLine
 * Writes:   agora.StockReconAmendment, agora.StockReconRunLine,
 *           agora.StockReconRun, and in 'live' mode [PumpIT].dbo.STK_StockReconLine:
 *           QtyOpen and QtyClose back to their prior, QtyIssued back to its
 *           prior where the commit wrote it, QtyComputer back to its prior where
 *           a reset wrote it.
 *
 * WHY IT EXISTS. An engine fault (fixed 12 Nov 2026, "A short before an over
 * balances with it" in docs/stock-recon.md) left wrongly inserted issues in
 * PumpIT. Two live runs held 220 and 63 amendments of which 3 and 2 item chains
 * were wrong, and usp_StockRecon_Reverse can only undo a whole run. Ryan
 * approved "a reversal for a single item's chain ... a new PumpIT write".
 *
 * THE SAME CONTRACT AS usp_StockRecon_Reverse, restricted to this run's ACTIVE
 * amendments for one area and one item:
 *   - a reason is required (REASON_REQUIRED); NO_RUN; NOTHING_TO_REVERSE;
 *   - ISSUE_MOVED, COUNT_MOVED and POS_MOVED are checked for EVERY row of the
 *     chain before anything is written — all of the chain or none of it;
 *   - the PRIOR values the commit recorded go back, not the _Original columns
 *     and not a recomputation; the issue and the POS only where the commit
 *     wrote them; a journal-mode amendment is reversed without touching PumpIT,
 *     the mode taken from the amendment;
 *   - the chain's run lines go back to pending.
 *
 * ALWAYS THE WHOLE CHAIN, never a shift: the balancing moved a closing and the
 * next opening together, and putting one back without the other would leave a
 * break in PumpIT that neither the commit nor the staff made. That includes a
 * reset's pre-window closing (RunLineId < 0), which belongs to the same item.
 *
 * THE RUN STAYS COMMITTED while any amendment on it is still active, and is
 * left exactly as usp_StockRecon_Reverse leaves it — reversed, with when, who
 * and this reason — only when this chain was the last standing.
 *
 * THE REASON IS KEPT ON EACH AMENDMENT IT REVERSED (StockReconAmendment.
 * ReversalReason, added for this by v1__14q), because the run's own
 * ReversalReason belongs to a run-level reversal and is NULL while the run
 * stays committed.
 *
 * One transaction: the caller's when there is one (ProcedureService::write
 * opens it), its own otherwise. The run row is read under UPDLOCK first, so two
 * chain reversals on one run take turns and the second sees the first before
 * it decides whether anything is left standing.
 */
CREATE OR ALTER PROCEDURE [agora].[usp_StockRecon_ReverseChain]
    @RunId        INT,
    @BranchId     INT,
    @AreaNo       INT,
    @StockItemNo  NVARCHAR(10),
    @Reason       NVARCHAR(300),
    @UserId       INT = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    SET @Reason = NULLIF(LTRIM(RTRIM(ISNULL(@Reason, ''))), '');
    SET @StockItemNo = LTRIM(RTRIM(ISNULL(@StockItemNo, '')));

    IF @Reason IS NULL
        THROW 51000, 'AGORA:REASON_REQUIRED:A reversal has to say why.', 1;

    IF NOT EXISTS (SELECT 1 FROM agora.StockReconRun WHERE BranchId = @BranchId AND Id = @RunId)
        THROW 51000, 'AGORA:NO_RUN:That run does not exist for this branch.', 1;

    DECLARE @OwnTran BIT = CASE WHEN @@TRANCOUNT = 0 THEN 1 ELSE 0 END;
    IF @OwnTran = 1 BEGIN TRANSACTION;

    DECLARE @Status NVARCHAR(20);
    SELECT @Status = [Status]
    FROM agora.StockReconRun WITH (UPDLOCK, HOLDLOCK)
    WHERE BranchId = @BranchId AND Id = @RunId;

    IF NOT EXISTS (SELECT 1 FROM agora.StockReconAmendment
                   WHERE BranchId = @BranchId AND RunId = @RunId AND [State] = 'active'
                     AND AreaNo = @AreaNo AND StockItemNo = @StockItemNo)
        THROW 51000, 'AGORA:NOTHING_TO_REVERSE:This item has no amendment left standing on this run.', 1;

    /* An issue the commit wrote goes back only over the row exactly as the
       commit left it: the issue and the two counts. */
    DECLARE @Moved NVARCHAR(2048) = (
        SELECT TOP 1 CONCAT(N'AGORA:ISSUE_MOVED:', ISNULL(rl.ItemDescription, a.StockItemNo),
                            N' on ', CONVERT(char(10), a.TransactionDate, 23), N' shift ', a.ShiftNo,
                            CASE WHEN l.StockItemNo IS NULL
                                 THEN N' is no longer in PumpIT'
                                 ELSE CONCAT(N' has changed since this run changed its issue (it now holds issue ',
                                             FORMAT(CONVERT(decimal(18,3), ISNULL(l.QtyIssued, 0)), '0.000'),
                                             N', opening ', FORMAT(CONVERT(decimal(18,3), ISNULL(l.QtyOpen, 0)), '0.000'),
                                             N', closing ', FORMAT(CONVERT(decimal(18,3), ISNULL(l.QtyClose, 0)), '0.000'), N')') END,
                            N'. Putting the old issue back would write over that change, so nothing on this item was reversed. Correct it in PumpIT first.')
        FROM agora.StockReconAmendment a
        LEFT JOIN agora.StockReconRunLine rl
               ON rl.BranchId = a.BranchId AND rl.Id = a.RunLineId
        LEFT JOIN [PumpIT].dbo.STK_StockReconLine l
               ON l.SSBranchId = a.BranchId
              AND CONVERT(date, l.TransactionDate) = a.TransactionDate
              AND l.ShiftNo = a.ShiftNo AND l.AreaNo = a.AreaNo AND l.StockItemNo = a.StockItemNo
        WHERE a.BranchId = @BranchId AND a.RunId = @RunId
          AND a.AreaNo = @AreaNo AND a.StockItemNo = @StockItemNo
          AND a.[State] = 'active' AND a.StampMode = 'live'
          AND a.NewQtyIssued IS NOT NULL
          AND (   l.StockItemNo IS NULL
               OR ABS(CONVERT(decimal(18,4), ISNULL(l.QtyIssued, 0)) - a.NewQtyIssued) > 0.005
               OR ABS(CONVERT(decimal(18,4), ISNULL(l.QtyOpen,   0)) - a.NewQtyOpen)   > 0.005
               OR ABS(CONVERT(decimal(18,4), ISNULL(l.QtyClose,  0)) - a.NewQtyClose)  > 0.005)
        ORDER BY a.TransactionDate, a.ShiftNo);

    IF @Moved IS NOT NULL
        THROW 51000, @Moved, 1;

    /* A count the commit amended, the same way. */
    SET @Moved = (
        SELECT TOP 1 CONCAT(N'AGORA:COUNT_MOVED:', ISNULL(rl.ItemDescription, a.StockItemNo),
                            N' on ', CONVERT(char(10), a.TransactionDate, 23), N' shift ', a.ShiftNo,
                            CASE WHEN l.StockItemNo IS NULL
                                 THEN N' is no longer in PumpIT'
                                 ELSE CONCAT(N' has changed since this run amended its counts (it now holds opening ',
                                             FORMAT(CONVERT(decimal(18,3), ISNULL(l.QtyOpen, 0)), '0.000'),
                                             N', closing ', FORMAT(CONVERT(decimal(18,3), ISNULL(l.QtyClose, 0)), '0.000'),
                                             N'; the run left opening ', FORMAT(a.NewQtyOpen, '0.000'),
                                             N', closing ', FORMAT(a.NewQtyClose, '0.000'), N')') END,
                            N'. Putting the old counts back would write over that change, so nothing on this item was reversed. Correct it in PumpIT first.')
        FROM agora.StockReconAmendment a
        LEFT JOIN agora.StockReconRunLine rl
               ON rl.BranchId = a.BranchId AND rl.Id = a.RunLineId
        LEFT JOIN [PumpIT].dbo.STK_StockReconLine l
               ON l.SSBranchId = a.BranchId
              AND CONVERT(date, l.TransactionDate) = a.TransactionDate
              AND l.ShiftNo = a.ShiftNo AND l.AreaNo = a.AreaNo AND l.StockItemNo = a.StockItemNo
        WHERE a.BranchId = @BranchId AND a.RunId = @RunId
          AND a.AreaNo = @AreaNo AND a.StockItemNo = @StockItemNo
          AND a.[State] = 'active' AND a.StampMode = 'live'
          AND (   l.StockItemNo IS NULL
               OR ABS(CONVERT(decimal(18,4), ISNULL(l.QtyOpen,  0)) - a.NewQtyOpen)  > 0.005
               OR ABS(CONVERT(decimal(18,4), ISNULL(l.QtyClose, 0)) - a.NewQtyClose) > 0.005)
        ORDER BY a.TransactionDate, a.ShiftNo);

    IF @Moved IS NOT NULL
        THROW 51000, @Moved, 1;

    /* And a POS a reset put back. */
    SET @Moved = (
        SELECT TOP 1 CONCAT(N'AGORA:POS_MOVED:', ISNULL(rl.ItemDescription, a.StockItemNo),
                            N' on ', CONVERT(char(10), a.TransactionDate, 23), N' shift ', a.ShiftNo,
                            CASE WHEN l.StockItemNo IS NULL
                                 THEN N' is no longer in PumpIT'
                                 ELSE CONCAT(N' has changed since this run put its POS back (it now holds POS ',
                                             FORMAT(CONVERT(decimal(18,3), ISNULL(l.QtyComputer, 0)), '0.000'),
                                             N'; the run left ', FORMAT(a.NewQtyPOS, '0.000'), N')') END,
                            N'. Putting the old POS back would write over that change, so nothing on this item was reversed. Correct it in PumpIT first.')
        FROM agora.StockReconAmendment a
        LEFT JOIN agora.StockReconRunLine rl
               ON rl.BranchId = a.BranchId AND rl.Id = a.RunLineId
        LEFT JOIN [PumpIT].dbo.STK_StockReconLine l
               ON l.SSBranchId = a.BranchId
              AND CONVERT(date, l.TransactionDate) = a.TransactionDate
              AND l.ShiftNo = a.ShiftNo AND l.AreaNo = a.AreaNo AND l.StockItemNo = a.StockItemNo
        WHERE a.BranchId = @BranchId AND a.RunId = @RunId
          AND a.AreaNo = @AreaNo AND a.StockItemNo = @StockItemNo
          AND a.[State] = 'active' AND a.StampMode = 'live'
          AND a.NewQtyPOS IS NOT NULL
          AND (   l.StockItemNo IS NULL
               OR ABS(CONVERT(decimal(18,4), ISNULL(l.QtyComputer, 0)) - a.NewQtyPOS) > 0.005)
        ORDER BY a.TransactionDate, a.ShiftNo);

    IF @Moved IS NOT NULL
        THROW 51000, @Moved, 1;

    /* The live half first, as the run-level reversal does. Only the amendments
       the commit wrote to PumpIT; the issue and the POS only where it wrote
       them. */
    UPDATE l
       SET l.QtyOpen     = a.PriorQtyOpen,
           l.QtyClose    = a.PriorQtyClose,
           l.QtyIssued   = CASE WHEN a.NewQtyIssued IS NOT NULL THEN a.PriorQtyIssued ELSE l.QtyIssued END,
           l.QtyComputer = CASE WHEN a.NewQtyPOS IS NOT NULL THEN a.PriorQtyPOS ELSE l.QtyComputer END
    FROM [PumpIT].dbo.STK_StockReconLine l
    JOIN agora.StockReconAmendment a
      ON l.SSBranchId = a.BranchId
     AND CONVERT(date, l.TransactionDate) = a.TransactionDate
     AND l.ShiftNo = a.ShiftNo AND l.AreaNo = a.AreaNo AND l.StockItemNo = a.StockItemNo
    WHERE a.BranchId = @BranchId AND a.RunId = @RunId
      AND a.AreaNo = @AreaNo AND a.StockItemNo = @StockItemNo
      AND a.[State] = 'active' AND a.StampMode = 'live';

    DECLARE @Reversed INT;

    UPDATE agora.StockReconAmendment
       SET [State]        = 'reversed',
           ReversedAt     = SYSUTCDATETIME(),
           ReversedBy     = @UserId,
           ReversalReason = @Reason,
           UpdatedAt      = SYSUTCDATETIME(),
           UpdatedBy      = @UserId
    WHERE BranchId = @BranchId AND RunId = @RunId AND [State] = 'active'
      AND AreaNo = @AreaNo AND StockItemNo = @StockItemNo;

    SET @Reversed = @@ROWCOUNT;

    /* This chain's proposals go back to pending; every other chain's stay
       committed. */
    UPDATE agora.StockReconRunLine
       SET CommitState = 'pending',
           BlockReason = NULL,
           UpdatedAt   = SYSUTCDATETIME(),
           UpdatedBy   = @UserId
    WHERE BranchId = @BranchId AND RunId = @RunId AND CommitState = 'committed'
      AND AreaNo = @AreaNo AND StockItemNo = @StockItemNo;

    DECLARE @Left INT = (SELECT COUNT(*) FROM agora.StockReconAmendment
                         WHERE BranchId = @BranchId AND RunId = @RunId AND [State] = 'active');

    /* Nothing left standing: the run is reversed, as the run-level reversal
       leaves it. Otherwise it stays committed. */
    IF @Left = 0
        UPDATE agora.StockReconRun
           SET [Status]        = 'reversed',
               ReversedAt      = SYSUTCDATETIME(),
               ReversedBy      = @UserId,
               ReversalReason  = @Reason,
               UpdatedAt       = SYSUTCDATETIME(),
               UpdatedBy       = @UserId
        WHERE BranchId = @BranchId AND Id = @RunId;

    DECLARE @Item NVARCHAR(200) = ISNULL((SELECT TOP 1 ItemDescription FROM agora.StockReconRunLine
                                          WHERE BranchId = @BranchId AND RunId = @RunId
                                            AND AreaNo = @AreaNo AND StockItemNo = @StockItemNo
                                            AND ItemDescription IS NOT NULL), @StockItemNo);

    IF @OwnTran = 1 COMMIT TRANSACTION;

    SELECT CONVERT(bit, 1) AS Ok,
           'REVERSED'      AS Code,
           CONVERT(nvarchar(400), CONCAT(@Reversed, N' amendment(s) on ', @Item, N' put back to what they held before. ',
                  CASE WHEN @Left = 0
                       THEN N'Nothing else on this run was standing, so the run is reversed.'
                       ELSE CONCAT(@Left, N' amendment(s) on other items stay posted.') END)) AS [Message],
           CONVERT(bigint, @RunId) AS Id;
END
