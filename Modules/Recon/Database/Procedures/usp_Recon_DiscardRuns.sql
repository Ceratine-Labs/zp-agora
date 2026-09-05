/* ============================================================================
   agora.usp_Recon_DiscardRuns

   Throw away previews.

   A run is a record of a READ. Nothing in the customer's estate moved to make
   one, so discarding it destroys no business fact — it clears the working list
   of a clerk who has previewed the same month four times while narrowing the
   dates. That is the opposite of a cashup or a drop-safe bag, which are
   reversed and never deleted (feature-rules §4).

   THE ONE RULE, and it is here rather than in PHP because that is where a rule
   belongs: a run that has been COMMITTED is evidence and can never be
   discarded. Once a run has stamped reconciliations it is the only record of
   what was stamped, on whose authority and against which rules — the thing
   PumpIT has never had, and the reason question 3.7 of the findings could only
   be guessed at. Deleting one would recreate the problem the ledger exists to
   solve.

   Scope, widening as arguments are omitted:
     @RunId      one run.
     @ReconArea  every uncommitted run for the branch in that area.
     (neither)   every uncommitted run for the branch.

   Never crosses branches. A clerk clearing their own working list must not be
   able to clear another site's, and @BranchId is required for that reason.

   Returns the writer status row: (Ok, Code, Message, Id) where Id is the
   number of runs discarded.
   ============================================================================ */

CREATE OR ALTER PROCEDURE [agora].[usp_Recon_DiscardRuns]
    @BranchId  int,
    @ReconArea nvarchar(20) = NULL,
    @RunId     bigint       = NULL,
    @UserId    int          = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    IF @BranchId IS NULL
        THROW 51000, 'AGORA:BRANCH_REQUIRED:A discard is always scoped to one branch.', 1;

    /* Named first, so the refusal below can be specific about WHY, and so the
       delete and the count cannot disagree. */
    DECLARE @Targets TABLE (Id bigint PRIMARY KEY, Status nvarchar(20));

    INSERT INTO @Targets (Id, Status)
    SELECT r.Id, r.Status
    FROM agora.ReconRun r
    WHERE r.BranchId = @BranchId
      AND (@RunId IS NULL OR r.Id = @RunId)
      AND (@ReconArea IS NULL OR r.ReconArea = @ReconArea);

    IF @RunId IS NOT NULL AND NOT EXISTS (SELECT 1 FROM @Targets)
        THROW 51000, 'AGORA:RUN_NOT_FOUND:That run does not exist on this branch.', 1;

    /* Asking for one committed run by id is a mistake worth saying out loud.
       A sweep across an area silently skips them instead — the clerk asked to
       clear their previews, not to be stopped by history. */
    IF @RunId IS NOT NULL AND EXISTS (SELECT 1 FROM @Targets WHERE Status = 'committed')
        THROW 51000, 'AGORA:RUN_COMMITTED:That run has been executed. A committed run is the only record of what it stamped and is never discarded.', 1;

    DELETE FROM @Targets WHERE Status = 'committed';

    DECLARE @Count int = (SELECT COUNT(*) FROM @Targets);

    DELETE l
    FROM agora.ReconRunLine l
    INNER JOIN @Targets t ON t.Id = l.RunId
    WHERE l.BranchId = @BranchId;

    DELETE r
    FROM agora.ReconRun r
    INNER JOIN @Targets t ON t.Id = r.Id
    WHERE r.BranchId = @BranchId;

    SELECT CONVERT(bit, 1)                                   AS Ok,
           'DISCARDED'                                       AS Code,
           CASE WHEN @Count = 0
                THEN 'Nothing to discard.'
                WHEN @Count = 1
                THEN 'One preview discarded.'
                ELSE CONVERT(nvarchar(10), @Count) + ' previews discarded.' END AS Message,
           CONVERT(bigint, @Count)                           AS Id;
END
