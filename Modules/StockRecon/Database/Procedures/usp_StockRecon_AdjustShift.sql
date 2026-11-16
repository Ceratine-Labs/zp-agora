/*
    agora.usp_StockRecon_AdjustShift — one shift's proposed opening, issue
    and/or closing, set by hand with a reason (Ryan, 16 Nov 2026: "want to be
    able to adjust opening, closing and issue values... not just closing").
    usp_StockRecon_AdjustClose (the closing only, the same morning) now calls
    this with the closing alone.

    It changes the RUN, never PumpIT. The Amend press writes the rows through
    usp_StockRecon_Commit like any other amendment, priors recorded,
    reversible. Each value given that differs from what the run proposes is
    one adjustment row in agora.StockReconManualAdjustment ([Field] 'open',
    'issue' or 'close'; CountedClose / PriorCloseNew / NewClose hold that
    field's counted, proposed and new value; NextRunLineId is the other row it
    moved), applied in shift order — opening, issue, closing:

      * the OPENING moves the previous shift's closing with it, so the chain
        still joins (Open(i) = Close(i-1)): this shift's variance down by the
        change, the previous shift's up. Not on the chain's first shift in the
        run: its opening is the closing of a count outside this run.
      * the ISSUE changes this shift only: the variance moves the other way by
        the change (more issued, more expected on the shelf). Set back to what
        PumpIT holds, the issue is no longer changed at all.
      * the CLOSING moves the next shift's opening with it, the variance as
        the opening's in reverse. Not on the chain's last shift in the run.

    Every row it touches is ticked and marked with the adjustment, and a chain
    held back for any reason but a possible duplicate is released: a person
    has said what the figures are.

    Refused: a run that is not previewed, a row already written, a chain a
    possible duplicate holds back, a negative value, nothing changed, the
    first shift's opening, the last shift's closing, and no reason.

    Parameters: @BranchId, @RunId, @RunLineId, @NewOpen, @NewIssued, @NewClose
                (NULL = leave as proposed), @Reason, @UserId
    Result:     the status row (Ok, Code, Message, Id = the last adjustment,
                Ids = every adjustment made, comma separated).
*/
CREATE OR ALTER PROCEDURE agora.usp_StockRecon_AdjustShift
    @BranchId  INT,
    @RunId     BIGINT,
    @RunLineId BIGINT,
    @NewOpen   DECIMAL(18,4) = NULL,
    @NewIssued DECIMAL(18,4) = NULL,
    @NewClose  DECIMAL(18,4) = NULL,
    @Reason    NVARCHAR(500),
    @UserId    INT
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE @tol DECIMAL(18,4) = 0.0005;
    SET @Reason = LTRIM(RTRIM(ISNULL(@Reason, N'')));

    IF @NewOpen < 0 OR @NewIssued < 0 OR @NewClose < 0
        THROW 51000, 'AGORA:BAD_VALUE:An opening, issue or closing must be zero or more.', 1;
    IF LEN(@Reason) < 5
        THROW 51000, 'AGORA:REASON_REQUIRED:Say why the shift is being changed, in at least five characters.', 1;

    DECLARE @OwnTran BIT = CASE WHEN @@TRANCOUNT = 0 THEN 1 ELSE 0 END;
    IF @OwnTran = 1 BEGIN TRANSACTION;

    DECLARE @Status VARCHAR(20);
    SELECT @Status = r.[Status]
    FROM agora.StockReconRun r WITH (UPDLOCK, HOLDLOCK)
    WHERE r.BranchId = @BranchId AND r.Id = @RunId;

    IF @Status IS NULL
        THROW 51000, 'AGORA:NO_RUN:That run does not exist for this branch.', 1;
    IF @Status <> 'previewed'
        THROW 51000, 'AGORA:RUN_NOT_OPEN:Only a previewed run can be adjusted. Preview the period again to adjust it.', 1;

    DECLARE @Area INT, @Item NVARCHAR(10), @Seg INT, @Date DATE, @Shift INT,
            @DupHold BIT, @State VARCHAR(20),
            @CountedOpen DECIMAL(18,4), @CountedIssued DECIMAL(18,4), @CountedClose DECIMAL(18,4),
            @PriorOpen DECIMAL(18,4), @PriorIssued DECIMAL(18,4), @PriorClose DECIMAL(18,4);

    SELECT @Area = l.AreaNo, @Item = l.StockItemNo, @Seg = l.ContinuitySegment,
           @Date = l.TransactionDate, @Shift = l.ShiftNo,
           @DupHold = l.DupHold, @State = l.CommitState,
           @CountedOpen = l.QtyOpen, @CountedIssued = l.QtyIssued, @CountedClose = l.QtyClose,
           @PriorOpen = l.QtyOpenNew, @PriorIssued = ISNULL(l.QtyIssuedNew, l.QtyIssued), @PriorClose = l.QtyCloseNew
    FROM agora.StockReconRunLine l WITH (UPDLOCK)
    WHERE l.BranchId = @BranchId AND l.RunId = @RunId AND l.Id = @RunLineId;

    IF @Area IS NULL
        THROW 51000, 'AGORA:NO_LINE:That shift is not on this run.', 1;
    IF @State <> 'pending'
        THROW 51000, 'AGORA:LINE_WRITTEN:This shift has already been written. Reverse it first.', 1;
    IF @DupHold = 1
        THROW 51000, 'AGORA:DUP_HOLD:A possible duplicate issue holds this chain back. Release it first.', 1;

    -- Only what actually changes.
    IF ABS(ISNULL(@NewOpen, @PriorOpen) - @PriorOpen) <= @tol SET @NewOpen = NULL;
    IF ABS(ISNULL(@NewIssued, @PriorIssued) - @PriorIssued) <= @tol SET @NewIssued = NULL;
    IF ABS(ISNULL(@NewClose, @PriorClose) - @PriorClose) <= @tol SET @NewClose = NULL;

    IF @NewOpen IS NULL AND @NewIssued IS NULL AND @NewClose IS NULL
        THROW 51000, 'AGORA:NOTHING_CHANGED:The opening, issue and closing are what the run already proposes.', 1;

    DECLARE @PrevId BIGINT, @NextId BIGINT;
    SELECT TOP 1 @PrevId = p.Id
    FROM agora.StockReconRunLine p WITH (UPDLOCK)
    WHERE p.BranchId = @BranchId AND p.RunId = @RunId
      AND p.AreaNo = @Area AND p.StockItemNo = @Item
      AND (p.ContinuitySegment = @Seg OR (p.ContinuitySegment IS NULL AND @Seg IS NULL))
      AND (p.TransactionDate < @Date OR (p.TransactionDate = @Date AND p.ShiftNo < @Shift))
    ORDER BY p.TransactionDate DESC, p.ShiftNo DESC;

    SELECT TOP 1 @NextId = n.Id
    FROM agora.StockReconRunLine n WITH (UPDLOCK)
    WHERE n.BranchId = @BranchId AND n.RunId = @RunId
      AND n.AreaNo = @Area AND n.StockItemNo = @Item
      AND (n.ContinuitySegment = @Seg OR (n.ContinuitySegment IS NULL AND @Seg IS NULL))
      AND (n.TransactionDate > @Date OR (n.TransactionDate = @Date AND n.ShiftNo > @Shift))
    ORDER BY n.TransactionDate, n.ShiftNo;

    IF @NewOpen IS NOT NULL AND @PrevId IS NULL
        THROW 51000, 'AGORA:FIRST_SHIFT:This is the first shift of the chain: its opening is the closing of a count outside this run. Adjust a later shift, or preview a longer period.', 1;
    IF @NewClose IS NOT NULL AND @NextId IS NULL
        THROW 51000, 'AGORA:LAST_SHIFT:This is the last shift of the chain: its closing is the next count''s opening, outside this run. Adjust an earlier shift, or preview a longer period.', 1;

    DECLARE @Adj TABLE (Id BIGINT, Field VARCHAR(10));
    DECLARE @Outcome NVARCHAR(80) = LEFT(N'Adjusted by hand: ' + @Reason, 80);
    DECLARE @Delta DECIMAL(18,4);

    /* ----------------------------------------------------------- the opening
       The previous shift's closing moves with it. */
    IF @NewOpen IS NOT NULL
    BEGIN
        SET @Delta = @NewOpen - @PriorOpen;

        INSERT INTO agora.StockReconManualAdjustment
            (BranchId, RunId, RunLineId, NextRunLineId, [Field], AreaNo, StockItemNo, TransactionDate, ShiftNo,
             CountedClose, PriorCloseNew, NewClose, Reason, CreatedAt, CreatedBy)
        OUTPUT inserted.Id, inserted.[Field] INTO @Adj
        VALUES (@BranchId, @RunId, @RunLineId, @PrevId, 'open', @Area, @Item, @Date, @Shift,
                @CountedOpen, @PriorOpen, @NewOpen, @Reason, SYSUTCDATETIME(), @UserId);

        UPDATE l
           SET QtyOpenNew = @NewOpen,
               QtyVarNew = l.QtyVarNew - @Delta,
               ManualAdjustmentId = (SELECT MAX(Id) FROM @Adj),
               Selected = 1,
               Outcome = @Outcome
        FROM agora.StockReconRunLine l
        WHERE l.BranchId = @BranchId AND l.RunId = @RunId AND l.Id = @RunLineId;

        UPDATE p
           SET QtyCloseNew = @NewOpen,
               AmendClose = @NewOpen - p.QtyClose,
               QtyVarNew = p.QtyVarNew + @Delta,
               ManualAdjustmentId = (SELECT MAX(Id) FROM @Adj),
               Selected = 1,
               Outcome = N'Closing follows the opening adjusted by hand'
        FROM agora.StockReconRunLine p
        WHERE p.BranchId = @BranchId AND p.RunId = @RunId AND p.Id = @PrevId;
    END

    /* ------------------------------------------------------------- the issue
       This shift only. Back at what PumpIT holds, the issue is not changed. */
    IF @NewIssued IS NOT NULL
    BEGIN
        SET @Delta = @NewIssued - @PriorIssued;

        INSERT INTO agora.StockReconManualAdjustment
            (BranchId, RunId, RunLineId, NextRunLineId, [Field], AreaNo, StockItemNo, TransactionDate, ShiftNo,
             CountedClose, PriorCloseNew, NewClose, Reason, CreatedAt, CreatedBy)
        OUTPUT inserted.Id, inserted.[Field] INTO @Adj
        VALUES (@BranchId, @RunId, @RunLineId, NULL, 'issue', @Area, @Item, @Date, @Shift,
                @CountedIssued, @PriorIssued, @NewIssued, @Reason, SYSUTCDATETIME(), @UserId);

        UPDATE l
           SET QtyIssuedNew = CASE WHEN ABS(@NewIssued - l.QtyIssued) <= @tol THEN NULL ELSE @NewIssued END,
               QtyVarNew = l.QtyVarNew - @Delta,
               ManualAdjustmentId = (SELECT MAX(Id) FROM @Adj),
               Selected = 1,
               Outcome = @Outcome
        FROM agora.StockReconRunLine l
        WHERE l.BranchId = @BranchId AND l.RunId = @RunId AND l.Id = @RunLineId;
    END

    /* ----------------------------------------------------------- the closing
       The next shift's opening moves with it. */
    IF @NewClose IS NOT NULL
    BEGIN
        SET @Delta = @NewClose - @PriorClose;

        INSERT INTO agora.StockReconManualAdjustment
            (BranchId, RunId, RunLineId, NextRunLineId, [Field], AreaNo, StockItemNo, TransactionDate, ShiftNo,
             CountedClose, PriorCloseNew, NewClose, Reason, CreatedAt, CreatedBy)
        OUTPUT inserted.Id, inserted.[Field] INTO @Adj
        VALUES (@BranchId, @RunId, @RunLineId, @NextId, 'close', @Area, @Item, @Date, @Shift,
                @CountedClose, @PriorClose, @NewClose, @Reason, SYSUTCDATETIME(), @UserId);

        UPDATE l
           SET QtyCloseNew = @NewClose,
               AmendClose = @NewClose - l.QtyClose,
               QtyVarNew = l.QtyVarNew + @Delta,
               ManualAdjustmentId = (SELECT MAX(Id) FROM @Adj),
               Selected = 1,
               Outcome = @Outcome
        FROM agora.StockReconRunLine l
        WHERE l.BranchId = @BranchId AND l.RunId = @RunId AND l.Id = @RunLineId;

        UPDATE n
           SET QtyOpenNew = @NewClose,
               QtyVarNew = n.QtyVarNew - @Delta,
               ManualAdjustmentId = (SELECT MAX(Id) FROM @Adj),
               Selected = 1,
               Outcome = N'Opening follows the closing adjusted by hand'
        FROM agora.StockReconRunLine n
        WHERE n.BranchId = @BranchId AND n.RunId = @RunId AND n.Id = @NextId;
    END

    /* What each touched row now writes: counts or an issue that differ from
       what PumpIT holds. */
    UPDATE x
       SET WouldAmend = CASE WHEN ABS(x.QtyCloseNew - x.QtyCloseNow) > @tol OR ABS(x.QtyOpenNew - x.QtyOpenNow) > @tol
                                  OR (x.QtyIssuedNew IS NOT NULL AND ABS(x.QtyIssuedNew - x.QtyIssued) > @tol)
                             THEN 1 ELSE x.WouldAmend END,
           CountsMove = CASE WHEN ABS(x.QtyCloseNew - x.QtyCloseNow) > @tol OR ABS(x.QtyOpenNew - x.QtyOpenNow) > @tol
                             THEN 1 ELSE x.CountsMove END
    FROM agora.StockReconRunLine x
    WHERE x.BranchId = @BranchId AND x.RunId = @RunId AND x.Id IN (@RunLineId, ISNULL(@PrevId, -1), ISNULL(@NextId, -1))
      AND x.ManualAdjustmentId IN (SELECT Id FROM @Adj);

    -- A person has said what the figures are: the chain is no longer held.
    UPDATE agora.StockReconRunLine
       SET ChainBlocked = 0
    WHERE BranchId = @BranchId AND RunId = @RunId
      AND AreaNo = @Area AND StockItemNo = @Item
      AND (ContinuitySegment = @Seg OR (ContinuitySegment IS NULL AND @Seg IS NULL))
      AND ChainBlocked = 1;

    IF @OwnTran = 1 COMMIT TRANSACTION;

    DECLARE @What NVARCHAR(200) = (
        SELECT STRING_AGG(CASE a.Field WHEN 'open' THEN N'opening' WHEN 'issue' THEN N'issue' ELSE N'closing' END, N', ')
               WITHIN GROUP (ORDER BY a.Id)
        FROM @Adj a);

    SELECT CONVERT(bit, 1) AS Ok,
           'ADJUSTED' AS Code,
           N'The ' + @What + N' for ' + FORMAT(@Date, 'd MMM') + N' shift ' + CONVERT(nvarchar(4), @Shift)
             + N' set by hand'
             + CASE WHEN @NewOpen IS NOT NULL THEN N'; the shift before closes at the new opening' ELSE N'' END
             + CASE WHEN @NewClose IS NOT NULL THEN N'; the next shift opens at the new closing' ELSE N'' END
             + N'. Ticked: press Amend to write them to PumpIT.' AS Message,
           (SELECT MAX(Id) FROM @Adj) AS Id,
           (SELECT STRING_AGG(CONVERT(varchar(20), a.Id), ',') WITHIN GROUP (ORDER BY a.Id) FROM @Adj a) AS Ids;
END
