/* ============================================================================
 * agora.usp_Assets_Move — record something that happened to an asset:
 * a transfer, a trip to repair and back, a sale, a trade-in, a write-off.
 *
 * Called by: Asset movement -> Record a movement, and an asset's page (T057).
 * Writes:    agora.AssetMovement — one INSERT, carrying the asset's site and
 *            status before and after;
 *            agora.Asset — LocatedBranchId, Status, DisposedOn,
 *            DisposalProceeds;
 *            agora.AssetRepair — DateActualReturn on the asset's open job
 *            card, for a return from repair only.
 *            Nothing in PumpIT.
 *
 * WHAT EACH TYPE DOES is agora.vw_AssetMovementType.Kind, not a list here:
 *
 *   Kind         Codes    Needs the asset       Leaves it
 *   TRANSFER     MBS      in service            at @ToBranchId, still on its
 *                                               own site's register
 *   TO_REPAIR    RM       in service            at repair, where it was
 *   FROM_REPAIR  RR       at repair             in service, at @ToBranchId or
 *                                               where it was; the open job
 *                                               card gets its return date
 *   DISPOSAL     S, TI    in service or repair  DISPOSED on the movement date,
 *                                               with @Proceeds
 *   WRITE_OFF    WO       in service or repair  WRITTEN_OFF, for nothing
 *
 * A code the customer adds to their list has no Kind until someone says what
 * it does, and is refused rather than guessed at.
 *
 * MOVEMENTS ARE IN DATE ORDER. One dated before the asset's latest movement is
 * refused: the asset's state on that earlier day is not the state this
 * procedure can see, and replaying history out of order is how a register ends
 * up saying a machine was sold before it was bought. A wrong movement is
 * reversed (agora.usp_Assets_ReverseMovement), newest first.
 *
 * RULES: NOT_FOUND · DELETED · UNKNOWN_MOVEMENT_TYPE · DATE_REQUIRED ·
 *        FUTURE_DATE · BEFORE_PURCHASE · OUT_OF_ORDER · NOT_IN_SERVICE ·
 *        NOT_AT_REPAIR · ALREADY_GONE · DESTINATION_REQUIRED · UNKNOWN_BRANCH ·
 *        SAME_SITE · REASON_REQUIRED · BAD_PROCEEDS · UNKNOWN_JOB_CARD
 *
 * Returns the writer status row (Ok, Code, Message, Id = the movement's Id)
 * and its MovementRef.
 * ============================================================================ */

