/* ============================================================================
   agora.usp_Cash_CloseCashup

   WHAT IT IS FOR
   The manager's sign-off: a confirmed cashup is closed, and nothing moves it
   again — agora.usp_Cash_SaveCashupLeg and agora.usp_Cash_ConfirmCashup both
   refuse a closed cashup. Its own permission (cash.cashup.close), separate
   from confirming, because agreeing to the figures and signing them off are
   two people's acts.

   Writes Agora's database only — the legacy header has no close column:
     agora.Cashup       Status 'closed', ClosedAt / ClosedBy / CloseNote
     agora.CashupEvent  one 'closed' row, with the figures closed

   NOTHING IN PUMPIT IS WRITTEN.

   ONLY WHAT WAS CONFIRMED IS CLOSED. The cashup must be confirmed, and its
   figures now must be the figures confirmed — a leg that arrived or moved
   after the confirmation (a late card load, a recon adjustment in PumpIT)
   means the manager would be closing numbers nobody agreed to. That is
   refused until the cashup is confirmed again.

   Every timestamp is SYSUTCDATETIME().

   Refusals:
     AGORA:CASH_CASHUP_USER           no user
     AGORA:CASH_CASHUP_KEY            site, day, till and shift are all required
     AGORA:CASH_CASHUP_NOT_FOUND      PumpIT holds no cashup for that till and shift
     AGORA:CASH_CASHUP_CLOSED         already closed
     AGORA:CASH_CASHUP_NOT_CONFIRMED  never confirmed, or a save withdrew it
     AGORA:CASH_CASHUP_CHANGED        the figures moved since the confirmation
   ============================================================================ */
CREATE OR ALTER PROCEDURE [agora].[usp_Cash_CloseCashup]
    @BranchId         INT,
    @TransactionDate  DATE,
    @TillNo           INT,
    @ShiftNo          INT,
    @Note             NVARCHAR(400) = NULL,
    @UserId           INT
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    SET @Note = NULLIF(LTRIM(RTRIM(@Note)), '');

    IF @UserId IS NULL
        THROW 51000, 'AGORA:CASH_CASHUP_USER:A cashup is closed by a person, and none was given.', 1;
    IF @BranchId IS NULL OR @TransactionDate IS NULL OR @TillNo IS NULL OR @ShiftNo IS NULL
        THROW 51000, 'AGORA:CASH_CASHUP_KEY:A cashup is one site, one day, one till and one shift. Give all four.', 1;

    DECLARE @At DATETIME = @TransactionDate;
    DECLARE @Now DATETIME2(0) = SYSUTCDATETIME();
    DECLARE @ZRead MONEY, @Actual MONEY, @Variance MONEY, @Found BIT = 0;

    SELECT @ZRead = t.ZRead, @Actual = t.ActualTotal, @Variance = t.Variance, @Found = 1
    FROM agora.vw_CashupTotals t
    WHERE t.BranchId = @BranchId AND t.TillNo = @TillNo AND t.ShiftNo = @ShiftNo AND t.TransactionAt = @At;

    IF @Found = 0
        THROW 51000, 'AGORA:CASH_CASHUP_NOT_FOUND:PumpIT holds no cashup for that till and shift on that day. There is nothing to close.', 1;

    DECLARE @Id BIGINT, @State NVARCHAR(12), @CZ MONEY, @CA MONEY;

    BEGIN TRY
        BEGIN TRANSACTION;

        SELECT @Id = c.Id, @State = c.[Status], @CZ = c.ConfirmedZRead, @CA = c.ConfirmedActual
        FROM agora.Cashup c WITH (UPDLOCK, HOLDLOCK)
        WHERE c.BranchId = @BranchId AND c.TransactionDate = @TransactionDate AND c.TillNo = @TillNo AND c.ShiftNo = @ShiftNo;

        IF @State = 'closed'
            THROW 51000, 'AGORA:CASH_CASHUP_CLOSED:This cashup is already closed.', 1;

        IF ISNULL(@State, 'open') <> 'confirmed'
            THROW 51000, 'AGORA:CASH_CASHUP_NOT_CONFIRMED:Only a confirmed cashup is closed. Confirm its figures first.', 1;

        IF ABS(ISNULL(@CZ, 0) - ISNULL(@ZRead, 0)) >= 0.005 OR ABS(ISNULL(@CA, 0) - ISNULL(@Actual, 0)) >= 0.005
        BEGIN
            DECLARE @Moved NVARCHAR(400) = CONCAT('AGORA:CASH_CASHUP_CHANGED:The figures moved after they were confirmed (actual R',
                FORMAT(@CA, 'N2'), ' confirmed, R', FORMAT(@Actual, 'N2'), ' now). Confirm them again before closing.');
            THROW 51000, @Moved, 1;
        END

        UPDATE agora.Cashup
           SET [Status] = 'closed', ClosedAt = @Now, ClosedBy = @UserId, CloseNote = @Note,
               UpdatedAt = @Now, UpdatedBy = @UserId
         WHERE BranchId = @BranchId AND Id = @Id;

        INSERT INTO agora.CashupEvent (BranchId, CashupId, TransactionDate, TillNo, ShiftNo, [Event],
                                       ZRead, ActualTotal, Variance, Note, CreatedAt, CreatedBy)
        VALUES (@BranchId, @Id, @TransactionDate, @TillNo, @ShiftNo, 'closed',
                @ZRead, @Actual, @Variance, @Note, @Now, @UserId);

        COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        THROW;
    END CATCH;

    SELECT
        CAST(1 AS BIT)              AS Ok,
        'CASH_CASHUP_CLOSED_OFF'    AS Code,
        CONVERT(NVARCHAR(400), CONCAT('Closed, at a variance of R', FORMAT(@Variance, 'N2'), '.')) AS Message,
        @Id                         AS Id,
        @ZRead                      AS ZRead,
        @Actual                     AS ActualTotal,
        @Variance                   AS Variance;
END
