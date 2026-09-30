/* ============================================================================
   agora.usp_Cash_ConfirmCashup

   WHAT IT IS FOR
   "Confirm" on the Daily Banking screen and the phone: a person agrees to a
   cashup's figures — its Z-read, what it actually totals and the variance
   between them — as they stand now.

   The legacy has no such thing. BRN_DailyBanking carries no confirm or status
   column (Posted is 0 on every row), so this writes Agora's database only:

     agora.Cashup       Status 'confirmed', and the figures confirmed
                        (ConfirmedZRead / ConfirmedActual / ConfirmedVariance),
                        who and when, and the reason if one was needed
     agora.CashupEvent  one 'confirmed' row

   NOTHING IN PUMPIT IS WRITTEN.

   A REASON IS KEPT FOR EVERY EXCEPTION (plan §2: "close the day, do not fill in
   forms … a reason kept for every exception"). A confirmation needs @Note when
     · the variance is beyond @VarianceTolerance either way — the application
       passes cash.cashup.variance_tolerance (R150, the mockup's own line
       between an ordinary and a critical variance); or
     · the Z-read does not agree with the cashup (agora.vw_CashupTotals'
       ZreadCheck is anything but 'Agrees') — no Z-read allocated, a different
       figure, or another person's.
   It does not refuse the figures themselves: a short cashup is confirmed as
   short, with the reason.

   A cashup already confirmed at exactly these figures is not confirmed twice
   (Ok = 0, CASH_CASHUP_ALREADY). One confirmed at figures that have since
   moved may be confirmed again, and the new figures replace the old; the
   trail keeps both. A closed cashup is not confirmed again.

   NOT DONE HERE: "confirming updates the console step" (T037 acceptance).
   There is no day-close spine yet (T029). When usp_DayEnd_GetCloseStatus
   exists, "cashups confirmed" is computed from agora.vw_CashupTotals.Status.

   Every timestamp is SYSUTCDATETIME().

   Refusals:
     AGORA:CASH_CASHUP_USER       no user
     AGORA:CASH_CASHUP_KEY        site, day, till and shift are all required
     AGORA:CASH_CASHUP_NOT_FOUND  PumpIT holds no cashup for that till and shift
     AGORA:CASH_CASHUP_CLOSED     closed by a manager
     AGORA:CASH_CASHUP_REASON     an exception, and no reason given
     AGORA:CASH_CASHUP_ALREADY    returned as Ok = 0: already confirmed at these figures
   ============================================================================ */