CREATE OR ALTER PROCEDURE [agora].[usp_Assets_Move]
    @BranchId     INT,
    @AssetId      BIGINT,
    @MovementType NVARCHAR(10),
    @MovementDate DATE           = NULL,
    @ToBranchId   INT            = NULL,
    @Proceeds     DECIMAL(18,2)  = NULL,
    @Reason       NVARCHAR(300)  = NULL,
    @RepairId     BIGINT         = NULL,
    @UserId       INT            = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE @Today date = CONVERT(date, GETDATE());

    SET @MovementType = UPPER(NULLIF(LTRIM(RTRIM(@MovementType)), ''));
    SET @Reason       = NULLIF(LTRIM(RTRIM(@Reason)), '');

    DECLARE @Kind varchar(20) = (SELECT TOP 1 m.Kind FROM agora.vw_AssetMovementType m WHERE m.MovementType = @MovementType);
    DECLARE @TypeName nvarchar(100) = (SELECT TOP 1 m.MovementTypeDescription FROM agora.vw_AssetMovementType m WHERE m.MovementType = @MovementType);

    IF @Kind IS NULL
        THROW 51000, 'AGORA:UNKNOWN_MOVEMENT_TYPE:That movement type is not one Agora knows what to do with.', 1;

    IF @MovementDate IS NULL
        THROW 51000, 'AGORA:DATE_REQUIRED:Give the date it happened.', 1;

    IF @MovementDate > @Today
        THROW 51000, 'AGORA:FUTURE_DATE:A movement is recorded when it has happened, not before.', 1;

    BEGIN TRANSACTION;

    DECLARE @AssetNo nvarchar(30), @Located int, @Status nvarchar(20), @Purchased date, @DeletedAt datetime2(0);

    SELECT @AssetNo = a.AssetNo, @Located = a.LocatedBranchId, @Status = a.[Status],
           @Purchased = a.DatePurchased, @DeletedAt = a.DeletedAt
    FROM agora.Asset a WITH (UPDLOCK, HOLDLOCK)
    WHERE a.BranchId = @BranchId AND a.Id = @AssetId;

    IF @AssetNo IS NULL
        THROW 51000, 'AGORA:NOT_FOUND:That asset is not on this site''s register.', 1;

    IF @DeletedAt IS NOT NULL
        THROW 51000, 'AGORA:DELETED:That asset was deleted from the register.', 1;

    IF @MovementDate < @Purchased
        THROW 51000, 'AGORA:BEFORE_PURCHASE:That date is before the asset was bought.', 1;

    DECLARE @LastDate date = (SELECT MAX(m.MovementDate) FROM agora.AssetMovement m WITH (UPDLOCK, HOLDLOCK)
                               WHERE m.BranchId = @BranchId AND m.AssetId = @AssetId AND m.ReversedAt IS NULL);

    IF @LastDate IS NOT NULL AND @MovementDate < @LastDate
    BEGIN
        DECLARE @OrderMsg nvarchar(400) = CONCAT('AGORA:OUT_OF_ORDER:This asset''s last movement is dated ',
            CONVERT(varchar(10), @LastDate, 23), '. Record movements in the order they happened, or reverse the later one first.');
        THROW 51000, @OrderMsg, 1;
    END

    DECLARE @To int = @Located, @ToStatus nvarchar(20) = @Status, @Closes bigint = NULL;

    IF @Kind = 'TRANSFER'
    BEGIN
        IF @Status <> 'IN_SERVICE'
            THROW 51000, 'AGORA:NOT_IN_SERVICE:Only an asset in service can be moved to another site. Bring it back from repair first.', 1;
        IF @ToBranchId IS NULL
            THROW 51000, 'AGORA:DESTINATION_REQUIRED:Say which site it is going to.', 1;
        IF NOT EXISTS (SELECT 1 FROM agora.Branch WHERE BranchId = @ToBranchId)
            THROW 51000, 'AGORA:UNKNOWN_BRANCH:The site it is going to does not exist.', 1;
        IF @ToBranchId = @Located
            THROW 51000, 'AGORA:SAME_SITE:The asset is already at that site.', 1;
        SET @To = @ToBranchId;
    END
    ELSE IF @Kind = 'TO_REPAIR'
    BEGIN
        IF @Status <> 'IN_SERVICE'
            THROW 51000, 'AGORA:NOT_IN_SERVICE:Only an asset in service can be sent for repair.', 1;
        SET @ToStatus = 'AT_REPAIR';
    END
    ELSE IF @Kind = 'FROM_REPAIR'
    BEGIN
        IF @Status <> 'AT_REPAIR'
            THROW 51000, 'AGORA:NOT_AT_REPAIR:This asset is not away at repair, so it cannot come back from it.', 1;
        IF @ToBranchId IS NOT NULL AND NOT EXISTS (SELECT 1 FROM agora.Branch WHERE BranchId = @ToBranchId)
            THROW 51000, 'AGORA:UNKNOWN_BRANCH:The site it is coming back to does not exist.', 1;
        SET @To = ISNULL(@ToBranchId, @Located);
        SET @ToStatus = 'IN_SERVICE';

        /* The job card it comes back from: the one named, or the asset's
           most recent open one. None is fine — an asset can go to repair
           without a card having been raised — and then nothing is stamped. */
        IF @RepairId IS NOT NULL
        BEGIN
            IF NOT EXISTS (SELECT 1 FROM agora.AssetRepair r
                            WHERE r.BranchId = @BranchId AND r.Id = @RepairId AND r.AssetId = @AssetId
                              AND r.IsCancelled = 0 AND r.DateActualReturn IS NULL)
                THROW 51000, 'AGORA:UNKNOWN_JOB_CARD:That job card is not open on this asset.', 1;
            SET @Closes = @RepairId;
        END
        ELSE
            SET @Closes = (SELECT TOP 1 r.Id FROM agora.AssetRepair r WITH (UPDLOCK)
                            WHERE r.BranchId = @BranchId AND r.AssetId = @AssetId
                              AND r.IsCancelled = 0 AND r.DateActualReturn IS NULL
                              AND r.CallDate <= @MovementDate
                            ORDER BY r.CallDate DESC, r.Id DESC);
    END
    ELSE IF @Kind IN ('DISPOSAL', 'WRITE_OFF')
    BEGIN
        IF @Status NOT IN ('IN_SERVICE', 'AT_REPAIR')
            THROW 51000, 'AGORA:ALREADY_GONE:This asset has already been disposed of or written off.', 1;
        IF @Reason IS NULL
            THROW 51000, 'AGORA:REASON_REQUIRED:Say why the asset is leaving the register.', 1;
        IF @Proceeds < 0 OR (@Kind = 'WRITE_OFF' AND ISNULL(@Proceeds, 0) <> 0)
            THROW 51000, 'AGORA:BAD_PROCEEDS:Proceeds are what a sale or trade-in fetched: never negative, and nothing on a write-off.', 1;
        SET @ToStatus = CASE @Kind WHEN 'DISPOSAL' THEN 'DISPOSED' ELSE 'WRITTEN_OFF' END;
    END

    DECLARE @No int = 1 + ISNULL((
        SELECT MAX(m.MovementNo) FROM agora.AssetMovement m WITH (UPDLOCK, HOLDLOCK)
         WHERE m.BranchId = @BranchId), 0);

    INSERT INTO agora.AssetMovement (
        BranchId, MovementNo, AssetId, MovementType, MovementDate, FromBranchId, ToBranchId,
        FromStatus, ToStatus, Proceeds, Reason, RepairId, CreatedAt, CreatedBy)
    VALUES (
        @BranchId, @No, @AssetId, @MovementType, @MovementDate, @Located, @To,
        @Status, @ToStatus, CASE WHEN @Kind = 'DISPOSAL' THEN @Proceeds END, @Reason, @Closes, SYSUTCDATETIME(), @UserId);

    DECLARE @MovementId bigint = SCOPE_IDENTITY();

    UPDATE agora.Asset
       SET LocatedBranchId  = @To,
           [Status]         = @ToStatus,
           DisposedOn       = CASE WHEN @Kind IN ('DISPOSAL', 'WRITE_OFF') THEN @MovementDate ELSE DisposedOn END,
           DisposalProceeds = CASE WHEN @Kind = 'DISPOSAL' THEN @Proceeds
                                   WHEN @Kind = 'WRITE_OFF' THEN NULL
                                   ELSE DisposalProceeds END,
           UpdatedAt        = SYSUTCDATETIME(),
           UpdatedBy        = @UserId
     WHERE BranchId = @BranchId AND Id = @AssetId;

    IF @Closes IS NOT NULL
        UPDATE agora.AssetRepair
           SET DateActualReturn = @MovementDate,
               UpdatedAt        = SYSUTCDATETIME(),
               UpdatedBy        = @UserId
         WHERE BranchId = @BranchId AND Id = @Closes;

    COMMIT TRANSACTION;

    DECLARE @Ref varchar(27) = (SELECT MovementRef FROM agora.AssetMovement WHERE BranchId = @BranchId AND Id = @MovementId);
    DECLARE @ToName nvarchar(200) = (SELECT TOP 1 Name FROM agora.Branch WHERE BranchId = @To);

    SELECT Ok = CONVERT(bit, 1), Code = 'MOVED',
           [Message] = CONCAT(@AssetNo, ': ', @TypeName,
                              CASE WHEN @Kind = 'TRANSFER' THEN CONCAT(' to ', @ToName) ELSE '' END,
                              ' recorded as ', @Ref, '.'),
           Id = @MovementId, MovementRef = @Ref;
END
