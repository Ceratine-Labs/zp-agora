/* ============================================================================
   agora.usp_Recon_PreviewSmartATM

   PORTED, NOT REWRITTEN. The body below is sp_RPT_AUTOReconSmartATMPreview as it was
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

   ---------------------------------------------------------------------------
   DELIBERATE DEVIATION — the date basis. Ryan, 4 September 2026.
   ---------------------------------------------------------------------------
   Every other procedure in this module is the ported original with its table
   references swapped. This one is not, and the difference is stated here
   rather than buried in the body.

   The original scopes the deposit side on `BRN_DailyBankingSmartATM.
   DepositDateTime`. That column is the CASHUP date — the trading day the
   deposit was filed under — and it is midnight on every row, because it is a
   day rather than a moment. The real timestamp is `BRN_SmartATM.
   DepositDateTime`: 17:28, 19:17, 07:49.

   Measured on the customer's instance over three months: 31,825 deposits,
   NONE without a device row, and 5,561 of them — 17.5% — falling on a
   different calendar day from their cashup date, spread from 366 hours before
   to 212 hours after. So a month selected on the screen was pulling in a
   different set of deposits from the one the operator meant, and the bank
   settles against when the money went in, not when the till was written up.

   The window test already used the device timestamp; only the range filter
   did not. This makes them agree.

   Source: ~/Development/ZP/Zulu Petroleum/docs/sql/sp_RPT_AUTOReconSmartATMPreview.sql
   ============================================================================ */

