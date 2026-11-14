/*
    agora.usp_StockRecon_AmendedElsewhere — the shifts on a run that another
    run has already amended, and which run holds each (14 Nov 2026).

    Ryan, 14 Nov 2026, on run #70235 (Engen Manguzi, 26 Oct – 1 Nov): 58 of its
    81 items already carried amendments from runs #70157 and #70158, nothing
    on the screen said so, and its commit wrote 2 shifts — the rest were
    skipped as "already carries an amendment". "Show a badge ... omit the items
    that have been processed from row check and show the users that hey this
    was already run."

    One row per line of this run whose shift (area, item, day, shift) carries
    an active amendment from ANOTHER run, naming the latest such run. The run
    page badges the item, leaves those shifts unticked and out of the press,
    and says which run to reverse the item on to balance it here. The commit
    keeps its own refusal; this only says it first.

    Read-only.

    Parameters: @BranchId, @RunId
    Result: RunLineId, HeldByRunId
*/
CREATE OR ALTER PROCEDURE agora.usp_StockRecon_AmendedElsewhere
    @BranchId INT,
    @RunId    INT
AS
BEGIN
    SET NOCOUNT ON;

    IF NOT EXISTS (SELECT 1 FROM agora.StockReconRun r WHERE r.BranchId = @BranchId AND r.Id = @RunId)
        THROW 51000, 'AGORA:NOT_FOUND:That run is not on this site.', 1;

    SELECT rl.Id AS RunLineId,
           MAX(a.RunId) AS HeldByRunId
    FROM agora.StockReconRunLine rl
    JOIN agora.StockReconAmendment a
      ON a.BranchId = rl.BranchId
     AND a.AreaNo = rl.AreaNo AND a.StockItemNo = rl.StockItemNo
     AND a.TransactionDate = rl.TransactionDate AND a.ShiftNo = rl.ShiftNo
     AND a.State = 'active'
     AND a.RunId <> rl.RunId
    WHERE rl.BranchId = @BranchId AND rl.RunId = @RunId
    GROUP BY rl.Id;
END
