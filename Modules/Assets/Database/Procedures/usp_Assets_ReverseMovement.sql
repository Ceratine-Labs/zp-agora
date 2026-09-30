/* ============================================================================
 * agora.usp_Assets_ReverseMovement — undo a movement recorded in error.
 *
 * Called by: an asset's page -> Reverse, behind assets.movement.edit (T057).
 * Writes:    agora.AssetMovement — ReversedAt, ReversedBy, ReverseReason on the
 *            one row (it is never deleted);
 *            agora.Asset — LocatedBranchId and Status put back to the
 *            movement's own From columns; DisposedOn and DisposalProceeds
 *            cleared when the movement was a disposal or a write-off;
 *            agora.AssetRepair — DateActualReturn cleared on the job card a
 *            return from repair closed.
 *
 * ONLY THE ASSET'S LATEST LIVE MOVEMENT may be reversed. Its From columns are
 * then exactly the state to go back to. Reversing an older one would put the
 * asset back to a state the later movements have already moved it on from —
 * so the later ones are reversed first, newest first, and each reversal is
 * exact.
 *
 * @AssetId is optional; when given, the movement must be that asset's.
 *
 * RULES: EDIT_NOT_ALLOWED · REASON_REQUIRED · NOT_FOUND · ALREADY_REVERSED ·
 *        NOT_LATEST
 * ============================================================================ */

CREATE OR ALTER PROCEDURE [agora].[usp_Assets_ReverseMovement]
    @BranchId     INT,
    @MovementId   BIGINT,
    @AssetId      BIGINT        = NULL,
    @Reason       NVARCHAR(300) = NULL,
    @AllowReverse BIT           = 0,
    @UserId       INT           = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    SET @Reason = NULLIF(LTRIM(RTRIM(@Reason)), '');

    IF @AllowReverse = 0
        THROW 51000, 'AGORA:EDIT_NOT_ALLOWED:Reversing a movement needs the assets.movement.edit permission.', 1;

    IF @Reason IS NULL
        THROW 51000, 'AGORA:REASON_REQUIRED:Say why the movement is being reversed. It stays on the record, marked reversed.', 1;

    BEGIN TRANSACTION;

    DECLARE @MovedAsset bigint, @From int, @FromStatus nvarchar(20), @Type nvarchar(10),
            @ReversedAt datetime, @RepairId bigint, @Ref varchar(27), @MovementDate date;

    SELECT @MovedAsset = m.AssetId, @From = m.FromBranchId, @FromStatus = m.FromStatus, @Type = m.MovementType,
           @ReversedAt = m.ReversedAt, @RepairId = m.RepairId, @Ref = m.MovementRef, @MovementDate = m.MovementDate
    FROM agora.AssetMovement m WITH (UPDLOCK, HOLDLOCK)
    WHERE m.BranchId = @BranchId AND m.Id = @MovementId;

    /* @AssetId, when the caller names the asset (its page does), must be the
       asset the movement belongs to. */
    IF @MovedAsset IS NULL OR @MovedAsset <> ISNULL(@AssetId, @MovedAsset)
        THROW 51000, 'AGORA:NOT_FOUND:That movement is not on this asset.', 1;

    SET @AssetId = @MovedAsset;

    IF @ReversedAt IS NOT NULL
        THROW 51000, 'AGORA:ALREADY_REVERSED:That movement has already been reversed.', 1;

    /* Latest = no live movement on the same asset after it, by date and then
       by the order it was recorded. */
    IF EXISTS (SELECT 1 FROM agora.AssetMovement m WITH (UPDLOCK, HOLDLOCK)
                WHERE m.BranchId = @BranchId AND m.AssetId = @AssetId AND m.ReversedAt IS NULL
                  AND m.Id <> @MovementId
                  AND (m.MovementDate > @MovementDate OR (m.MovementDate = @MovementDate AND m.Id > @MovementId)))
        THROW 51000, 'AGORA:NOT_LATEST:A later movement of this asset is still in force. Reverse that one first.', 1;

    DECLARE @Kind varchar(20) = (SELECT TOP 1 k.Kind FROM agora.vw_AssetMovementType k WHERE k.MovementType = @Type);

    UPDATE agora.Asset
       SET LocatedBranchId  = @From,
           [Status]         = @FromStatus,
           DisposedOn       = CASE WHEN @Kind IN ('DISPOSAL', 'WRITE_OFF') THEN NULL ELSE DisposedOn END,
           DisposalProceeds = CASE WHEN @Kind IN ('DISPOSAL', 'WRITE_OFF') THEN NULL ELSE DisposalProceeds END,
           UpdatedAt        = SYSUTCDATETIME(),
           UpdatedBy        = @UserId
     WHERE BranchId = @BranchId AND Id = @AssetId;

    /* A return from repair stamped this job card's return date; take it off
       again, so the card is open exactly as it was before. */
    IF @RepairId IS NOT NULL AND @Kind = 'FROM_REPAIR'
        UPDATE agora.AssetRepair
           SET DateActualReturn = NULL,
               UpdatedAt        = SYSUTCDATETIME(),
               UpdatedBy        = @UserId
         WHERE BranchId = @BranchId AND Id = @RepairId AND DateActualReturn = @MovementDate;

    UPDATE agora.AssetMovement
       SET ReversedAt    = SYSUTCDATETIME(),
           ReversedBy    = @UserId,
           ReverseReason = @Reason,
           UpdatedAt     = SYSUTCDATETIME(),
           UpdatedBy     = @UserId
     WHERE BranchId = @BranchId AND Id = @MovementId;

    COMMIT TRANSACTION;

    SELECT Ok = CONVERT(bit, 1), Code = 'REVERSED',
           [Message] = CONCAT(@Ref, ' reversed. The asset is back where it was before it.'),
           Id = @MovementId;
END
