/*
 * agora.usp_StockRecon_WithdrawRelease — take a release back, so the possible
 * duplicate issue (D2) holds its chain back again.
 *
 * Read by:  Stock recon centre -> a chain -> a released D2 -> Withdraw.
 * Reads:    agora.StockReconDuplicateRelease, agora.StockReconRunLine
 * Writes:   agora.StockReconDuplicateRelease — WithdrawnAt, WithdrawnBy,
 *           WithdrawNote on the one row. NOTHING in the customer's estate.
 *
 * A release is a transactional entry, so it is withdrawn and never deleted
 * (feature-rules §4): the row stays, with who released it and why beside who
 * took it back and when. From the next preview the D2 holds its chain back
 * again; a run already previewed with the release standing is caught at the
 * press — usp_StockRecon_Commit re-checks every D2 against this ledger and
 * skips the chain.
 *
 * Refusals: RELEASE_USER · NO_RELEASE · RELEASE_WITHDRAWN
 *
 * Returns the writer status row (Ok, Code, Message, Id = the release).
 *
 *   EXEC agora.usp_StockRecon_WithdrawRelease @BranchId = 23, @ReleaseId = 7,
 *        @Note = 'Released against the wrong shift', @UserId = 1;
 */
CREATE OR ALTER PROCEDURE [agora].[usp_StockRecon_WithdrawRelease]
    @BranchId   INT,
    @ReleaseId  BIGINT,
    @Note       NVARCHAR(400) = NULL,
    @UserId     INT
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE @Msg NVARCHAR(2048);

    SET @Note = NULLIF(LTRIM(RTRIM(ISNULL(@Note, N''))), N'');

    IF @UserId IS NULL
        THROW 51000, 'AGORA:RELEASE_USER:A release is withdrawn by a person, and none was given.', 1;

    DECLARE @Label NVARCHAR(200), @WithdrawnAt DATETIME2(0);

    SELECT @Label = CONCAT(ISNULL(rl.ItemDescription, x.StockItemNo), N' on ', CONVERT(char(10), x.TransactionDate, 23),
                           N' shift ', x.ShiftNo),
           @WithdrawnAt = x.WithdrawnAt
    FROM agora.StockReconDuplicateRelease x
    LEFT JOIN agora.StockReconRunLine rl
           ON rl.BranchId = x.BranchId AND rl.Id = x.RunLineId
    WHERE x.BranchId = @BranchId AND x.Id = @ReleaseId;

    IF @Label IS NULL
        THROW 51000, 'AGORA:NO_RELEASE:That release does not exist for this branch.', 1;

    IF @WithdrawnAt IS NOT NULL
    BEGIN
        SET @Msg = CONCAT(N'AGORA:RELEASE_WITHDRAWN:The release of ', @Label, N' was already withdrawn on ',
                          CONVERT(char(16), @WithdrawnAt, 120), N'. Nothing was changed.');
        THROW 51000, @Msg, 1;
    END

    BEGIN TRY
        BEGIN TRANSACTION;

        UPDATE agora.StockReconDuplicateRelease
           SET WithdrawnAt  = SYSUTCDATETIME(),
               WithdrawnBy  = @UserId,
               WithdrawNote = @Note,
               UpdatedAt    = SYSUTCDATETIME(),
               UpdatedBy    = @UserId
        WHERE BranchId = @BranchId AND Id = @ReleaseId AND WithdrawnAt IS NULL;

        IF @@ROWCOUNT <> 1
            THROW 51000, 'AGORA:RELEASE_WITHDRAWN:The release was withdrawn while this ran. Nothing was changed.', 1;

        COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        THROW;
    END CATCH;

    SELECT CONVERT(bit, 1)                                  AS Ok,
           'WITHDRAWN'                                      AS Code,
           CONVERT(nvarchar(400), CONCAT(N'The release of ', @Label,
               N' is withdrawn: from the next preview the possible duplicate holds its chain back again.'))
                                                            AS [Message],
           @ReleaseId                                       AS Id;
END
