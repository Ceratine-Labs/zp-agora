/* ============================================================================
   agora.usp_Recon_PairNearReferences

   Where an extraction is a character out, JOIN the two orphans into the one
   reconciliation they actually are — and say so on the row.

   THE CASE THIS EXISTS FOR
   ------------------------
   Branch 9, CashMachine, August 2026. The bank narrative
   `CF NPF CREDIT ABSA BANK CCB69744` yields `69744`; the deposit slip
   `D6974400001` yields `697440`. One reconciliation, reported as two orphans
   at opposite ends of the screen, because MOPS_EndPosition is an END POSITION
   in this area and was read as a LENGTH (finding 9 — the column means
   different things in different areas and does not say which).

   An earlier version of this only FLAGGED the two rows. Ryan's call, 4
   September 2026: "if you're able to find a near reference, mark it but match
   it — it needs to be a match with a flag." He is right. A flag still leaves
   the operator to do the join in their head, and leaves the run reporting 31
   findings where there are 20.

   So the deposit-only row is ABSORBED into the bank-only row: the two sides
   are added together, the difference recomputed, and the outcome decided the
   way any other row's is — Matched when the totals agree, Amount mismatch when
   they do not. `MopsKeyRef` records the reference the deposits are really
   under, which is what the drill and the commit address them by from then on.
   The absorbed row is deleted; nothing is lost, because NearRefNote carries
   both references and PairedFromLineId carries its id.

   ** A PAIRED ROW CAN RECONCILE. ** That is the point of the change, and it is
   worth being explicit: such a row is matched on an INFERENCE about the
   customer's configuration, not on the configured rule. It is therefore
   always flagged, the note on it names both references, and — like every other
   row — it reconciles nothing until an operator ticks it and
   agora.usp_Recon_Commit re-checks both sides against the estate.

   WHAT COUNTS AS NEAR — deliberately narrow, because a false pairing would
   reconcile two things that have nothing to do with each other:
     · one row Bank only, the other Deposit only, in the same run;
     · both references at least 4 characters;
     · the SHORTER is a PREFIX of the longer — an extraction that ran on past
       the end, which is the error being looked for, not a typo in the middle;
     · they differ by ONE or TWO characters, no more.
   Closest counterpart wins, and a row already claimed cannot be claimed again.

   Reads and writes agora.ReconRunLine and agora.ReconRun only.
   ============================================================================ */

