/* ============================================================================
   agora.usp_Recon_PreviewCashBags

   PORTED, NOT REWRITTEN. The body below is sp_RPT_AUTOReconCashBagsPreview as it was
   validated against ZP's own data, with two mechanical changes and no others:

     1. It is created in the agora schema on the AGORA database, under the
        module-prefixed name, rather than as a dbo.sp_RPT_* on PumpIT. Agora
        deploys and owns it, so it cannot be edited out from under us and the
        customer can diff ours against theirs.

     2. Every legacy table it reads is reached through the matching agora.vw_*
        view, which names PumpIT across databases. The procedure still fires
        AT PumpIT — same instance, three-part name — it just no longer lives
        there. `SSBranchId` reads as `BranchId` because that is what the views
        expose.

   Nothing about the logic moved. The corrections it already carried over the
   live AUTO RECON procedures are the point of it, and are described in the
   original header below:

     * both sides aggregated by reference before being compared, so a batch
       settled across several bank lines stops reporting the whole batch total
       against each one (finding 3);
     * a FULL OUTER JOIN with four named outcomes, so 'Matched' needs both
       sides present and equal — the live `IF @CurrAmount <> @MOPSAmount` is
       UNKNOWN when there is no deposit and falls through to the MATCHED
       branch (finding 2, critical);
     * deposit batches with no bank line are reported at all, which on the
       live screen they never are (finding 4);
     * extraction positions read from BRN_AutoReconCriteria for every rule
       rather than one row per branch (finding 8).

   STILL READ-ONLY. No INSERT, UPDATE, DELETE or DDL against anything in the
   customer's estate; table variables only. Executing a reconciliation is a
   separate, gated path and is not this procedure.

   Source: ~/Development/ZP/Zulu Petroleum/docs/sql/sp_RPT_AUTOReconCashBagsPreview.sql
   ============================================================================ */

