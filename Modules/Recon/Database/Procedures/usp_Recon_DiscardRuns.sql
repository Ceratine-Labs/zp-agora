/* ============================================================================
   agora.usp_Recon_DiscardRuns

   Throw away previews.

   A run is a record of a READ. Nothing in the customer's estate moved to make
   one, so discarding it destroys no business fact — it clears the working list
   of a clerk who has previewed the same month four times while narrowing the
   dates. That is the opposite of a cashup or a drop-safe bag, which are
   reversed and never deleted (feature-rules §4).

   THE ONE RULE, and it is here rather than in PHP because that is where a rule
   belongs: A RUN THAT HAS HAD ANYTHING PROCESSED AGAINST IT IS EVIDENCE AND IS
   NEVER DISCARDED. Once a run has stamped reconciliations it is the only
   record of what was stamped, on whose authority and against which rules — the
   thing PumpIT has never had, and the reason question 3.7 of the findings
   could only be guessed at. Deleting one would recreate the problem the ledger
   exists to solve.

   WHAT "PROCESSED" MEANS, and why the test is not `Status = 'committed'`.
   Ryan, 9 Sep 2026: "if anything is processed against a run we can't close it,
   else the ladies can remove it, but if anything processed then no." Until
   that sentence the guard named the committed status alone, and a REVERSED run
   walked straight through it — a run that stamped rows and then unstamped them
   has unarguably had something processed against it, and is the one record
   that both facts happened. Worse, this procedure deletes ReconRun and
   ReconRunLine and nothing else, so discarding a reversed run left its
   ReconBatch, ReconMatch and ReconStamp rows behind pointing at a RunId that
   no longer existed. Measured on the local container before the fix: runs 205
   and 206, both reversed, both swept, seven orphaned evidence rows each.

   So a run is protected when EITHER
     · its status says it was executed — 'committed' or 'reversed'; or
     · anything hangs off it in ReconBatch, ReconMatch or ReconStamp.
   The second test is the rule stated literally and the first is the cheap
   catch for a commit that stamped nothing (usp_Recon_Commit sets 'committed'
   even when every line was blocked, which is conservative and correct). Either
   one alone has a hole; both together do not.

   Scope, widening as arguments are omitted:
     @RunId      one run.
     @ReconArea  every unprocessed run for the branch in that area.
     (neither)   every unprocessed run for the branch.

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
       delete and the count cannot disagree. Processed is resolved ONCE, here,
       for the same reason: a refusal and a skip that computed it separately
       would eventually be computed differently. */
    DECLARE @Targets TABLE (Id bigint PRIMARY KEY, Status nvarchar(20), Processed bit);

    INSERT INTO @Targets (Id, Status, Processed)
    SELECT r.Id, r.Status,
           CASE WHEN r.Status IN ('committed', 'reversed')
                     OR EXISTS (SELECT 1 FROM agora.ReconBatch b
                                WHERE b.BranchId = r.BranchId AND b.RunId = r.Id)
                     OR EXISTS (SELECT 1 FROM agora.ReconMatch m
                                WHERE m.BranchId = r.BranchId AND m.RunId = r.Id)
                     OR EXISTS (SELECT 1 FROM agora.ReconStamp s
                                WHERE s.BranchId = r.BranchId AND s.RunId = r.Id)
                THEN 1 ELSE 0 END
    FROM agora.ReconRun r
    WHERE r.BranchId = @BranchId
      AND (@RunId IS NULL OR r.Id = @RunId)
      AND (@ReconArea IS NULL OR r.ReconArea = @ReconArea);

    IF @RunId IS NOT NULL AND NOT EXISTS (SELECT 1 FROM @Targets)
        THROW 51000, 'AGORA:RUN_NOT_FOUND:That run does not exist on this branch.', 1;

    /* Asking for one processed run by id is a mistake worth saying out loud.
       A sweep across an area silently skips them instead — the clerk asked to
       clear their previews, not to be stopped by history. */
    IF @RunId IS NOT NULL AND EXISTS (SELECT 1 FROM @Targets WHERE Processed = 1)
        THROW 51000, 'AGORA:RUN_COMMITTED:That run has been executed. A run that has stamped anything is the only record of what it stamped, and is never discarded — a reversed one doubly so, because it is also the record of the undoing.', 1;

    DELETE FROM @Targets WHERE Processed = 1;

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
