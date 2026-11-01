/*
 * Archive only previews whose original proposals are all proven unusable.
 * Scope is shared with GridRuns; NULL RunIdsJson means every matching run,
 * while [] means none. There is deliberately no paging/export limit here.
 * RunValidity supplies the conservative proof, independently of Selected.
 * Closing preserves the run, its original lines and all audit history.
 *
 * Commit and archive serialize on each ReconRun row. Scope and validity are
 * rechecked after those locks are held, so a preview committed while this
 * request was waiting becomes history and cannot be archived.
 * No customer tables or reconciliation ledger children are written.
 */
CREATE OR ALTER PROCEDURE [agora].[usp_Recon_ArchiveInvalidRuns]
    @BranchIds        nvarchar(max) = NULL,
    @DateFrom         date          = NULL,
    @DateTo           date          = NULL,
    @Search           nvarchar(200) = NULL,
    @FiltersJson      nvarchar(max) = NULL,
    @ReconArea        nvarchar(20)  = NULL,
    @MineOnly         bit           = 1,
    @UserId           int           = NULL,
    @AllowedBranchIds nvarchar(max) = NULL,
    @OpenOnly         bit           = 0,
    @RunIdsJson       nvarchar(max) = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    IF @UserId IS NULL
        THROW 51000, 'AGORA:USER_REQUIRED:Archiving runs requires a signed-in user.', 1;

    CREATE TABLE #ArchiveCandidates (RunId bigint PRIMARY KEY);
    INSERT INTO #ArchiveCandidates (RunId)
    EXEC agora.usp_Recon_RunScope
        @BranchIds = @BranchIds, @DateFrom = @DateFrom, @DateTo = @DateTo,
        @Search = @Search, @FiltersJson = @FiltersJson, @ReconArea = @ReconArea,
        @MineOnly = @MineOnly, @UserId = @UserId,
        @AllowedBranchIds = @AllowedBranchIds, @OpenOnly = @OpenOnly,
        @RunIdsJson = @RunIdsJson;

    DECLARE @Matched int,
            @Archived int = 0, @RunId bigint, @LockedId bigint,
            @CandidateJson nvarchar(max), @ScopedJson nvarchar(max);

    SELECT @CandidateJson = N'[' + ISNULL(STRING_AGG(CONVERT(nvarchar(max), RunId), N','), N'') + N']'
    FROM #ArchiveCandidates;

    /* "All invalid" snapshots invalid candidates, not every valid/history
       row sharing the grid scope. Skipped then means an eligible run changed
       while this request was waiting. Explicit selections count all selected
       in-scope rows, including rows that cannot safely be archived. */
    IF @RunIdsJson IS NULL
    BEGIN
        CREATE TABLE #InitialArchiveValidity (
            RunId bigint PRIMARY KEY, Validity nvarchar(12),
            InvalidRows int NULL, RemainingRows int NULL, IsInvalid bit
        );
        INSERT INTO #InitialArchiveValidity (RunId, Validity, InvalidRows, RemainingRows, IsInvalid)
        EXEC agora.usp_Recon_RunValidity @RunIdsJson = @CandidateJson, @InvalidOnly = 1;

        DELETE c FROM #ArchiveCandidates c
        WHERE NOT EXISTS (SELECT 1 FROM #InitialArchiveValidity v WHERE v.RunId = c.RunId AND v.IsInvalid = 1);

        SELECT @CandidateJson = N'[' + ISNULL(STRING_AGG(CONVERT(nvarchar(max), RunId), N','), N'') + N']'
        FROM #ArchiveCandidates;
    END;
    SET @Matched = (SELECT COUNT(*) FROM #ArchiveCandidates);

    BEGIN TRY
        /* Retain source key/range locks from the final validity read through
           the close. A concurrent reversal cannot restore outstanding bank
           rows between proof and update. SQL Server restores the caller's
           isolation level when this stored procedure returns. */
        SET TRANSACTION ISOLATION LEVEL SERIALIZABLE;
        BEGIN TRANSACTION;

        /* Stable lock order also serializes overlapping bulk requests. */
        DECLARE archive_locks CURSOR LOCAL FAST_FORWARD FOR
            SELECT RunId FROM #ArchiveCandidates ORDER BY RunId;
        OPEN archive_locks;
        FETCH NEXT FROM archive_locks INTO @RunId;
        WHILE @@FETCH_STATUS = 0
        BEGIN
            SET @LockedId = NULL;
            SELECT @LockedId = r.Id
            FROM agora.ReconRun r WITH (UPDLOCK, HOLDLOCK)
            WHERE r.Id = @RunId;
            FETCH NEXT FROM archive_locks INTO @RunId;
        END;
        CLOSE archive_locks;
        DEALLOCATE archive_locks;

        CREATE TABLE #ArchiveScope (RunId bigint PRIMARY KEY);
        INSERT INTO #ArchiveScope (RunId)
        EXEC agora.usp_Recon_RunScope
            @BranchIds = @BranchIds, @DateFrom = @DateFrom, @DateTo = @DateTo,
            @Search = @Search, @FiltersJson = @FiltersJson, @ReconArea = @ReconArea,
            @MineOnly = @MineOnly, @UserId = @UserId,
            @AllowedBranchIds = @AllowedBranchIds, @OpenOnly = @OpenOnly,
            @RunIdsJson = @CandidateJson;

        SELECT @ScopedJson = N'[' + ISNULL(STRING_AGG(CONVERT(nvarchar(max), RunId), N','), N'') + N']'
        FROM #ArchiveScope;

        CREATE TABLE #ArchiveValidity (
            RunId bigint PRIMARY KEY, Validity nvarchar(12),
            InvalidRows int NULL, RemainingRows int NULL, IsInvalid bit
        );
        INSERT INTO #ArchiveValidity (RunId, Validity, InvalidRows, RemainingRows, IsInvalid)
        EXEC agora.usp_Recon_RunValidity @RunIdsJson = @ScopedJson, @InvalidOnly = 1;

        UPDATE r
        SET Status = 'closed', UpdatedAt = SYSUTCDATETIME(), UpdatedBy = @UserId
        FROM agora.ReconRun r
        JOIN #ArchiveScope s ON s.RunId = r.Id
        JOIN #ArchiveValidity v ON v.RunId = r.Id AND v.IsInvalid = 1
        WHERE r.Status = 'previewed'
          AND NOT EXISTS (SELECT 1 FROM agora.ReconBatch b WHERE b.BranchId = r.BranchId AND b.RunId = r.Id)
          AND NOT EXISTS (SELECT 1 FROM agora.ReconMatch m WHERE m.BranchId = r.BranchId AND m.RunId = r.Id)
          AND NOT EXISTS (SELECT 1 FROM agora.ReconStamp s WHERE s.BranchId = r.BranchId AND s.RunId = r.Id)
          AND NOT EXISTS (SELECT 1 FROM agora.ReconAllocation a WHERE a.BranchId = r.BranchId AND a.RunId = r.Id);
        SET @Archived = @@ROWCOUNT;

        COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF XACT_STATE() <> 0 ROLLBACK TRANSACTION;
        THROW;
    END CATCH;

    SELECT CONVERT(bit, 1) AS Ok, 'ARCHIVED' AS Code,
           CONVERT(nvarchar(20), @Archived) + N' invalid runs archived; '
             + CONVERT(nvarchar(20), @Matched - @Archived) + N' matching runs kept.' AS Message,
           CONVERT(bigint, @Archived) AS Id,
           @Archived AS Archived, @Matched - @Archived AS Skipped;
END
