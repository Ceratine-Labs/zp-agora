/*
 * agora.usp_Recon_GetSides — everything still outstanding, both sides, for a
 * human to pair by hand.
 *
 * Read by:  Recon -> {area} -> Manual match
 * Reads:    agora.vw_BankStatementLine, vw_AutoReconCriteria and the five
 *           agora.vw_DailyBanking* views. All read-only.
 * Writes:   nothing.
 *
 * WHY THIS EXISTS BESIDE THE AUTOMATIC PREVIEW. The previews propose; they can
 * only propose what a configured rule can reach. A reference typed wrong at
 * the till, a deposit banked under a colleague's slip, a batch split across
 * two days — none of those has a rule and none of them ever will, and today
 * they sit on the estate forever. This is the screen where a person pairs
 * them, and the pairing goes through the same ledger as an automatic one so it
 * is reversible by exactly the same path.
 *
 * THE COLOUR IS THE FEATURE. Two hundred bank lines beside two hundred
 * deposits is not a screen anybody can work. So both sides are given a
 * PairKey — the reference each one resolves to, extracted on the bank side
 * exactly as the previews extract it — and a ColourIndex that is non-zero ONLY
 * where that key appears on BOTH sides. A colour therefore means "there is
 * something over there with this same reference", which is a fact worth
 * looking at; colouring every row by its own key would mean nothing and would
 * cost the reader the same attention.
 *
 * ColourIndex is a DENSE_RANK, not a hash: consecutive small integers so the
 * screen can walk a fixed palette, and stable within one answer. It is not
 * stable ACROSS answers and must not be treated as an identity — the key
 * itself travels beside it, in text, because colour alone is not a label
 * anybody can read out, search for, or see.
 *
 * @State  outstanding  ReconState = 1 / ReconBatchNoPumpIT = 0. The default,
 *                      and what a person pairing by hand is looking at.
 *         reconciled   what has already been claimed, for checking a figure.
 *         all          both, with each row saying which it is.
 */
CREATE OR ALTER PROCEDURE [agora].[usp_Recon_GetSides]
    @ReconArea         nvarchar(20),
    @BranchId          int,
    @FromDate          date,
    @ToDate            datetime,
    @State             varchar(12)  = 'outstanding',
    @RuleOrder         varchar(12)  = 'specific',
    @BankStartOverride int          = NULL,
    @BankLenOverride   int          = NULL,
    @MaxRows           int          = 500