CREATE OR ALTER PROCEDURE [agora].[usp_Cash_ConfirmCashup]
    @BranchId           INT,
    @TransactionDate    DATE,
    @TillNo             INT,
    @ShiftNo            INT,
    @Note               NVARCHAR(400) = NULL,
    @VarianceTolerance  DECIMAL(18,2) = 150.00,
    @UserId             INT
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    SET @Note = NULLIF(LTRIM(RTRIM(@Note)), '');

    IF @UserId IS NULL
        THROW 51000, 'AGORA:CASH_CASHUP_USER:A confirmation is made by a person, and none was given.', 1;
    IF @BranchId IS NULL OR @TransactionDate IS NULL OR @TillNo IS NULL OR @ShiftNo IS NULL
        THROW 51000, 'AGORA:CASH_CASHUP_KEY:A cashup is one site, one day, one till and one shift. Give all four.', 1;

    DECLARE @At DATETIME = @TransactionDate;
    DECLARE @Now DATETIME2(0) = SYSUTCDATETIME();
    DECLARE @ZRead MONEY, @Actual MONEY, @Variance MONEY, @Check VARCHAR(20), @Employee NVARCHAR(11), @Found BIT = 0;

    SELECT @ZRead = t.ZRead, @Actual = t.ActualTotal, @Variance = t.Variance, @Check = t.ZreadCheck,
           @Employee = t.EmployeeCode, @Found = 1
    FROM agora.vw_CashupTotals t
    WHERE t.BranchId = @BranchId AND t.TillNo = @TillNo AND t.ShiftNo = @ShiftNo AND t.TransactionAt = @At;

    IF @Found = 0
        THROW 51000, 'AGORA:CASH_CASHUP_NOT_FOUND:PumpIT holds no cashup for that till and shift on that day. There is nothing to confirm yet.', 1;

    IF @Note IS NULL AND ABS(ISNULL(@Variance, 0)) > ISNULL(@VarianceTolerance, 0)
    BEGIN
        DECLARE @NeedVariance NVARCHAR(400) = CONCAT('AGORA:CASH_CASHUP_REASON:The variance is R', FORMAT(@Variance, 'N2'),
            ', beyond the R', FORMAT(@VarianceTolerance, 'N2'), ' either way that is confirmed without a word. Say why.');
        THROW 51000, @NeedVariance, 1;
    END

    IF @Note IS NULL AND @Check <> 'Agrees'
    BEGIN
        DECLARE @NeedZread NVARCHAR(400) = CONCAT('AGORA:CASH_CASHUP_REASON:The Z-read does not agree with this cashup (',
            LOWER(@Check), '). Say why it is being confirmed anyway, or correct the Z-read allocation first.');
        THROW 51000, @NeedZread, 1;
    END

    DECLARE @Id BIGINT, @State NVARCHAR(12), @CZ MONEY, @CA MONEY;

    BEGIN TRY
        BEGIN TRANSACTION;

        SELECT @Id = c.Id, @State = c.[Status], @CZ = c.ConfirmedZRead, @CA = c.ConfirmedActual
        FROM agora.Cashup c WITH (UPDLOCK, HOLDLOCK)
        WHERE c.BranchId = @BranchId AND c.TransactionDate = @TransactionDate AND c.TillNo = @TillNo AND c.ShiftNo = @ShiftNo;

        IF @State = 'closed'
            THROW 51000, 'AGORA:CASH_CASHUP_CLOSED:This cashup has been closed by a manager and is not confirmed again.', 1;

        IF @State = 'confirmed' AND ABS(ISNULL(@CZ, 0) - ISNULL(@ZRead, 0)) < 0.005 AND ABS(ISNULL(@CA, 0) - ISNULL(@Actual, 0)) < 0.005
        BEGIN
            COMMIT TRANSACTION;

            SELECT CAST(0 AS BIT) AS Ok, 'CASH_CASHUP_ALREADY' AS Code,
                   CONVERT(NVARCHAR(400), 'Already confirmed at these figures.') AS Message,
                   @Id AS Id, @ZRead AS ZRead, @Actual AS ActualTotal, @Variance AS Variance;
            RETURN;
        END

        IF @Id IS NULL
        BEGIN
            INSERT INTO agora.Cashup (BranchId, TransactionDate, TillNo, ShiftNo, EmployeeCode, [Status], CreatedAt, CreatedBy)
            VALUES (@BranchId, @TransactionDate, @TillNo, @ShiftNo, @Employee, 'open', @Now, @UserId);

            SET @Id = SCOPE_IDENTITY();
        END

        UPDATE agora.Cashup
           SET [Status] = 'confirmed', EmployeeCode = @Employee,
               ConfirmedAt = @Now, ConfirmedBy = @UserId,
               ConfirmedZRead = @ZRead, ConfirmedActual = @Actual, ConfirmedVariance = @Variance,
               ConfirmNote = @Note,
               UpdatedAt = @Now, UpdatedBy = @UserId
         WHERE BranchId = @BranchId AND Id = @Id;

        INSERT INTO agora.CashupEvent (BranchId, CashupId, TransactionDate, TillNo, ShiftNo, [Event],
                                       ZRead, ActualTotal, Variance, Note, CreatedAt, CreatedBy)
        VALUES (@BranchId, @Id, @TransactionDate, @TillNo, @ShiftNo, 'confirmed',
                @ZRead, @Actual, @Variance,
                LEFT(CONCAT(@Note, CASE WHEN @Check <> 'Agrees' THEN CONCAT(CASE WHEN @Note IS NULL THEN '' ELSE ' ' END, '[Z-read: ', LOWER(@Check), ']') ELSE '' END), 400),
                @Now, @UserId);

        COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        THROW;
    END CATCH;

    SELECT
        CAST(1 AS BIT)                  AS Ok,
        'CASH_CASHUP_CONFIRMED'         AS Code,
        CONVERT(NVARCHAR(400), CONCAT('Confirmed: Z-read R', FORMAT(@ZRead, 'N2'), ', actual R', FORMAT(@Actual, 'N2'),
                                      ', variance R', FORMAT(@Variance, 'N2'), '.')) AS Message,
        @Id                             AS Id,
        @ZRead                          AS ZRead,
        @Actual                         AS ActualTotal,
        @Variance                       AS Variance;
END
