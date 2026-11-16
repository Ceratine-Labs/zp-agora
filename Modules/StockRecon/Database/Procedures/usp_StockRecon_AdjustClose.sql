/*
    agora.usp_StockRecon_AdjustClose — one shift's proposed closing, set by hand
    with a reason (Ryan, 16 Nov 2026: "per detail row ... manually adjust ... a
    pencil ... a modal which asks for a reason").

    It changes the RUN, never PumpIT: the shift's QtyCloseNew, and the next
    shift's QtyOpenNew with it, so the chain still joins. Both rows are ticked
    and marked with the adjustment; the Amend press writes them through
    usp_StockRecon_Commit like any other amendment, priors recorded, reversible.
    Each variance moves by exactly the change, so whatever else the preview
    put on the row (a removed duplicate, a captured issue, waste) stands.

    Refused: a run that is not previewed, a row already written, a chain a
    possible duplicate holds back, the last shift of a chain (its closing is
    the next count's opening, outside this run), a negative closing, and an
    adjustment without a reason. A chain held back for any other reason is
    released by a person's adjustment: they have said what the closing is.

    Parameters: @BranchId, @RunId, @RunLineId, @NewClose, @Reason, @UserId
    Result:     the status row (Ok, Code, Message, Id = the adjustment).
*/
CREATE OR ALTER PROCEDURE agora.usp_StockRecon_AdjustClose
    @BranchId  INT,
    @RunId     BIGINT,
    @RunLineId BIGINT,
    @NewClose  DECIMAL(18,4),
    @Reason    NVARCHAR(500),
    @UserId    INT
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE @tol DECIMAL(18,4) = 0.0005;
    SET @Reason = LTRIM(RTRIM(ISNULL(@Reason, N'')));

    IF @NewClose IS NULL OR @NewClose < 0
        THROW 51000, 'AGORA:BAD_CLOSE:The closing count must be zero or more.', 1;
    IF LEN(@Reason) < 5
        THROW 51000, 'AGORA:REASON_REQUIRED:Say why the closing is being changed, in at least five characters.', 1;

    BEGIN TRANSACTION;

    DECLARE @Status VARCHAR(20);
    SELECT @Status = r.[Status]
    FROM agora.StockReconRun r WITH (UPDLOCK, HOLDLOCK)
    WHERE r.BranchId = @BranchId AND r.Id = @RunId;

    IF @Status IS NULL
        THROW 51000, 'AGORA:NO_RUN:That run does not exist for this branch.', 1;
    IF @Status <> 'previewed'
        THROW 51000, 'AGORA:RUN_NOT_OPEN:Only a previewed run can be adjusted. Preview the period again to adjust it.', 1;

    DECLARE @Area INT, @Item NVARCHAR(10), @Seg INT, @Date DATE, @Shift INT,
            @Counted DECIMAL(18,4), @Prior DECIMAL(18,4), @DupHold BIT, @State VARCHAR(20);

    SELECT @Area = l.AreaNo, @Item = l.StockItemNo, @Seg = l.ContinuitySegment,
           @Date = l.TransactionDate, @Shift = l.ShiftNo,
           @Counted = l.QtyClose, @Prior = l.QtyCloseNew, @DupHold = l.DupHold, @State = l.CommitState
    FROM agora.StockReconRunLine l WITH (UPDLOCK)
    WHERE l.BranchId = @BranchId AND l.RunId = @RunId AND l.Id = @RunLineId;

    IF @Area IS NULL
        THROW 51000, 'AGORA:NO_LINE:That shift is not on this run.', 1;
    IF @State <> 'pending'
        THROW 51000, 'AGORA:LINE_WRITTEN:This shift has already been written. Reverse it first.', 1;
    IF @DupHold = 1
        THROW 51000, 'AGORA:DUP_HOLD:A possible duplicate issue holds this chain back. Release it first.', 1;

    DECLARE @NextId BIGINT;
    SELECT TOP 1 @NextId = n.Id
    FROM agora.StockReconRunLine n WITH (UPDLOCK)
    WHERE n.BranchId = @BranchId AND n.RunId = @RunId
      AND n.AreaNo = @Area AND n.StockItemNo = @Item
      AND (n.ContinuitySegment = @Seg OR (n.ContinuitySegment IS NULL AND @Seg IS NULL))
      AND (n.TransactionDate > @Date OR (n.TransactionDate = @Date AND n.ShiftNo > @Shift))
    ORDER BY n.TransactionDate, n.ShiftNo;

    IF @NextId IS NULL
        THROW 51000, 'AGORA:LAST_SHIFT:This is the last shift of the chain: its closing is the next count''s opening, outside this run. Adjust an earlier shift, or preview a longer period.', 1;

    DECLARE @Delta DECIMAL(18,4) = @NewClose - @Prior;
    DECLARE @Adj TABLE (Id BIGINT);

    INSERT INTO agora.StockReconManualAdjustment
        (BranchId, RunId, RunLineId, NextRunLineId, AreaNo, StockItemNo, TransactionDate, ShiftNo,
         CountedClose, PriorCloseNew, NewClose, Reason, CreatedAt, CreatedBy)
    OUTPUT inserted.Id INTO @Adj
    VALUES (@BranchId, @RunId, @RunLineId, @NextId, @Area, @Item, @Date, @Shift,
            @Counted, @Prior, @NewClose, @Reason, SYSUTCDATETIME(), @UserId);

    DECLARE @AdjId BIGINT = (SELECT Id FROM @Adj);

    -- The shift: its closing, and its variance by exactly the change.
    UPDATE l
       SET QtyCloseNew = @NewClose,
           AmendClose = @NewClose - l.QtyClose,
           QtyVarNew = l.QtyVarNew + @Delta,
           ManualAdjustmentId = @AdjId,
           WouldAmend = CASE WHEN ABS(@NewClose - l.QtyCloseNow) > @tol OR ABS(l.QtyOpenNew - l.QtyOpenNow) > @tol
                             THEN 1 ELSE l.WouldAmend END,
           CountsMove = CASE WHEN ABS(@NewClose - l.QtyCloseNow) > @tol OR ABS(l.QtyOpenNew - l.QtyOpenNow) > @tol
                             THEN 1 ELSE l.CountsMove END,
           Selected = 1,
           Outcome = LEFT(N'Adjusted by hand: ' + @Reason, 80)
    FROM agora.StockReconRunLine l
    WHERE l.BranchId = @BranchId AND l.RunId = @RunId AND l.Id = @RunLineId;

    -- The next shift: its opening follows, its variance the other way.
    UPDATE n
       SET QtyOpenNew = @NewClose,
           QtyVarNew = n.QtyVarNew - @Delta,
           ManualAdjustmentId = @AdjId,
           WouldAmend = CASE WHEN ABS(@NewClose - n.QtyOpenNow) > @tol OR ABS(n.QtyCloseNew - n.QtyCloseNow) > @tol
                             THEN 1 ELSE n.WouldAmend END,
           CountsMove = CASE WHEN ABS(@NewClose - n.QtyOpenNow) > @tol OR ABS(n.QtyCloseNew - n.QtyCloseNow) > @tol
                             THEN 1 ELSE n.CountsMove END,
           Selected = 1,
           Outcome = N'Opening follows the closing adjusted by hand'
    FROM agora.StockReconRunLine n
    WHERE n.BranchId = @BranchId AND n.RunId = @RunId AND n.Id = @NextId;

    -- A person has said what the closing is: the chain is no longer held.
    UPDATE agora.StockReconRunLine
       SET ChainBlocked = 0
    WHERE BranchId = @BranchId AND RunId = @RunId
      AND AreaNo = @Area AND StockItemNo = @Item
      AND (ContinuitySegment = @Seg OR (ContinuitySegment IS NULL AND @Seg IS NULL))
      AND ChainBlocked = 1;

    COMMIT TRANSACTION;

    SELECT CONVERT(bit, 1) AS Ok,
           'ADJUSTED' AS Code,
           N'Closing for ' + FORMAT(@Date, 'd MMM') + N' shift ' + CONVERT(nvarchar(4), @Shift)
             + N' set to ' + FORMAT(@NewClose, '0.###') + N' by hand, and the next shift opens at it. '
             + N'Both are ticked: press Amend to write them to PumpIT.' AS Message,
           @AdjId AS Id;
END
