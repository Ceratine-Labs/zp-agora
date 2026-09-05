/* ============================================================================
   agora.usp_Recon_PreviewFNB

   PORTED, NOT REWRITTEN. The body below is sp_RPT_AUTOReconFNBPreview as it was
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

   Source: ~/Development/ZP/Zulu Petroleum/docs/sql/sp_RPT_AUTOReconFNBPreview.sql
   ============================================================================ */

/* ============================================================================
   sp_RPT_AUTOReconFNBPreview           PumpIT / ZP-MIST-SVR        2026-08-27
   (supersedes the 2026-08-05 version)

   PREVIEW ONLY — returns a result set. No INSERT / UPDATE / DELETE / DDL, no
   permanent object (table variables only), and no change to ReconState,
   ReconBatchNo or ReconBatchNoPumpIT on any table.

   1-to-1 with [dbo].[sp_AUTOReconcile_FNB_BankRecon] on the batched
   population, corrected in the three ways the other previews are corrected.

   By volume this is the one that matters most: 22,910 unreconciled FNB bank
   lines across 18 branches for 2026, against 783 for ABSA and 4 for
   CashMachine.

   *** NOT COMPILED. The procedure has not been submitted to SQL Server on our
   *** side, so the parameter and declaration scaffolding is unproven. The
   *** query bodies ARE proven — see below.

   ---------------------------------------------------------------------------
   Validation, 27 August 2026 — branch 18, July 2026, run inline as read-only
   ---------------------------------------------------------------------------
   The bodies of the population split and both batch keys were run against live
   PumpIT with the parameters substituted. What they returned:

     split/standalone     409 lines   10,746,254.39
     split/batched          0 lines            0.00
     pos/Deposit only     174 groups   9,988,350.70   (MOPS side)
     num/Deposit only     210 groups  11,049,438.03   (MOPS side)

   Two things are settled by that.

   FIRST, the split does what it was written to do. The 5 August run of this
   procedure reported branch 18 as "5 bank groups, 412 lines, 11,252,909.50,
   zero matched". Every one of those lines is in fact standalone: at (40,3) the
   'FN' narratives slice to a handful of junk references like '5FN', and five
   phantom batches were being reported where there are none. There are now 409
   standalone lines reported as standalone and no batched population at all,
   which is the truth about this branch. (412 -> 409 and the change in total are
   three weeks of movement, not the split.)

   SECOND, the numeric batch key is measurably better on the MOPS side and not
   merely equivalent. Against the same deposits, the positional key (8,3)
   resolves 174 groups worth 9,988,350.70 while TRY_CONVERT resolves 210 worth
   11,049,438.03 — 36 groups and 1,061,087.33 that the positional slice drops
   silently, because those BatchNo values are space-padded or short and (8,3)
   returns an empty string. That is finding 5.9 measured on one branch in one
   month.

   NOT yet exercised by that run: the batched half of the join (branch 18 has no
   batched lines in the period), the criteria iteration on a branch with more
   than one row, and the standalone rule with @StandaloneRule = 1. Run a branch
   from the space-separated population — 21 Engen Manguzi or 23 Engen Bethlehem
   One Plus — before trusting the batched path, and branch 23 specifically for
   the two-rows-both-ProcessOrder-1 case.

   ---------------------------------------------------------------------------
   Sources mirrored exactly
   ---------------------------------------------------------------------------
     bank  RCN_BankStatementLinesPumpIT   Type='FNB', IDState=2, ReconState=1
                                          (as sp_SelectFNBBankStatement)
     MOPS  BRN_DailyBankingFNB            ReconBatchNoPumpIT = 0
                                          (as sp_SelectFNBDaily)

   sp_SelectFNBDaily is not called directly: it joins BRN_DailyBanking purely to
   surface EmployeeCode, which contributes nothing to the totals.

   ---------------------------------------------------------------------------
   Change 1 — the two populations are separated (finding 6, section 5.7)
   ---------------------------------------------------------------------------
   FNB lines are two different things sharing a Type. They separate cleanly on
   the suffix:

       RIGHT(RTRIM(Description), 2) = 'FN'   -- standalone: 15 002 lines, 2026
                                             -- otherwise batched: 10 973

   The batched shape is  SETTLEMENT ACB CREDIT SPEEDPOINT850250 203  — merchant
   at (33,6), batch as the trailing token. It matches the MOPS keys 95.8% /
   89.8% of the time.

   The 'FN' shape is  SETTLEMENT ACB CREDIT SPEEDPOINT00065283FN  and contains
   NO batch number and NO merchant number. The 8-digit block is a device
   identifier — only 54 distinct values across all 17 branches. Tested against
   every merchant number in BRN_DailyBankingFNB: 0 of 54 match as 8 digits, 0
   as the first 6, 7 as the last 6. At the configured positions this population
   matches 0% on both keys, and (40,3) lands on the last digit plus the 'FN',
   producing a "batch" like 5FN.

   Before this change those 15 002 lines were mixed into the same aggregate and
   reported as ordinary unmatched batches. They are now reported under
   Population = 'standalone' and are never silently folded in.

   ---------------------------------------------------------------------------
   Change 2 — numeric batch key on the batched population (section 5.9)
   ---------------------------------------------------------------------------
   BRN_DailyBankingFNB.BatchNo is free text in five formats. SUBSTRING(BatchNo,
   8, 3) is valid for 36 208 of 48 951 2026 rows (74%): it returns empty for
   11 416 space-padded / short values, and for 1 322 date-shaped values
   ('20260101') it returns a plausible but WRONG '1' that can false-match.

   @BatchKey = 'numeric' (the default) uses
       MOPS   TRY_CONVERT(bigint, LTRIM(RTRIM(BatchNo)))
       bank   TRY_CONVERT(bigint, trailing space-delimited token)
   Measured: identical to the positional key on the batched population (9 090
   of 10 973, 82.8% either way), format-agnostic across all five BatchNo
   shapes, and '#REF!' becomes NULL rather than an empty string that could
   collide. A strict improvement with no measured downside.

   @BatchKey = 'position' keeps the old behaviour so the two can be compared.

   It does not rescue the 'FN' population; nothing positional or numeric can.
   The residual 17.2% of batched lines that still do not match is not explained
   and should not be assumed to share a cause.

   ---------------------------------------------------------------------------
   Change 3 — the standalone rule, and why it is OFF
   ---------------------------------------------------------------------------
   *** @StandaloneRule DEFAULTS TO 0. ZULU PETROLEUM HAVE NOT ANSWERED QUESTION
   *** 3.2. THE RULE BELOW IS OUR BEST-EVIDENCED CANDIDATE, NOT A CONFIRMED
   *** BUSINESS RULE, AND NOTHING SHOULD BE RECONCILED ON IT UNTIL THEY SAY SO.

   With @StandaloneRule = 0 the standalone lines are reported grouped by device
   and date with Outcome = 'Standalone - no agreed rule (Q3.2 open)' and
   WouldReconcile = 0 always. That is the honest state of the world.

   With @StandaloneRule = 1 the candidate from section 3.2 is applied: the bank
   day total settles the previous trading day's till takings. The evidence for
   it is that one deposit date per recon batch holds for 90.3% of historical
   'FN' reconciliations and T+1 settlement for 80.7% (average lag 0.85 days).
   The evidence against relying on it is in section 5.8: only 21.1% of the
   reconciled 'FN' history has a MOPS total equal to the bank amount, so the
   history was made by hand and cannot confirm any algorithm.

   ONE HONEST LIMITATION IN THE IMPLEMENTATION. The candidate is phrased "per
   device", and the code cannot do that: there is no mapping from the 8-digit
   device identifier to anything on the MOPS side — that is the same finding
   that makes these lines standalone in the first place. So @StandaloneRule = 1
   aggregates the bank side to branch-and-date, not device-and-date, and
   compares that against the branch's unconsumed MOPS rows dated
   @StandaloneLagDays earlier. The devices contributing to each day are listed
   in the DeviceRefs column so the grouping stays visible. If ZP confirms the
   rule AND supplies a device-to-till mapping, this is the block to tighten.

   MOPS rows already consumed by the batched population are excluded from the
   standalone pool, so no deposit is counted twice. Deposits that neither
   population claims are reported as 'Deposit only'.

   ---------------------------------------------------------------------------
   Change 4 — criteria iteration instead of TOP 1 (section 6.3)
   ---------------------------------------------------------------------------
   The live procedure reads TOP 1 ... ORDER BY ProcessOrder, so extra rows are
   dead configuration. Branch 23 (Engen Bethlehem One Plus) has TWO FNB rows
   and BOTH carry ProcessOrder = 1 — a tie with no defined winner, so which one
   applies is left to the query plan and can change between executions.

   This procedure resolves a criteria row PER BANK LINE: the first row whose
   FILTER_Value matches that line's narrative. @RuleOrder = 'specific' (the
   default) breaks ties by the length of FILTER_Value, longest first, so the
   most specific prefix wins and branch 23 becomes deterministic.
   'processorder' reproduces the ambiguity for comparison.

   The rule that fired is reported per output row as UsedProcessOrder /
   UsedBankStart / UsedBankLen. Where an aggregate spans lines that resolved to
   different rules, RulesInGroup is greater than 1 — worth looking at, because
   it means one batch reference was assembled under two configurations.

   This is what Question 3.4 needs before the six zero-match branches
   (Elephant Coast, Theku Plaza, Baobab Convenience Centre, Esikhawini OK
   Liquor, Total Hluhluwe, Ngwelezane OK) can be given per-narrative rows. The
   configuration change alone would have done nothing while the code read one
   row.

   ---------------------------------------------------------------------------
   Position convention
   ---------------------------------------------------------------------------
   BANK pairs are read as END positions when the end is at or after the start
   and as LENGTHs otherwise, so this procedure is correct before and after
   docs/sql/2026-08-18b-criteria-restore-and-endposition.sql is applied. FNB
   bank1 is (40,3) today and (40,42) after; bank2 is (33,6) today and (33,38)
   after. Both readings resolve to 3 and 6.

   MOPS is NOT inferred and defaults to 'length'. FNB's MOPS pair is (8,3)
   today and (8,10) after that script's @DoMopsAndFilter switch is committed —
   and unlike the bank pairs, (8,3) read as an end position gives a plausible
   wrong answer rather than an impossible one. Set @MopsConvention = 'endpos'
   only after that switch has committed. It is ignored when @BatchKey is
   'numeric', which does not slice.

     EXEC agora.usp_Recon_PreviewFNB @BranchId = 18,
                                         @FromDate = '2026-07-01',
                                         @ToDate   = '2026-07-31';
   ============================================================================ */

