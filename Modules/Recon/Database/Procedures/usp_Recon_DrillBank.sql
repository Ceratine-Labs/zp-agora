/* ============================================================================
   agora.usp_Recon_DrillBank

   The bank statement lines behind one proposal.

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

CREATE OR ALTER PROCEDURE [agora].[usp_Recon_DrillBank]
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
    /* Off for the screen, which mirrors the preview and so shows only
       outstanding lines. ON for agora.usp_Recon_Commit, which has to be able
       to SEE a line something else has reconciled since the preview in order
       to say so. Filtered out, such a line looks like a line that never
       existed, and the commit reports "the two sides no longer balance" when
       the truth is "something else got there first". Same rows either way;
       the difference is whether the reason it gives is precise. */
    @IncludeReconciled bit           = 0
AS
BEGIN
    SET NOCOUNT ON;

    /*
     * THE AREA IS NOT THE BANK STATEMENT'S TYPE, and for one area they differ.
     *
     * RCN_BankStatementLinesPumpIT.Type carries the customer's own vocabulary,
     * and usp_Recon_PreviewCashBags filters it on 'CashDeposit' — not on
     * 'CashBags', which is what Agora calls the area. Every other area happens
     * to use the same word for both, which is exactly why this went unnoticed:
     * `l.Type = @ReconArea` is right four times out of five.
     *
     * The fifth cost more than a wrong drill. This procedure is also how
     * usp_Recon_Commit re-reads the bank side, so for CashBags it found no
     * lines and every batch was skipped as "no longer on the statement in this
     * period". agora.ReconRun showed it plainly: five CashBags runs, all still
     * `previewed`, not one committed row — against 187 for ABSA and 191 for
     * FNB. The area could not be reconciled at all, and nothing said so.
     *
     * Reported by Ryan on 7 September 2026 as "run 53 exported no bank rows".
     */
    DECLARE @BankType nvarchar(50) =
        CASE WHEN @ReconArea = 'CashBags' THEN 'CashDeposit' ELSE @ReconArea END;

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

    /* ---- 2. Bank side ------------------------------------------------------ */

    DECLARE @Bank TABLE (
        BankStatementLineID bigint,
        LineDate            datetime,
        Description         nvarchar(400),
        Amount              money,
        ExtractedRef        nvarchar(50),
        ExtractedRef2       nvarchar(50),
        Leg                 nvarchar(2),
        NarrKey             nvarchar(255),
        ProcessOrder        int,
        UsedStart           int,
        UsedLen             int,
        ReconState          int,
        ReconBatchNo        int
    );

    INSERT INTO @Bank
    SELECT l.BankStatementLineID,
           l.LineDate,
           l.Description,
           l.Amount,
           /* FNB's batched population keys on the whole value read as a
              number, not on the configured slice, when the run said so. */
           CASE WHEN @ReconArea = 'FNB' AND @BatchKey = 'numeric'
                THEN CONVERT(nvarchar(50), TRY_CONVERT(bigint,
                         LTRIM(RTRIM(SUBSTRING(l.Description, x.BankStart, x.BankLen)))))
                ELSE LTRIM(RTRIM(SUBSTRING(l.Description, x.BankStart, x.BankLen))) END,
           LTRIM(RTRIM(SUBSTRING(l.Description, x.BankStart2, x.BankLen2))),
           /* ABSA settles a batch as the sum of its credit and debit legs; the
              leg is the last two characters of the narrative. */
           CASE WHEN @ReconArea = 'ABSA' THEN RIGHT(RTRIM(l.Description), 2) END,
           /* CashBags 'contains' matches a bag reference anywhere in the
              narrative once separators are stripped. */
           REPLACE(REPLACE(REPLACE(REPLACE(RTRIM(l.Description), '-', ''), '/', ''), ' ', ''), '_', ''),
           x.ProcessOrder, x.BankStart, x.BankLen,
           l.ReconState, l.ReconBatchNo
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
      AND l.Type = @BankType AND l.IDState = 2
      AND (@IncludeReconciled = 1 OR l.ReconState = 1)
      AND l.LineDate >= @FromDate AND l.LineDate <= @ToDate
      AND (@BankLineId IS NULL OR l.BankStatementLineID = @BankLineId);

    SELECT b.BankStatementLineID,
           b.LineDate,
           b.Description,
           b.Amount,
           b.ExtractedRef,
           b.ExtractedRef2,
           b.Leg,
           b.ProcessOrder AS UsedProcessOrder,
           b.UsedStart    AS UsedBankStart,
           b.UsedLen      AS UsedBankLen,
           b.ReconState   AS ReconState,
           b.ReconBatchNo AS ReconBatchNo
    FROM @Bank b
    WHERE
        /* One exact line — CashBags in 'contains' mode. */
        (@BankLineId IS NOT NULL)
        /* A trading-day window — SmartATM, and FNB's standalone population,
           which carries no batch reference at all (58% of FNB lines). */
        OR (@BankLineId IS NULL AND @WindowFrom IS NOT NULL
            AND b.LineDate >= @WindowFrom AND b.LineDate <= @WindowTo
            AND (@KeyRef IS NULL OR b.ExtractedRef = @KeyRef))
        /* By reference, narrowed by merchant where the area has one. ABSA and
           FNB compare merchants differently and the previews are deliberately
           unlike each other there — ABSA numerically, because the narrative
           carries a leading zero the deposit table does not; FNB as a string,
           because its live procedure does. */
        OR (@BankLineId IS NULL AND @WindowFrom IS NULL AND @KeyRef IS NOT NULL
            AND b.ExtractedRef = @KeyRef
            AND (@KeyRef2 IS NULL
                 OR (@ReconArea = 'ABSA' AND TRY_CONVERT(int, b.ExtractedRef2) = TRY_CONVERT(int, @KeyRef2))
                 OR (@ReconArea <> 'ABSA' AND b.ExtractedRef2 = @KeyRef2)))
    ORDER BY b.LineDate, b.BankStatementLineID;

END
