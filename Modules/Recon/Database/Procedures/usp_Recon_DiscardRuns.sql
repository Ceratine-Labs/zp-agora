/* ============================================================================
   agora.usp_Recon_DiscardRuns

   Tidy the working list: throw previews away, or mark one complete, or reopen
   one that was marked complete.

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

   THREE GESTURES, ONE PROCEDURE, BECAUSE THEY SHARE THAT RULE (23 Sep 2026).
   Ryan's sentence was about closing as much as removing, and two definitions
   of "processed" is how the reversed-run hole happened in the first place. So
   marking a run complete and reopening it live here, beside the discard, and
   read the same @Targets.Processed — not a copy of it.

     @Action = 'discard'  (the default, and every caller before 23 Sep)
               Delete the targeted previews. Scope, widening as arguments are
               omitted:
                 @RunId      one run.
                 @ReconArea  every unprocessed run for the branch in that area.
                 (neither)   every unprocessed run for the branch.
               A SWEEP (no @RunId) narrows further on request:
                 @OlderThanDays  only runs made more than N days ago. NULL is
                                 no age limit — the behaviour before it existed.
                 @CreatedBy      only this person's runs. The Runs tab shows a
                                 clerk her own runs by default, and a Clear
                                 button under that list must not quietly reach
                                 a colleague's.
               A sweep NEVER takes a run marked complete. Closing is how a
               clerk says "keep this, but get it out of my way", and a clear
               that swept it anyway would make closing pointless past the age
               limit. A closed run can still be discarded by its own id.

     @Action = 'close'    Mark one run complete (@RunId required). Offered only
               where nothing has been processed against it, and only on a run
               that finished previewing — 'previewed' or 'failed'. A committed
               or reversed run is already history, and closing it would imply
               it could have gone another way. usp_Recon_Commit only executes a
               'previewed' run, so a closed run cannot be reconciled until it
               is reopened.

     @Action = 'reopen'   Undo a close (@RunId required). Closing destroys
               nothing, so it must be undoable. The run goes back to 'failed'
               if it carries a FailureCode — a refused preview stays refused —
               and to 'previewed' otherwise. Anything not 'closed' is refused:
               reopening a committed or reversed run would let a stamped run
               be executed again.

   Never crosses branches. A clerk tidying her own working list must not be
   able to touch another site's, and @BranchId is required for that reason.

   Returns the writer status row: (Ok, Code, Message, Id) where Id is the
   number of runs discarded, closed or reopened.
   ============================================================================ */

