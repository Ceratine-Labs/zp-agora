/* ============================================================================
   agora.usp_Recon_GetAllocation

   Read by:  the run page (Recon -> a run -> "Who the cash is accounted to"),
             and the run's proposals extract (app.recon.run).
   Reads:    agora.ReconAllocation, ReconBatch, ReconMatch, agora.[User].
   Writes:   nothing.

   THE SPLIT BY CASHIER OF ONE RUN, as the clerk confirmed it. A cash-bag match
   by hand keeps its split in agora.ReconAllocation, one row per cashier line,
   written by usp_Recon_ManualMatch inside the match's own transaction. Until
   27 Sep 2026 the only place to read it back was the trace. A split that only
   exists at match time is not an accountability record, so the run it was
   made on shows it — and so does the extract of that run.

   SIGN. Share is BANK LESS BAGS, the sign the split was confirmed in: negative
   is money the bank did not receive, and only a negative share on a line with
   a cashier is ever a staff short. Accountable = Banked + Share.

   REVERSED LINES COME BACK TOO, marked. usp_Recon_Reverse never deletes a
   split; it stamps ReversedAt and the reason and turns a short that was due
   and never raised into 'cancelled'. The figures stay readable because they
   are the record of what was confirmed.

   Returns two sets, both empty for a run with no split (every auto run, and
   every manual match outside CashBags):

     1. one row per cashier line — the line, the batch it belongs to and the
        proposal (RunLineId) that batch settled, who confirmed it and when,
        and whether and why it was reversed. Largest banked first within a
        batch, the order the split is drawn in on the match screen.
     2. one row per batch that carries a split — the batch's own figures
        beside the sums of its lines, and Ties = 1 when the lines add up to
        the batch: bags to MopsRowCount, banked to MopsTotal, the shares to
        the variance (BankTotal - MopsTotal), accountable to BankTotal.
        usp_Recon_ManualMatch refuses a split that does not, so Ties = 0
        means something changed a row after the match, and the page says so.
        ShortDue is what the lines' staff shorts come to (a positive amount,
        as BRN_StaffShorts carries it): due and not yet raised, or raised.
   ============================================================================ */

CREATE OR ALTER PROCEDURE [agora].[usp_Recon_GetAllocation]
    @BranchId int,
    @RunId    bigint
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @Lines TABLE (
        Id              bigint        NOT NULL,
        RunId           bigint        NOT NULL,
        RunLineId       bigint        NULL,
        BatchId         bigint        NOT NULL,
        BatchNo         int           NOT NULL,
        CashierCode     nvarchar(50)  NULL,
        CashierName     nvarchar(200) NULL,
        Bags            int           NOT NULL,
        Banked          money         NOT NULL,
        DropSafe        money         NULL,
        FirstBagDate    date          NOT NULL,
        LastBagDate     date          NOT NULL,
        DerivedShare    money         NOT NULL,
        Share           money         NOT NULL,
        Accountable     money         NOT NULL,
        Basis           nvarchar(10)  NOT NULL,
        ShortStatus     nvarchar(20)  NOT NULL,
        StaffShortsNo   int           NULL,
        ConfirmedAt     datetime      NULL,
        ConfirmedByName nvarchar(80)  NULL,
        ReversedAt      datetime      NULL,
        ReversedByName  nvarchar(80)  NULL,
        ReversalReason  nvarchar(300) NULL
    );

    INSERT INTO @Lines
    SELECT a.Id, a.RunId,
           /* A batch settles one proposal, and every match row of it names
              the same one; TOP 1 keeps a label from changing the row count. */
           (SELECT TOP 1 m.RunLineId FROM agora.ReconMatch m
            WHERE m.BranchId = a.BranchId AND m.BatchId = a.BatchId ORDER BY m.Id),
           a.BatchId, a.BatchNo, a.CashierCode, a.CashierName, a.Bags, a.Banked, a.DropSafe,
           a.FirstBagDate, a.LastBagDate, a.DerivedShare, a.Share, a.Accountable, a.Basis,
           a.ShortStatus, a.StaffShortsNo, a.ConfirmedAt, cu.UserName,
           a.ReversedAt, ru.UserName, a.ReversalReason
    FROM agora.ReconAllocation a
    LEFT JOIN agora.[User] cu ON cu.Id = a.ConfirmedBy
    LEFT JOIN agora.[User] ru ON ru.Id = a.ReversedBy
    WHERE a.BranchId = @BranchId
      AND a.RunId = @RunId;

    SELECT l.*
    FROM @Lines l
    ORDER BY l.BatchNo DESC, l.Banked DESC, ISNULL(l.CashierCode, N'') COLLATE Latin1_General_BIN2, l.Id;

    SELECT b.Id AS BatchId,
           b.BatchNo,
           s.RunLineId,
           b.State,
           b.BankTotal,
           b.MopsTotal,
           b.MopsRowCount,
           b.BankTotal - b.MopsTotal AS Variance,
           b.ReversedAt,
           b.ReversalReason,
           s.Lines,
           s.Bags,
           s.Banked,
           s.DropSafe,
           s.Share,
           s.Accountable,
           s.ShortDue,
           CONVERT(bit, CASE WHEN s.Bags = b.MopsRowCount
                              AND s.Banked = b.MopsTotal
                              AND s.Share = b.BankTotal - b.MopsTotal
                              AND s.Accountable = b.BankTotal
                             THEN 1 ELSE 0 END) AS Ties
    FROM (
        SELECT BatchId,
               MAX(RunLineId)   AS RunLineId,
               COUNT(*)         AS Lines,
               SUM(Bags)        AS Bags,
               SUM(Banked)      AS Banked,
               SUM(DropSafe)    AS DropSafe,
               SUM(Share)       AS Share,
               SUM(Accountable) AS Accountable,
               ISNULL(SUM(CASE WHEN ShortStatus IN ('pending', 'raised') THEN -Share END), 0) AS ShortDue
        FROM @Lines
        GROUP BY BatchId
    ) s
    JOIN agora.ReconBatch b ON b.BranchId = @BranchId AND b.Id = s.BatchId
    ORDER BY b.BatchNo DESC;
END