CREATE OR ALTER PROCEDURE [agora].[usp_Recon_PairNearReferences]
    @RunId    bigint,
    @BranchId int
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE @Pairs TABLE (
        BankLineId    bigint PRIMARY KEY,
        DepositLineId bigint,
        BankRef       nvarchar(50),
        DepositRef    nvarchar(50),
        Distance      int
    );

    ;WITH BankOnly AS (
        SELECT Id, KeyRef FROM agora.ReconRunLine
        WHERE BranchId = @BranchId AND RunId = @RunId
          AND KeyRef IS NOT NULL AND LEN(KeyRef) >= 4
          AND Outcome LIKE 'Bank only%'
    ),
    DepositOnly AS (
        SELECT Id, KeyRef FROM agora.ReconRunLine
        WHERE BranchId = @BranchId AND RunId = @RunId
          AND KeyRef IS NOT NULL AND LEN(KeyRef) >= 4
          AND Outcome LIKE 'Deposit only%'
    ),
    Candidate AS (
        SELECT b.Id AS BankLineId, d.Id AS DepositLineId,
               b.KeyRef AS BankRef, d.KeyRef AS DepositRef,
               ABS(LEN(b.KeyRef) - LEN(d.KeyRef)) AS Distance,
               ROW_NUMBER() OVER (
                   PARTITION BY b.Id
                   ORDER BY ABS(LEN(b.KeyRef) - LEN(d.KeyRef)), d.Id
               ) AS Rank
        FROM BankOnly b
        JOIN DepositOnly d
          ON  b.KeyRef <> d.KeyRef
          AND ABS(LEN(b.KeyRef) - LEN(d.KeyRef)) BETWEEN 1 AND 2
          AND (
                (LEN(b.KeyRef) < LEN(d.KeyRef) AND LEFT(d.KeyRef, LEN(b.KeyRef)) = b.KeyRef)
             OR (LEN(d.KeyRef) < LEN(b.KeyRef) AND LEFT(b.KeyRef, LEN(d.KeyRef)) = d.KeyRef)
          )
    )
    INSERT INTO @Pairs (BankLineId, DepositLineId, BankRef, DepositRef, Distance)
    SELECT BankLineId, DepositLineId, BankRef, DepositRef, Distance
    FROM Candidate WHERE Rank = 1;

    /* A deposit claimed by two bank rows goes to the closest one only. */
    DELETE p FROM @Pairs p
    WHERE EXISTS (
        SELECT 1 FROM @Pairs q
        WHERE q.DepositLineId = p.DepositLineId
          AND (q.Distance < p.Distance OR (q.Distance = p.Distance AND q.BankLineId < p.BankLineId))
    );

    IF NOT EXISTS (SELECT 1 FROM @Pairs)
    BEGIN
        SELECT CONVERT(bit, 1) AS Ok, 'NONE' AS Code,
               'No near references.' AS Message, CONVERT(bigint, 0) AS Id;
        RETURN;
    END

    /* ---- Absorb the deposit side into the bank row ------------------------- */

    UPDATE b
    SET b.MopsKeyRef       = p.DepositRef,
        b.PairedFromLineId = p.DepositLineId,
        b.NearRefLineId    = p.DepositLineId,
        b.MopsTxns         = d.MopsTxns,
        b.MopsTotal        = d.MopsTotal,
        b.DiffAmount       = d.MopsTotal - b.BankTotal,
        /* Decided exactly as any other row's is. The pairing changes which
           deposits are in front of us, not what counts as a match. */
        b.Outcome          = CASE WHEN d.MopsTotal = b.BankTotal
                                  THEN 'Matched - reference read a character apart'
                                  ELSE 'Amount mismatch - reference read a character apart' END,
        b.WouldReconcile   = CASE WHEN d.MopsTotal = b.BankTotal THEN 1 ELSE 0 END,
        b.NearRefNote      = 'Paired on an inferred reference: the bank reads ' + p.BankRef
                           + ' and the deposit side reads ' + p.DepositRef + ', '
                           + CONVERT(nvarchar(2), p.Distance)
                           + CASE WHEN p.Distance = 1 THEN ' character' ELSE ' characters' END
                           + ' apart. MOPS_EndPosition is stored as an end position in some areas and a '
                           + 'length in others, and the column does not say which — so this pairing is '
                           + 'an inference, not the configured rule.',
        b.UpdatedAt        = SYSDATETIME()
    FROM agora.ReconRunLine b
    JOIN @Pairs p ON p.BankLineId = b.Id
    JOIN agora.ReconRunLine d ON d.Id = p.DepositLineId AND d.BranchId = b.BranchId
    WHERE b.BranchId = @BranchId;

    /* The absorbed row goes. Nothing is lost: both references are in the note
       and PairedFromLineId keeps its id. Leaving it would report the deposits
       twice — once on its own row and once inside the pairing — and every
       total on the run would be wrong. */
    DELETE l
    FROM agora.ReconRunLine l
    JOIN @Pairs p ON p.DepositLineId = l.Id
    WHERE l.BranchId = @BranchId;

    /* ---- The run's counts are now wrong; recompute them from the rows ------ */

    UPDATE r
    SET r.TotalRows       = a.TotalRows,
        r.MatchedRows     = a.MatchedRows,
        r.MismatchRows    = a.MismatchRows,
        r.BankOnlyRows    = a.BankOnlyRows,
        r.DepositOnlyRows = a.DepositOnlyRows,
        r.OtherRows       = a.OtherRows,
        r.MopsTotal       = a.MopsTotal,
        r.MatchedTotal    = a.MatchedTotal,
        r.UpdatedAt       = SYSDATETIME()
    FROM agora.ReconRun r
    CROSS APPLY (
        SELECT COUNT(*) AS TotalRows,
               SUM(CASE WHEN l.WouldReconcile = 1 THEN 1 ELSE 0 END) AS MatchedRows,
               SUM(CASE WHEN l.WouldReconcile = 0 AND l.Outcome LIKE 'Amount mismatch%' THEN 1 ELSE 0 END) AS MismatchRows,
               SUM(CASE WHEN l.Outcome LIKE 'Bank only%' THEN 1 ELSE 0 END) AS BankOnlyRows,
               SUM(CASE WHEN l.Outcome LIKE 'Deposit only%' THEN 1 ELSE 0 END) AS DepositOnlyRows,
               SUM(CASE WHEN l.WouldReconcile = 0
                         AND l.Outcome NOT LIKE 'Amount mismatch%'
                         AND l.Outcome NOT LIKE 'Bank only%'
                         AND l.Outcome NOT LIKE 'Deposit only%' THEN 1 ELSE 0 END) AS OtherRows,
               ISNULL(SUM(l.MopsTotal), 0) AS MopsTotal,
               ISNULL(SUM(CASE WHEN l.WouldReconcile = 1 THEN l.BankTotal END), 0) AS MatchedTotal
        FROM agora.ReconRunLine l
        WHERE l.BranchId = r.BranchId AND l.RunId = r.Id
    ) a
    WHERE r.BranchId = @BranchId AND r.Id = @RunId;

    SELECT CONVERT(bit, 1) AS Ok,
           'PAIRED'         AS Code,
           CONVERT(nvarchar(10), (SELECT COUNT(*) FROM @Pairs))
             + ' near-reference pair(s) joined into single rows.' AS Message,
           CONVERT(bigint, (SELECT COUNT(*) FROM @Pairs)) AS Id;
END
