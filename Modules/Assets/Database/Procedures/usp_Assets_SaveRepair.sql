/* ============================================================================
 * agora.usp_Assets_SaveRepair — raise a repair job card, or update one.
 *
 * Called by: Asset repair -> Log a repair, and a job card's page (T057).
 * Writes:    agora.AssetRepair — one INSERT or UPDATE. Nothing in PumpIT.
 * Reads:     agora.vw_AssetPurchaseRequest and agora.vw_Supplier — the customer's
 *            BRN_Transaction and BRN_Suppliers, read-only, to VALIDATE the
 *            purchase request number and the supplier.
 *
 * THE PURCHASE REQUEST (Ryan, 27 Sep 2026). A job card carries the number of
 * the purchase request that pays for it — CR000550, CA004411 — as the legacy
 * form's OrderNo did, and the cost of the repair is that request's approved
 * amount, read live. It is not typed here, because a second copy of the cost
 * would disagree with the request within a week.
 *
 * The request is looked for at the SITE where the asset stood when it was
 * called in (SiteBranchId), because that is the site that raised and pays for
 * it; (site, TransactionNo) identifies one request on live across both the
 * cash (01) and credit (02) types. It may be attached later — a call-out is
 * usually logged before the quote comes back — and:
 *   · a request that does not exist at that site is refused;
 *   · a DECLINED request is refused: it pays for nothing;
 *   · a pending one is accepted, and the card shows no cost until it is
 *     approved;
 *   · the card's supplier must be the request's. A card with no supplier
 *     takes the request's; a card whose supplier differs is refused, because
 *     one of the two numbers is wrong and the comeback rule charges a
 *     comeback to the supplier on the card.
 *   · one request may pay for more than one card (one call-out, three
 *     fridges). Each card shows the request's amount and says it is shared;
 *     totals count the request once.
 *
 * RULES: NOT_FOUND · DELETED · ASSET_GONE · DATE_REQUIRED · FUTURE_DATE ·
 *        BEFORE_PURCHASE · PROBLEM_REQUIRED · UNKNOWN_SUPPLIER ·
 *        UNKNOWN_PURCHASE_REQUEST · PR_DECLINED · PR_SUPPLIER_MISMATCH ·
 *        RETURN_BEFORE_CALL · EDIT_NOT_ALLOWED · CANCELLED · UNKNOWN_JOB_CARD
 *
 * Returns the writer status row (Ok, Code, Message, Id = the card's Id) and
 * its JobCardNo.
 * ============================================================================ */

