/* ============================================================================
   agora.usp_Recon_PreviewABSA

   PORTED, NOT REWRITTEN. The body below is sp_RPT_AUTOReconABSAPreview as it was
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

   Source: ~/Development/ZP/Zulu Petroleum/docs/sql/sp_RPT_AUTOReconABSAPreview.sql
   ============================================================================ */

/* ============================================================================
   sp_RPT_AUTOReconABSAPreview          PumpIT / ZP-MIST-SVR        2026-08-05

   PREVIEW ONLY — returns a result set. No INSERT / UPDATE / DELETE / DDL, no
   permanent object (table variables only), and no change to ReconState,
   ReconBatchNo or ReconBatchNoPumpIT on any table.

   1-to-1 with [dbo].[sp_AUTOReconcile_ABSA_BankRecon], so it can be run side
   by side against the live AUTO RECON screen for the same branch and dates.

   Sources mirrored exactly:
     bank  RCN_BankStatementLinesPumpIT   Type='ABSA', IDState=2, ReconState=1
                                          (as sp_SelectABSABankStatement)
     MOPS  BRN_DailyBankingABSA           ReconBatchNoPumpIT = 0
                                          (as sp_SelectABSADaily)

   CONFIRMED by ZP, 14 Aug 2026: an ABSA batch reconciles on the SUM of its
   credit and debit legs, not leg by leg. Correction 1 below is therefore a
   business rule, not an inference - do not "restore" the live proc's
   per-line comparison. BankCC and BankDD are surfaced separately so the
   split stays visible even though only the net decides the match.

   What differs from the live proc, and why:
     1. Both sides are aggregated by (BatchNumber, MerchantNumber) before being
        compared. The live proc walks bank lines one at a time but takes the
        MOPS side as SUM() over the whole batch, so when ABSA settles a batch
        across a CC leg and one or more DD legs, the full batch total is
        reported once per line and every difference is wrong.
     2. Four outcomes via FULL OUTER JOIN. The live proc uses
        IF @CurrAmount <> @MOPSAmount, which is UNKNOWN when there is no MOPS
        row and therefore falls to the ELSE — the MATCHED branch. Here
        'Matched' needs both sides present and equal.
     3. Deposit batches with no bank line are reported. The live proc iterates
        bank lines only, so those never reach the screen at all.

   sp_SelectABSADaily is not called directly: it joins BRN_DailyBanking purely
   to surface EmployeeCode, which contributes nothing to the totals and could
   fan them out if that relationship is ever not 1:1. Verified equal on branch
   18 / July 2026 — 383 rows and 88,733.00 either way.

   Merchant numbers are compared numerically because the bank narrative carries
   a leading zero ('02026318') that BRN_DailyBankingABSA.MerchantNumber does not
   ('2026318'). This mirrors the live proc's CONVERT(int, ...) comparison.


   ---------------------------------------------------------------------------
   2026-08-27 — criteria iteration and position convention
   ---------------------------------------------------------------------------
   Two changes since the 2026-08-05 version that was validated on branch 18 /
   July 2026. Re-run that case first: 27 batches, 10 matched at 26,287.50 both
   sides, 6 amount mismatch, 4 bank-only, 7 deposit-only. Neither change should
   move it — ABSA has exactly one criteria row per branch and its positions are
   unambiguous — so any difference means one of them is wrong.

   1. The criteria lookup no longer reads TOP 1. Section 6.3: additional rows
      for a branch are never read by the live procedures, which is why the six
      zero-match FNB branches cannot be fixed by configuration alone. ABSA has
      one row per branch today and is unaffected, but the procedures should not
      differ from each other in how they resolve a rule, and the moment a
      second ABSA row is added this one already handles it. The row that fired
      is reported as UsedProcessOrder; RulesInGroup > 1 means one batch was
      assembled from lines that resolved to different rules.

   2. BANK_EndPosition is read as an END position when it is at or after
      BANK_StartPosition and as a LENGTH otherwise. ABSA stores lengths today —
      (58,3) and (49,8), where the end-position reading is arithmetically
      impossible — and end positions after
      docs/sql/2026-08-18b-criteria-restore-and-endposition.sql commits its
      @DoABSA switch: (58,60) and (49,56). Both readings resolve to 3 and 8, so
      this procedure is correct on either side of that change. ABSA has no
      positional MOPS slice, so there is no @MopsConvention here.

     EXEC agora.usp_Recon_PreviewABSA @BranchId = 18,
                                          @FromDate = '2026-07-01',
                                          @ToDate   = '2026-07-31';
   ============================================================================ */

CREATE OR ALTER PROCEDURE [agora].[usp_Recon_PreviewABSA]
    @BranchId          int,
    @FromDate          datetime,
    @ToDate            datetime,
    @RuleOrder         varchar(12) = 'specific',   /* 'specific' | 'processorder' */
    @BankStartOverride int = NULL,
    @BankLenOverride   int = NULL
