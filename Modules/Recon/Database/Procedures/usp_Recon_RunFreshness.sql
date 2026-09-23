/* ============================================================================
   agora.usp_Recon_RunFreshness

   Has somebody else reconciled what this preview is offering to stamp?

   Read by:  the run page (ReconController::show), for a 'previewed' run, to put
             a warning above the Reconcile button and mark the rows it names.
   Reads:    agora.ReconRun, agora.ReconRunLine, agora.ReconBatch, agora.[User]
   Writes:   nothing.

   WHY IT EXISTS. Ryan, 9 Sep 2026: "if the user resumes a run, it needs to
   check the rows again if some one else has done it." The commit already
   re-checks every row and skips anything that moved — nothing is ever stamped
   twice — but it says so only AFTER the press, and a clerk who resumes a
   two-week-old preview presses Reconcile on forty batches and is told that
   thirty-eight were already done. On live by 23 Sep 2026, three runs had been
   committed that stamped nothing at all for exactly that reason, and 157
   ticked proposals had been refused at commit.

   WHAT IT CHECKS, AND WHAT IT DELIBERATELY DOES NOT.
   It asks one question per pending proposal: has ANOTHER Agora run committed
   a batch in this branch and area, on the same reference (KeyRef, and KeyRef2
   where the area has one), since this run was previewed? Measured on live on
   23 Sep 2026 before writing it: of the 155 refusals outside Smart ATM that
   said "reconciled by something else", 146 were exactly this — another Agora
   run on the same key, committed after the preview.

   It does NOT re-drill. usp_Recon_Commit re-finds every proposal's rows
   through usp_Recon_DrillBank and usp_Recon_DrillMops, one cursor step per
   proposal, and on a busy branch-month that is what timed out three executes
   on 8 Sep. A warning that took as long as the commit would never be looked
   at. This is one set-based read of Agora's own ledger, and it answers the
   case that actually happens.

   What it cannot see, it does not pretend to: a line stamped by the legacy
   executable, or by a screen in PumpIT, is not in Agora's ledger. The commit's
   own re-check still catches those, and the page says so.

   A matching key is a strong sign, not proof. A reference can recur, so the
   page UNTICKS the rows named here rather than removing their tick boxes — the
   clerk can put a tick back, and the commit re-check has the final word.

   TWO CLOCKS, AND WHY "SINCE" NEEDS CARE. ReconRun.CreatedAt is written by the
   application, in its own timezone (config app.timezone, 'UTC'), while
   ReconBatch.CreatedAt is written by usp_Recon_Commit from SYSDATETIME(), which
   on ZP-MIST-SVR is +02:00. Compared raw, "committed after the preview" would
   also catch batches committed up to two hours BEFORE it. So the preview time
   is moved onto the server's clock first: back to UTC by the offset the
   application passes (@AppUtcOffsetMinutes, 0 for a UTC application), then
   forward by the server's own offset from UTC.

   Returns two sets:
     1. one row — Pending (reconcilable and not yet committed), PendingTotal,
        Claimed, ClaimedTotal, ClaimingRuns, PreviewedAt;
     2. one row per claimed proposal — RunLineId, KeyRef, KeyRef2,
        ClaimedByRunId, ClaimedBatchNo, ClaimedAt (on the application's
        clock), ClaimedBy.
   ============================================================================ */

CREATE OR ALTER PROCEDURE [agora].[usp_Recon_RunFreshness]
    @RunId               bigint,
    @BranchId            int,
    @AppUtcOffsetMinutes int = 0
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @Area nvarchar(20), @PreviewedAt datetime2(0);

    SELECT @Area = r.ReconArea, @PreviewedAt = r.CreatedAt
    FROM agora.ReconRun r
    WHERE r.Id = @RunId AND r.BranchId = @BranchId;

    IF @Area IS NULL
        THROW 51000, 'AGORA:RUN_NOT_FOUND:That run does not exist on this branch.', 1;

    /* The preview time on the server's clock, which is the clock the batches
       carry — and, going the other way, each claim's time on the
       application's clock, so the page can say "ten minutes ago" and mean it.
       See the header. */
    DECLARE @ServerOffset int = DATEDIFF(minute, SYSUTCDATETIME(), SYSDATETIME()),
            @AppOffset    int = ISNULL(@AppUtcOffsetMinutes, 0);
    DECLARE @PreviewedAtServer datetime2(0) =
        DATEADD(minute, @ServerOffset, DATEADD(minute, -@AppOffset, @PreviewedAt));

    DECLARE @Pending TABLE (
        RunLineId      bigint PRIMARY KEY,
        KeyRef         nvarchar(50) NULL,
        KeyRef2        nvarchar(50) NULL,
        BankTotal      money        NULL,
        ClaimedByRunId bigint       NULL,
        ClaimedBatchNo int          NULL,
        ClaimedAt      datetime2(0) NULL,
        ClaimedBy      nvarchar(80) NULL
    );

    INSERT INTO @Pending (RunLineId, KeyRef, KeyRef2, BankTotal,
                          ClaimedByRunId, ClaimedBatchNo, ClaimedAt, ClaimedBy)
    SELECT l.Id, l.KeyRef, l.KeyRef2, l.BankTotal,
           c.RunId, c.BatchNo, c.CreatedAt, c.UserName
    FROM agora.ReconRunLine l
    OUTER APPLY (
        /* The newest claim, so the page names the run a clerk is most likely
           to recognise — the one that happened this morning. */
        SELECT TOP 1 b.RunId, b.BatchNo, b.CreatedAt, u.UserName
        FROM agora.ReconBatch b
        LEFT JOIN agora.[User] u ON u.Id = b.CreatedBy
        WHERE b.BranchId  = l.BranchId
          AND b.ReconArea = @Area
          AND b.RunId    <> @RunId
          AND b.State     = 'committed'
          AND b.KeyRef    = l.KeyRef
          AND ISNULL(b.KeyRef2, N'') = ISNULL(l.KeyRef2, N'')
          AND b.CreatedAt > @PreviewedAtServer
        ORDER BY b.CreatedAt DESC
    ) c
    WHERE l.BranchId = @BranchId
      AND l.RunId = @RunId
      AND l.WouldReconcile = 1
      AND l.CommitState = 'pending';

    SELECT COUNT(*)                                                               AS Pending,
           ISNULL(SUM(BankTotal), 0)                                              AS PendingTotal,
           ISNULL(SUM(CASE WHEN ClaimedByRunId IS NOT NULL THEN 1 ELSE 0 END), 0) AS Claimed,
           ISNULL(SUM(CASE WHEN ClaimedByRunId IS NOT NULL THEN BankTotal END), 0) AS ClaimedTotal,
           COUNT(DISTINCT ClaimedByRunId)                                         AS ClaimingRuns,
           @PreviewedAt                                                           AS PreviewedAt
    FROM @Pending;

    SELECT RunLineId, KeyRef, KeyRef2, ClaimedByRunId, ClaimedBatchNo,
           DATEADD(minute, @AppOffset, DATEADD(minute, -@ServerOffset, ClaimedAt)) AS ClaimedAt,
           ClaimedBy
    FROM @Pending
    WHERE ClaimedByRunId IS NOT NULL
    ORDER BY RunLineId;
END