AS
BEGIN
    SET NOCOUNT ON;

    SET @State   = LOWER(ISNULL(NULLIF(LTRIM(RTRIM(@State)), ''), 'outstanding'));
    SET @MaxRows = CASE WHEN ISNULL(@MaxRows, 500) BETWEEN 1 AND 5000 THEN @MaxRows ELSE 500 END;

    /* The area is not the statement's Type, and for one area they differ:
       CashBags reads 'CashDeposit'. Getting this wrong is what made every
       CashBags batch skip as "no longer on the statement" — see the header of
       usp_Recon_DrillBank. */
    DECLARE @BankType nvarchar(50) =
        CASE WHEN @ReconArea = 'CashBags' THEN 'CashDeposit' ELSE @ReconArea END;

    DECLARE @WantOutstanding bit = CASE WHEN @State IN ('outstanding', 'all') THEN 1 ELSE 0 END;
    DECLARE @WantReconciled  bit = CASE WHEN @State IN ('reconciled', 'all') THEN 1 ELSE 0 END;

    /* ---- 1. Criteria, resolved exactly as the previews resolve them -------- */

    DECLARE @Crit TABLE (
        ProcessOrder int, AutoReconId int,
        FilterStart int, FilterLen int, FilterValue nvarchar(50),
        BankStart int, BankLen int, Specificity int
    );

    INSERT INTO @Crit (ProcessOrder, AutoReconId, FilterStart, FilterLen, FilterValue,
                       BankStart, BankLen, Specificity)
    SELECT ProcessOrder, AutoReconId,
           FILTER_StartPosition,
           CASE WHEN FILTER_EndPosition >= FILTER_StartPosition AND FILTER_StartPosition > 0
                THEN FILTER_EndPosition - FILTER_StartPosition + 1
                ELSE FILTER_EndPosition END,
           FILTER_Value,
           ISNULL(@BankStartOverride, BANK_StartPosition),
           /* BANK_EndPosition is a LENGTH in some areas and an END POSITION in
              others (finding 9), and the column does not say which. Read as an
              end position when that is arithmetically possible, as a length
              otherwise — the same test every preview makes. */
           ISNULL(@BankLenOverride,
                  CASE WHEN BANK_EndPosition >= BANK_StartPosition
                       THEN BANK_EndPosition - BANK_StartPosition + 1
                       ELSE BANK_EndPosition END),
           LEN(ISNULL(FILTER_Value, ''))
    FROM agora.vw_AutoReconCriteria
    WHERE BranchId = @BranchId AND BankReconArea = @ReconArea;

    /*
     * A MISSING RULE IS NOT A REASON TO REFUSE THIS SCREEN, which is the one
     * place it differs from a preview.
     *
     * A preview with no criteria row can propose nothing and must say so — on
     * the live system that is finding 1, twenty-four of twenty-six branches
     * producing nothing with no explanation. But pairing by hand does not need
     * a rule at all: the whole point is a person doing what no rule can. So a
     * branch with no configuration still gets both its lists; they simply
     * arrive with no extracted reference and therefore no colour, and the
     * screen says why.
     */
    DECLARE @HasCriteria bit = CASE WHEN EXISTS (SELECT 1 FROM @Crit WHERE BankStart > 0 AND BankLen > 0) THEN 1 ELSE 0 END;

    /* ---- 2. The bank side -------------------------------------------------- */

    DECLARE @Bank TABLE (
        BankStatementLineID bigint PRIMARY KEY,
        LineDate datetime, Description nvarchar(400), Amount money,
        PairKey nvarchar(50), UsedProcessOrder int, UsedBankStart int, UsedBankLen int,
        ReconState int, ReconBatchNo int
    );

    INSERT INTO @Bank
    SELECT TOP (@MaxRows)
           l.BankStatementLineID, l.LineDate, l.Description, l.Amount,
           CASE WHEN @HasCriteria = 1
                THEN NULLIF(LTRIM(RTRIM(SUBSTRING(l.Description, x.BankStart, x.BankLen))), '')
           END,
           x.ProcessOrder, x.BankStart, x.BankLen,
           l.ReconState, l.ReconBatchNo
    FROM agora.vw_BankStatementLine l
    /* One criteria row resolved PER LINE — the first whose FILTER_Value the
       narrative satisfies, most specific prefix first. OUTER APPLY, not CROSS:
       a line matching no rule stays visible as a line with no extraction
       rather than vanishing, and on this screen that line is exactly the one
       somebody has to pair by hand. */
    OUTER APPLY (
        SELECT TOP 1 c.ProcessOrder, c.BankStart, c.BankLen
        FROM @Crit c
        WHERE c.BankStart > 0 AND c.BankLen > 0
          AND (c.FilterValue IS NULL
               OR SUBSTRING(l.Description, c.FilterStart, c.FilterLen) = LTRIM(RTRIM(c.FilterValue)))
        ORDER BY CASE WHEN @RuleOrder = 'specific' THEN c.Specificity END DESC,
                 c.ProcessOrder ASC, c.AutoReconId ASC
    ) x
    WHERE l.BranchId = @BranchId
      AND l.Type = @BankType
      /* IDState 1 means the line was never classified at all — 75,305 rows on
         the live system. They belong to the unidentified queue, not here: a
         line nobody has said is an ABSA settlement cannot be paired against an
         ABSA deposit. */
      AND l.IDState = 2
      AND l.LineDate >= @FromDate AND l.LineDate <= @ToDate
      AND ((@WantOutstanding = 1 AND l.ReconState = 1)
        OR (@WantReconciled  = 1 AND l.ReconState <> 1))
    ORDER BY l.LineDate, l.BankStatementLineID;

    /* ---- 3. The deposit side, per area ------------------------------------- */

    DECLARE @Mops TABLE (
        RowNo int IDENTITY(1,1) PRIMARY KEY,
        SourceId bigint NULL, SourceKey nvarchar(100), SourceRef nvarchar(50),
        SourceRef2 nvarchar(50), SourceDate datetime, Amount money,
        PairKey nvarchar(50), ReconBatchNoPumpIT int
    );

    IF @ReconArea = 'ABSA'
        INSERT INTO @Mops (SourceId, SourceKey, SourceRef, SourceRef2, SourceDate, Amount, PairKey, ReconBatchNoPumpIT)
        SELECT TOP (@MaxRows) NULL,
               LTRIM(RTRIM(CONVERT(nvarchar(50), d.BatchNumber))),
               LTRIM(RTRIM(CONVERT(nvarchar(50), d.BatchNumber))),
               LTRIM(RTRIM(d.MerchantNumber)),
               d.TransactionDate, d.TransactionAmount,
               LTRIM(RTRIM(CONVERT(nvarchar(50), d.BatchNumber))),
               d.ReconBatchNoPumpIT
        FROM agora.vw_DailyBankingABSA d
        WHERE d.BranchId = @BranchId
          AND d.TransactionDate >= @FromDate AND d.TransactionDate <= @ToDate
          AND ((@WantOutstanding = 1 AND d.ReconBatchNoPumpIT = 0)
            OR (@WantReconciled  = 1 AND d.ReconBatchNoPumpIT <> 0))
        ORDER BY d.TransactionDate, d.BatchNumber;

    ELSE IF @ReconArea = 'FNB'
        INSERT INTO @Mops (SourceId, SourceKey, SourceRef, SourceRef2, SourceDate, Amount, PairKey, ReconBatchNoPumpIT)
        SELECT TOP (@MaxRows) NULL,
               LTRIM(RTRIM(d.BatchNo)), LTRIM(RTRIM(d.BatchNo)), LTRIM(RTRIM(d.MerchantNo)),
               d.TransactionDate, d.Amount,
               /* FNB's BatchNo is free text in five formats. Compared as a
                  number where it is one, so '00412' and '412' are the same
                  batch — which is what @BatchKey = 'numeric' does in the
                  preview, and the reading the data supports. */
               ISNULL(CONVERT(nvarchar(50), TRY_CONVERT(bigint, LTRIM(RTRIM(d.BatchNo)))), LTRIM(RTRIM(d.BatchNo))),
               d.ReconBatchNoPumpIT
        FROM agora.vw_DailyBankingFNB d
        WHERE d.BranchId = @BranchId
          AND d.TransactionDate >= @FromDate AND d.TransactionDate <= @ToDate
          AND ((@WantOutstanding = 1 AND d.ReconBatchNoPumpIT = 0)
            OR (@WantReconciled  = 1 AND d.ReconBatchNoPumpIT <> 0))
        ORDER BY d.TransactionDate, d.BatchNo;

    ELSE IF @ReconArea = 'CashMachine'
        INSERT INTO @Mops (SourceId, SourceKey, SourceRef, SourceRef2, SourceDate, Amount, PairKey, ReconBatchNoPumpIT)
        SELECT TOP (@MaxRows) NULL,
               LTRIM(RTRIM(d.SlipNo)), LTRIM(RTRIM(d.SlipNo)), NULL,
               d.TransactionDate, d.DepositaAmount,
               LTRIM(RTRIM(d.SlipNo)), d.ReconBatchNoPumpIT
        FROM agora.vw_DailyBankingDeposita d
        WHERE d.BranchId = @BranchId
          AND d.TransactionDate >= @FromDate AND d.TransactionDate <= @ToDate
          AND ((@WantOutstanding = 1 AND d.ReconBatchNoPumpIT = 0)
            OR (@WantReconciled  = 1 AND d.ReconBatchNoPumpIT <> 0))
        ORDER BY d.TransactionDate, d.SlipNo;

    ELSE IF @ReconArea = 'CashBags'
        /* The one deposit table with a key of its own, so a manual match on
           this area can address its rows by id rather than by value. */
        INSERT INTO @Mops (SourceId, SourceKey, SourceRef, SourceRef2, SourceDate, Amount, PairKey, ReconBatchNoPumpIT)
        SELECT TOP (@MaxRows) d.DailyBankingCashBagID,
               LTRIM(RTRIM(d.CashBagNo)), LTRIM(RTRIM(d.CashBagNo)), NULL,
               d.TransactionDate, d.CashBagAmount,
               LTRIM(RTRIM(d.CashBagNo)), d.ReconBatchNoPumpIT
        FROM agora.vw_DailyBankingCashBags d
        WHERE d.BranchId = @BranchId
          AND d.TransactionDate >= @FromDate AND d.TransactionDate <= @ToDate
          AND ((@WantOutstanding = 1 AND d.ReconBatchNoPumpIT = 0)
            OR (@WantReconciled  = 1 AND d.ReconBatchNoPumpIT <> 0))
        ORDER BY d.TransactionDate, d.CashBagNo;

    ELSE IF @ReconArea = 'SmartATM'
        INSERT INTO @Mops (SourceId, SourceKey, SourceRef, SourceRef2, SourceDate, Amount, PairKey, ReconBatchNoPumpIT)
        SELECT TOP (@MaxRows) d.DailyBankingSmartATMID,
               LTRIM(RTRIM(CONVERT(nvarchar(50), d.TerminalId))),
               LTRIM(RTRIM(CONVERT(nvarchar(50), d.TerminalId))),
               LTRIM(RTRIM(CONVERT(nvarchar(50), d.TraceNo))),
               d.DepositDateTime, d.Deposited,
               LTRIM(RTRIM(CONVERT(nvarchar(50), d.TerminalId))),
               d.ReconBatchNoPumpIT
        FROM agora.vw_DailyBankingSmartATM d
        WHERE d.BranchId = @BranchId
          AND d.DepositDateTime >= @FromDate AND d.DepositDateTime <= @ToDate
          AND ((@WantOutstanding = 1 AND d.ReconBatchNoPumpIT = 0)
            OR (@WantReconciled  = 1 AND d.ReconBatchNoPumpIT <> 0))
        ORDER BY d.DepositDateTime, d.TerminalId;

    ELSE
        THROW 51000, 'AGORA:UNKNOWN_AREA:That is not a reconciliation area.', 1;

    /* ---- 4. The colour, which is the whole point --------------------------- */

    DECLARE @Pair TABLE (PairKey nvarchar(50) PRIMARY KEY, ColourIndex int);

    INSERT INTO @Pair (PairKey, ColourIndex)
    SELECT k.PairKey, DENSE_RANK() OVER (ORDER BY k.PairKey)
    FROM (
        /* Only a key present on BOTH sides earns a colour. A colour then means
           "there is something over there carrying this reference", which is
           worth a look; a colour per distinct key would mean nothing and would
           cost the reader the same attention. */
        SELECT DISTINCT b.PairKey
        FROM @Bank b
        WHERE b.PairKey IS NOT NULL
          AND EXISTS (SELECT 1 FROM @Mops m WHERE m.PairKey = b.PairKey)
    ) k;

    /* ---- 5. Both sides, and then the shape of the answer ------------------- */

    SELECT b.BankStatementLineID,
           b.LineDate,
           b.Description,
           b.Amount,
           b.PairKey,
           ISNULL(p.ColourIndex, 0) AS ColourIndex,
           b.UsedProcessOrder,
           b.UsedBankStart,
           b.UsedBankLen,
           b.ReconState,
           b.ReconBatchNo,
           CONVERT(bit, CASE WHEN b.ReconState = 1 THEN 1 ELSE 0 END) AS IsOutstanding
    FROM @Bank b
    LEFT JOIN @Pair p ON p.PairKey = b.PairKey
    ORDER BY ISNULL(p.ColourIndex, 2147483647), b.LineDate, b.BankStatementLineID;

    SELECT m.RowNo,
           m.SourceId,
           m.SourceKey,
           m.SourceRef,
           m.SourceRef2,
           m.SourceDate,
           m.Amount,
           m.PairKey,
           ISNULL(p.ColourIndex, 0) AS ColourIndex,
           m.ReconBatchNoPumpIT,
           CONVERT(bit, CASE WHEN m.ReconBatchNoPumpIT = 0 THEN 1 ELSE 0 END) AS IsOutstanding
    FROM @Mops m
    LEFT JOIN @Pair p ON p.PairKey = m.PairKey
    ORDER BY ISNULL(p.ColourIndex, 2147483647), m.SourceDate, m.SourceKey;

    /* Result set 3: what the screen needs to describe itself honestly — how
       many of each side, how much, how many pairs have a colour, and whether
       this branch is configured at all. */
    SELECT @ReconArea                                            AS ReconArea,
           @BranchId                                             AS BranchId,
           @HasCriteria                                          AS HasCriteria,
           (SELECT COUNT(*) FROM @Bank)                          AS BankRows,
           (SELECT ISNULL(SUM(Amount), 0) FROM @Bank)            AS BankTotal,
           (SELECT COUNT(*) FROM @Mops)                          AS MopsRows,
           (SELECT ISNULL(SUM(Amount), 0) FROM @Mops)            AS MopsTotal,
           (SELECT COUNT(*) FROM @Pair)                          AS ColouredKeys,
           @MaxRows                                              AS MaxRows;
END
