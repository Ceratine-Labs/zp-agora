/*
 * agora.usp_StockRecon_DiscardRuns — throw away previews.
 *
 * Read by:  Stock recon centre -> Runs -> Discard.
 * Writes:   agora.StockReconRunLine, agora.StockReconRun. Nothing else, ever.
 *
 * A PREVIEW is the record of a read and clearing it destroys nothing. A
 * COMMITTED run is the only record of what was amended, so it is refused —
 * and a REVERSED one is kept for the same reason: the reversal is the evidence
 * that the amendment happened and was undone.
 *
 * @RunId names one run; leaving it NULL clears every discardable run in scope,
 * optionally narrowed to one area. @UserId narrows to that person's own runs,
 * which is what the screen's button sends: a clerk clearing their morning's
 * attempts should not sweep away somebody else's.
 */
CREATE OR ALTER PROCEDURE [agora].[usp_StockRecon_DiscardRuns]
    @BranchId  INT,
    @AreaNo    INT = NULL,
    @RunId     INT = NULL,
    @UserId    INT = NULL,
    @MineOnly  BIT = 1
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    IF @RunId IS NOT NULL
    BEGIN
        DECLARE @Status NVARCHAR(20) = (
            SELECT [Status] FROM agora.StockReconRun WHERE BranchId = @BranchId AND Id = @RunId);

        IF @Status IS NULL
            THROW 51000, 'AGORA:NO_RUN:That run does not exist for this branch.', 1;

        IF @Status IN ('committed', 'reversed')
            THROW 51000, 'AGORA:RUN_COMMITTED:A committed run is the only record of what it amended and is never discarded.', 1;
    END

    DECLARE @Doomed TABLE (Id bigint PRIMARY KEY);

    INSERT INTO @Doomed (Id)
    SELECT r.Id
    FROM agora.StockReconRun r
    WHERE r.BranchId = @BranchId
      AND r.[Status] IN ('previewing', 'previewed', 'failed')
      AND (@RunId  IS NULL OR r.Id = @RunId)
      AND (@AreaNo IS NULL OR r.AreaNo = @AreaNo)
      AND (@MineOnly = 0 OR (@UserId IS NOT NULL AND r.CreatedBy = @UserId));

    DELETE rl FROM agora.StockReconRunLine rl
    JOIN @Doomed d ON d.Id = rl.RunId
    WHERE rl.BranchId = @BranchId;

    DECLARE @Gone INT;

    DELETE r FROM agora.StockReconRun r
    JOIN @Doomed d ON d.Id = r.Id
    WHERE r.BranchId = @BranchId;

    SET @Gone = @@ROWCOUNT;

    SELECT CONVERT(bit, 1) AS Ok,
           'DISCARDED'     AS Code,
           CONVERT(nvarchar(20), @Gone) + ' preview(s) discarded. Nothing in the customer''s databases was touched.' AS [Message],
           CONVERT(bigint, @Gone) AS Id;
END
