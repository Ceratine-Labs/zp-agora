/* ============================================================================
 * agora.usp_Assets_DeleteAsset — take an asset registered IN ERROR off the
 * register.
 *
 * Called by: an asset's page -> Delete (T057), behind assets.register.delete.
 * Writes:    agora.Asset — DeletedAt, DeletedBy, Reason on one row. A soft
 *            delete: the row stays, and so does its number, which is never
 *            issued again.
 *
 * An asset is master data, so it may be deleted — but ONLY while nothing has
 * happened to it. Once it has a movement or a job card (even a reversed or a
 * cancelled one) it is part of a history somebody may need to read, and the
 * way off the register is a disposal or a write-off, which are movements
 * (feature-rules §4: transactions are never deleted, and they would be left
 * pointing at nothing).
 *
 * RULES: DELETE_NOT_ALLOWED · NOT_FOUND · ALREADY_DELETED · HAS_HISTORY ·
 *        REASON_REQUIRED
 * ============================================================================ */

CREATE OR ALTER PROCEDURE [agora].[usp_Assets_DeleteAsset]
    @BranchId    INT,
    @AssetId     BIGINT,
    @Reason      NVARCHAR(300) = NULL,
    @AllowDelete BIT           = 0,
    @UserId      INT           = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    SET @Reason = NULLIF(LTRIM(RTRIM(@Reason)), '');

    IF @AllowDelete = 0
        THROW 51000, 'AGORA:DELETE_NOT_ALLOWED:Deleting an asset needs the assets.register.delete permission.', 1;

    IF @Reason IS NULL
        THROW 51000, 'AGORA:REASON_REQUIRED:Say why the asset is being deleted — it is kept against the row.', 1;

    BEGIN TRANSACTION;

    DECLARE @AssetNo nvarchar(30), @DeletedAt datetime2(0);

    SELECT @AssetNo = a.AssetNo, @DeletedAt = a.DeletedAt
    FROM agora.Asset a WITH (UPDLOCK, HOLDLOCK)
    WHERE a.BranchId = @BranchId AND a.Id = @AssetId;

    IF @AssetNo IS NULL
        THROW 51000, 'AGORA:NOT_FOUND:That asset is not on this site''s register.', 1;

    IF @DeletedAt IS NOT NULL
        THROW 51000, 'AGORA:ALREADY_DELETED:That asset was already deleted.', 1;

    IF EXISTS (SELECT 1 FROM agora.AssetMovement m WHERE m.BranchId = @BranchId AND m.AssetId = @AssetId)
       OR EXISTS (SELECT 1 FROM agora.AssetRepair r WHERE r.BranchId = @BranchId AND r.AssetId = @AssetId)
        THROW 51000, 'AGORA:HAS_HISTORY:This asset has movements or job cards, so it cannot be deleted. Dispose of it or write it off instead.', 1;

    UPDATE agora.Asset
       SET DeletedAt = SYSUTCDATETIME(),
           DeletedBy = @UserId,
           Reason    = @Reason,
           UpdatedAt = SYSUTCDATETIME(),
           UpdatedBy = @UserId
     WHERE BranchId = @BranchId AND Id = @AssetId;

    COMMIT TRANSACTION;

    SELECT Ok = CONVERT(bit, 1), Code = 'DELETED',
           [Message] = CONCAT(@AssetNo, ' deleted from the register.'),
           Id = @AssetId;
END
