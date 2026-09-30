/* ============================================================================
   agora.usp_Cash_PostStaffShort

   Post an approved staff short: put it on one cashup's pump-shortages leg,
   where the cashup counts it and where the deduction schedule reads it
   (dbo.sp_RPT_CashShortDeductionPerEmployee: PumpAttShort = -AmountShort,
   deducted in full). The port of what PumpIT's Daily Banking screen does with
   dbo.sp_Load_ApprovedStaffShorts — which lists a site's approved, unposted
   shorts (Approved 1, IsDeclined 0, IsSelectedForDailyBanking 0, the person on
   BRN_Employee) for the cashier to tick onto the cashup.

   IT WRITES NOTHING ITSELF. Posting IS the cashup's StaffShorts leg, and there
   is one writer of a leg: this checks the short, names the cashup, and calls
   agora.usp_Cash_SaveCashupLeg with Channel 'StaffShorts' and the short's
   number — the same call the Daily Banking screen's "Add from the approved
   staff shorts" makes. That procedure's header lists what it writes:

     BRN_DailyBankingEmployees  INSERT the line (SSBranchId, TillNo, ShiftNo,
                                TransactionDate, EmployeeCode, AmountShort,
                                Imported 0, Note, Reason, StaffShortsNo,
                                CreateDateTime) — as the legacy copies it
                                (4,160 of 4,160 posted shorts: same person,
                                amount and note, the number carried)
     BRN_StaffShorts            UPDATE IsSelectedForDailyBanking 0 -> 1
     BRN_DailyBanking           the header's totals (EmployeeAmount), or the
                                header itself when the cashup has none yet

   all under cash.cashup's rules — the cashup open, one line per person per
   cashup (PumpIT's key), nothing banked twice — and records a 'posted'
   agora.StaffShortEvent. Its status row is the one returned.

   WHICH CASHUP. The one named (@TransactionDate, @TillNo, @ShiftNo); when none
   is named, the one the short was raised for (agora.StaffShort) — a short
   raised in PumpIT, or without a till and shift, has to be told. In PumpIT the
   cashier chooses: 3,893 of 4,160 posted shorts sit on a cashup dated before
   the short was captured.

   NOT A SHORT A CASH-BAG MATCH RAISED (Ryan, PM decision 01a0e5b5). Its
   cashup already counted those bags in full, so a pump-shortage line would put
   that cashup over by the same amount; the deduction schedule reads the short
   directly. Refused here, and again by usp_Cash_SaveCashupLeg, and the pool
   never offers one.

   Refusals, before anything is written: CASH_SHORT_USER · CASH_WRITES_OFF ·
             CASH_SHORT_KEY · CASH_SHORT_NOT_FOUND · CASH_SHORT_FROM_RECON ·
             CASH_SHORT_NOT_APPROVED · CASH_SHORT_DECLINED · CASH_SHORT_POSTED ·
             CASH_SHORT_CASHUP; then usp_Cash_SaveCashupLeg's own.

     EXEC agora.usp_Cash_PostStaffShort @BranchId = 999, @StaffShortsNo = 5000,
          @TransactionDate = '2026-09-20', @TillNo = 51, @ShiftNo = 3, @WriteMode = 'live', @UserId = 1;
   ============================================================================ */
