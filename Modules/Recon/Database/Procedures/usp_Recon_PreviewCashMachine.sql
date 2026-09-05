/* ============================================================================
   agora.usp_Recon_PreviewCashMachine

   PORTED, NOT REWRITTEN. The body below is sp_RPT_AUTOReconCashMachinePreview as it was
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

   Source: ~/Development/ZP/Zulu Petroleum/docs/sql/sp_RPT_AUTOReconCashMachinePreview.sql
   ============================================================================ */

/* ============================================================================
   sp_RPT_AUTOReconCashMachinePreview   PumpIT / ZP-MIST-SVR        2026-08-05

   PREVIEW ONLY — returns a result set. No INSERT / UPDATE / DELETE / DDL, no
   permanent object (table variables only), and no change to ReconState,
   ReconBatchNo or ReconBatchNoPumpIT on any table.

   1-to-1 with [dbo].[sp_AUTOReconcile_CashMachine_BankRecon].

   Sources mirrored exactly:
     bank  RCN_BankStatementLinesPumpIT   Type='CashMachine', IDState=2,
                                          ReconState=1
                                          (as sp_SelectCashMachineBankStatement)
     MOPS  BRN_DailyBankingDeposita       ReconBatchNoPumpIT = 0
                                          (as sp_SelectCashMachineDaily)

   This is the area where the live proc raises
       Invalid object name '_SelectCashMachineBankStatement_AUTO1'
   for 24 of 26 branches: it builds that work table inside a per-branch
   IF @BranchId = N chain in which only branches 13 and 17 contain the
   SELECT ... INTO. This procedure reads the extraction positions from
   BRN_AutoReconCriteria instead, so every branch works.

   Positions were verified against live data rather than assumed: scanning
   start positions 1-45 across all Type='CashMachine' lines for 2026 and
   testing which yields a value present in SUBSTRING(SlipNo, 2, 5) on
   BRN_DailyBankingDeposita gives position 28 for all 7 branches that hold
   cash-machine deposits, at a 99.2-100% hit rate.

   CashMachine was authored with BANK_EndPosition as an END POSITION
   (28..32 = 5 characters), unlike ABSA/FNB/SmartATM which store a LENGTH.
   This proc infers which convention a row uses rather than assuming, so it
   stays correct across the 2026-08-18 normalisation regardless of whether
   that has been run yet.

   Branch 7 (Theku Plaza) was configured (43, 47), past the end of its
   32-character descriptions, so it extracted nothing and every batch fell to
   'Deposit only' with WouldReconcile = 0 rather than matching against
   nothing. docs/sql/2026-08-18-cashmachine-criteria-normalisation.sql sets it
   to (28, 5) with the rest. Until ZP's DBA runs that file, preview branch 7
   with @BankStartOverride = 28, @BankLenOverride = 5 and change no
   configuration.

   Around 10.8% of CashMachine lines (1 052 of 9 715) use a longer narrative
   that carries the slip number at the END, after the last 'CCB', rather than
   at position 28. No single start position serves both formats, so those
   lines report 'Bank only' here. Fixing them needs a second criteria row per
   branch plus the change that stops this proc reading only the first row.


   ---------------------------------------------------------------------------
   2026-08-27 — criteria iteration, and MOPS gets an explicit convention
   ---------------------------------------------------------------------------
   Two changes since the 2026-08-05 version. Re-run its validated cases first:
   branch 13 on stored config gives 1 matched and 1 deposit-only; branch 7 with
   @BankStartOverride = 28, @BankLenOverride = 5 gives 1 mismatch (81,700.00 vs
   81,600.00) and 6 deposit-only. Neither change should move either result.

   1. The criteria lookup iterates instead of reading TOP 1 (section 6.3), so a
      branch given a second row for the longer 'ZULULAND G NPF CREDIT EFT...'
      narrative will start using it without a further code change. That is the
      10.8% of CashMachine lines described below which currently report as
      'Bank only'. The row that fired is reported as UsedProcessOrder.

   2. THE MOPS CONVENTION IS NOW EXPLICIT AND THIS MATTERS MORE HERE THAN
      ANYWHERE ELSE. The bank pair can be inferred safely because every
      CashMachine start is 28 and every length is 5, so the two readings never
      collide. The MOPS pair CANNOT: it is (2,5) today and would be (2,6) under
      the end-position convention, and inferring from (2,5) — where 5 is at or
      after 2 — would resolve it to a length of 4 and silently truncate the
      slip number to four characters. Every match would quietly stop.

      So @MopsConvention is a parameter, it defaults to 'length', and it must
      only be set to 'endpos' after the @DoMopsAndFilter switch in
      docs/sql/2026-08-18b-criteria-restore-and-endposition.sql has committed.
      CashMachine is the one area where guessing this wrong is invisible.

     EXEC agora.usp_Recon_PreviewCashMachine @BranchId = 13,
                                                 @FromDate = '2026-06-01',
                                                 @ToDate   = '2026-07-31';

     EXEC agora.usp_Recon_PreviewCashMachine @BranchId = 7,
                                                 @FromDate = '2026-06-01',
                                                 @ToDate   = '2026-07-31',
                                                 @BankStartOverride = 28,
                                                 @BankLenOverride   = 5;
   ============================================================================ */