/* ============================================================================
   sp_RPT_AUTOReconSmartATMPreview      PumpIT / ZP-MIST-SVR        2026-08-05

   PREVIEW ONLY — returns a result set. No INSERT / UPDATE / DELETE / DDL, no
   permanent object (table variables only), and no change to ReconState,
   ReconBatchNo or ReconBatchNoPumpIT on any table.

   1-to-1 with [dbo].[sp_AUTOReconcile_SmartATM_BankRecon].

   SmartATM does not reconcile on a batch number. It matches a terminal against
   a trading-day window, so this procedure is shaped differently from the ABSA,
   FNB and CashMachine previews even though the output columns line up.

   Sources mirrored exactly:
     bank  RCN_BankStatementLinesPumpIT   Type='SmartATM', IDState=2,
                                          ReconState=1
                                          (as sp_SelectSmartATMBankStatement)
     MOPS  BRN_DailyBankingSmartATM       ReconBatchNoPumpIT = 0, filtered on
             LEFT JOIN BRN_SmartATM       its own DepositDateTime, with the
                                          window tested against BRN_SmartATM's
                                          DepositDateTime
                                          (as sp_SelectSmartATMDaily)

   The lookup join was checked for fan-out before being used: 841 rows and
   3,353,750.00 with or without it for 2026, and zero unmatched rows.

   Matching rule, from the live proc:
     terminal   SUBSTRING(Description, BANK_StartPosition,  BANK_EndPosition)
     MMDD       SUBSTRING(Description, BANK_StartPosition2, BANK_EndPosition2)
     window     previous day 19:00:00  ->  MM/DD 18:59:00, year taken from the
                bank line's LineDate
     MOPS       SUBSTRING(TerminalId, MOPS_StartPosition, MOPS_EndPosition)
                matching the terminal, with SmartATM_DateTime inside the window

   ------------------------------------------------------------------------
   One deliberate behaviour change beyond the shared corrections
   ------------------------------------------------------------------------
   The live proc builds that window by string concatenation and subtracts one
   from the day as text:

       set @REPFrom = REPLICATE('0', 2 - LEN(CONVERT(int, SUBSTRING(@MMDD,3,2)) - 1))
       set @CalcFromDateTime = CONVERT(datetime, <yyyy> + '/' + <MM> + '/' +
                                       @REPFrom + <DD - 1> + ' 19:00:00.000')

   When DD is '01' that produces day '00', and the first of a month therefore
   throws "The conversion of a varchar data type to a datetime data type
   resulted in an out-of-range value". The live proc carries commented-out
   examples of exactly that failure ('2023/06/00 19:00', '2023/06/31 19:00'),
   so it is a known-hit bug rather than a theoretical one. Both boundaries are
   built here with DATEADD off the parsed date instead, which is rollover-safe:

       WindowFrom = DATEADD(hour,   -5, midnight of MM/DD)   -- prev day 19:00
       WindowTo   = DATEADD(minute, 1139, midnight of MM/DD) -- same day 18:59

   A description whose MM/DD does not parse yields a NULL window, and its rows
   are reported as 'Bank line - unparseable date in narrative' rather than being
   silently compared against nothing.

   ------------------------------------------------------------------------
   OPEN — the trading-day boundary is NOT settled (ZP, 14 Aug 2026)
   ------------------------------------------------------------------------
   The 19:00 -> 18:59 window above is what the live proc does, reproduced
   faithfully. It is not confirmed to be what the business means by a trading
   day. Asked which date is the trading day, ZP answered: "we are going to
   have to consider before and after midnight, we will come around to this
   one."

   So the window here is a mirror of current behaviour, not a decision. A
   deposit taken at, say, 23:40 lands in the NEXT calendar day's MM/DD in the
   bank narrative while belonging to the previous day's takings, and the two
   sources (BRN_DailyBankingSmartATM's own date vs BRN_SmartATM's
   DepositDateTime) do not agree on which side of midnight it falls. Until ZP
   fixes the definition, treat any SmartATM near-midnight mismatch reported
   by this preview as unexplained rather than as a defect to chase.

   Rework the window together with the same boundary in
   sp_AUTOReconcile_SmartATM_BankRecon — changing one without the other makes
   preview and Execute disagree.


   ---------------------------------------------------------------------------
   2026-08-27 — criteria iteration and position convention
   ---------------------------------------------------------------------------
   Re-run the validated case first: branch 18, Jan-Jul 2026, 9 terminal/window
   groups all resolving with deposits on both sides — e.g. ATMH0133 MMDD 0730
   -> window 2026-07-29 19:00 to 2026-07-30 18:59, bank 68,250.00 against 18
   deposits totalling 56,850.00. Neither change below should move it.

   1. The criteria lookup iterates instead of reading TOP 1 (section 6.3),
      resolving one row per bank line by FILTER_Value with the most specific
      prefix winning. SmartATM has one row per branch today. The row that fired
      is reported as UsedProcessOrder.

   2. BANK pairs are read as END positions when the end is at or after the
      start and as LENGTHs otherwise, so this is correct before and after
      docs/sql/2026-08-18b-criteria-restore-and-endposition.sql commits its
      @DoSmartATM switch: bank1 (23,8) -> (23,30) and bank2 (31,4) -> (31,34),
      both resolving to 8 and 4 either way.

      NOTE the second pair is used twice — as a slice for the MMDD text, and as
      ARITHMETIC (start2, start2+2) to cut the month and day apart for the
      window. The arithmetic uses the resolved START, which neither convention
      changes, so the window is unaffected. This is worth knowing before anyone
      edits either number.

      MOPS is NOT inferred. SmartATM's MOPS pair is (1,8), where the two
      conventions give the same answer and there is therefore no signal to
      infer from. @MopsConvention is explicit and defaults to 'length'.

     EXEC agora.usp_Recon_PreviewSmartATM @BranchId = 18,
                                              @FromDate = '2026-07-01',
                                              @ToDate   = '2026-07-31';
   ============================================================================ */