CREATE OR ALTER PROCEDURE [agora].[usp_Cash_PostStaffShort]
    @BranchId         INT,
    @StaffShortsNo    INT,
    @TransactionDate  DATE          = NULL,
    @TillNo           INT           = NULL,
    @ShiftNo          INT           = NULL,
    @WriteMode        NVARCHAR(10)  = 'off',
    @UserId           INT
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    IF @UserId IS NULL
        THROW 51000, 'AGORA:CASH_SHORT_USER:A staff short is posted by a person, and none was given.', 1;
    IF ISNULL(@WriteMode, 'off') <> 'live'
        THROW 51000, 'AGORA:CASH_WRITES_OFF:Posting staff shorts to PumpIT is switched off. Nothing was written.', 1;
    IF @BranchId IS NULL OR @StaffShortsNo IS NULL
        THROW 51000, 'AGORA:CASH_SHORT_KEY:Name the site and the staff short.', 1;

    DECLARE @Msg NVARCHAR(400);
    DECLARE @Approved BIT, @Declined BIT, @Selected BIT, @Found BIT = 0, @OnLeg NVARCHAR(120);

    SELECT @Found = 1, @Approved = s.Approved, @Declined = s.IsDeclined, @Selected = ISNULL(s.IsSelectedForDailyBanking, 0)
    FROM [PumpIT].dbo.BRN_StaffShorts s
    WHERE s.SSBranchId = @BranchId AND s.StaffShortsNo = @StaffShortsNo;

    SELECT TOP 1 @OnLeg = CONCAT('till ', x.TillNo, ', shift ', x.ShiftNo, ' on ', CONVERT(CHAR(10), x.TransactionDate, 23))
    FROM [PumpIT].dbo.BRN_DailyBankingEmployees x
    WHERE x.SSBranchId = @BranchId AND x.StaffShortsNo = @StaffShortsNo AND @StaffShortsNo <> 0;

    IF @Found = 0
    BEGIN
        SET @Msg = CONCAT('AGORA:CASH_SHORT_NOT_FOUND:Staff short ', @StaffShortsNo, ' is not in PumpIT for this site. Nothing was written.');
        THROW 51000, @Msg, 1;
    END
    DECLARE @FromRecon NVARCHAR(100) = (SELECT TOP 1 ISNULL(a.SourceRef, N'recon') FROM agora.StaffShort a
                                        WHERE a.BranchId = @BranchId AND a.StaffShortsNo = @StaffShortsNo AND a.Source = 'recon');
    IF @FromRecon IS NOT NULL
    BEGIN
        SET @Msg = CONCAT('AGORA:CASH_SHORT_FROM_RECON:Staff short ', @StaffShortsNo, ' was raised by a cash-bag match (', @FromRecon,
                          ') and is not put on a cashup: the cashup already counted those bags in full, and deductions read the short directly. Nothing was written.');
        THROW 51000, @Msg, 1;
    END
    IF @Declined = 1
    BEGIN
        SET @Msg = CONCAT('AGORA:CASH_SHORT_DECLINED:Staff short ', @StaffShortsNo, ' was declined, so it is not posted. Nothing was written.');
        THROW 51000, @Msg, 1;
    END
    IF @Approved = 0
    BEGIN
        SET @Msg = CONCAT('AGORA:CASH_SHORT_NOT_APPROVED:Staff short ', @StaffShortsNo, ' is waiting for approval. Only an approved short is posted to a cashup. Nothing was written.');
        THROW 51000, @Msg, 1;
    END
    IF @Selected = 1 OR @OnLeg IS NOT NULL
    BEGIN
        SET @Msg = CONCAT('AGORA:CASH_SHORT_POSTED:Staff short ', @StaffShortsNo, ' is posted already',
                          ISNULL(' — on the cashup for ' + @OnLeg, ''), '. Nothing was written.');
        THROW 51000, @Msg, 1;
    END

    /* The cashup: the one named, else the one it was raised for. */
    IF @TransactionDate IS NULL AND @TillNo IS NULL AND @ShiftNo IS NULL
        SELECT TOP 1 @TransactionDate = a.TransactionDate, @TillNo = a.TillNo, @ShiftNo = a.ShiftNo
        FROM agora.StaffShort a
        WHERE a.BranchId = @BranchId AND a.StaffShortsNo = @StaffShortsNo AND a.TillNo IS NOT NULL;

    IF @TransactionDate IS NULL OR @TillNo IS NULL OR @ShiftNo IS NULL
        THROW 51000, 'AGORA:CASH_SHORT_CASHUP:Say which cashup the short goes on — the day, the till and the shift. Nothing was written.', 1;

    DECLARE @Lines NVARCHAR(100) = (SELECT @StaffShortsNo AS StaffShortsNo FOR JSON PATH);

    EXEC agora.usp_Cash_SaveCashupLeg
         @BranchId        = @BranchId,
         @TransactionDate = @TransactionDate,
         @TillNo          = @TillNo,
         @ShiftNo         = @ShiftNo,
         @Channel         = 'StaffShorts',
         @LinesJson       = @Lines,
         @Note            = NULL,
         @WriteMode       = @WriteMode,
         @UserId          = @UserId;
END