/* ============================================================================
   sp_RPT_AUTOReconCashBagsPreview      PumpIT / ZP-MIST-SVR        2026-08-27

   PREVIEW ONLY — returns a result set. No INSERT / UPDATE / DELETE / DDL, no
   permanent object (table variables only), and no change to ReconState,
   ReconBatchNo or ReconBatchNoPumpIT on any table.

   The fifth and last of the preview set. Section 6.4 of the findings document
   said CashBags could not have one yet because its rules are not in
   BRN_AutoReconCriteria. That is still true of the FILTER_Value column, but it
   is not a reason to have no preview: this procedure reads what configuration
   there is, and where there is none it uses the extraction method measured in
   section 6.2.1 instead of a position that is known to be wrong.

   *** NOT TESTED. The procedure has not been compiled by SQL Server on our
   *** side and no output of it has been checked against the AUTO RECON screen.
   *** The query bodies are modelled on the four previews that were validated
   *** against live data; this one is not yet. Run it on branch 24 or 9 first
   *** and compare against the "Expected first run" section below.

   ---------------------------------------------------------------------------
   Sources mirrored from [dbo].[sp_AUTOReconcile_CashBags_BankRecon]
   ---------------------------------------------------------------------------
     bank  RCN_BankStatementLinesPumpIT   Type='CashDeposit', IDState=2,
                                          ReconState=1
                                          (as sp_SelectCashBagsBankStatement)
     MOPS  BRN_DailyBankingCashBags       ReconBatchNoPumpIT = 0, amount is
                                          CashBagAmount
                                          (as sp_SelectCashBagsDaily)

   Note the bank Type is 'CashDeposit', not 'CashBags'.

   The reference is DBagNo, which is NOT a column on BRN_DailyBankingCashBags.
   sp_SelectCashBagsDaily reaches it through two lookups:

       BRN_DailyBankingCashBags a
         LEFT JOIN BRN_DropSafe c            on SSBranchId, c.BagNo = a.CashBagNo
         LEFT JOIN BRN_DropSafe_Collection d on SSBranchId, d.CollectionId = c.CollectionId
       -> d.DBagNo

   and the live procedure then does

       update _SelectCashBagsDaily_AUTO set DBagNo = CashBagNo where DBagNo is Null

   so a bag with no drop-safe collection falls back to its own CashBagNo. Both
   behaviours are reproduced here. The two lookups are deduped in a CTE before
   the amount is touched — BRN_DropSafe is bag-level and
   BRN_DropSafe_Collection is collection-level, so a straight join can repeat
   CashBagAmount once per collection row and inflate the MOPS side.

   ---------------------------------------------------------------------------
   A defect specific to CashBags, and worse than finding 2
   ---------------------------------------------------------------------------
   Finding 2 of the findings document describes a NULL comparison that reaches
   the matched path when a bank line has no deposit behind it. In four of the
   five procedures that is an edge case. In CashBags it is every single line.

   sp_AUTOReconcile_CashBags_BankRecon declares

       declare @ReconField nvarchar(20), @MOPS_StartPosition int,
               @MOPS_EndPosition int, @BANK_StartPosition int,
               @Bank_EndPosition int

   and never assigns any of them — the BRN_AutoReconCriteria lookup that fills
   them in the other procedures is not present at all. The MOPS lookup is then

       select @MOPSAmount = sum(CashBagAmount)
         from _SelectCashBagsDaily_AUTO
        where substring(DBagNo, @MOPS_StartPosition, @MOPS_EndPosition) = @CurrRecord

   SUBSTRING with a NULL start and a NULL length returns NULL on every row, so
   the WHERE is never true, so @MOPSAmount is NULL for every bank line without
   exception. The decision is then

       if @CurrAmount <> @MOPSAmount     -- money <> NULL  ->  UNKNOWN

   which falls to the ELSE — the matched path. Under @Process = 1 that mints a
   reconciliation batch number and stamps the bank line ReconState = 2 for
   EVERY line the work table holds, while the deposit-side UPDATE (which keys
   off d.DBagNo, not the NULL substring, so it is not equally poisoned) may
   still affect zero rows.

   This is a code reading, not an inference: lines 70, 533 and 536 of the
   procedure as it stands on ZP-MIST-SVR. It has not been executed to confirm
   it, and it must not be — confirming it means running Execute.

   It applies to the 15 branches whose per-branch block builds
   _SelectCashBagsBankStatement_AUTO1. The other 7 (2, 18, 20, 22, 23, 25, 26)
   raise 'Invalid object name' before reaching the cursor, per finding 5.

   ---------------------------------------------------------------------------
   Why this procedure does not match on position by default
   ---------------------------------------------------------------------------
   Section 6.2.1: the CashBags reference is typed by whoever is on shift, so
   the narrative is free text after a stable merchant prefix. Branch 9 alone
   carries 36 distinct shapes since January 2025 and they interleave. Measured
   across all CashBags lines from 2025, the configured positions match 647 of
   3 558 lines (18.2%); stripping '-', '/', space and '_' from both the
   narrative and the bag number and testing containment matches 1 368 (38.4%),
   and rescues four branches that match nothing at all today.

   So @MatchMode defaults to 'contains'. 'position' is kept so the two can be
   run side by side on the same branch and the difference quantified, which is
   the whole point of a preview.

   38.4% is not a good number and this procedure does not pretend otherwise.
   706 of 3 558 narratives (19.8%) contain no run of 11 or more digits at all,
   because something other than a bag number was typed. Those are reported as
   'Bank only - no bag reference in narrative' rather than being folded in with
   genuine unmatched lines, so the recoverable gap stays separable from the
   part that no extraction method can reach.

   ---------------------------------------------------------------------------
   ProcessOrder, and why 'specific' is the default rule order
   ---------------------------------------------------------------------------
   Section 6.3: every live procedure reads its criteria with TOP 1 ... ORDER BY
   ProcessOrder, so extra rows are dead configuration. Branch 9 is configured
   for three narrative formats and only the first is ever read.

   This procedure iterates instead. But taking the first row by ProcessOrder is
   NOT what the live code does, and on branch 9 it gives the wrong answer:

       ProcessOrder 1   FILTER (1,22)  'SETTLEMENT ACB CREDIT '
       ProcessOrder 2   FILTER (1,37)  'CASHFOCUS CREDIT TRANSFER G4S 512911 '
       ProcessOrder 3   FILTER (1,33)  'SETTLEMENT ACB CREDIT G4S_512911_'

   A G4S_512911_ narrative satisfies rows 1 and 3 both. The hardcoded block in
   the live procedure resolves that by excluding G4S from the generic case:

       WHEN SUBSTRING(Description, 1, 22) = 'SETTLEMENT ACB CREDIT '
            AND SUBSTRING(Description, 1, 33) <> 'SETTLEMENT ACB CREDIT G4S_512911_'

   ProcessOrder as stored puts the generic prefix first, so a naive iteration
   picks (23,34) where the code picks (34,45). @RuleOrder = 'specific' orders
   by the length of FILTER_Value descending, so the most specific prefix wins
   and the live intent is reproduced without relying on ProcessOrder being
   right. 'processorder' is available for comparison.

   Whichever is chosen, the resolved row is reported per output row as
   UsedProcessOrder / UsedBankStart / UsedBankLen, so the rule that fired is
   never a guess.

   ---------------------------------------------------------------------------
   Position convention
   ---------------------------------------------------------------------------
   BANK_EndPosition is read as an END position when it is at or after
   BANK_StartPosition and as a LENGTH otherwise, so the procedure is correct
   before and after docs/sql/2026-08-18b-criteria-restore-and-endposition.sql
   is applied. Every CashBags start is 23, 30, 34 or 38 and every plausible
   length is 12 to 14, so the two readings never collide here.

   MOPS is NOT inferred. Every CashBags MOPS pair is (1, 12), where the two
   conventions give the same number, so there is no signal to infer from — and
   getting it wrong silently truncates the bag number. @MopsConvention is
   explicit and defaults to 'length', which is what the table holds today. Set
   it to 'endpos' only after that script's @DoMopsAndFilter switch has been
   committed.

   ---------------------------------------------------------------------------
   What the sources hold, measured 27 August 2026 — READ THIS BEFORE RUNNING
   ---------------------------------------------------------------------------
   The MOPS-side query body, including the drop-safe dedupe, was run inline as
   a read-only SELECT against live PumpIT for branches 9 and 24, 2026 to date:

     branch  9   unreconciled bank lines        1        180.00
     branch  9   unreconciled deposits         25     15,208.10
     branch  9   deposits with no collection    2   (DBagNo falls back to CashBagNo)
     branch  9   deposits fanning to >1 collection row   0
     branch 24   unreconciled bank lines        0
     branch 24   unreconciled deposits          0
     branch  9   bank lines already RECONCILED   2,394   20,006,840.81
     branch 24   bank lines already RECONCILED   1,811   18,583,005.00

   Two things follow, and the second is the important one.

   The dedupe is not currently load-bearing: no bag in that sample resolves to
   more than one BRN_DropSafe_Collection row. Keep it anyway — the join is
   collection-level against a bag-level table and there is nothing in the schema
   that prevents the fan-out, so this measures today's data, not a constraint.
   CollectionRows is carried through so a future fan-out is visible rather than
   silently doubling CashBagAmount.

   The important one: there is almost nothing left unreconciled to preview.
   Between these two branches, 4,205 CashDeposit bank lines worth 38,589,845.81
   are already stamped ReconState = 2, against a single outstanding line of
   180.00. Read that next to the defect above — every line the CashBags
   procedure examines takes the matched path, because the MOPS comparison is
   NULL on every row — and the shape of the problem is not "AUTO RECON does not
   match enough". It is that it appears to have matched everything, and this
   preview arrives after the fact.

   SO THE FIRST USEFUL RUN OF THIS PROCEDURE IS PROBABLY NOT A PREVIEW OF
   OUTSTANDING WORK. It is a re-examination of what has already been stamped.
   To do that, change the two ReconState / ReconBatchNoPumpIT predicates from
   "unreconciled" to "reconciled" and compare what this procedure would have
   matched against what was actually stamped. That is step 8 of the recommended
   sequence — produce the remediation list read-only first — and this procedure
   is most of it. It has deliberately NOT been changed to do that here, because
   the preview must mirror the live procedure's own filters to be comparable.

   ---------------------------------------------------------------------------
   Expected first run
   ---------------------------------------------------------------------------
   Not yet measured on the matching logic itself — there is too little
   outstanding data on branches 9 and 24 to exercise it. Run it on a branch and
   period that still holds unreconciled lines, fill this table in, and compare
   against a second run with @MatchMode = 'position' — the gap between the two
   is the finding, and it is the number to put in front of ZP.

     EXEC agora.usp_Recon_PreviewCashBags @BranchId = 24,
                                              @FromDate = '2026-01-01',
                                              @ToDate   = '2026-08-31';

     EXEC agora.usp_Recon_PreviewCashBags @BranchId = 9,
                                              @FromDate = '2026-01-01',
                                              @ToDate   = '2026-08-31',
                                              @RuleOrder = 'processorder';
   ============================================================================ */

