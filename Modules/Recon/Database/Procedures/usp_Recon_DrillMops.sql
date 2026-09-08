/* ============================================================================
   agora.usp_Recon_DrillMops

   The deposit rows behind one proposal, one branch per area.

   Every row carries a SourceId where its table has one (only CashBags does)
   and a SourceKey — the natural key of the row, NOT the slice the match was
   made on. The slice is what the two sides are compared by; the key is what
   the row IS, and stamping has to address the row. Without it the commit
   would be matching on a sentence written for a human to read.

   ONE result set, always. That is the whole reason the drill is two procedures
   rather than one: `INSERT INTO @t EXEC` captures only the first result set,
   and agora.usp_Recon_Commit has to capture BOTH sides to know what it is
   stamping. A combined procedure could be read by the screen and not by the
   thing that writes.

   It also means the rows the operator looked at and the rows that get stamped
   come out of the SAME extraction. A second copy of this logic that resolved a
   reference even slightly differently would stamp lines nobody ever saw, which
   is the failure mode the whole rebuild exists to remove.

   READ-ONLY. Table variables only.

   @BatchKey, @MatchMode and @MopsConvention must be the values the RUN used —
   ReconRun.ParamsJson stores them for exactly this. The criteria resolution
   below is identical to the ported previews': every rule for the branch and
   area, most specific filter first, one resolved PER BANK LINE by OUTER APPLY.
   ============================================================================ */

CREATE OR ALTER PROCEDURE [agora].[usp_Recon_DrillMops]
    @ReconArea         nvarchar(20),
    @BranchId          int,
    @FromDate          datetime,
    @ToDate            datetime,
    @KeyRef            nvarchar(50)  = NULL,
    @KeyRef2           nvarchar(50)  = NULL,
    @BankLineId        bigint        = NULL,
    @WindowFrom        datetime      = NULL,
    @WindowTo          datetime      = NULL,
    @RuleOrder         varchar(12)   = 'specific',
    @BatchKey          varchar(10)   = 'numeric',
    @MatchMode         varchar(10)   = 'contains',
    @MopsConvention    varchar(10)   = 'length',
    @BankStartOverride int           = NULL,
    @BankLenOverride   int           = NULL,
    /* The reference the DEPOSIT side is known by, when that is not the same
       as the bank's. Null on every ordinary row — the two sides agreed. It is
       populated where a near-reference pairing was inferred (bank `69744`,
       deposit `697440`), and from then on it is what the deposits are found
       by. Looking for them under the bank's reference would return nothing. */
    @MopsKeyRef        nvarchar(50)  = NULL,
    /* The deposit's OWN id, where its table has one — only CashBags does.
       It names one bag exactly, which a reference cannot: two orphans on run
       53 shared the DBagNo 304822859458, so matching on that would have shown
       both deposits under each of them and doubled the money on screen. Null
       everywhere else, and null on a run previewed before agora.ReconRunLine
       carried the column — in which case the reference paths below behave
       exactly as they always have. */
    @MopsSourceId      bigint        = NULL
