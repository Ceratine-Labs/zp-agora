/* ============================================================================
   agora.usp_Cash_ReopenCashup

   WHAT IT IS FOR
   Undoing a manager's close (Ryan, 27 Sep 2026: whoever holds
   cash.cashup.close may reopen a closed cashup, with a required reason). A
   closed cashup refuses saves and confirmations; reopening puts it back to
   'open', so it can be corrected, confirmed again and closed again.

   Writes Agora's database only — the legacy header has no close column:
     agora.Cashup       Status 'open', ReopenedAt / ReopenedBy / ReopenReason.
                        The last confirmation and close are KEPT on the row
                        (ConfirmedAt, ClosedAt …) so it still says what was
                        agreed and signed off before; Status is what counts.
     agora.CashupEvent  one 'reopened' row, with the figures now and the reason

   NOTHING IN PUMPIT IS WRITTEN.

   Every timestamp is SYSUTCDATETIME().

   Refusals:
     AGORA:CASH_CASHUP_USER        no user
     AGORA:CASH_CASHUP_KEY         site, day, till and shift are all required
     AGORA:CASH_CASHUP_REASON      no reason — a reopening always says why
     AGORA:CASH_CASHUP_NOT_CLOSED  only a closed cashup is reopened
   ============================================================================ */
CREATE OR ALTER PROCEDURE [agora].[usp_Cash_ReopenCashup]
    @BranchId         INT,
    @TransactionDate  DATE,
    @TillNo           INT,
    @ShiftNo          INT,
    @Reason           NVARCHAR(400) = NULL,
    @UserId           INT
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    SET @Reason = NULLIF(LTRIM(RTRIM(@Reason)), '');

    IF @UserId IS NULL
        THROW 51000, 'AGORA:CASH_CASHUP_USER:A cashup is reopened by a person, and none was given.', 1;
    IF @BranchId IS NULL OR @TransactionDate IS NULL OR @TillNo IS NULL OR @ShiftNo IS NULL
        THROW 51000, 'AGORA:CASH_CASHUP_KEY:A cashup is one site, one day, one till and one shift. Give all four.', 1;
    IF @Reason IS NULL
        THROW 51000, 'AGORA:CASH_CASHUP_REASON:Say why the cashup is being reopened. A signed-off cashup is reopened with a reason, always.', 1;

    DECLARE @At DATETIME = @TransactionDate;
    DECLARE @Now DATETIME2(0) = SYSUTCDATETIME();
    DECLARE @Id BIGINT, @State NVARCHAR(12);
    DECLARE @ZRead MONEY, @Actual MONEY, @Variance MONEY;

    SELECT @ZRead = t.ZRead, @Actual = t.ActualTotal, @Variance = t.Variance
    FROM agora.vw_CashupTotals t
    WHERE t.BranchId = @BranchId AND t.TillNo = @TillNo AND t.ShiftNo = @ShiftNo AND t.TransactionAt = @At;

    BEGIN TRY
        BEGIN TRANSACTION;

        SELECT @Id = c.Id, @State = c.[Status]
        FROM agora.Cashup c WITH (UPDLOCK, HOLDLOCK)
        WHERE c.BranchId = @BranchId AND c.TransactionDate = @TransactionDate AND c.TillNo = @TillNo AND c.ShiftNo = @ShiftNo;

        IF ISNULL(@State, '') <> 'closed'
            THROW 51000, 'AGORA:CASH_CASHUP_NOT_CLOSED:Only a closed cashup is reopened. This one is not closed.', 1;

        UPDATE agora.Cashup
           SET [Status] = 'open', ReopenedAt = @Now, ReopenedBy = @UserId, ReopenReason = @Reason,
               UpdatedAt = @Now, UpdatedBy = @UserId
         WHERE BranchId = @BranchId AND Id = @Id;

        INSERT INTO agora.CashupEvent (BranchId, CashupId, TransactionDate, TillNo, ShiftNo, [Event],
                                       ZRead, ActualTotal, Variance, Note, CreatedAt, CreatedBy)
        VALUES (@BranchId, @Id, @TransactionDate, @TillNo, @ShiftNo, 'reopened',
                @ZRead, @Actual, @Variance, @Reason, @Now, @UserId);

        COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        THROW;
    END CATCH;

    SELECT
        CAST(1 AS BIT)            AS Ok,
        'CASH_CASHUP_REOPENED'    AS Code,
        CONVERT(NVARCHAR(400), 'Reopened. It can be corrected, confirmed again and closed again.') AS Message,
        @Id                       AS Id;
END