CREATE OR ALTER PROCEDURE [agora].[usp_Recon_PreviewCashMachine]
    @BranchId          int,
    @FromDate          datetime,
    @ToDate            datetime,
    @RuleOrder         varchar(12) = 'specific',   /* 'specific' | 'processorder' */
    @MopsConvention    varchar(10) = 'length',     /* 'length'   | 'endpos' — see header */
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

    IF @MopsConvention NOT IN ('length', 'endpos')
    BEGIN
        SELECT '@MopsConvention must be ''length'' or ''endpos''.' AS Error, @MopsConvention AS Supplied;
        RETURN;
    END

    DECLARE @Crit TABLE (
        ProcessOrder int, AutoReconId int,
        FilterStart int, FilterLen int, FilterValue nvarchar(50),
        BankStart int, BankLen int, MopsStart int, MopsLen int, Specificity int
    );

    /* CashMachine stored BANK_EndPosition as an END position (28,32 = five
       characters) where ABSA, FNB and SmartATM store a LENGTH. The procedure
       has to be correct under both conventions — it runs against branches whose
       rows may not have been converted yet, and there is no safe ordering
       otherwise. A value at or beyond the start can only be an end position; a
       smaller one can only be a length. Unambiguous for the BANK pair, because
       every CashMachine start is 28 and every length is 5.

       The MOPS pair is NOT inferred — (2,5) and (2,6) are both plausible and
       the wrong choice truncates silently. See the header. */
    INSERT INTO @Crit (ProcessOrder, AutoReconId, FilterStart, FilterLen, FilterValue,
                       BankStart, BankLen, MopsStart, MopsLen, Specificity)
    SELECT ProcessOrder, AutoReconId,
           FILTER_StartPosition,
           CASE WHEN FILTER_EndPosition >= FILTER_StartPosition AND FILTER_StartPosition > 0
                THEN FILTER_EndPosition - FILTER_StartPosition + 1
                ELSE FILTER_EndPosition END,
           FILTER_Value,
           ISNULL(@BankStartOverride, BANK_StartPosition),
           ISNULL(@BankLenOverride,
                  CASE WHEN BANK_EndPosition >= BANK_StartPosition
                       THEN BANK_EndPosition - BANK_StartPosition + 1   /* legacy: end position */
                       ELSE BANK_EndPosition END),                      /* normalised: length   */
           MOPS_StartPosition,
           CASE WHEN @MopsConvention = 'endpos' AND MOPS_StartPosition > 0
                THEN MOPS_EndPosition - MOPS_StartPosition + 1
                ELSE MOPS_EndPosition END,
           LEN(ISNULL(FILTER_Value, ''))
    FROM agora.vw_AutoReconCriteria
    WHERE BranchId = @BranchId AND BankReconArea = 'CashMachine';

    IF NOT EXISTS (SELECT 1 FROM @Crit)
    BEGIN
        SELECT 'No BRN_AutoReconCriteria row for BankReconArea = ''CashMachine''.' AS Error,
               @BranchId AS BranchId;
        RETURN;
    END

    IF EXISTS (SELECT 1 FROM @Crit WHERE BankLen <= 0 OR MopsLen <= 0)
    BEGIN
        SELECT 'A resolved extraction length is not positive — check BRN_AutoReconCriteria.' AS Error,
               ProcessOrder, BankStart AS ResolvedBankStart, BankLen AS ResolvedBankLen,
               MopsStart AS ResolvedMopsStart, MopsLen AS ResolvedMopsLen
        FROM @Crit WHERE BankLen <= 0 OR MopsLen <= 0;
        RETURN;
    END

    DECLARE @Bank TABLE (BatchRef nvarchar(50), Amount money,
                         ProcessOrder int, UsedStart int, UsedLen int);
    DECLARE @Mops TABLE (BatchRef nvarchar(50), Amount money);

    INSERT INTO @Bank (BatchRef, Amount, ProcessOrder, UsedStart, UsedLen)
    SELECT LTRIM(RTRIM(SUBSTRING(l.Description, x.BankStart, x.BankLen))),
           l.Amount, x.ProcessOrder, x.BankStart, x.BankLen
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
      AND l.Type = 'CashMachine' AND l.IDState = 2 AND l.ReconState = 1
      AND l.LineDate >= @FromDate AND l.LineDate <= @ToDate;

    INSERT INTO @Mops (BatchRef, Amount)
    SELECT LTRIM(RTRIM(SUBSTRING(SlipNo, c.MopsStart, c.MopsLen))), DepositaAmount
    FROM agora.vw_DailyBankingDeposita
    CROSS APPLY (SELECT TOP 1 k.MopsStart, k.MopsLen FROM @Crit k
                 ORDER BY k.ProcessOrder ASC, k.AutoReconId ASC) c
    WHERE BranchId = @BranchId
      AND ReconBatchNoPumpIT = 0
      AND TransactionDate >= @FromDate AND TransactionDate <= @ToDate;

    ;WITH BankAgg AS (
        SELECT BatchRef, COUNT(*) AS BankLines, SUM(Amount) AS BankTotal,
               MIN(ProcessOrder) AS UsedProcessOrder,
               MIN(UsedStart)    AS UsedBankStart,
               MIN(UsedLen)      AS UsedBankLen,
               COUNT(DISTINCT ProcessOrder) AS RulesInGroup
        FROM @Bank WHERE BatchRef <> '' GROUP BY BatchRef
    ),
    MopsAgg AS (
        SELECT BatchRef, COUNT(*) AS MopsTxns, SUM(Amount) AS MopsTotal
        FROM @Mops WHERE BatchRef <> '' GROUP BY BatchRef
    )
    SELECT 'CashMachine'                             AS ReconArea,
           COALESCE(b.BatchRef, m.BatchRef)          AS SlipRef,
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