AS
BEGIN
    SET NOCOUNT ON;

    /* ---- 1. Criteria, resolved exactly as the previews resolve them -------- */

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
           /* BANK_EndPosition is a LENGTH in ABSA/FNB/SmartATM and an END
              POSITION in CashMachine/CashBags (finding 9). Read as an end
              position when that is arithmetically possible, as a length
              otherwise — the same test the previews make. */
           ISNULL(@BankLenOverride,
                  CASE WHEN BANK_EndPosition >= BANK_StartPosition
                       THEN BANK_EndPosition - BANK_StartPosition + 1
                       ELSE BANK_EndPosition END),
           BANK_StartPosition2,
           CASE WHEN BANK_StartPosition2 > 0 AND BANK_EndPosition2 >= BANK_StartPosition2
                THEN BANK_EndPosition2 - BANK_StartPosition2 + 1
                ELSE BANK_EndPosition2 END,
           MOPS_StartPosition,
           CASE WHEN @MopsConvention = 'endpos' AND MOPS_EndPosition >= MOPS_StartPosition
                THEN MOPS_EndPosition - MOPS_StartPosition + 1
                ELSE MOPS_EndPosition END,
           LEN(ISNULL(FILTER_Value, ''))
    FROM agora.vw_AutoReconCriteria
    WHERE BranchId = @BranchId AND BankReconArea = @ReconArea;

    /* The bank side is not read here, with one exception: CashBags in
       'contains' mode matches a bag reference against a bank NARRATIVE, so it
       needs the line it is matching against. Nothing else touches it. */
    DECLARE @Bank TABLE (
        BankStatementLineID bigint,
        NarrKey             nvarchar(255)
    );

    IF @ReconArea = 'CashBags' AND @BankLineId IS NOT NULL
        INSERT INTO @Bank (BankStatementLineID, NarrKey)
        SELECT l.BankStatementLineID,
               REPLACE(REPLACE(REPLACE(REPLACE(RTRIM(l.Description), '-', ''), '/', ''), ' ', ''), '_', '')
        FROM agora.vw_BankStatementLine l
        WHERE l.BranchId = @BranchId AND l.BankStatementLineID = @BankLineId;

    /* ---- 3. Deposit side, one branch per area ------------------------------ */

    /* One name for "the reference the deposits are under", so the five area
       branches below do not each have to remember the distinction. */
    DECLARE @Ref nvarchar(50) = ISNULL(@MopsKeyRef, @KeyRef);

    DECLARE @MopsStart int, @MopsLen int;
    SELECT TOP 1 @MopsStart = MopsStart, @MopsLen = MopsLen
    FROM @Crit ORDER BY ProcessOrder ASC, AutoReconId ASC;

    IF @ReconArea = 'ABSA'
        SELECT LTRIM(RTRIM(CONVERT(nvarchar(50), a.BatchNumber))) AS SourceRef,
               a.TransactionDate                                  AS SourceDate,
               a.TransactionAmount                                AS Amount,
               'Merchant '+ LTRIM(RTRIM(a.MerchantNumber))        AS Detail,
               CONVERT(bigint, NULL)                              AS SourceId,
               LTRIM(RTRIM(CONVERT(nvarchar(50), a.BatchNumber)))
                 + '|' + LTRIM(RTRIM(a.MerchantNumber))           AS SourceKey
        FROM agora.vw_DailyBankingABSA a
        WHERE a.BranchId = @BranchId
          AND a.ReconBatchNoPumpIT = 0
          AND a.TransactionDate >= @FromDate AND a.TransactionDate <= @ToDate
          AND TRY_CONVERT(bigint, LTRIM(RTRIM(CONVERT(nvarchar(50), a.BatchNumber)))) = TRY_CONVERT(bigint, @Ref)
          AND (@KeyRef2 IS NULL OR TRY_CONVERT(int, a.MerchantNumber) = TRY_CONVERT(int, @KeyRef2))
        ORDER BY a.TransactionDate;

    ELSE IF @ReconArea = 'FNB'
        SELECT LTRIM(RTRIM(f.BatchNo))                  AS SourceRef,
               f.TransactionDate                        AS SourceDate,
               f.Amount                                 AS Amount,
               'Merchant '+ LTRIM(RTRIM(f.MerchantNo))  AS Detail,
               CONVERT(bigint, NULL)                    AS SourceId,
               LTRIM(RTRIM(f.BatchNo)) + '|' + LTRIM(RTRIM(f.MerchantNo)) AS SourceKey
        FROM agora.vw_DailyBankingFNB f
        WHERE f.BranchId = @BranchId
          AND f.ReconBatchNoPumpIT = 0
          AND f.TransactionDate >= @FromDate AND f.TransactionDate <= @ToDate
          AND (
                /* The standalone population has no batch reference; it is
                   selected by the lagged trading day instead. */
                (@KeyRef IS NULL AND @WindowFrom IS NOT NULL
                 AND f.TransactionDate >= @WindowFrom AND f.TransactionDate <= @WindowTo)
             OR (@KeyRef IS NOT NULL AND @BatchKey = 'numeric'
                 AND CONVERT(nvarchar(50), TRY_CONVERT(bigint, LTRIM(RTRIM(f.BatchNo)))) = @Ref)
             OR (@KeyRef IS NOT NULL AND @BatchKey <> 'numeric'
                 AND LTRIM(RTRIM(SUBSTRING(f.BatchNo, @MopsStart, @MopsLen))) = @Ref)
          )
          AND (@KeyRef2 IS NULL OR LTRIM(RTRIM(f.MerchantNo)) = @KeyRef2)
        ORDER BY f.TransactionDate;

    ELSE IF @ReconArea = 'CashMachine'
        /* CashMachine reconciles against Deposita — the one area whose two
           sides are named differently. */
        SELECT LTRIM(RTRIM(SUBSTRING(d.SlipNo, @MopsStart, @MopsLen))) AS SourceRef,
               d.TransactionDate                                       AS SourceDate,
               d.DepositaAmount                                        AS Amount,
               'Slip '+ LTRIM(RTRIM(d.SlipNo))                         AS Detail,
               CONVERT(bigint, NULL)                                   AS SourceId,
               /* The whole slip number, not the configured slice — the slice
                  is what the two sides are MATCHED on, the slip is what the
                  row IS. Stamping must address the row. */
               LTRIM(RTRIM(d.SlipNo))                                  AS SourceKey
        FROM agora.vw_DailyBankingDeposita d
        WHERE d.BranchId = @BranchId
          AND d.ReconBatchNoPumpIT = 0
          AND d.TransactionDate >= @FromDate AND d.TransactionDate <= @ToDate
          AND LTRIM(RTRIM(SUBSTRING(d.SlipNo, @MopsStart, @MopsLen))) = @Ref
        ORDER BY d.TransactionDate;

    ELSE IF @ReconArea = 'CashBags'
        /* A bag's reference is its collection's DBagNo, falling back to its own
           number. Bag level and collection level are different grains, so the
           collection is aggregated before it is joined — a straight join
           repeats the amount once per collection row. */
        SELECT r.Ref                       AS SourceRef,
               g.TransactionDate           AS SourceDate,
               g.CashBagAmount             AS Amount,
               'Bag '+ LTRIM(RTRIM(g.CashBagNo))
                     + CASE WHEN g.CollectionRows > 1
                            THEN ' · '+ CONVERT(nvarchar(10), g.CollectionRows) +' collections'
                            ELSE '' END    AS Detail,
               /* The one area whose deposit table has a key of its own. */
               g.DailyBankingCashBagID     AS SourceId,
               CONVERT(nvarchar(50), g.DailyBankingCashBagID) AS SourceKey
        FROM (
            SELECT a.DailyBankingCashBagID, a.TransactionDate, a.CashBagNo, a.CashBagAmount,
                   MAX(d.DBagNo) AS DBagNo, COUNT(d.DBagNo) AS CollectionRows
            FROM agora.vw_DailyBankingCashBags a
            LEFT JOIN agora.vw_DropSafe c
                   ON c.BranchId = a.BranchId AND c.BagNo = a.CashBagNo
            LEFT JOIN agora.vw_DropSafeCollection d
                   ON d.BranchId = c.BranchId AND d.CollectionId = c.CollectionId
            WHERE a.BranchId = @BranchId
              AND a.ReconBatchNoPumpIT = 0
              AND a.TransactionDate >= @FromDate AND a.TransactionDate <= @ToDate
            GROUP BY a.DailyBankingCashBagID, a.TransactionDate, a.CashBagNo, a.CashBagAmount
        ) g
        CROSS APPLY (SELECT ISNULL(g.DBagNo, g.CashBagNo) AS Ref) r
        WHERE
            /* By id, and nothing else, when the caller can name the bag. This
               is how a "Deposit only - no bank line" proposal is drilled:
               'contains' finds deposits by looking for the reference inside a
               bank NARRATIVE, and an orphan has no bank line to look in — so
               before this branch existed every orphan drilled to nothing and
               the panel showed two empty columns. */
            (@MopsSourceId IS NOT NULL AND g.DailyBankingCashBagID = @MopsSourceId)

            OR (@MopsSourceId IS NULL AND (
                   (@MatchMode = 'position' AND LTRIM(RTRIM(SUBSTRING(r.Ref, @MopsStart, @MopsLen))) = @Ref)
                OR (@MatchMode <> 'position' AND @BankLineId IS NOT NULL
                    AND EXISTS (
                        SELECT 1 FROM @Bank b
                        WHERE b.BankStatementLineID = @BankLineId
                          AND LEN(REPLACE(REPLACE(REPLACE(REPLACE(RTRIM(r.Ref), '-', ''), '/', ''), ' ', ''), '_', '')) >= 4
                          AND CHARINDEX(
                                REPLACE(REPLACE(REPLACE(REPLACE(RTRIM(r.Ref), '-', ''), '/', ''), ' ', ''), '_', ''),
                                b.NarrKey) > 0))))
        ORDER BY g.TransactionDate;

    ELSE IF @ReconArea = 'SmartATM'
        /* Paired inside the trading-day window the bank narrative names, and
           scoped by the DEVICE timestamp rather than the cashup date — see the
           deviation note on usp_Recon_PreviewSmartATM. The two disagree on
           17.5% of rows, so drilling on the cashup date would show a different
           set of deposits from the one the preview counted. */
        SELECT LTRIM(RTRIM(SUBSTRING(a.TerminalId, @MopsStart, @MopsLen))) AS SourceRef,
               ISNULL(b.DepositDateTime, a.DepositDateTime)                AS SourceDate,
               a.Deposited                                                 AS Amount,
               'Terminal '+ LTRIM(RTRIM(a.TerminalId))
                     +' · trace '+ LTRIM(RTRIM(a.TraceNo))
                     + CASE WHEN b.DepositDateTime IS NULL THEN ' · no device row'
                            ELSE ' · ' + CONVERT(nvarchar(16), b.DepositDateTime, 120) END AS Detail,
               /* This table has a key of its own; use it rather than a string
                  built out of a float. */
               a.DailyBankingSmartATMID                                    AS SourceId,
               CONVERT(nvarchar(50), a.DailyBankingSmartATMID)             AS SourceKey
        FROM agora.vw_DailyBankingSmartATM a
        LEFT JOIN agora.vw_SmartATM b
               ON a.BranchId = b.BranchId AND a.TerminalId = b.TerminalId
              AND a.TraceNo = b.TraceNo AND a.UniqueNo = b.UniqueNo
        WHERE a.BranchId = @BranchId
          AND a.ReconBatchNoPumpIT = 0
          AND ISNULL(b.DepositDateTime, a.DepositDateTime) >= @FromDate
          AND ISNULL(b.DepositDateTime, a.DepositDateTime) <= @ToDate
          AND LTRIM(RTRIM(SUBSTRING(a.TerminalId, @MopsStart, @MopsLen))) = @Ref
          AND (@WindowFrom IS NULL
               OR (ISNULL(b.DepositDateTime, a.DepositDateTime) >= @WindowFrom
                   AND ISNULL(b.DepositDateTime, a.DepositDateTime) <= @WindowTo))
        ORDER BY ISNULL(b.DepositDateTime, a.DepositDateTime);

    ELSE
        /* An unknown area returns an empty second set rather than nothing at
           all: the caller reads two result sets by contract. */
        SELECT CONVERT(nvarchar(50), NULL)  AS SourceRef,
               CONVERT(datetime, NULL)      AS SourceDate,
               CONVERT(money, NULL)         AS Amount,
               CONVERT(nvarchar(200), NULL) AS Detail,
               CONVERT(bigint, NULL)        AS SourceId,
               CONVERT(nvarchar(200), NULL) AS SourceKey
        WHERE 1 = 0;
END
