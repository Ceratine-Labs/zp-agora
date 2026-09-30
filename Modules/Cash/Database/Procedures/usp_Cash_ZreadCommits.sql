/* ============================================================================
   agora.usp_Cash_ZreadCommits

   WHAT IT IS FOR
   "Saved presses" on the ZREAD Allocation screen: every Update ZREAD press
   that saved a Z-read dated inside the period, newest first, with whether it
   can still be reversed. It is where the Reverse button lives.

   One row per press (CommitRef):
     CommittedAt     when it was pressed — UTC, as every Agora timestamp is;
                     the screen converts it for display
     CommittedBy     who pressed it
     Zreads          how many Z-reads it saved
     StampMode       live (written to PumpIT) or journal (Agora only)
     State           in force · partly replaced · changed in PumpIT · reversed
     ChangedInPumpIt how many of its Z-reads were stamped and have since been
                     allocated differently on the PumpIT screen — the later
                     allocation wins (agora.vw_CashZread)
     CanReverse      1 while every allocation it saved is still the one in
                     force AND none has been changed in PumpIT since — the same
                     two refusals agora.usp_Cash_ReverseZreadCommit applies
                     (SUPERSEDED, MOVED), so the button and the procedure agree

   READ-ONLY.
   ============================================================================ */
CREATE OR ALTER PROCEDURE [agora].[usp_Cash_ZreadCommits]
    @BranchId  INT,
    @DateFrom  DATE,
    @DateTo    DATE,
    @Top       INT = 20
AS
BEGIN
    SET NOCOUNT ON;

    SET @Top = CASE WHEN @Top BETWEEN 1 AND 200 THEN @Top ELSE 20 END;

    SELECT TOP (@Top)
        c.CommitRef,
        c.CommittedAt,
        u.UserName AS CommittedBy,
        c.Zreads,
        c.FirstDate,
        c.LastDate,
        c.StampMode,
        CONVERT(NVARCHAR(20), CASE
            WHEN c.Reversed = c.Zreads THEN 'reversed'
            WHEN c.Superseded > 0      THEN 'partly replaced'
            WHEN ch.Changed > 0        THEN 'changed in PumpIT'
            ELSE 'in force' END) AS [State],
        ch.Changed AS ChangedInPumpIt,
        CONVERT(BIT, CASE WHEN c.Allocated = c.Zreads AND ch.Changed = 0 THEN 1 ELSE 0 END) AS CanReverse,
        c.ReversedAt,
        c.ReversalReason
    FROM (
        SELECT a.CommitRef,
               MIN(a.AllocatedAt)  AS CommittedAt,
               MIN(a.AllocatedBy)  AS AllocatedBy,
               COUNT(*)            AS Zreads,
               MIN(a.ZReadDate)    AS FirstDate,
               MAX(a.ZReadDate)    AS LastDate,
               MAX(a.StampMode)    AS StampMode,
               SUM(CASE WHEN a.[Status] = 'allocated'  THEN 1 ELSE 0 END) AS Allocated,
               SUM(CASE WHEN a.[Status] = 'superseded' THEN 1 ELSE 0 END) AS Superseded,
               SUM(CASE WHEN a.[Status] = 'reversed'   THEN 1 ELSE 0 END) AS Reversed,
               MAX(a.ReversedAt)     AS ReversedAt,
               MAX(a.ReversalReason) AS ReversalReason
        FROM agora.ZreadAllocation a
        WHERE a.BranchId = @BranchId
          AND a.CommitRef IS NOT NULL
          AND a.CommitRef IN (SELECT x.CommitRef FROM agora.ZreadAllocation x
                              WHERE x.BranchId = @BranchId AND x.CommitRef IS NOT NULL
                                AND x.ZReadDate BETWEEN @DateFrom AND @DateTo)
        GROUP BY a.CommitRef
    ) c
    OUTER APPLY (SELECT TOP 1 us.UserName FROM agora.[User] us WHERE us.Id = c.AllocatedBy) u
    /* The press's allocations still on record, read through the view that
       decides whether PumpIT has since moved on — by branch and day first, so
       the legacy table is sought, not scanned. */
    OUTER APPLY (
        SELECT COUNT(*) AS Changed
        FROM agora.ZreadAllocation a
        JOIN agora.vw_CashZread v
          ON v.BranchId = a.BranchId
         AND v.ZReadAt >= a.ZReadDate AND v.ZReadAt < DATEADD(day, 1, a.ZReadDate)
         AND v.AllocationId = a.Id
         AND v.ChangedInPumpItAfterAgora = 1
        WHERE a.BranchId = @BranchId AND a.CommitRef = c.CommitRef AND a.[Status] = 'allocated'
    ) ch
    ORDER BY c.CommittedAt DESC, c.CommitRef;
END