AS
BEGIN
    SET NOCOUNT ON;

    IF @RuleOrder NOT IN ('specific', 'processorder')
    BEGIN
        SELECT '@RuleOrder must be ''specific'' or ''processorder''.' AS Error, @RuleOrder AS Supplied;
        RETURN;
    END

    DECLARE @Crit TABLE (
        ProcessOrder int, AutoReconId int,
        FilterStart int, FilterLen int, FilterValue nvarchar(50),
        BankStart int, BankLen int, BankStart2 int, BankLen2 int, Specificity int
    );

    INSERT INTO @Crit (ProcessOrder, AutoReconId, FilterStart, FilterLen, FilterValue,
                       BankStart, BankLen, BankStart2, BankLen2, Specificity)
    SELECT ProcessOrder, AutoReconId,
           FILTER_StartPosition,
           CASE WHEN FILTER_EndPosition >= FILTER_StartPosition AND FILTER_StartPosition > 0
                THEN FILTER_EndPosition - FILTER_StartPosition + 1
                ELSE FILTER_EndPosition END,
           FILTER_Value,
           ISNULL(@BankStartOverride, BANK_StartPosition),
           ISNULL(@BankLenOverride,
                  CASE WHEN BANK_EndPosition >= BANK_StartPosition
                       THEN BANK_EndPosition - BANK_StartPosition + 1   /* end position */
                       ELSE BANK_EndPosition END),                      /* length       */
           BANK_StartPosition2,
           CASE WHEN BANK_StartPosition2 > 0 AND BANK_EndPosition2 >= BANK_StartPosition2
                THEN BANK_EndPosition2 - BANK_StartPosition2 + 1
                ELSE BANK_EndPosition2 END,
           LEN(ISNULL(FILTER_Value, ''))
    FROM agora.vw_AutoReconCriteria
    WHERE BranchId = @BranchId AND BankReconArea = 'ABSA';

    IF NOT EXISTS (SELECT 1 FROM @Crit)
    BEGIN
        SELECT 'No BRN_AutoReconCriteria row for BankReconArea = ''ABSA''.' AS Error,
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

    DECLARE @Bank TABLE (BatchRef nvarchar(50), MerchantRef nvarchar(50),
                         Leg nvarchar(2), Amount money,
                         ProcessOrder int, UsedStart int, UsedLen int);
    DECLARE @Mops TABLE (BatchRef nvarchar(50), MerchantRef nvarchar(50),
                         Amount money);

    INSERT INTO @Bank (BatchRef, MerchantRef, Leg, Amount, ProcessOrder, UsedStart, UsedLen)
    SELECT LTRIM(RTRIM(SUBSTRING(l.Description, x.BankStart,  x.BankLen))),
           LTRIM(RTRIM(SUBSTRING(l.Description, x.BankStart2, x.BankLen2))),
           RIGHT(RTRIM(l.Description), 2),
           l.Amount,
           x.ProcessOrder, x.BankStart, x.BankLen
    FROM agora.vw_BankStatementLine l
    /* One criteria row resolved PER LINE — the first whose FILTER_Value the
       narrative satisfies, most specific prefix first. OUTER APPLY, not CROSS:
       a line matching no rule must stay visible as a line with no extraction
       rather than vanish from the preview. */
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
      AND l.Type = 'ABSA' AND l.IDState = 2 AND l.ReconState = 1
      AND l.LineDate >= @FromDate AND l.LineDate <= @ToDate;

    INSERT INTO @Mops (BatchRef, MerchantRef, Amount)
    SELECT LTRIM(RTRIM(CONVERT(nvarchar(50), BatchNumber))),
           LTRIM(RTRIM(MerchantNumber)),
           TransactionAmount
    FROM agora.vw_DailyBankingABSA
    WHERE BranchId = @BranchId
      AND ReconBatchNoPumpIT = 0
      AND TransactionDate >= @FromDate AND TransactionDate <= @ToDate;

    ;WITH BankAgg AS (
        SELECT BatchRef, MerchantRef,
               COUNT(*) AS BankLines, SUM(Amount) AS BankTotal,
               SUM(CASE WHEN Leg = 'CC' THEN Amount ELSE 0 END) AS BankCC,
               SUM(CASE WHEN Leg = 'DD' THEN Amount ELSE 0 END) AS BankDD,
               MIN(ProcessOrder) AS UsedProcessOrder,
               MIN(UsedStart)    AS UsedBankStart,
               MIN(UsedLen)      AS UsedBankLen,
               COUNT(DISTINCT ProcessOrder) AS RulesInGroup
        FROM @Bank WHERE BatchRef <> '' GROUP BY BatchRef, MerchantRef
    ),
    MopsAgg AS (
        SELECT BatchRef, MerchantRef,
               COUNT(*) AS MopsTxns, SUM(Amount) AS MopsTotal
        FROM @Mops WHERE BatchRef <> '' GROUP BY BatchRef, MerchantRef
    )
    SELECT 'ABSA'                                    AS ReconArea,
           COALESCE(b.BatchRef,    m.BatchRef)       AS BatchRef,
           COALESCE(b.MerchantRef, m.MerchantRef)    AS MerchantRef,
           ISNULL(b.BankLines, 0)                    AS BankLines,
           ISNULL(b.BankTotal, 0)                    AS BankTotal,
           ISNULL(b.BankCC,    0)                    AS BankCC,
           ISNULL(b.BankDD,    0)                    AS BankDD,
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
    FULL OUTER JOIN MopsAgg m
      ON  TRY_CONVERT(int, m.BatchRef)    = TRY_CONVERT(int, b.BatchRef)
      AND TRY_CONVERT(int, m.MerchantRef) = TRY_CONVERT(int, b.MerchantRef)
    ORDER BY CASE WHEN b.BatchRef IS NULL        THEN 3
                  WHEN m.BatchRef IS NULL        THEN 2
                  WHEN m.MopsTotal = b.BankTotal THEN 0
                  ELSE 1 END,
             COALESCE(b.MerchantRef, m.MerchantRef),
             COALESCE(b.BatchRef,    m.BatchRef);
END