CREATE OR ALTER PROCEDURE [agora].[usp_Assets_SaveRepair]
    @BranchId           INT,
    @RepairId           BIGINT          = NULL,
    @AssetId            BIGINT          = NULL,
    @CallDate           DATE            = NULL,
    @SupplierCode       NVARCHAR(16)    = NULL,
    @SupplierReference  NVARCHAR(100)   = NULL,
    @Problem            NVARCHAR(1000)  = NULL,
    @TransactionNo      NVARCHAR(30)    = NULL,
    @DateExpectedReturn DATE            = NULL,
    @DateActualReturn   DATE            = NULL,
    @AllowEdit          BIT             = 0,
    @UserId             INT             = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE @Today date = CONVERT(date, GETDATE());

    SET @SupplierCode      = NULLIF(LTRIM(RTRIM(@SupplierCode)), '');
    SET @SupplierReference = NULLIF(LTRIM(RTRIM(@SupplierReference)), '');
    SET @Problem           = NULLIF(LTRIM(RTRIM(@Problem)), '');
    SET @TransactionNo     = UPPER(NULLIF(LTRIM(RTRIM(@TransactionNo)), ''));

    BEGIN TRANSACTION;

    DECLARE @Site int, @Existing bit = 0, @Cancelled bit = 0;

    IF @RepairId IS NOT NULL
    BEGIN
        IF @AllowEdit = 0
            THROW 51000, 'AGORA:EDIT_NOT_ALLOWED:Changing a job card needs the assets.repair.edit permission.', 1;

        SELECT @AssetId = r.AssetId, @Site = r.SiteBranchId, @Cancelled = r.IsCancelled, @Existing = 1,
               /* The call date and the problem are what was reported; a later
                  save may correct them, but only by sending them again. */
               @CallDate = ISNULL(@CallDate, r.CallDate),
               @Problem  = ISNULL(@Problem, r.Problem)
        FROM agora.AssetRepair r WITH (UPDLOCK, HOLDLOCK)
        WHERE r.BranchId = @BranchId AND r.Id = @RepairId;

        IF @Existing = 0
            THROW 51000, 'AGORA:UNKNOWN_JOB_CARD:That job card is not on this site''s register.', 1;

        IF @Cancelled = 1
            THROW 51000, 'AGORA:CANCELLED:That job card was cancelled. Raise a new one.', 1;
    END

    DECLARE @AssetNo nvarchar(30), @Located int, @Status nvarchar(20), @Purchased date, @DeletedAt datetime2(0);

    SELECT @AssetNo = a.AssetNo, @Located = a.LocatedBranchId, @Status = a.[Status],
           @Purchased = a.DatePurchased, @DeletedAt = a.DeletedAt
    FROM agora.Asset a
    WHERE a.BranchId = @BranchId AND a.Id = @AssetId;

    IF @AssetNo IS NULL
        THROW 51000, 'AGORA:NOT_FOUND:That asset is not on this site''s register.', 1;

    IF @DeletedAt IS NOT NULL
        THROW 51000, 'AGORA:DELETED:That asset was deleted from the register.', 1;

    IF @Existing = 0 AND @Status IN ('DISPOSED', 'WRITTEN_OFF')
        THROW 51000, 'AGORA:ASSET_GONE:That asset is off the register — sold, traded in or written off — and cannot be repaired.', 1;

    /* A new card is raised where the asset stands now; an existing one keeps
       the site it was raised at. */
    SET @Site = ISNULL(@Site, @Located);

    IF @CallDate IS NULL
        THROW 51000, 'AGORA:DATE_REQUIRED:Give the date the repair was called in.', 1;

    IF @CallDate > @Today OR @DateActualReturn > @Today
        THROW 51000, 'AGORA:FUTURE_DATE:A call or a return is recorded when it has happened.', 1;

    IF @CallDate < @Purchased
        THROW 51000, 'AGORA:BEFORE_PURCHASE:That call date is before the asset was bought.', 1;

    IF @Problem IS NULL
        THROW 51000, 'AGORA:PROBLEM_REQUIRED:Say what is wrong with it.', 1;

    IF @DateExpectedReturn < @CallDate OR @DateActualReturn < @CallDate
        THROW 51000, 'AGORA:RETURN_BEFORE_CALL:It cannot come back before it was called in.', 1;

    IF @SupplierCode IS NOT NULL
       AND NOT EXISTS (SELECT 1 FROM agora.vw_Supplier s WHERE s.BranchId = @Site AND s.SupplierCode = @SupplierCode)
        THROW 51000, 'AGORA:UNKNOWN_SUPPLIER:That supplier is not set up at the site the repair is at.', 1;

    IF @TransactionNo IS NOT NULL
    BEGIN
        DECLARE @PrSupplier nvarchar(16), @PrDeclined bit, @PrFound bit = 0;

        SELECT TOP 1 @PrSupplier = p.SupplierCode, @PrDeclined = p.IsDeclined, @PrFound = 1
        FROM agora.vw_AssetPurchaseRequest p
        WHERE p.BranchId = @Site AND p.TransactionNo = @TransactionNo;

        IF @PrFound = 0
        BEGIN
            DECLARE @PrMsg nvarchar(400) = CONCAT('AGORA:UNKNOWN_PURCHASE_REQUEST:There is no purchase request ', @TransactionNo,
                ' at ', (SELECT TOP 1 Name FROM agora.Branch WHERE BranchId = @Site), '.');
            THROW 51000, @PrMsg, 1;
        END

        IF @PrDeclined = 1
            THROW 51000, 'AGORA:PR_DECLINED:That purchase request was declined, so it pays for nothing. Attach the one that was approved.', 1;

        IF @SupplierCode IS NULL
            SET @SupplierCode = @PrSupplier;
        ELSE IF @SupplierCode <> @PrSupplier
        BEGIN
            DECLARE @MisMsg nvarchar(400) = CONCAT('AGORA:PR_SUPPLIER_MISMATCH:Purchase request ', @TransactionNo,
                ' is for supplier ', @PrSupplier, ', not ', @SupplierCode, '. One of the two is wrong.');
            THROW 51000, @MisMsg, 1;
        END
    END

    IF @Existing = 0
    BEGIN
        DECLARE @No int = 1 + ISNULL((
            SELECT MAX(r.RepairNo) FROM agora.AssetRepair r WITH (UPDLOCK, HOLDLOCK)
             WHERE r.BranchId = @BranchId), 0);

        INSERT INTO agora.AssetRepair (
            BranchId, RepairNo, AssetId, SiteBranchId, CallDate, SupplierCode, SupplierReference, Problem,
            TransactionNo, DateExpectedReturn, DateActualReturn, IsCancelled, CreatedAt, CreatedBy)
        VALUES (
            @BranchId, @No, @AssetId, @Site, @CallDate, @SupplierCode, @SupplierReference, @Problem,
            @TransactionNo, @DateExpectedReturn, @DateActualReturn, 0, SYSUTCDATETIME(), @UserId);

        SET @RepairId = SCOPE_IDENTITY();
    END
    ELSE
        UPDATE agora.AssetRepair
           SET CallDate           = @CallDate,
               SupplierCode       = @SupplierCode,
               SupplierReference  = @SupplierReference,
               Problem            = @Problem,
               TransactionNo      = @TransactionNo,
               DateExpectedReturn = @DateExpectedReturn,
               DateActualReturn   = @DateActualReturn,
               UpdatedAt          = SYSUTCDATETIME(),
               UpdatedBy          = @UserId
         WHERE BranchId = @BranchId AND Id = @RepairId;

    COMMIT TRANSACTION;

    /* Said on the way back, because it is the reason the screen exists. */
    DECLARE @JobCardNo varchar(27), @PrevCard varchar(27), @Days int, @IsComeback bit;

    SELECT @JobCardNo = c.JobCardNo, @PrevCard = c.PrevJobCardNo, @Days = c.DaysSincePrevious, @IsComeback = c.IsComeback
    FROM agora.vw_AssetRepairCall c
    WHERE c.BranchId = @BranchId AND c.Id = @RepairId;

    SELECT Ok = CONVERT(bit, 1),
           Code = CASE WHEN @IsComeback = 1 THEN 'COMEBACK' WHEN @Existing = 1 THEN 'SAVED' ELSE 'RAISED' END,
           [Message] = CONCAT(@JobCardNo, CASE WHEN @Existing = 1 THEN ' saved.' ELSE CONCAT(' raised on ', @AssetNo, '.') END,
                              CASE WHEN @IsComeback = 1
                                   THEN CONCAT(' This is a comeback: ', @Days, ' day', CASE WHEN @Days = 1 THEN '' ELSE 's' END,
                                               ' after ', @PrevCard, '.')
                                   ELSE '' END),
           Id = @RepairId, JobCardNo = @JobCardNo;
END