CREATE OR ALTER PROCEDURE [agora].[usp_Recon_PreviewSmartATM]
    @BranchId          int,
    @FromDate          datetime,
    @ToDate            datetime,
    @RuleOrder         varchar(12) = 'specific',   /* 'specific' | 'processorder' */
    @MopsConvention    varchar(10) = 'length',     /* 'length'   | 'endpos' */
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
    WHERE BranchId = @BranchId AND BankReconArea = 'SmartATM';

    IF NOT EXISTS (SELECT 1 FROM @Crit)
    BEGIN
        SELECT 'No BRN_AutoReconCriteria row for BankReconArea = ''SmartATM''.' AS Error,
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

    DECLARE @Bank TABLE (
        TerminalRef  nvarchar(50),
        MMDD         nvarchar(4),
        WindowFrom   datetime,
        WindowTo     datetime,
        Amount       money,
        ProcessOrder int,
        UsedStart    int,
        UsedLen      int
    );

    DECLARE @Mops TABLE (
        TerminalRef      nvarchar(50),
        SmartATMDateTime datetime,
        Amount           money
    );

    /* The -5h / +1139min window mirrors the live proc's 19:00 -> 18:59 trading
       day. ZP has NOT confirmed that boundary — see "OPEN — the trading-day
       boundary is NOT settled" in the header before changing either number. */
    INSERT INTO @Bank (TerminalRef, MMDD, WindowFrom, WindowTo, Amount,
                       ProcessOrder, UsedStart, UsedLen)
    SELECT TerminalRef, MMDD,
           CASE WHEN BaseDate IS NULL THEN NULL
                ELSE DATEADD(hour,   -5,   CONVERT(datetime, BaseDate)) END,
           CASE WHEN BaseDate IS NULL THEN NULL
                ELSE DATEADD(minute, 1139, CONVERT(datetime, BaseDate)) END,
           Amount, ProcessOrder, UsedStart, UsedLen
    FROM (
        SELECT LTRIM(RTRIM(SUBSTRING(l.Description, x.BankStart,  x.BankLen)))  AS TerminalRef,
               LTRIM(RTRIM(SUBSTRING(l.Description, x.BankStart2, x.BankLen2))) AS MMDD,
               /* start2 arithmetic, not the resolved length — see the header:
                  the convention change moves the end, never the start. */
               TRY_CONVERT(date,
                   CONVERT(varchar(4), YEAR(l.LineDate)) + '-' +
                   SUBSTRING(l.Description, x.BankStart2,     2) + '-' +
                   SUBSTRING(l.Description, x.BankStart2 + 2, 2))              AS BaseDate,
               l.Amount,
               x.ProcessOrder AS ProcessOrder, x.BankStart AS UsedStart, x.BankLen AS UsedLen
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
          AND l.Type = 'SmartATM' AND l.IDState = 2 AND l.ReconState = 1
          AND l.LineDate >= @FromDate AND l.LineDate <= @ToDate
    ) AS src;

    INSERT INTO @Mops (TerminalRef, SmartATMDateTime, Amount)
    SELECT LTRIM(RTRIM(SUBSTRING(a.TerminalId, k.MopsStart, k.MopsLen))),
           b.DepositDateTime,
           a.Deposited
    FROM agora.vw_DailyBankingSmartATM a
    CROSS APPLY (SELECT TOP 1 c.MopsStart, c.MopsLen FROM @Crit c
                 ORDER BY c.ProcessOrder ASC, c.AutoReconId ASC) k
    LEFT JOIN agora.vw_SmartATM b
           ON a.BranchId = b.BranchId
          AND a.TerminalId = b.TerminalId
          AND a.TraceNo    = b.TraceNo
          AND a.UniqueNo   = b.UniqueNo
    WHERE a.BranchId = @BranchId
      AND a.ReconBatchNoPumpIT = 0
      /* THE DEPOSIT'S OWN TIMESTAMP, not the cashup's. See the deviation note
         at the top of this file. ISNULL so a deposit with no device row falls
         back to the filing date rather than vanishing from the preview —
         there are none today, and a row that silently disappeared would be
         the worst possible way to find the first one. */
      AND ISNULL(b.DepositDateTime, a.DepositDateTime) >= @FromDate
      AND ISNULL(b.DepositDateTime, a.DepositDateTime) <= @ToDate;

    ;WITH BankAgg AS (
        SELECT TerminalRef, MMDD, WindowFrom, WindowTo,
               COUNT(*) AS BankLines, SUM(Amount) AS BankTotal,
               MIN(ProcessOrder) AS UsedProcessOrder,
               MIN(UsedStart)    AS UsedBankStart,
               MIN(UsedLen)      AS UsedBankLen,
               COUNT(DISTINCT ProcessOrder) AS RulesInGroup
        FROM @Bank
        WHERE TerminalRef <> ''
        GROUP BY TerminalRef, MMDD, WindowFrom, WindowTo
    ),
    Paired AS (
        SELECT b.TerminalRef, b.MMDD, b.WindowFrom, b.WindowTo,
               b.BankLines, b.BankTotal,
               b.UsedProcessOrder, b.UsedBankStart, b.UsedBankLen, b.RulesInGroup,
               (SELECT COUNT(*)    FROM @Mops m
                 WHERE m.TerminalRef = b.TerminalRef
                   AND b.WindowFrom IS NOT NULL
                   AND m.SmartATMDateTime >= b.WindowFrom
                   AND m.SmartATMDateTime <= b.WindowTo)   AS MopsTxns,
               (SELECT SUM(m.Amount) FROM @Mops m
                 WHERE m.TerminalRef = b.TerminalRef
                   AND b.WindowFrom IS NOT NULL
                   AND m.SmartATMDateTime >= b.WindowFrom
                   AND m.SmartATMDateTime <= b.WindowTo)   AS MopsTotal
        FROM BankAgg b
    ),
    Orphans AS (
        SELECT m.TerminalRef,
               COUNT(*)      AS MopsTxns,
               SUM(m.Amount) AS MopsTotal
        FROM @Mops m
        WHERE NOT EXISTS (
                  SELECT 1 FROM BankAgg b
                   WHERE b.TerminalRef = m.TerminalRef
                     AND b.WindowFrom IS NOT NULL
                     AND m.SmartATMDateTime >= b.WindowFrom
                     AND m.SmartATMDateTime <= b.WindowTo)
        GROUP BY m.TerminalRef
    )
    SELECT * FROM (
        SELECT 'SmartATM'                AS ReconArea,
               p.TerminalRef             AS TerminalRef,
               p.MMDD                    AS BankMMDD,
               p.WindowFrom              AS WindowFrom,
               p.WindowTo                AS WindowTo,
               p.BankLines               AS BankLines,
               p.BankTotal               AS BankTotal,
               ISNULL(p.MopsTxns,  0)    AS MopsTxns,
               ISNULL(p.MopsTotal, 0)    AS MopsTotal,
               ISNULL(p.MopsTotal, 0) - p.BankTotal AS Diff_MOPS_BANK,
               CASE WHEN p.WindowFrom IS NULL           THEN 'Bank line - unparseable date in narrative'
                    WHEN ISNULL(p.MopsTxns, 0) = 0      THEN 'Bank only - no deposit in window'
                    WHEN p.MopsTotal = p.BankTotal      THEN 'Matched'
                    ELSE                                     'Amount mismatch' END AS Outcome,
               CASE WHEN p.WindowFrom IS NOT NULL
                     AND ISNULL(p.MopsTxns, 0) > 0
                     AND p.MopsTotal = p.BankTotal      THEN CONVERT(bit, 1)
                    ELSE CONVERT(bit, 0) END            AS WouldReconcile,
               p.UsedProcessOrder                     AS UsedProcessOrder,
               p.UsedBankStart                        AS UsedBankStart,
               p.UsedBankLen                          AS UsedBankLen,
               p.RulesInGroup                         AS RulesInGroup,
               CASE WHEN p.WindowFrom IS NULL      THEN 4
                    WHEN ISNULL(p.MopsTxns, 0) = 0 THEN 2
                    WHEN p.MopsTotal = p.BankTotal THEN 0
                    ELSE 1 END                          AS SortRank
        FROM Paired p
        UNION ALL
        SELECT 'SmartATM', o.TerminalRef, NULL, NULL, NULL,
               0, 0, o.MopsTxns, o.MopsTotal, o.MopsTotal,
               'Deposit only - no bank line', CONVERT(bit, 0),
               NULL, NULL, NULL, NULL, 3
        FROM Orphans o
    ) AS r
    ORDER BY r.SortRank, r.TerminalRef, r.WindowFrom;
END
