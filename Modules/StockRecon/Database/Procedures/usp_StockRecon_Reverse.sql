/*
 * agora.usp_StockRecon_Reverse — undo a commit, exactly and nothing else.
 *
 * Read by:  Stock recon centre -> a committed run -> Reverse.
 * Reads:    agora.StockReconAmendment, agora.StockReconRun
 * Writes:   agora.StockReconAmendment, agora.StockReconRunLine,
 *           agora.StockReconRun, and in 'live' mode the two counts on
 *           [PumpIT].dbo.STK_StockReconLine.
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

    /* The live half first, so a failure there leaves the ledger saying the
       amendment is still active — which is true, and is the safe direction to
       be wrong in. Only amendments the commit actually wrote to PumpIT. */
    UPDATE l
       SET l.QtyOpen  = a.PriorQtyOpen,
           l.QtyClose = a.PriorQtyClose
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