CREATE OR ALTER PROCEDURE [agora].[usp_Recon_PreviewCashBags]
    @BranchId          int,
    @FromDate          datetime,
    @ToDate            datetime,
    @MatchMode         varchar(10) = 'contains',   /* 'contains' | 'position' */
    @RuleOrder         varchar(12) = 'specific',   /* 'specific' | 'processorder' */
    @MopsConvention    varchar(10) = 'length',     /* 'length'   | 'endpos'  */
    @MinBagKeyLen      int         = 11,
    @BankStartOverride int         = NULL,
    @BankLenOverride   int         = NULL
AS
BEGIN
    SET NOCOUNT ON;

    IF @MatchMode NOT IN ('contains', 'position')
    BEGIN
        SELECT '@MatchMode must be ''contains'' or ''position''.' AS Error, @MatchMode AS Supplied;
        RETURN;
    END

    IF @RuleOrder NOT IN ('specific', 'processorder')
    BEGIN
        SELECT '@RuleOrder must be ''specific'' or ''processorder''.' AS Error, @RuleOrder AS Supplied;
        RETURN;
    END

    IF @MopsConvention NOT IN ('length', 'endpos')
    BEGIN
        SELECT '@MopsConvention must be ''length'' or ''endpos''.' AS Error, @MopsConvention AS Supplied;
        RETURN;
    END

    /* ---- 1. Criteria rows for this branch, all of them --------------------- */

    DECLARE @Crit TABLE (
        ProcessOrder int,
        AutoReconId  int,
        FilterStart  int,
        FilterLen    int,
        FilterValue  nvarchar(50),
        BankStart    int,
        BankLen      int,
        MopsStart    int,
        MopsLen      int,
        Specificity  int
    );

    INSERT INTO @Crit (ProcessOrder, AutoReconId, FilterStart, FilterLen, FilterValue,
                       BankStart, BankLen, MopsStart, MopsLen, Specificity)
    SELECT ProcessOrder,
           AutoReconId,
           FILTER_StartPosition,
           /* FILTER pairs all start at 1, where both conventions agree. */
           CASE WHEN FILTER_EndPosition >= FILTER_StartPosition AND FILTER_StartPosition > 0
                THEN FILTER_EndPosition - FILTER_StartPosition + 1
                ELSE FILTER_EndPosition END,
           FILTER_Value,
           ISNULL(@BankStartOverride, BANK_StartPosition),
           ISNULL(@BankLenOverride,
                  CASE WHEN BANK_EndPosition >= BANK_StartPosition
                       THEN BANK_EndPosition - BANK_StartPosition + 1  /* end position */
                       ELSE BANK_EndPosition END),                     /* length       */
           MOPS_StartPosition,
           CASE WHEN @MopsConvention = 'endpos' AND MOPS_StartPosition > 0
                THEN MOPS_EndPosition - MOPS_StartPosition + 1
                ELSE MOPS_EndPosition END,
           LEN(ISNULL(FILTER_Value, ''))
    FROM agora.vw_AutoReconCriteria
    WHERE BranchId = @BranchId AND BankReconArea = 'CashBags';

    IF NOT EXISTS (SELECT 1 FROM @Crit)
    BEGIN
        SELECT 'No BRN_AutoReconCriteria row for BankReconArea = ''CashBags''.' AS Error,
               @BranchId AS BranchId;
        RETURN;
    END

    IF EXISTS (SELECT 1 FROM @Crit WHERE BankLen <= 0)
    BEGIN
        SELECT 'A resolved extraction length is not positive — check BRN_AutoReconCriteria.' AS Error,
               ProcessOrder, BankStart AS ResolvedBankStart, BankLen AS ResolvedBankLen
        FROM @Crit WHERE BankLen <= 0;
        RETURN;
    END

    /* ---- 2. Bank side, with the criteria row that fires resolved per line --- */

    DECLARE @Bank TABLE (
        BankStatementLineID int,
        LineDate            datetime,
        Description         nvarchar(255),
        Amount              money,
        ProcessOrder        int,
        UsedStart           int,
        UsedLen             int,
        PositionRef         nvarchar(50),
        NarrKey             nvarchar(255),
        HasDigitRun         bit
    );

    INSERT INTO @Bank (BankStatementLineID, LineDate, Description, Amount,
                       ProcessOrder, UsedStart, UsedLen, PositionRef, NarrKey, HasDigitRun)
    SELECT l.BankStatementLineID,
           l.LineDate,
           l.Description,
           l.Amount,
           x.ProcessOrder,
           x.BankStart,
           x.BankLen,
           LTRIM(RTRIM(SUBSTRING(l.Description, x.BankStart, x.BankLen))),
           REPLACE(REPLACE(REPLACE(REPLACE(RTRIM(l.Description), '-', ''), '/', ''), ' ', ''), '_', ''),
           /* A bag number is a long digit run. PATINDEX over @MinBagKeyLen
              digits tells us whether there is anything to find at all, which
              separates "we failed to extract it" from "it was never typed". */
           CASE WHEN PATINDEX('%' + REPLICATE('[0-9]', @MinBagKeyLen) + '%',
                              REPLACE(REPLACE(REPLACE(REPLACE(RTRIM(l.Description), '-', ''), '/', ''), ' ', ''), '_', '')
                             ) > 0
                THEN CONVERT(bit, 1) ELSE CONVERT(bit, 0) END
    FROM agora.vw_BankStatementLine l
    OUTER APPLY (
        SELECT TOP 1 c.ProcessOrder, c.BankStart, c.BankLen
        FROM @Crit c
        WHERE c.FilterValue IS NULL
           OR SUBSTRING(l.Description, c.FilterStart, c.FilterLen) = LTRIM(RTRIM(c.FilterValue))
        ORDER BY CASE WHEN @RuleOrder = 'specific' THEN c.Specificity END DESC,
                 c.ProcessOrder ASC,
                 c.AutoReconId ASC
    ) x
    WHERE l.BranchId = @BranchId
      AND l.Type = 'CashDeposit' AND l.IDState = 2 AND l.ReconState = 1
      AND l.LineDate >= @FromDate AND l.LineDate <= @ToDate;

    /* ---- 3. MOPS side. Dedupe the two drop-safe lookups BEFORE the amount --- */

    DECLARE @Mops TABLE (
        DailyBankingCashBagID int,
        TransactionDate       datetime,
        CashBagNo             nvarchar(20),
        DBagNo                nvarchar(20),
        Amount                money,
        PositionRef           nvarchar(50),
        BagKey                nvarchar(50),
        CollectionRows        int
    );

    /* One row per BRN_DailyBankingCashBags row, whatever the lookups fan to.
       MAX() picks a single DBagNo where a bag resolves to more than one
       collection; CollectionRows surfaces how often that happened rather than
       hiding it. sp_SelectCashBagsDaily also joins BRN_DailyBanking for
       EmployeeCode — omitted, it contributes nothing to the totals and is the
       same fan-out risk for no gain. */
    ;WITH Bag AS (
        SELECT a.DailyBankingCashBagID,
               a.TransactionDate,
               a.CashBagNo,
               a.CashBagAmount,
               MAX(d.DBagNo)     AS DBagNo,
               COUNT(d.DBagNo)   AS CollectionRows
        FROM agora.vw_DailyBankingCashBags a
        LEFT JOIN agora.vw_DropSafe c
               ON c.BranchId = a.BranchId
              AND c.BagNo      = a.CashBagNo
        LEFT JOIN agora.vw_DropSafeCollection d
               ON d.BranchId   = c.BranchId
              AND d.CollectionId = c.CollectionId
        WHERE a.BranchId = @BranchId
          AND a.ReconBatchNoPumpIT = 0
          AND a.TransactionDate >= @FromDate AND a.TransactionDate <= @ToDate
        GROUP BY a.DailyBankingCashBagID, a.TransactionDate, a.CashBagNo, a.CashBagAmount
    )
    INSERT INTO @Mops (DailyBankingCashBagID, TransactionDate, CashBagNo, DBagNo,
                       Amount, PositionRef, BagKey, CollectionRows)
    SELECT b.DailyBankingCashBagID,
           b.TransactionDate,
           b.CashBagNo,
           r.Ref,
           b.CashBagAmount,
           LTRIM(RTRIM(SUBSTRING(r.Ref, m.MopsStart, m.MopsLen))),
           REPLACE(REPLACE(REPLACE(REPLACE(RTRIM(r.Ref), '-', ''), '/', ''), ' ', ''), '_', ''),
           b.CollectionRows
    FROM Bag b
    /* The live procedure's fallback: a bag with no collection uses its own
       CashBagNo as the reference. */
    CROSS APPLY (SELECT ISNULL(b.DBagNo, b.CashBagNo) AS Ref) r
    CROSS APPLY (SELECT TOP 1 c.MopsStart, c.MopsLen FROM @Crit c
                 ORDER BY c.ProcessOrder ASC, c.AutoReconId ASC) m;

    /* ---- 4a. contains: match a bag key inside the stripped narrative ------- */

    IF @MatchMode = 'contains'
    BEGIN
        ;WITH Pair AS (
            SELECT b.BankStatementLineID, m.DailyBankingCashBagID, m.Amount
            FROM @Bank b
            JOIN @Mops m
              ON LEN(m.BagKey) >= @MinBagKeyLen
             AND CHARINDEX(m.BagKey, b.NarrKey) > 0
        ),
        /* A bag whose key appears in more than one bank line in scope cannot be
           attributed to either without a rule ZP has not given us. It is still
           reported, flagged, and never counted as reconcilable. */
        BagFan AS (
            SELECT DailyBankingCashBagID, COUNT(DISTINCT BankStatementLineID) AS BankLineHits
            FROM Pair GROUP BY DailyBankingCashBagID
        ),
        PerLine AS (
            SELECT b.BankStatementLineID, b.LineDate, b.Description, b.Amount,
                   b.ProcessOrder, b.UsedStart, b.UsedLen, b.HasDigitRun,
                   COUNT(p.DailyBankingCashBagID)                                AS MopsBags,
                   SUM(p.Amount)                                                 AS MopsTotal,
                   MAX(CASE WHEN f.BankLineHits > 1 THEN 1 ELSE 0 END)           AS AmbiguousBag
            FROM @Bank b
            LEFT JOIN Pair   p ON p.BankStatementLineID = b.BankStatementLineID
            LEFT JOIN BagFan f ON f.DailyBankingCashBagID = p.DailyBankingCashBagID
            GROUP BY b.BankStatementLineID, b.LineDate, b.Description, b.Amount,
                     b.ProcessOrder, b.UsedStart, b.UsedLen, b.HasDigitRun
        ),
        Orphan AS (
            SELECT m.DailyBankingCashBagID, m.TransactionDate, m.DBagNo, m.Amount
            FROM @Mops m
            WHERE NOT EXISTS (SELECT 1 FROM Pair p
                              WHERE p.DailyBankingCashBagID = m.DailyBankingCashBagID)
        )
        SELECT * FROM (
            SELECT 'CashBags'                            AS ReconArea,
                   'contains'                            AS MatchMode,
                   p.BankStatementLineID                 AS BankLineID,
                   p.LineDate                            AS BankDate,
                   p.Description                         AS BankNarrative,
                   1                                     AS BankLines,
                   p.Amount                              AS BankTotal,
                   p.MopsBags                            AS MopsTxns,
                   ISNULL(p.MopsTotal, 0)                AS MopsTotal,
                   ISNULL(p.MopsTotal, 0) - p.Amount     AS Diff_MOPS_BANK,
                   CASE WHEN p.MopsBags = 0 AND p.HasDigitRun = 0
                             THEN 'Bank only - no bag reference in narrative'
                        WHEN p.MopsBags = 0
                             THEN 'Bank only - no deposit'
                        WHEN p.AmbiguousBag = 1
                             THEN 'Ambiguous - a bag matches more than one bank line'
                        WHEN p.MopsTotal = p.Amount
                             THEN 'Matched'
                        ELSE      'Amount mismatch' END  AS Outcome,
                   CASE WHEN p.MopsBags > 0 AND p.AmbiguousBag = 0
                         AND p.MopsTotal = p.Amount THEN CONVERT(bit, 1)
                        ELSE CONVERT(bit, 0) END         AS WouldReconcile,
                   p.ProcessOrder                        AS UsedProcessOrder,
                   p.UsedStart                           AS UsedBankStart,
                   p.UsedLen                             AS UsedBankLen,
                   CASE WHEN p.MopsBags = 0 AND p.HasDigitRun = 0 THEN 5
                        WHEN p.AmbiguousBag = 1                   THEN 4
                        WHEN p.MopsBags = 0                       THEN 2
                        WHEN p.MopsTotal = p.Amount               THEN 0
                        ELSE 1 END                       AS SortRank
            FROM PerLine p
            UNION ALL
            SELECT 'CashBags', 'contains', NULL, o.TransactionDate, o.DBagNo,
                   0, 0, 1, o.Amount, o.Amount,
                   'Deposit only - no bank line', CONVERT(bit, 0),
                   NULL, NULL, NULL, 3
            FROM Orphan o
        ) AS r
        ORDER BY r.SortRank, r.BankDate, r.BankLineID;

        RETURN;
    END

    /* ---- 4b. position: mirror the live extraction, corrected ---------------- */

    ;WITH BankAgg AS (
        SELECT PositionRef AS BatchRef,
               COUNT(*)    AS BankLines,
               SUM(Amount) AS BankTotal,
               MIN(ProcessOrder) AS UsedProcessOrder,
               MIN(UsedStart)    AS UsedBankStart,
               MIN(UsedLen)      AS UsedBankLen,
               COUNT(DISTINCT ProcessOrder) AS RulesInGroup
        FROM @Bank WHERE PositionRef <> '' AND PositionRef IS NOT NULL
        GROUP BY PositionRef
    ),
    MopsAgg AS (
        SELECT PositionRef AS BatchRef,
               COUNT(*)    AS MopsTxns,
               SUM(Amount) AS MopsTotal
        FROM @Mops WHERE PositionRef <> '' AND PositionRef IS NOT NULL
        GROUP BY PositionRef
    )
    SELECT 'CashBags'                                AS ReconArea,
           'position'                                AS MatchMode,
           COALESCE(b.BatchRef, m.BatchRef)          AS BagRef,
           ISNULL(b.BankLines, 0)                    AS BankLines,
           ISNULL(b.BankTotal, 0)                    AS BankTotal,
           ISNULL(m.MopsTxns,  0)                    AS MopsTxns,
           ISNULL(m.MopsTotal, 0)                    AS MopsTotal,
           ISNULL(m.MopsTotal, 0) - ISNULL(b.BankTotal, 0) AS Diff_MOPS_BANK,
           CASE WHEN b.BatchRef IS NULL        THEN 'Deposit only - no bank line'
                WHEN m.BatchRef IS NULL        THEN 'Bank only - no deposit'
                WHEN m.MopsTotal = b.BankTotal THEN 'Matched'
                ELSE                                'Amount mismatch' END AS Outcome,
           CASE WHEN b.BatchRef IS NOT NULL AND m.BatchRef IS NOT NULL
                 AND m.MopsTotal = b.BankTotal THEN CONVERT(bit, 1)
                ELSE CONVERT(bit, 0) END             AS WouldReconcile,
           b.UsedProcessOrder                        AS UsedProcessOrder,
           b.UsedBankStart                           AS UsedBankStart,
           b.UsedBankLen                             AS UsedBankLen,
           b.RulesInGroup                            AS RulesInGroup
    FROM BankAgg b
    FULL OUTER JOIN MopsAgg m ON m.BatchRef = b.BatchRef
    ORDER BY CASE WHEN b.BatchRef IS NULL        THEN 3
                  WHEN m.BatchRef IS NULL        THEN 2
                  WHEN m.MopsTotal = b.BankTotal THEN 0
                  ELSE 1 END,
             COALESCE(b.BatchRef, m.BatchRef);
END