CREATE OR ALTER PROCEDURE [agora].[usp_Recon_DiscardRuns]
    @BranchId      int,
    @ReconArea     nvarchar(20) = NULL,
    @RunId         bigint       = NULL,
    @UserId        int          = NULL,
    @Action        nvarchar(10) = N'discard',
    @OlderThanDays int          = NULL,
    @CreatedBy     int          = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    SET @Action = ISNULL(@Action, N'discard');

    IF @BranchId IS NULL
        THROW 51000, 'AGORA:BRANCH_REQUIRED:A discard is always scoped to one branch.', 1;

    IF @Action NOT IN (N'discard', N'close', N'reopen')
        THROW 51000, 'AGORA:BAD_ACTION:A run can be discarded, closed or reopened — nothing else.', 1;

    IF @Action <> N'discard' AND @RunId IS NULL
        THROW 51000, 'AGORA:RUN_REQUIRED:Closing and reopening act on one run at a time.', 1;

    IF @OlderThanDays IS NOT NULL AND @OlderThanDays < 0
        THROW 51000, 'AGORA:BAD_AGE:An age limit is a number of days, zero or more.', 1;

    /* Named first, so the refusal below can be specific about WHY, and so the
       delete and the count cannot disagree. Processed is resolved ONCE, here,
       for the same reason: a refusal and a skip that computed it separately
       would eventually be computed differently. */
    DECLARE @Targets TABLE (Id bigint PRIMARY KEY, Status nvarchar(20), Processed bit, Failed bit);

    INSERT INTO @Targets (Id, Status, Processed, Failed)
    SELECT r.Id, r.Status,
           CASE WHEN r.Status IN ('committed', 'reversed')
                     OR EXISTS (SELECT 1 FROM agora.ReconBatch b
                                WHERE b.BranchId = r.BranchId AND b.RunId = r.Id)
                     OR EXISTS (SELECT 1 FROM agora.ReconMatch m
                                WHERE m.BranchId = r.BranchId AND m.RunId = r.Id)
                     OR EXISTS (SELECT 1 FROM agora.ReconStamp s
                                WHERE s.BranchId = r.BranchId AND s.RunId = r.Id)
                THEN 1 ELSE 0 END,
           CASE WHEN r.FailureCode IS NOT NULL THEN 1 ELSE 0 END
    FROM agora.ReconRun r
    WHERE r.BranchId = @BranchId
      AND (@RunId IS NULL OR r.Id = @RunId)
      AND (@ReconArea IS NULL OR r.ReconArea = @ReconArea)
      /* The two sweep narrowings. They are ignored for a named run: a person
         who asked for run 412 by its id asked for run 412. */
      AND (@RunId IS NOT NULL OR @OlderThanDays IS NULL
           OR r.CreatedAt < DATEADD(day, -@OlderThanDays, SYSDATETIME()))
      AND (@RunId IS NOT NULL OR @CreatedBy IS NULL OR r.CreatedBy = @CreatedBy);

    IF @RunId IS NOT NULL AND NOT EXISTS (SELECT 1 FROM @Targets)
        THROW 51000, 'AGORA:RUN_NOT_FOUND:That run does not exist on this branch.', 1;

    /* ---- close -------------------------------------------------------------- */

    IF @Action = N'close'
    BEGIN
        IF EXISTS (SELECT 1 FROM @Targets WHERE Processed = 1)
            THROW 51000, 'AGORA:RUN_PROCESSED:Something has been processed against this run, so it is already history. Only a run nothing was done with can be marked complete.', 1;

        IF EXISTS (SELECT 1 FROM @Targets WHERE Status = 'closed')
            THROW 51000, 'AGORA:RUN_ALREADY_CLOSED:This run is already marked complete.', 1;

        IF EXISTS (SELECT 1 FROM @Targets WHERE Status NOT IN ('previewed', 'failed'))
            THROW 51000, 'AGORA:RUN_NOT_FINISHED:This run has not finished previewing, so there is nothing yet to mark complete.', 1;

        UPDATE r
        SET r.Status = 'closed',
            r.UpdatedAt = SYSDATETIME(),
            r.UpdatedBy = @UserId
        FROM agora.ReconRun r
        INNER JOIN @Targets t ON t.Id = r.Id
        WHERE r.BranchId = @BranchId;

        SELECT CONVERT(bit, 1)                                        AS Ok,
               'CLOSED'                                               AS Code,
               'Run #' + CONVERT(nvarchar(20), @RunId) + ' marked complete. Nothing in PumpIT was touched, and it can be reopened.' AS Message,
               CONVERT(bigint, 1)                                     AS Id;
        RETURN;
    END

    /* ---- reopen ------------------------------------------------------------- */

    IF @Action = N'reopen'
    BEGIN
        IF EXISTS (SELECT 1 FROM @Targets WHERE Status <> 'closed')
            THROW 51000, 'AGORA:RUN_NOT_CLOSED:Only a run marked complete can be reopened. A committed or reversed run is history.', 1;

        UPDATE r
        SET r.Status = CASE WHEN t.Failed = 1 THEN 'failed' ELSE 'previewed' END,
            r.UpdatedAt = SYSDATETIME(),
            r.UpdatedBy = @UserId
        FROM agora.ReconRun r
        INNER JOIN @Targets t ON t.Id = r.Id
        WHERE r.BranchId = @BranchId;

        SELECT CONVERT(bit, 1)                                        AS Ok,
               'REOPENED'                                             AS Code,
               'Run #' + CONVERT(nvarchar(20), @RunId) + ' reopened.' AS Message,
               CONVERT(bigint, 1)                                     AS Id;
        RETURN;
    END

    /* ---- discard ------------------------------------------------------------ */

    /* Asking for one processed run by id is a mistake worth saying out loud.
       A sweep across an area silently skips them instead — the clerk asked to
       clear their previews, not to be stopped by history. */
    IF @RunId IS NOT NULL AND EXISTS (SELECT 1 FROM @Targets WHERE Processed = 1)
        THROW 51000, 'AGORA:RUN_COMMITTED:That run has been executed. A run that has stamped anything is the only record of what it stamped, and is never discarded — a reversed one doubly so, because it is also the record of the undoing.', 1;

    DELETE FROM @Targets WHERE Processed = 1;

    /* A sweep keeps what a clerk deliberately kept. See the header. */
    IF @RunId IS NULL
        DELETE FROM @Targets WHERE Status = 'closed';

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
