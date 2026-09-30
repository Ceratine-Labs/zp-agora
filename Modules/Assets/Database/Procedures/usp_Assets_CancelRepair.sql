/* ============================================================================
 * agora.usp_Assets_CancelRepair — cancel a job card raised in error.
 *
 * Called by: a job card's page -> Cancel, behind assets.repair.edit (T057).
 * Writes:    agora.AssetRepair — IsCancelled, CancelledAt, CancelledBy,
 *            CancelReason on one row. Never a delete: a job card is a record
 *            of a call somebody made, and the trail stays.
 *
 * A cancelled card stops being a CALL: agora.vw_AssetRepairCall leaves it out
 * before it looks for the previous call, so a card raised twice by mistake
 * does not turn the next genuine call on that asset into a comeback. That is
 * the whole reason cancelling exists rather than simply leaving the duplicate.
 *
 * RULES: EDIT_NOT_ALLOWED · REASON_REQUIRED · UNKNOWN_JOB_CARD ·
 *        ALREADY_CANCELLED
 * ============================================================================ */

CREATE OR ALTER PROCEDURE [agora].[usp_Assets_CancelRepair]
    @BranchId  INT,
    @RepairId  BIGINT,
    @Reason    NVARCHAR(300) = NULL,
    @AllowEdit BIT           = 0,
    @UserId    INT           = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    SET @Reason = NULLIF(LTRIM(RTRIM(@Reason)), '');

    IF @AllowEdit = 0
        THROW 51000, 'AGORA:EDIT_NOT_ALLOWED:Cancelling a job card needs the assets.repair.edit permission.', 1;

    IF @Reason IS NULL
        THROW 51000, 'AGORA:REASON_REQUIRED:Say why the job card is being cancelled.', 1;

    BEGIN TRANSACTION;

    DECLARE @JobCardNo varchar(27), @Cancelled bit;

    SELECT @JobCardNo = r.JobCardNo, @Cancelled = r.IsCancelled
    FROM agora.AssetRepair r WITH (UPDLOCK, HOLDLOCK)
    WHERE r.BranchId = @BranchId AND r.Id = @RepairId;

    IF @JobCardNo IS NULL
        THROW 51000, 'AGORA:UNKNOWN_JOB_CARD:That job card is not on this site''s register.', 1;

    IF @Cancelled = 1
        THROW 51000, 'AGORA:ALREADY_CANCELLED:That job card is already cancelled.', 1;

    UPDATE agora.AssetRepair
       SET IsCancelled  = 1,
           CancelledAt  = SYSUTCDATETIME(),
           CancelledBy  = @UserId,
           CancelReason = @Reason,
           UpdatedAt    = SYSUTCDATETIME(),
           UpdatedBy    = @UserId
     WHERE BranchId = @BranchId AND Id = @RepairId;

    COMMIT TRANSACTION;

    SELECT Ok = CONVERT(bit, 1), Code = 'CANCELLED',
           [Message] = CONCAT(@JobCardNo, ' cancelled. It no longer counts as a call.'),
           Id = @RepairId;
END
