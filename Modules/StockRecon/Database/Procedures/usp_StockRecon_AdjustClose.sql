/*
    agora.usp_StockRecon_AdjustClose — one shift's proposed closing, set by hand
    with a reason (Ryan, 16 Nov 2026: "per detail row ... manually adjust ... a
    pencil ... a modal which asks for a reason").

    Since the same afternoon the opening and the issue can be set by hand too
    ("want to be able to adjust opening, closing and issue values... not just
    closing"), so the work is agora.usp_StockRecon_AdjustShift's, which has the
    whole contract. This is that procedure with the closing alone, kept for
    the callers and tests written against it: BAD_CLOSE for a negative
    closing, then everything AdjustShift refuses.

    Parameters: @BranchId, @RunId, @RunLineId, @NewClose, @Reason, @UserId
    Result:     the status row (Ok, Code, Message, Id = the adjustment, Ids).
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

    IF @NewClose IS NULL OR @NewClose < 0
        THROW 51000, 'AGORA:BAD_CLOSE:The closing count must be zero or more.', 1;

    EXEC agora.usp_StockRecon_AdjustShift
        @BranchId = @BranchId, @RunId = @RunId, @RunLineId = @RunLineId,
        @NewOpen = NULL, @NewIssued = NULL, @NewClose = @NewClose,
        @Reason = @Reason, @UserId = @UserId;
END