CREATE OR ALTER PROCEDURE [agora].[usp_Recon_PreviewFNB]
    @BranchId          int,
    @FromDate          datetime,
    @ToDate            datetime,
    @BatchKey          varchar(10) = 'numeric',    /* 'numeric' | 'position' */
    @RuleOrder         varchar(12) = 'specific',   /* 'specific' | 'processorder' */
    @MopsConvention    varchar(10) = 'length',     /* 'length'   | 'endpos'  */
    @StandaloneRule    bit         = 0,            /* Q3.2 unanswered — see header */
    @StandaloneLagDays int         = 1,
    @BankStartOverride int         = NULL,
    @BankLenOverride   int         = NULL
AS
BEGIN
    SET NOCOUNT ON;

    IF @BatchKey NOT IN ('numeric', 'position')
    BEGIN
        SELECT '@BatchKey must be ''numeric'' or ''position''.' AS Error, @BatchKey AS Supplied;
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

    /* ---- 1. Every criteria row for this branch, not TOP 1 ------------------ */

    DECLARE @Crit TABLE (
        ProcessOrder int, AutoReconId int,
        FilterStart int, FilterLen int, FilterValue nvarchar(50),
        BankStart int, BankLen int, BankStart2 int, BankLen2 int,
        MopsStart int, MopsLen int, Specificity int
    );

    INSERT INTO @Crit (ProcessOrder, AutoReconId, FilterStart, FilterLen, FilterValue,
                       BankStart, BankLen, BankStart2, BankLen2, MopsStart, MopsLen, Specificity)
    SELECT ProcessOrder, AutoReconId,
           FILTER_StartPosition,
           CASE WHEN FILTER_EndPosition >= FILTER_StartPosition AND FILTER_StartPosition > 0
                THEN FILTER_EndPosition - FILTER_StartPosition + 1
                ELSE FILTER_EndPosition END,
           FILTER_Value,
           ISNULL(@BankStartOverride, BANK_StartPosition),
           ISNULL(@BankLenOverride,
                  CASE WHEN BANK_EndPosition >= BANK_StartPosition
                       THEN BANK_EndPosition - BANK_StartPosition + 1
                       ELSE BANK_EndPosition END),
           BANK_StartPosition2,
           CASE WHEN BANK_StartPosition2 > 0 AND BANK_EndPosition2 >= BANK_StartPosition2
                THEN BANK_EndPosition2 - BANK_StartPosition2 + 1
                ELSE BANK_EndPosition2 END,
           MOPS_StartPosition,
           CASE WHEN @MopsConvention = 'endpos' AND MOPS_StartPosition > 0
                THEN MOPS_EndPosition - MOPS_StartPosition + 1
                ELSE MOPS_EndPosition END,
           LEN(ISNULL(FILTER_Value, ''))
    FROM agora.vw_AutoReconCriteria
    WHERE BranchId = @BranchId AND BankReconArea = 'FNB';

    IF NOT EXISTS (SELECT 1 FROM @Crit)
    BEGIN
        SELECT 'No BRN_AutoReconCriteria row for BankReconArea = ''FNB''.' AS Error,
               @BranchId AS BranchId;
        RETURN;
    END

    IF EXISTS (SELECT 1 FROM @Crit WHERE BankLen <= 0)
    BEGIN
        SELECT 'A resolved extraction length is not positive.' AS Error,
               ProcessOrder, BankStart AS ResolvedBankStart, BankLen AS ResolvedBankLen
        FROM @Crit WHERE BankLen <= 0;
        RETURN;
    END

    /* ---- 2. Bank side, split into the two populations ---------------------- */

    DECLARE @Bank TABLE (
        BankStatementLineID int,
        LineDate            datetime,
        Population          varchar(10),
        BatchRef            nvarchar(50),
        BatchNum            bigint,
        MerchantRef         nvarchar(50),
        DeviceRef           nvarchar(20),
        Amount              money,
        ProcessOrder        int,
        UsedStart           int,
        UsedLen             int
    );

    INSERT INTO @Bank (BankStatementLineID, LineDate, Population, BatchRef, BatchNum,
                       MerchantRef, DeviceRef, Amount, ProcessOrder, UsedStart, UsedLen)
    SELECT s.BankStatementLineID,
           s.LineDate,
           s.Population,
           LTRIM(RTRIM(SUBSTRING(s.Description, s.BankStart, s.BankLen))),
           /* Trailing space-delimited token, numerically. NULL on the
              standalone population, where there is no batch to convert. */
           CASE WHEN s.Population = 'batched'
                THEN TRY_CONVERT(bigint,
                         REVERSE(LEFT(REVERSE(RTRIM(s.Description)),
                                      CHARINDEX(' ', REVERSE(RTRIM(s.Description)) + ' ') - 1)))
                END,
           LTRIM(RTRIM(SUBSTRING(s.Description, s.BankStart2, s.BankLen2))),
           /* The 8 characters immediately before the 'FN' suffix. */
           CASE WHEN s.Population = 'standalone' AND LEN(RTRIM(s.Description)) >= 10
                THEN SUBSTRING(RTRIM(s.Description), LEN(RTRIM(s.Description)) - 9, 8)
                END,
           s.Amount,
           s.ProcessOrder, s.BankStart, s.BankLen
    FROM (
        SELECT l.BankStatementLineID, l.LineDate, l.Description, l.Amount,
               CASE WHEN RIGHT(RTRIM(l.Description), 2) = 'FN'
                    THEN 'standalone' ELSE 'batched' END AS Population,
               x.ProcessOrder, x.BankStart, x.BankLen, x.BankStart2, x.BankLen2
        FROM agora.vw_BankStatementLine l
        OUTER APPLY (
            SELECT TOP 1 c.ProcessOrder, c.BankStart, c.BankLen, c.BankStart2, c.BankLen2
            FROM @Crit c
            WHERE c.FilterValue IS NULL
               OR SUBSTRING(l.Description, c.FilterStart, c.FilterLen) = LTRIM(RTRIM(c.FilterValue))
            ORDER BY CASE WHEN @RuleOrder = 'specific' THEN c.Specificity END DESC,
                     c.ProcessOrder ASC,
                     c.AutoReconId ASC
        ) x
        WHERE l.BranchId = @BranchId
          AND l.Type = 'FNB' AND l.IDState = 2 AND l.ReconState = 1
          AND l.LineDate >= @FromDate AND l.LineDate <= @ToDate
    ) s;

    /* ---- 3. MOPS side ------------------------------------------------------ */

    DECLARE @Mops TABLE (
        MopsRowId       int IDENTITY(1,1),
        TransactionDate datetime,
        BatchRef        nvarchar(50),
        BatchNum        bigint,
        MerchantRef     nvarchar(50),
        Amount          money
    );

    INSERT INTO @Mops (TransactionDate, BatchRef, BatchNum, MerchantRef, Amount)
    SELECT TransactionDate,
           LTRIM(RTRIM(SUBSTRING(BatchNo, c.MopsStart, c.MopsLen))),
           TRY_CONVERT(bigint, LTRIM(RTRIM(BatchNo))),
           LTRIM(RTRIM(MerchantNo)),
           Amount
    FROM agora.vw_DailyBankingFNB
    CROSS APPLY (SELECT TOP 1 k.MopsStart, k.MopsLen FROM @Crit k
                 ORDER BY k.ProcessOrder ASC, k.AutoReconId ASC) c
    WHERE BranchId = @BranchId
      AND ReconBatchNoPumpIT = 0
      AND TransactionDate >= @FromDate AND TransactionDate <= @ToDate;

    /* ---- 4. Batched population --------------------------------------------
       The merchant comparison is a STRING match, not numeric. That is
       deliberate — sp_AUTOReconcile_FNB_BankRecon compares MerchantNo directly
       whereas the ABSA proc wraps both sides in CONVERT(int, ...). Matching
       the live behaviour per area is the point of this procedure. */

    DECLARE @BankAgg TABLE (
        KeyText nvarchar(50), MerchantRef nvarchar(50),
        BankLines int, BankTotal money,
        UsedProcessOrder int, UsedBankStart int, UsedBankLen int, RulesInGroup int
    );

    DECLARE @MopsAgg TABLE (
        KeyText nvarchar(50), MerchantRef nvarchar(50),
        MopsTxns int, MopsTotal money
    );

    INSERT INTO @BankAgg
    SELECT k.KeyText, MerchantRef, COUNT(*), SUM(Amount),
           MIN(ProcessOrder), MIN(UsedStart), MIN(UsedLen), COUNT(DISTINCT ProcessOrder)
    FROM @Bank b
    CROSS APPLY (SELECT CASE WHEN @BatchKey = 'numeric'
                             THEN CONVERT(nvarchar(50), b.BatchNum)
                             ELSE b.BatchRef END AS KeyText) k
    WHERE b.Population = 'batched' AND k.KeyText IS NOT NULL AND k.KeyText <> ''
    GROUP BY k.KeyText, MerchantRef;

    INSERT INTO @MopsAgg
    SELECT k.KeyText, MerchantRef, COUNT(*), SUM(Amount)
    FROM @Mops m
    CROSS APPLY (SELECT CASE WHEN @BatchKey = 'numeric'
                             THEN CONVERT(nvarchar(50), m.BatchNum)
                             ELSE m.BatchRef END AS KeyText) k
    WHERE k.KeyText IS NOT NULL AND k.KeyText <> ''
    GROUP BY k.KeyText, MerchantRef;

    /* ---- 5. Standalone population ------------------------------------------
       Grouped by date. Devices are listed, not grouped on — see the header for
       why the "per device" half of the candidate rule cannot be implemented. */

    DECLARE @StandAgg TABLE (
        BankDate    date,
        DeviceRefs  nvarchar(400),
        Devices     int,
        BankLines   int,
        BankTotal   money,
        MopsTxns    int,
        MopsTotal   money
    );

    INSERT INTO @StandAgg (BankDate, DeviceRefs, Devices, BankLines, BankTotal, MopsTxns, MopsTotal)
    SELECT d.BankDate,
           STUFF((SELECT DISTINCT ', ' + b2.DeviceRef
                    FROM @Bank b2
                   WHERE b2.Population = 'standalone'
                     AND CONVERT(date, b2.LineDate) = d.BankDate
                     AND b2.DeviceRef IS NOT NULL
                   FOR XML PATH('')), 1, 2, ''),
           d.Devices, d.BankLines, d.BankTotal,
           CASE WHEN @StandaloneRule = 1 THEN u.MopsTxns  ELSE 0 END,
           CASE WHEN @StandaloneRule = 1 THEN u.MopsTotal ELSE 0 END
    FROM (
        SELECT CONVERT(date, LineDate) AS BankDate,
               COUNT(DISTINCT DeviceRef) AS Devices,
               COUNT(*) AS BankLines,
               SUM(Amount) AS BankTotal
        FROM @Bank WHERE Population = 'standalone'
        GROUP BY CONVERT(date, LineDate)
    ) d
    OUTER APPLY (
        /* Only MOPS rows the batched population did not already claim. */
        SELECT COUNT(*) AS MopsTxns, SUM(m.Amount) AS MopsTotal
        FROM @Mops m
        WHERE CONVERT(date, m.TransactionDate) = DATEADD(day, -@StandaloneLagDays, d.BankDate)
          AND NOT EXISTS (
              SELECT 1 FROM @BankAgg ba
              WHERE ba.MerchantRef = m.MerchantRef
                AND ba.KeyText = CASE WHEN @BatchKey = 'numeric'
                                      THEN CONVERT(nvarchar(50), m.BatchNum)
                                      ELSE m.BatchRef END)
    ) u;

    /* ---- 6. One result set ------------------------------------------------- */

    SELECT * FROM (
        /* batched */
        SELECT 'FNB'                                     AS ReconArea,
               'batched'                                 AS Population,
               COALESCE(b.KeyText,     m.KeyText)        AS BatchRef,
               COALESCE(b.MerchantRef, m.MerchantRef)    AS MerchantRef,
               CONVERT(datetime,      NULL)              AS BankDate,
               CONVERT(nvarchar(400), NULL)              AS DeviceRefs,
               ISNULL(b.BankLines, 0)                    AS BankLines,
               ISNULL(b.BankTotal, 0)                    AS BankTotal,
               ISNULL(m.MopsTxns,  0)                    AS MopsTxns,
               ISNULL(m.MopsTotal, 0)                    AS MopsTotal,
               ISNULL(m.MopsTotal, 0) - ISNULL(b.BankTotal, 0) AS Diff_MOPS_BANK,
               CASE WHEN b.KeyText IS NULL        THEN 'Deposit only - no bank line'
                    WHEN m.KeyText IS NULL        THEN 'Bank only - no deposit'
                    WHEN m.MopsTotal = b.BankTotal THEN 'Matched'
                    ELSE                               'Amount mismatch' END AS Outcome,
               CASE WHEN b.KeyText IS NOT NULL AND m.KeyText IS NOT NULL
                     AND m.MopsTotal = b.BankTotal THEN CONVERT(bit, 1)
                    ELSE CONVERT(bit, 0) END             AS WouldReconcile,
               b.UsedProcessOrder                        AS UsedProcessOrder,
               b.UsedBankStart                           AS UsedBankStart,
               b.UsedBankLen                             AS UsedBankLen,
               b.RulesInGroup                            AS RulesInGroup,
               CASE WHEN b.KeyText IS NULL        THEN 3
                    WHEN m.KeyText IS NULL        THEN 2
                    WHEN m.MopsTotal = b.BankTotal THEN 0
                    ELSE 1 END                           AS SortRank
        FROM @BankAgg b
        FULL OUTER JOIN @MopsAgg m
          ON  m.KeyText     = b.KeyText
          AND m.MerchantRef = b.MerchantRef

        UNION ALL

        /* standalone */
        SELECT 'FNB', 'standalone',
               CONVERT(nvarchar(50), NULL), CONVERT(nvarchar(50), NULL),
               CONVERT(datetime, s.BankDate), s.DeviceRefs,
               s.BankLines, s.BankTotal, s.MopsTxns, s.MopsTotal,
               s.MopsTotal - s.BankTotal,
               CASE WHEN @StandaloneRule = 0        THEN 'Standalone - no agreed rule (Q3.2 open)'
                    WHEN s.MopsTxns = 0             THEN 'Standalone - no takings on the lagged day'
                    WHEN s.MopsTotal = s.BankTotal  THEN 'Standalone - candidate rule ties'
                    ELSE                                 'Standalone - candidate rule differs' END,
               /* Never 1. The rule is not confirmed; nothing may reconcile on
                  it, and a preview that said otherwise would be lying. */
               CONVERT(bit, 0),
               NULL, NULL, NULL, NULL,
               6
        FROM @StandAgg s
    ) AS r
    ORDER BY r.SortRank, r.MerchantRef, r.BankDate, r.BatchRef;
END
