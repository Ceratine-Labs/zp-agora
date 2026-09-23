/*
 * agora.usp_Recon_SuggestMatches — what the batch number could not pair,
 * proposed by value.
 *
 * Read by:  Recon -> FNB -> Suggestions
 * Reads:    agora.vw_BankStatementLine, vw_DailyBankingFNB,
 *           vw_AutoReconCriteria, and agora.usp_Recon_PreviewFNB (called, so
 *           "what the batch number already settles" has one definition).
 * Writes:   NOTHING. Temporary tables only. A suggestion is accepted by a
 *           person, through agora.usp_Recon_ManualMatch, which re-reads and
 *           re-checks every row exactly as it does for a match made by hand.
 *
 * WHY. 58% of FNB bank lines end in 'FN' and carry no batch number and no
 * merchant number — only a device id — so no configured rule can pair them
 * (question 3.2). ZP asked, 23 Sep 2026, for an iterative algorithm that runs
 * AFTER the batch-number match, over what is left, and proposes pairings by
 * value and by grouping for the recon clerks to accept.
 *
 * WHAT THE DATA SAYS, which is what the passes below are built on. Measured on
 * the live estate, June–September 2026:
 *   · A deposit BATCH (rows sharing date, BatchNo and MerchantNo) is the unit
 *     FNB settles. 1 bank line = 1 batch of 1–8 rows is 3,700 of the 3,767
 *     balanced historical batches.
 *   · The bank line lands 1–3 days after the takings (T+1 mostly, T+2/T+3
 *     over weekends), never before them.
 *   · On an 'FN' line the device settles either one batch, or several lines
 *     for one device on one day add up to one batch (branch 15: device
 *     00707874 every day, two lines against batch 12/13).
 *   · Monday's lines settle Friday, Saturday and Sunday — each line one day's
 *     batches together.
 *
 * THE ALGORITHM, in the order a clerk would look.
 *
 *   Pass 0 — the batch number. agora.usp_Recon_PreviewFNB is called for the
 *     same site and period, and every row in a group it calls 'Matched' is
 *     set aside. Those belong on the Auto reconciliation tab, and the summary
 *     says how many there are.
 *
 *   Then, over what is left, three passes. Each proposes CANDIDATES — a set
 *   of bank lines and a set of deposits whose totals are equal to the cent,
 *   with every deposit dated on or up to @MaxLagDays before the bank line:
 *
 *   Pass 1 — pairs of natural groups. On the bank side a line, or all of one
 *     device's lines on one day. On the deposit side a row, a batch, or a
 *     batch that ran over two consecutive days.
 *   Pass 2 — combinations. One bank line (or device day) against 2 to
 *     @MaxParts deposit batches from ONE day; or one deposit batch against 2
 *     to @MaxParts bank lines from ONE day.
 *   Pass 3 — day spans. What remains on one bank day (all of it, or one
 *     device's) against everything that remains across a run of trading days.
 *
 *   A candidate is STRONG when nothing else wants any of its rows: no other
 *   candidate, with a different set on either side, shares a single bank line
 *   or deposit with it — and, where a bank line carries a batch number, that
 *   number is on one of its deposits. Rows that are indistinguishable from
 *   each other (same date, same narrative or batch, same amount) are treated
 *   as one, so two identical R220 lines never make each other ambiguous.
 *
 *   THE ITERATION. Strong candidates are taken. Taking them changes what is
 *   left — a device day loses a line and becomes a new group, a batch loses a
 *   row, a day's remainder is a new total — so the passes run again from
 *   pass 1 over the remainder, and again, until a full sweep finds nothing
 *   strong. A later pass never touches a row an earlier pass in the same
 *   sweep had a candidate for, so a combination can never out-vote a simpler
 *   explanation that was merely ambiguous.
 *
 *   Last, one round of POSSIBLE suggestions: the ambiguous candidates, taken
 *   greedily in order (fewest competitors, fewest rows, shortest lag, oldest
 *   first) so that no row is ever in two suggestions. Each says how many
 *   other readings it beat. A person decides.
 *
 * STRONG AND POSSIBLE ARE EXACT TO THE CENT, ALWAYS. Neither proposes a
 * pairing whose two sides differ.
 *
 * CLOSE — the third tier (ZP and Ryan, 23 Sep 2026). Sites 8, 23, 25 and 26
 * got almost nothing from the two exact tiers because their bank days never
 * tie to the cent: site 26 banked R143,238.26 on 5 Aug against takings of
 * R143,338.26 on 3 Aug, R100.00 short; site 8 banked R19,197.34 on 4 Aug
 * against R19,179.28 on 3 Aug, R18.06 over. So, last, and over what the exact
 * tiers left ONLY:
 *
 *   Two sides are close when they differ by more than nothing and by no more
 *   than @ClosePct of the bank side, capped at @CloseMax (1%, R500), and the
 *   takings are from 1 to @MaxLagDays days before the bank day.
 *
 *   Pass 4 — days first: a bank line, a device's day or a whole bank day
 *     against a run of takings days; or a whole bank day against one deposit
 *     or batch.
 *   Pass 5 — then units, from what is left: a bank line or a device's day
 *     against a deposit, a batch, or a batch over two days.
 *
 *   Each pass taken greedily, never a row twice: a reading whose batch
 *   number agrees first, then the smallest difference, then the shortest
 *   lag. Days go first because a near tie between one line and one deposit
 *   inside a day that is itself a few rand off is a coincidence of size —
 *   site 8, 4 Aug, is the case (see pass 4 in the body). Every close
 *   suggestion says its difference in rand and which way it runs.
 *
 *   Measured on the estate, 1 Aug - 22 Sep 2026, every FNB branch, one
 *   month at a time: 4,281 lines outstanding; strong covers 2,041, possible
 *   357, and close adds 260 suggestions over 529 lines (R5.32M), median
 *   difference R29.00, 90% within R162, 55 of them with a competing reading.
 *   Lines first instead of days first gave 322 suggestions, 178 contested.
 *   Slowest branch-month 1.9 s.
 *
 *   The blind replay below, with the tier on, returns the exact tiers row for
 *   row as it did without it — 29,509 member rows, 0 different — and close
 *   never touches a row an exact suggestion holds. History has no forced FNB
 *   reconciliations to score close against; of the 559 close suggestions the
 *   replay made, 6 touch rows history reconciled exactly (the few the exact
 *   tiers missed) and 4 of those mix two reconciliations. That is the case
 *   for the reason a person has to give.
 *
 * A close suggestion can never displace an exact one — it only ever sees rows
 * both exact tiers finished with — and it is never taken on the algorithm's
 * word. A variance is a forced match; agora.usp_Recon_ManualMatch refuses one
 * without a reason (FORCE_REASON_REQUIRED), and a reason is a person's.
 * @CloseMax = 0 turns the tier off, which is how the blind replay proves the
 * exact tiers did not move.
 *
 * ---------------------------------------------------------------------------
 * Validation, 23 September 2026 — a blind replay of four months of history
 * ---------------------------------------------------------------------------
 * Every FNB reconciliation of June–September 2026 whose two sides balance
 * (3,767 batched, 232 'FN') was copied into a local instance with real
 * outstanding rows around it, turned back into outstanding rows, and this
 * procedure was run over every branch-month WITH THE BATCH NUMBER PASS OFF —
 * so it had to find the batch-number matches by value alone. Against what
 * was actually reconciled:
 *
 *   strong suggestions touching history      4,018
 *     the same rows as the reconciliation    3,729
 *     a finer split inside one of them         286
 *     two whole reconciliations as one           3   (a device's two days)
 *     disagreeing with history                   0
 *   possible suggestions touching history      126   (6 disagree)
 *   historical reconciliations found whole   3,759 of 3,767 batched,
 *                                              226 of   232 'FN'
 *
 * The first run of that replay found seven strong disagreements, and the two
 * causes are why the context lines and the batch-number check exist (see the
 * pool section). 76 branch-months took 97 seconds on the local instance.
 *
 * Result sets:
 *   1  one row per suggestion — strong, then possible, then close; DiffAmount
 *      is the deposits less the bank side, zero on every exact one
 *   2  one row per member (bank line or deposit) of every suggestion — enough
 *      to post it to agora.usp_Recon_ManualMatch unchanged (a close one also
 *      needs a reason)
 *   3  one summary row: what was outstanding, what the batch number settles,
 *      what was suggested, what is left
 *
 * Refusals: SUGGEST_UNSUPPORTED (an area other than FNB).
 */
CREATE OR ALTER PROCEDURE [agora].[usp_Recon_SuggestMatches]
    @BranchId    int,
    @ReconArea   nvarchar(20),
    @FromDate    date,
    @ToDate      datetime,
    @MaxLagDays  int = 4,
    @MaxParts    int = 4,
    @MaxSweeps   int = 20,
    @CloseMax    money        = 500.00,
    @ClosePct    decimal(5,2) = 1.00
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    IF ISNULL(@ReconArea, '') <> 'FNB'
        THROW 51000, 'AGORA:SUGGEST_UNSUPPORTED:Suggestions by value are built for FNB, where most bank lines carry no batch number. The other areas pair on a reference every row carries.', 1;

    SET @MaxLagDays = CASE WHEN @MaxLagDays BETWEEN 1 AND 10 THEN @MaxLagDays ELSE 4 END;
    SET @MaxParts   = CASE WHEN @MaxParts   BETWEEN 2 AND 4  THEN @MaxParts   ELSE 4 END;
    SET @MaxSweeps  = CASE WHEN @MaxSweeps  BETWEEN 1 AND 50 THEN @MaxSweeps  ELSE 20 END;
    SET @CloseMax   = CASE WHEN @CloseMax   BETWEEN 0 AND 5000 THEN @CloseMax ELSE 500.00 END;
    SET @ClosePct   = CASE WHEN @ClosePct   > 0 AND @ClosePct <= 5 THEN @ClosePct ELSE 1.00 END;

    /* The close tolerance in cents: the cap, and the share of the bank side
       it may never exceed (per mille of cents, so integer arithmetic). */
    DECLARE @CloseCapCents bigint = CONVERT(bigint, ROUND(@CloseMax * 100, 0));
    DECLARE @ClosePerMille bigint = CONVERT(bigint, ROUND(@ClosePct * 10, 0));

    DECLARE @Started  datetime2(3) = SYSDATETIME();

    /*
     * THE EDGES OF THE PERIOD. A bank line on the first day of the period
     * settles takings from before it, so the deposit side has to reach back.
     * But a deposit from before the period may equally belong to a bank line
     * from before it, and a line nobody loaded cannot say so — it simply lets
     * an in-period line take the deposit. The replay against four months of
     * history found exactly that. So bank lines a lag-window either side of
     * the period are loaded as CONTEXT: they compete for deposits like any
     * other line, and a suggestion made of them is never offered — it belongs
     * to the period they are in.
     */
    DECLARE @BankFrom date     = DATEADD(day, -@MaxLagDays, @FromDate);
    DECLARE @BankTo   datetime = DATEADD(day,  @MaxLagDays, @ToDate);
    DECLARE @MopsFrom date     = DATEADD(day, -2 * @MaxLagDays, @FromDate);

    /* ---- 1. The two pools ------------------------------------------------- */

    /*
     * GroupKey is what "one device" means on the bank side. On an 'FN' line
     * it is the 8-digit device id in front of the suffix. On any other line it
     * is the narrative without its last token — the batch number, where there
     * is one — so a merchant's lines on one day group together.
     *
     * Token is that batch number, compared as a number. An 'FN' line has none.
     * Where a line has one and the deposits a candidate pairs it with do not
     * carry it, the value may tie but the reference contradicts it, and such a
     * candidate is only ever offered as possible.
     *
     * MemberSig is what makes two rows indistinguishable: same day, same
     * narrative, same amount. Candidates are compared by the set of their
     * members' signatures, never by row id. The FULL narrative, not the
     * device: two R200 lines from one merchant ending 428 and 625 are two
     * different batches, and the replay caught the pair being treated as one.
     */
    CREATE TABLE #B (
        Id          bigint        NOT NULL PRIMARY KEY,
        D           date          NOT NULL,
        InPeriod    bit           NOT NULL,
        Amount      money         NOT NULL,
        Cents       bigint        NOT NULL,
        Population  varchar(10) COLLATE DATABASE_DEFAULT   NOT NULL,
        GroupKey    nvarchar(200) COLLATE DATABASE_DEFAULT NOT NULL,
        Token       nvarchar(50) COLLATE DATABASE_DEFAULT  NULL,
        Descr       nvarchar(400) COLLATE DATABASE_DEFAULT NULL,
        MemberSig   nvarchar(450) COLLATE DATABASE_DEFAULT NOT NULL,
        State       varchar(8) COLLATE DATABASE_DEFAULT    NOT NULL,   -- open | batch | taken
        Held        bit           NOT NULL DEFAULT 0,
        Sug         int           NULL,
        INDEX IX_B_Open (State, Held, D)
    );

    INSERT INTO #B (Id, D, InPeriod, Amount, Cents, Population, GroupKey, Token, Descr, MemberSig, State)
    SELECT l.BankStatementLineID, CONVERT(date, l.LineDate),
           CASE WHEN l.LineDate >= @FromDate AND l.LineDate <= @ToDate THEN 1 ELSE 0 END,
           l.Amount,
           CONVERT(bigint, ROUND(l.Amount * 100, 0)),
           x.Population, x.GroupKey,
           CASE WHEN x.Population = 'batched'
                THEN CONVERT(nvarchar(50), TRY_CONVERT(bigint,
                         REVERSE(LEFT(REVERSE(n.Narr), CHARINDEX(N' ', REVERSE(n.Narr) + N' ') - 1)))) END,
           l.Description,
           CONCAT(CONVERT(char(10), CONVERT(date, l.LineDate), 23), N'|', LEFT(n.Narr, 400), N'|',
                  CONVERT(bigint, ROUND(l.Amount * 100, 0))),
           'open'
    FROM agora.vw_BankStatementLine l
    CROSS APPLY (SELECT RTRIM(ISNULL(l.Description, N'')) AS Narr) n
    CROSS APPLY (
        SELECT CASE WHEN RIGHT(n.Narr, 2) = 'FN' THEN 'standalone' ELSE 'batched' END AS Population,
               CASE WHEN RIGHT(n.Narr, 2) = 'FN' AND LEN(n.Narr) >= 10
                    THEN SUBSTRING(n.Narr, LEN(n.Narr) - 9, 8)
                    WHEN CHARINDEX(N' ', REVERSE(n.Narr)) > 0
                    THEN LEFT(n.Narr, LEN(n.Narr) - CHARINDEX(N' ', REVERSE(n.Narr)))
                    ELSE n.Narr END AS GroupKey
    ) x
    WHERE l.BranchId = @BranchId
      AND l.Type = 'FNB' AND l.IDState = 2 AND l.ReconState = 1
      AND l.LineDate >= @BankFrom AND l.LineDate <= @BankTo;

    /*
     * The deposit side reaches back two lag-windows: one for the period's
     * first lines, one more for the context lines in front of them.
     *
     * SourceKey is the BatchNo exactly as agora.usp_Recon_ManualMatch names
     * the row. BatchKey is the same value compared as a number where it is
     * one ('0000000013' and '13' are one batch), which is how the preview
     * reads it.
     */
    CREATE TABLE #M (
        RowNo       int IDENTITY(1,1) PRIMARY KEY,
        TS          datetime      NOT NULL,
        D           date          NOT NULL,
        SourceKey   nvarchar(100) COLLATE DATABASE_DEFAULT NULL,
        BatchNum    nvarchar(50) COLLATE DATABASE_DEFAULT  NULL,
        BatchKey    nvarchar(100) COLLATE DATABASE_DEFAULT NOT NULL,
        Merchant    nvarchar(50) COLLATE DATABASE_DEFAULT  NULL,
        Amount      money         NOT NULL,
        Cents       bigint        NOT NULL,
        MemberSig   nvarchar(450) COLLATE DATABASE_DEFAULT NOT NULL,
        State       varchar(8) COLLATE DATABASE_DEFAULT    NOT NULL,
        Held        bit           NOT NULL DEFAULT 0,
        Sug         int           NULL,
        INDEX IX_M_Open (State, Held, D),
        INDEX IX_M_Batch (BatchKey, D)
    );

    INSERT INTO #M (TS, D, SourceKey, BatchNum, BatchKey, Merchant, Amount, Cents, MemberSig, State)
    SELECT d.TransactionDate, CONVERT(date, d.TransactionDate),
           LTRIM(RTRIM(d.BatchNo)),
           CONVERT(nvarchar(50), TRY_CONVERT(bigint, LTRIM(RTRIM(d.BatchNo)))),
           ISNULL(CONVERT(nvarchar(100), TRY_CONVERT(bigint, LTRIM(RTRIM(d.BatchNo)))), ISNULL(LTRIM(RTRIM(d.BatchNo)), N'')),
           LTRIM(RTRIM(d.MerchantNo)),
           d.Amount,
           CONVERT(bigint, ROUND(d.Amount * 100, 0)),
           CONCAT(CONVERT(char(10), CONVERT(date, d.TransactionDate), 23), N'|', LTRIM(RTRIM(d.BatchNo)), N'|',
                  LTRIM(RTRIM(d.MerchantNo)), N'|', CONVERT(bigint, ROUND(d.Amount * 100, 0))),
           'open'
    FROM agora.vw_DailyBankingFNB d
    WHERE d.BranchId = @BranchId
      AND d.ReconBatchNoPumpIT = 0
      AND d.TransactionDate >= @MopsFrom AND d.TransactionDate <= @BankTo
    ORDER BY d.TransactionDate, d.BatchNo, d.Amount;

    /*
     * EVERY INDEX IS DECLARED INSIDE ITS CREATE TABLE, and that is a
     * performance rule rather than a style. A CREATE INDEX issued after a
     * temporary table exists stops SQL Server caching that table between
     * calls, and then every statement that touches it is compiled again on
     * every call — measured here at four of the five seconds a busy
     * branch-month took. The OPTION (KEEPFIXED PLAN) inside the loop is the
     * same problem from the other side: the tables are emptied and refilled on
     * every pass, which would otherwise recompile each statement on each pass.
     */

    /* ---- 2. Pass 0: what the batch number already settles ------------------ */

    /*
     * The decision is the preview's, not a copy of it: it is called, and its
     * 'Matched' groups are read back. What is repeated here is only how a ROW
     * is put back into its group — the trailing token and the merchant slice
     * on the bank side, the numeric BatchNo and the merchant on the deposit
     * side — resolved by the same criteria rule, in the same order, the
     * preview uses.
     *
     * With no usable rule the preview refuses, and nothing settles by batch
     * number: the pass is skipped and the summary says so.
     */
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
           BANK_StartPosition,
           CASE WHEN BANK_EndPosition >= BANK_StartPosition
                THEN BANK_EndPosition - BANK_StartPosition + 1
                ELSE BANK_EndPosition END,
           BANK_StartPosition2,
           CASE WHEN BANK_StartPosition2 > 0 AND BANK_EndPosition2 >= BANK_StartPosition2
                THEN BANK_EndPosition2 - BANK_StartPosition2 + 1
                ELSE BANK_EndPosition2 END,
           LEN(ISNULL(FILTER_Value, ''))
    FROM agora.vw_AutoReconCriteria
    WHERE BranchId = @BranchId AND BankReconArea = 'FNB';

    DECLARE @BatchPass bit =
        CASE WHEN EXISTS (SELECT 1 FROM @Crit) AND NOT EXISTS (SELECT 1 FROM @Crit WHERE BankLen <= 0)
             THEN 1 ELSE 0 END;

    IF @BatchPass = 1
    BEGIN
        /* The preview's result set, column for column. If the preview ever
           changes shape this INSERT fails loudly, which is the right answer —
           a silent misread would put rows in the wrong pile. */
        DECLARE @Preview TABLE (
            ReconArea nvarchar(20), Population varchar(10), BatchRef nvarchar(50), MerchantRef nvarchar(50),
            BankDate datetime, DeviceRefs nvarchar(400), BankLines int, BankTotal money,
            MopsTxns int, MopsTotal money, Diff_MOPS_BANK money, Outcome nvarchar(100),
            WouldReconcile bit, UsedProcessOrder int, UsedBankStart int, UsedBankLen int,
            RulesInGroup int, SortRank int
        );

        INSERT INTO @Preview
        EXEC agora.usp_Recon_PreviewFNB @BranchId = @BranchId, @FromDate = @FromDate, @ToDate = @ToDate;

        UPDATE b SET b.State = 'batch'
        FROM #B b
        CROSS APPLY (
            SELECT TOP 1 c.BankStart2, c.BankLen2
            FROM @Crit c
            WHERE c.FilterValue IS NULL
               OR SUBSTRING(b.Descr, c.FilterStart, c.FilterLen) = LTRIM(RTRIM(c.FilterValue))
            ORDER BY c.Specificity DESC, c.ProcessOrder ASC, c.AutoReconId ASC
        ) x
        CROSS APPLY (SELECT RTRIM(b.Descr) AS Narr) n
        WHERE b.Population = 'batched' AND b.InPeriod = 1
          AND EXISTS (
              SELECT 1 FROM @Preview p
              WHERE p.Population = 'batched' AND p.WouldReconcile = 1
                AND p.BatchRef = CONVERT(nvarchar(50), TRY_CONVERT(bigint,
                        REVERSE(LEFT(REVERSE(n.Narr), CHARINDEX(N' ', REVERSE(n.Narr) + N' ') - 1))))
                AND p.MerchantRef = LTRIM(RTRIM(SUBSTRING(b.Descr, x.BankStart2, x.BankLen2))));

        UPDATE m SET m.State = 'batch'
        FROM #M m
        WHERE m.TS >= @FromDate AND m.TS <= @ToDate
          AND EXISTS (
              SELECT 1 FROM @Preview p
              WHERE p.Population = 'batched' AND p.WouldReconcile = 1
                AND p.BatchRef = m.BatchNum
                AND p.MerchantRef = m.Merchant);
    END

    /* ---- 3. Working tables, rebuilt for every pass ------------------------- */

    /* Bank blocks: a line, a device's day, or a whole bank day. */
    CREATE TABLE #BK (
        BlockId  int IDENTITY(1,1) PRIMARY KEY,
        Kind     varchar(8) COLLATE DATABASE_DEFAULT    NOT NULL,     -- line | devday | bankday
        D        date          NOT NULL,
        GroupKey nvarchar(200) COLLATE DATABASE_DEFAULT NULL,
        RefId    bigint        NULL,
        Cents    bigint        NOT NULL
    );
    CREATE TABLE #BKM (BlockId int NOT NULL, Id bigint NOT NULL, PRIMARY KEY (BlockId, Id));

    /* Deposit blocks: a row, a batch, a batch over two days, or a run of
       days. IsUnit marks the blocks a combination is built from — every
       batch, including a batch of one row. */
    CREATE TABLE #MK (
        BlockId     int IDENTITY(1,1) PRIMARY KEY,
        Kind        varchar(8) COLLATE DATABASE_DEFAULT    NOT NULL,  -- row | batch | batch2 | days
        D0          date          NOT NULL,
        D1          date          NOT NULL,
        BatchKey    nvarchar(100) COLLATE DATABASE_DEFAULT NULL,
        MerchantKey nvarchar(50) COLLATE DATABASE_DEFAULT  NULL,
        RefId       int           NULL,
        IsUnit      bit           NOT NULL,
        Cents       bigint        NOT NULL,
        /* Pass 4 reads a band of cents, not one value. */
        INDEX IX_MK_Cents (Cents)
    );
    CREATE TABLE #MKM (BlockId int NOT NULL, RowNo int NOT NULL, PRIMARY KEY (BlockId, RowNo));

    /* Pairs of units on one day, the half a combination is met in the middle
       from. */
    CREATE TABLE #MP (D date NOT NULL, U1 int NOT NULL, U2 int NOT NULL, Cents bigint NOT NULL, INDEX IX_MP (Cents, D));
    CREATE TABLE #BP (D date NOT NULL, L1 int NOT NULL, L2 int NOT NULL, Cents bigint NOT NULL, INDEX IX_BP (Cents, D));

    /* Combinations: Side 'M' combines deposit units against a bank block,
       Side 'B' combines bank lines against a deposit unit. */
    CREATE TABLE #X (
        ComboId int IDENTITY(1,1) PRIMARY KEY,
        Side    char(1) COLLATE DATABASE_DEFAULT NOT NULL,
        Target  int     NOT NULL,
        P1 int NOT NULL, P2 int NOT NULL, P3 int NULL, P4 int NULL,
        Parts   int     NOT NULL
    );

    CREATE TABLE #C (
        CandId     int IDENTITY(1,1) PRIMARY KEY,
        Shape      varchar(24) COLLATE DATABASE_DEFAULT    NOT NULL,
        BankBlock  int            NULL,
        MopsBlock  int            NULL,
        Combo      int            NULL,
        BankSig    varbinary(32)  NULL,
        MopsSig    varbinary(32)  NULL,
        BankN      int            NULL,
        MopsN      int            NULL,
        BankCents  bigint         NULL,
        MopsCents  bigint         NULL,
        BankD      date           NULL,
        MopsD1     date           NULL,
        Lag        int            NULL,
        Rep        bit            NOT NULL DEFAULT 0,
        Alts       int            NULL,
        TokenOk    bit            NULL,
        InPeriod   bit            NULL,
        Sug        int            NULL
    );
    CREATE TABLE #CM (CandId int NOT NULL, Side char(1) COLLATE DATABASE_DEFAULT NOT NULL, Ref bigint NOT NULL,
                      PRIMARY KEY (CandId, Side, Ref), INDEX IX_CM_Ref (Side, Ref));

    CREATE TABLE #S (
        SuggestionNo int         NOT NULL PRIMARY KEY,
        Confidence   varchar(8) COLLATE DATABASE_DEFAULT  NOT NULL,   -- strong | possible | close
        Sweep        int         NOT NULL,
        Pass         int         NOT NULL,
        Shape        varchar(24) COLLATE DATABASE_DEFAULT NOT NULL,
        Alts         int         NOT NULL,
        TokenOk      bit         NOT NULL,
        InPeriod     bit         NOT NULL,
        DiffCents    bigint      NOT NULL DEFAULT 0              -- deposits less bank; 0 unless close
    );

    DECLARE @Nums TABLE (n int PRIMARY KEY);
    INSERT INTO @Nums (n) VALUES (0),(1),(2),(3),(4),(5),(6),(7),(8),(9),(10);

    /* ---- 4. The sweeps ------------------------------------------------------ */

    DECLARE @Mode varchar(8) = 'strong', @Sweep int = 0, @Pass int, @Took int, @N int, @Base int;
    DECLARE @CandId int;

    WHILE @Mode IS NOT NULL
    BEGIN
        SET @Sweep += 1;
        SET @Took = 0;
        /* Close is two passes of its own: 4, a day's worth on at least one
           side, then 5, a line or device day against a deposit or batch. */
        SET @Pass = CASE WHEN @Mode = 'close' THEN 4 ELSE 1 END;

        UPDATE #B SET Held = 0 WHERE Held = 1 OPTION (KEEPFIXED PLAN);
        UPDATE #M SET Held = 0 WHERE Held = 1 OPTION (KEEPFIXED PLAN);

        /* Strong: stop at the first pass that takes anything and start the
           sweep again from pass 1 over what is left. Possible: one round of
           all three. Close: passes 4 and 5, once each. */
        WHILE @Pass <= CASE WHEN @Mode = 'close' THEN 5 ELSE 3 END AND (@Took = 0 OR @Mode <> 'strong')
        BEGIN
            TRUNCATE TABLE #BK; TRUNCATE TABLE #BKM; TRUNCATE TABLE #MK; TRUNCATE TABLE #MKM;
            TRUNCATE TABLE #MP; TRUNCATE TABLE #BP; TRUNCATE TABLE #X;
            TRUNCATE TABLE #C;  TRUNCATE TABLE #CM;

            /* -- blocks, from what is open and not held --
               Only the kinds this pass reads: lines and rows are passes 1
               and 2, runs of days are pass 3; pass 4 reads days against
               anything and pass 5 the units. */

            IF @Pass <> 3
                INSERT INTO #BK (Kind, D, GroupKey, RefId, Cents)
                SELECT 'line', b.D, b.GroupKey, b.Id, b.Cents
                FROM #B b WHERE b.State = 'open' AND b.Held = 0 AND b.Cents > 0 OPTION (KEEPFIXED PLAN);

            INSERT INTO #BK (Kind, D, GroupKey, Cents)
            SELECT 'devday', b.D, b.GroupKey, SUM(b.Cents)
            FROM #B b WHERE b.State = 'open' AND b.Held = 0 AND b.Cents > 0
            GROUP BY b.D, b.GroupKey HAVING COUNT(*) >= 2 OPTION (KEEPFIXED PLAN);

            IF @Pass IN (3, 4)
                INSERT INTO #BK (Kind, D, Cents)
                SELECT 'bankday', b.D, SUM(b.Cents)
                FROM #B b WHERE b.State = 'open' AND b.Held = 0 AND b.Cents > 0
                GROUP BY b.D
                /* A bank day of one device is that device's day, already a
                   block. */
                HAVING COUNT(DISTINCT b.GroupKey) >= 2 OPTION (KEEPFIXED PLAN);

            INSERT INTO #BKM (BlockId, Id)
            SELECT k.BlockId, k.RefId FROM #BK k WHERE k.Kind = 'line'
            UNION ALL
            SELECT k.BlockId, b.Id
            FROM #BK k JOIN #B b ON b.D = k.D AND b.GroupKey = k.GroupKey
            WHERE k.Kind = 'devday' AND b.State = 'open' AND b.Held = 0 AND b.Cents > 0
            UNION ALL
            SELECT k.BlockId, b.Id
            FROM #BK k JOIN #B b ON b.D = k.D
            WHERE k.Kind = 'bankday' AND b.State = 'open' AND b.Held = 0 AND b.Cents > 0 OPTION (KEEPFIXED PLAN);

            /* A deposit with no BatchNo cannot be named to ManualMatch, so it
               is never offered. */
            IF @Pass <> 3
            BEGIN
                INSERT INTO #MK (Kind, D0, D1, BatchKey, MerchantKey, RefId, IsUnit, Cents)
                SELECT 'row', m.D, m.D, m.BatchKey, ISNULL(m.Merchant, N''), m.RowNo,
                       CASE WHEN COUNT(*) OVER (PARTITION BY m.D, m.BatchKey, ISNULL(m.Merchant, N'')) = 1 THEN 1 ELSE 0 END,
                       m.Cents
                FROM #M m WHERE m.State = 'open' AND m.Held = 0 AND m.Cents > 0 AND m.SourceKey IS NOT NULL OPTION (KEEPFIXED PLAN);

                INSERT INTO #MK (Kind, D0, D1, BatchKey, MerchantKey, IsUnit, Cents)
                SELECT 'batch', m.D, m.D, m.BatchKey, ISNULL(m.Merchant, N''), 1, SUM(m.Cents)
                FROM #M m WHERE m.State = 'open' AND m.Held = 0 AND m.Cents > 0 AND m.SourceKey IS NOT NULL
                GROUP BY m.D, m.BatchKey, ISNULL(m.Merchant, N'') HAVING COUNT(*) >= 2 OPTION (KEEPFIXED PLAN);
            END

            IF @Pass IN (1, 4, 5)
                /* A batch that ran over midnight: the same batch and merchant
                   on two consecutive days. 250 of the 3,767 balanced
                   historical batches have this shape. Built from each day's
                   batch total, joined to the next day's. */
                INSERT INTO #MK (Kind, D0, D1, BatchKey, MerchantKey, IsUnit, Cents)
                SELECT 'batch2', a.D, b.D, a.BatchKey, a.MerchantKey, 0, a.Cents + b.Cents
                FROM (SELECT m.D, m.BatchKey, ISNULL(m.Merchant, N'') AS MerchantKey, SUM(m.Cents) AS Cents
                      FROM #M m WHERE m.State = 'open' AND m.Held = 0 AND m.Cents > 0 AND m.SourceKey IS NOT NULL
                      GROUP BY m.D, m.BatchKey, ISNULL(m.Merchant, N'')) a
                JOIN (SELECT m.D, m.BatchKey, ISNULL(m.Merchant, N'') AS MerchantKey, SUM(m.Cents) AS Cents
                      FROM #M m WHERE m.State = 'open' AND m.Held = 0 AND m.Cents > 0 AND m.SourceKey IS NOT NULL
                      GROUP BY m.D, m.BatchKey, ISNULL(m.Merchant, N'')) b
                  ON b.BatchKey = a.BatchKey AND b.MerchantKey = a.MerchantKey AND b.D = DATEADD(day, 1, a.D)
                OPTION (KEEPFIXED PLAN);

            IF @Pass IN (3, 4)
                /* Runs of days: everything left from D0 to D0 + n. */
                INSERT INTO #MK (Kind, D0, D1, IsUnit, Cents)
                SELECT 'days', s.D0, DATEADD(day, k.n, s.D0), 0, SUM(m.Cents)
                FROM (SELECT DISTINCT D AS D0 FROM #M
                      WHERE State = 'open' AND Held = 0 AND Cents > 0 AND SourceKey IS NOT NULL) s
                JOIN @Nums k ON k.n <= @MaxLagDays
                JOIN #M m ON m.D BETWEEN s.D0 AND DATEADD(day, k.n, s.D0)
                         AND m.State = 'open' AND m.Held = 0 AND m.Cents > 0 AND m.SourceKey IS NOT NULL
                GROUP BY s.D0, k.n
                HAVING COUNT(DISTINCT m.BatchKey + N'|' + ISNULL(m.Merchant, N'')) >= 2 OPTION (KEEPFIXED PLAN);

            INSERT INTO #MKM (BlockId, RowNo)
            SELECT k.BlockId, k.RefId FROM #MK k WHERE k.Kind = 'row'
            UNION ALL
            SELECT k.BlockId, m.RowNo
            FROM #MK k JOIN #M m ON m.D BETWEEN k.D0 AND k.D1
            WHERE k.Kind IN ('batch', 'batch2')
              AND m.BatchKey = k.BatchKey AND ISNULL(m.Merchant, N'') = k.MerchantKey
              AND m.State = 'open' AND m.Held = 0 AND m.Cents > 0 AND m.SourceKey IS NOT NULL
            UNION ALL
            SELECT k.BlockId, m.RowNo
            FROM #MK k JOIN #M m ON m.D BETWEEN k.D0 AND k.D1
            WHERE k.Kind = 'days'
              AND m.State = 'open' AND m.Held = 0 AND m.Cents > 0 AND m.SourceKey IS NOT NULL OPTION (KEEPFIXED PLAN);

            /* -- candidates -- */

            IF @Pass = 1
                INSERT INTO #C (Shape, BankBlock, MopsBlock, BankD, MopsD1)
                SELECT bk.Kind + '=' + mk.Kind, bk.BlockId, mk.BlockId, bk.D, mk.D1
                FROM #BK bk
                JOIN #MK mk ON mk.Cents = bk.Cents
                WHERE bk.Kind IN ('line', 'devday')
                  AND mk.Kind IN ('row', 'batch', 'batch2')
                  AND mk.D1 <= bk.D
                  AND mk.D0 >= DATEADD(day, -@MaxLagDays, bk.D) OPTION (KEEPFIXED PLAN);

            IF @Pass = 2
            BEGIN
                INSERT INTO #MP (D, U1, U2, Cents)
                SELECT a.D0, a.BlockId, b.BlockId, a.Cents + b.Cents
                FROM #MK a JOIN #MK b ON b.D0 = a.D0 AND b.BlockId > a.BlockId
                WHERE a.IsUnit = 1 AND b.IsUnit = 1 OPTION (KEEPFIXED PLAN);

                INSERT INTO #BP (D, L1, L2, Cents)
                SELECT a.D, a.BlockId, b.BlockId, a.Cents + b.Cents
                FROM #BK a JOIN #BK b ON b.D = a.D AND b.BlockId > a.BlockId
                WHERE a.Kind = 'line' AND b.Kind = 'line' OPTION (KEEPFIXED PLAN);

                /* One bank line or device day = 2..4 deposit batches of ONE day. */
                INSERT INTO #X (Side, Target, P1, P2, Parts)
                SELECT 'M', t.BlockId, p.U1, p.U2, 2
                FROM #BK t JOIN #MP p ON p.Cents = t.Cents
                WHERE t.Kind IN ('line', 'devday')
                  AND p.D <= t.D AND p.D >= DATEADD(day, -@MaxLagDays, t.D) OPTION (KEEPFIXED PLAN);

                IF @MaxParts >= 3
                    INSERT INTO #X (Side, Target, P1, P2, P3, Parts)
                    SELECT 'M', t.BlockId, p.U1, p.U2, u.BlockId, 3
                    FROM #BK t
                    JOIN #MK u ON u.IsUnit = 1 AND u.Cents < t.Cents
                              AND u.D0 <= t.D AND u.D0 >= DATEADD(day, -@MaxLagDays, t.D)
                    JOIN #MP p ON p.D = u.D0 AND p.U2 < u.BlockId AND p.Cents = t.Cents - u.Cents
                    WHERE t.Kind IN ('line', 'devday') OPTION (KEEPFIXED PLAN);

                IF @MaxParts >= 4
                    INSERT INTO #X (Side, Target, P1, P2, P3, P4, Parts)
                    SELECT 'M', t.BlockId, p1.U1, p1.U2, p2.U1, p2.U2, 4
                    FROM #BK t
                    JOIN #MP p1 ON p1.Cents < t.Cents
                               AND p1.D <= t.D AND p1.D >= DATEADD(day, -@MaxLagDays, t.D)
                    JOIN #MP p2 ON p2.D = p1.D AND p2.U1 > p1.U2 AND p2.Cents = t.Cents - p1.Cents
                    WHERE t.Kind IN ('line', 'devday') OPTION (KEEPFIXED PLAN);

                /* One deposit batch = 2..4 bank lines of ONE day. */
                INSERT INTO #X (Side, Target, P1, P2, Parts)
                SELECT 'B', t.BlockId, p.L1, p.L2, 2
                FROM #MK t JOIN #BP p ON p.Cents = t.Cents
                WHERE t.IsUnit = 1
                  AND p.D >= t.D0 AND p.D <= DATEADD(day, @MaxLagDays, t.D0) OPTION (KEEPFIXED PLAN);

                IF @MaxParts >= 3
                    INSERT INTO #X (Side, Target, P1, P2, P3, Parts)
                    SELECT 'B', t.BlockId, p.L1, p.L2, l.BlockId, 3
                    FROM #MK t
                    JOIN #BK l ON l.Kind = 'line' AND l.Cents < t.Cents
                              AND l.D >= t.D0 AND l.D <= DATEADD(day, @MaxLagDays, t.D0)
                    JOIN #BP p ON p.D = l.D AND p.L2 < l.BlockId AND p.Cents = t.Cents - l.Cents
                    WHERE t.IsUnit = 1 OPTION (KEEPFIXED PLAN);

                IF @MaxParts >= 4
                    INSERT INTO #X (Side, Target, P1, P2, P3, P4, Parts)
                    SELECT 'B', t.BlockId, p1.L1, p1.L2, p2.L1, p2.L2, 4
                    FROM #MK t
                    JOIN #BP p1 ON p1.Cents < t.Cents
                               AND p1.D >= t.D0 AND p1.D <= DATEADD(day, @MaxLagDays, t.D0)
                    JOIN #BP p2 ON p2.D = p1.D AND p2.L1 > p1.L2 AND p2.Cents = t.Cents - p1.Cents
                    WHERE t.IsUnit = 1 OPTION (KEEPFIXED PLAN);

                INSERT INTO #C (Shape, Combo)
                SELECT CASE WHEN x.Side = 'M'
                            THEN bk.Kind + '=' + CONVERT(varchar(2), x.Parts) + ' batches'
                            ELSE CONVERT(varchar(2), x.Parts) + ' lines=batch' END,
                       x.ComboId
                FROM #X x
                LEFT JOIN #BK bk ON x.Side = 'M' AND bk.BlockId = x.Target OPTION (KEEPFIXED PLAN);
            END

            IF @Pass = 3
                INSERT INTO #C (Shape, BankBlock, MopsBlock)
                SELECT bk.Kind + '=days', bk.BlockId, mk.BlockId
                FROM #BK bk
                JOIN #MK mk ON mk.Cents = bk.Cents
                WHERE bk.Kind IN ('devday', 'bankday')
                  AND mk.Kind = 'days'
                  AND mk.D1 <= bk.D
                  AND mk.D0 >= DATEADD(day, -@MaxLagDays, bk.D) OPTION (KEEPFIXED PLAN);

            IF @Pass >= 4
                /* Close: bank blocks against deposit blocks whose total is
                   within the tolerance, and not equal — an equal one is the
                   exact tiers' and they have had it. The tolerance scales with
                   the bank side, so a R220 line is close only to
                   R217.80..R222.20 and a R143,000 day to within R500. '~' in
                   the shape marks a close reading.

                   DAYS FIRST. Pass 4 takes the readings with a day's worth on
                   at least one side — takings over a run of days, or a whole
                   bank day — and pass 5 then pairs lines and batches from what
                   is left. The other way round broke the case the tier was
                   asked for: site 8's open lines on 4 Aug total R19,197.34
                   against R19,179.28 of takings on 3 Aug, and a R0.42 tie
                   between one of those lines and one of those deposits, taken
                   first for being the smaller difference, split the day.

                   The takings must be from BEFORE the bank day. An exact tie
                   on the same day is rare but real (17 of 3,999 balanced
                   historical batches); a near one on the same day is a
                   coincidence of size, and on site 8 it was most of them. */
                INSERT INTO #C (Shape, BankBlock, MopsBlock, BankD, MopsD1)
                SELECT bk.Kind + '~' + mk.Kind, bk.BlockId, mk.BlockId, bk.D, mk.D1
                FROM #BK bk
                CROSS APPLY (SELECT CASE WHEN bk.Cents * @ClosePerMille / 1000 < @CloseCapCents
                                         THEN bk.Cents * @ClosePerMille / 1000 ELSE @CloseCapCents END AS Tol) t
                JOIN #MK mk ON mk.Cents BETWEEN bk.Cents - t.Tol AND bk.Cents + t.Tol
                           AND mk.Cents <> bk.Cents
                WHERE mk.D1 < bk.D
                  AND mk.D0 >= DATEADD(day, -@MaxLagDays, bk.D)
                  AND ((@Pass = 4 AND (mk.Kind = 'days' OR bk.Kind = 'bankday'))
                    OR (@Pass = 5 AND mk.Kind <> 'days' AND bk.Kind <> 'bankday')) OPTION (KEEPFIXED PLAN);

            /* -- members -- */

            IF @Pass <> 2
                INSERT INTO #CM (CandId, Side, Ref)
                SELECT c.CandId, 'B', m.Id FROM #C c JOIN #BKM m ON m.BlockId = c.BankBlock
                UNION ALL
                SELECT c.CandId, 'M', m.RowNo FROM #C c JOIN #MKM m ON m.BlockId = c.MopsBlock
                OPTION (KEEPFIXED PLAN);
            ELSE
            BEGIN
                /* The block a combination was built against... */
                INSERT INTO #CM (CandId, Side, Ref)
                SELECT c.CandId, 'B', m.Id
                FROM #C c JOIN #X x ON x.ComboId = c.Combo AND x.Side = 'M'
                JOIN #BKM m ON m.BlockId = x.Target
                UNION ALL
                SELECT c.CandId, 'M', m.RowNo
                FROM #C c JOIN #X x ON x.ComboId = c.Combo AND x.Side = 'B'
                JOIN #MKM m ON m.BlockId = x.Target
                OPTION (KEEPFIXED PLAN);

                /* ...and the parts it was built from. */
                INSERT INTO #CM (CandId, Side, Ref)
                SELECT c.CandId, 'M', m.RowNo
                FROM #C c JOIN #X x ON x.ComboId = c.Combo AND x.Side = 'M'
                CROSS APPLY (VALUES (x.P1), (x.P2), (x.P3), (x.P4)) v(P)
                JOIN #MKM m ON m.BlockId = v.P
                UNION ALL
                SELECT c.CandId, 'B', m.Id
                FROM #C c JOIN #X x ON x.ComboId = c.Combo AND x.Side = 'B'
                CROSS APPLY (VALUES (x.P1), (x.P2), (x.P3), (x.P4)) v(P)
                JOIN #BKM m ON m.BlockId = v.P
                OPTION (KEEPFIXED PLAN);
            END

            /* -- what each candidate is, from its members -- */

            UPDATE c
            SET c.BankSig = s.Sig, c.BankN = s.N, c.BankCents = s.Cents, c.BankD = s.MinD,
                c.InPeriod = CASE WHEN s.Context = 0 THEN 1 ELSE 0 END
            FROM #C c
            JOIN (SELECT x.CandId,
                         HASHBYTES('SHA2_256', STRING_AGG(CONVERT(nvarchar(max), b.MemberSig), N'#')
                                               WITHIN GROUP (ORDER BY b.MemberSig)) AS Sig,
                         COUNT(*) AS N, SUM(b.Cents) AS Cents, MIN(b.D) AS MinD,
                         SUM(CASE WHEN b.InPeriod = 0 THEN 1 ELSE 0 END) AS Context
                  FROM #CM x JOIN #B b ON b.Id = x.Ref
                  WHERE x.Side = 'B'
                  GROUP BY x.CandId) s ON s.CandId = c.CandId
            OPTION (KEEPFIXED PLAN);

            UPDATE c
            SET c.MopsSig = s.Sig, c.MopsN = s.N, c.MopsCents = s.Cents, c.MopsD1 = s.MaxD,
                c.Lag = DATEDIFF(day, s.MaxD, c.BankD)
            FROM #C c
            JOIN (SELECT x.CandId,
                         HASHBYTES('SHA2_256', STRING_AGG(CONVERT(nvarchar(max), m.MemberSig), N'#')
                                               WITHIN GROUP (ORDER BY m.MemberSig)) AS Sig,
                         COUNT(*) AS N, SUM(m.Cents) AS Cents, MAX(m.D) AS MaxD
                  FROM #CM x JOIN #M m ON m.RowNo = x.Ref
                  WHERE x.Side = 'M'
                  GROUP BY x.CandId) s ON s.CandId = c.CandId
            OPTION (KEEPFIXED PLAN);

            /* Guard, not filter: every exact generator above joins on equal
               cents, and pass 4 on a band that excludes equality, so a
               candidate whose members say otherwise is a bug in this file. It
               is dropped rather than offered. */
            DELETE cm FROM #CM cm JOIN #C c ON c.CandId = cm.CandId
            WHERE c.BankCents IS NULL OR c.MopsCents IS NULL
               OR (@Pass < 4 AND c.BankCents <> c.MopsCents)
               OR (@Pass >= 4 AND (c.BankCents = c.MopsCents OR c.Lag < 1
                                  OR ABS(c.MopsCents - c.BankCents) > c.BankCents * @ClosePerMille / 1000
                                  OR ABS(c.MopsCents - c.BankCents) > @CloseCapCents))
               OR c.Lag IS NULL OR c.Lag < 0 OR c.Lag > @MaxLagDays OPTION (KEEPFIXED PLAN);
            DELETE FROM #C
            WHERE BankCents IS NULL OR MopsCents IS NULL
               OR (@Pass < 4 AND BankCents <> MopsCents)
               OR (@Pass >= 4 AND (BankCents = MopsCents OR Lag < 1
                                  OR ABS(MopsCents - BankCents) > BankCents * @ClosePerMille / 1000
                                  OR ABS(MopsCents - BankCents) > @CloseCapCents))
               OR Lag IS NULL OR Lag < 0 OR Lag > @MaxLagDays OPTION (KEEPFIXED PLAN);

            /* One representative per reading. Several candidates can describe
               the same two sets — indistinguishable rows, or one set reached
               both as a device day and as a combination. */
            UPDATE c SET c.Rep = CASE WHEN c.CandId = r.MinId THEN 1 ELSE 0 END
            FROM #C c
            JOIN (SELECT BankSig, MopsSig, MIN(CandId) AS MinId FROM #C GROUP BY BankSig, MopsSig) r
              ON r.BankSig = c.BankSig AND r.MopsSig = c.MopsSig OPTION (KEEPFIXED PLAN);

            /* Competitors: other readings that want any of the same rows. */
            UPDATE c SET c.Alts = ISNULL(a.N, 0)
            FROM #C c
            LEFT JOIN (SELECT m.CandId, COUNT(DISTINCT c2.BankSig + c2.MopsSig) AS N
                       FROM #CM m
                       JOIN #CM m2 ON m2.Side = m.Side AND m2.Ref = m.Ref AND m2.CandId <> m.CandId
                       JOIN #C c1 ON c1.CandId = m.CandId
                       JOIN #C c2 ON c2.CandId = m2.CandId
                       WHERE NOT (c2.BankSig = c1.BankSig AND c2.MopsSig = c1.MopsSig)
                       GROUP BY m.CandId) a ON a.CandId = c.CandId
            WHERE c.Rep = 1 OPTION (KEEPFIXED PLAN);

            /* A batched line's own batch number must be among its deposits'.
               If it is not, the amounts tie but the reference says otherwise,
               and a person has to look. */
            UPDATE c SET c.TokenOk = CASE WHEN t.CandId IS NULL THEN 1 ELSE 0 END
            FROM #C c
            LEFT JOIN (SELECT DISTINCT x.CandId
                       FROM #CM x JOIN #B b ON b.Id = x.Ref
                       WHERE x.Side = 'B' AND b.Token IS NOT NULL
                         AND NOT EXISTS (SELECT 1 FROM #CM y JOIN #M m ON m.RowNo = y.Ref
                                         WHERE y.CandId = x.CandId AND y.Side = 'M' AND m.BatchKey = b.Token)) t
              ON t.CandId = c.CandId
            WHERE c.Rep = 1 OPTION (KEEPFIXED PLAN);

            /* -- take -- */

            SET @Base = ISNULL((SELECT MAX(SuggestionNo) FROM #S), 0);

            IF @Mode = 'strong'
            BEGIN
                /* Nothing else wants any of their rows, so the strong ones
                   are disjoint by construction and are taken together. */
                UPDATE c SET c.Sug = @Base + o.rn
                FROM #C c
                JOIN (SELECT CandId, ROW_NUMBER() OVER (ORDER BY BankD, MopsD1, CandId) AS rn
                      FROM #C WHERE Rep = 1 AND Alts = 0 AND TokenOk = 1) o ON o.CandId = c.CandId OPTION (KEEPFIXED PLAN);
            END
            ELSE
            BEGIN
                /* Greedy, in order, and never a row twice. Close orders by
                   the smallest difference, then the shortest lag, ahead of
                   everything else; on the exact tiers both keys are zero and
                   the order is what it always was. */
                DECLARE pick CURSOR LOCAL FAST_FORWARD FOR
                    SELECT CandId FROM #C WHERE Rep = 1
                    ORDER BY TokenOk DESC,
                             CASE WHEN @Pass >= 4 THEN ABS(MopsCents - BankCents) ELSE 0 END,
                             CASE WHEN @Pass >= 4 THEN CASE WHEN Lag = 0 THEN 99 ELSE Lag END ELSE 0 END,
                             Alts, BankN + MopsN,
                             CASE WHEN Lag = 0 THEN 99 ELSE Lag END,
                             BankD, CandId;
                OPEN pick;
                FETCH NEXT FROM pick INTO @CandId;
                WHILE @@FETCH_STATUS = 0
                BEGIN
                    IF NOT EXISTS (SELECT 1 FROM #CM m JOIN #B b ON b.Id = m.Ref
                                   WHERE m.CandId = @CandId AND m.Side = 'B' AND b.State <> 'open')
                       AND NOT EXISTS (SELECT 1 FROM #CM m JOIN #M r ON r.RowNo = m.Ref
                                       WHERE m.CandId = @CandId AND m.Side = 'M' AND r.State <> 'open')
                    BEGIN
                        SET @Base += 1;
                        UPDATE #C SET Sug = @Base WHERE CandId = @CandId OPTION (KEEPFIXED PLAN);
                        UPDATE b SET b.State = 'taken', b.Sug = @Base
                        FROM #B b JOIN #CM m ON m.Side = 'B' AND m.Ref = b.Id WHERE m.CandId = @CandId OPTION (KEEPFIXED PLAN);
                        UPDATE r SET r.State = 'taken', r.Sug = @Base
                        FROM #M r JOIN #CM m ON m.Side = 'M' AND m.Ref = r.RowNo WHERE m.CandId = @CandId OPTION (KEEPFIXED PLAN);
                    END
                    FETCH NEXT FROM pick INTO @CandId;
                END
                CLOSE pick; DEALLOCATE pick;
            END

            INSERT INTO #S (SuggestionNo, Confidence, Sweep, Pass, Shape, Alts, TokenOk, InPeriod, DiffCents)
            SELECT c.Sug, @Mode, @Sweep, @Pass, c.Shape, ISNULL(c.Alts, 0), c.TokenOk, c.InPeriod,
                   c.MopsCents - c.BankCents
            FROM #C c WHERE c.Sug IS NOT NULL OPTION (KEEPFIXED PLAN);

            SET @N = @@ROWCOUNT;

            IF @Mode = 'strong' AND @N > 0
            BEGIN
                UPDATE b SET b.State = 'taken', b.Sug = c.Sug
                FROM #B b JOIN #CM m ON m.Side = 'B' AND m.Ref = b.Id
                JOIN #C c ON c.CandId = m.CandId WHERE c.Sug IS NOT NULL OPTION (KEEPFIXED PLAN);
                UPDATE r SET r.State = 'taken', r.Sug = c.Sug
                FROM #M r JOIN #CM m ON m.Side = 'M' AND m.Ref = r.RowNo
                JOIN #C c ON c.CandId = m.CandId WHERE c.Sug IS NOT NULL OPTION (KEEPFIXED PLAN);
            END

            /* A pass that took nothing holds every row it had a reading for,
               so a later, looser pass cannot claim a row a simpler reading
               was only undecided about. */
            IF @Mode = 'strong' AND @N = 0
            BEGIN
                UPDATE b SET b.Held = 1 FROM #B b
                WHERE EXISTS (SELECT 1 FROM #CM m WHERE m.Side = 'B' AND m.Ref = b.Id) OPTION (KEEPFIXED PLAN);
                UPDATE r SET r.Held = 1 FROM #M r
                WHERE EXISTS (SELECT 1 FROM #CM m WHERE m.Side = 'M' AND m.Ref = r.RowNo) OPTION (KEEPFIXED PLAN);
            END

            SET @Took += @N;
            SET @Pass += 1;
        END

        IF @Mode = 'close' SET @Mode = NULL;
        ELSE IF @Mode = 'possible' SET @Mode = CASE WHEN @CloseCapCents > 0 THEN 'close' END;
        ELSE IF @Took = 0 OR @Sweep >= @MaxSweeps SET @Mode = 'possible';
    END

    /* ---- 5. The answer ----------------------------------------------------- */

    /* A suggestion made of context lines belongs to the period they are in.
       It did its job — it kept its deposits from being handed to the wrong
       line — and it is offered when that period is the one being looked at. */
    UPDATE b SET b.Sug = NULL FROM #B b JOIN #S s ON s.SuggestionNo = b.Sug WHERE s.InPeriod = 0;
    UPDATE m SET m.Sug = NULL FROM #M m JOIN #S s ON s.SuggestionNo = m.Sug WHERE s.InPeriod = 0;
    DELETE FROM #S WHERE InPeriod = 0;

    SELECT s.SuggestionNo,
           s.Confidence,
           s.Sweep,
           s.Pass,
           s.Shape,
           CASE
               WHEN s.Shape = 'line=row'      THEN 'One bank line and one deposit, the same amount'
               WHEN s.Shape = 'line=batch'    THEN 'One bank line settles a whole deposit batch'
               WHEN s.Shape = 'line=batch2'   THEN 'One bank line settles a deposit batch that ran over two days'
               WHEN s.Shape = 'devday=row'    THEN 'One device''s lines for the day add up to one deposit'
               WHEN s.Shape = 'devday=batch'  THEN 'One device''s lines for the day add up to one deposit batch'
               WHEN s.Shape = 'devday=batch2' THEN 'One device''s lines for the day add up to a batch that ran over two days'
               WHEN s.Shape LIKE 'line=% batches'   THEN 'One bank line adds up to ' + REPLACE(REPLACE(s.Shape, 'line=', ''), ' batches', '') + ' deposit batches from one day'
               WHEN s.Shape LIKE 'devday=% batches' THEN 'One device''s lines for the day add up to ' + REPLACE(REPLACE(s.Shape, 'devday=', ''), ' batches', '') + ' deposit batches from one day'
               WHEN s.Shape LIKE '% lines=batch'    THEN REPLACE(s.Shape, ' lines=batch', '') + ' bank lines from one day add up to one deposit batch'
               WHEN s.Shape = 'devday=days'   THEN 'What is left of one device''s day equals what is left of the takings over those days'
               WHEN s.Shape = 'bankday=days'  THEN 'What is left of the bank day equals what is left of the takings over those days'
               /* Close: the two halves of the shape, in words. */
               WHEN s.Shape LIKE '%~%' THEN
                   CASE LEFT(s.Shape, CHARINDEX('~', s.Shape) - 1)
                        WHEN 'line'    THEN 'One bank line'
                        WHEN 'devday'  THEN 'One device''s lines for the day'
                        ELSE                'What is left of the bank day' END
                   + ' is within a few rand of '
                   + CASE SUBSTRING(s.Shape, CHARINDEX('~', s.Shape) + 1, 24)
                          WHEN 'row'    THEN 'one deposit'
                          WHEN 'batch'  THEN 'one deposit batch'
                          WHEN 'batch2' THEN 'a deposit batch that ran over two days'
                          ELSE               'what is left of the takings over those days' END
               ELSE s.Shape END                                AS Basis,
           /* Why a possible one is only possible, in words. A strong one has
              no caution: nothing else wanted any of its rows. A close one
              leads with its difference, which is the thing a person has to
              give a reason for. */
           CASE
               WHEN s.Confidence = 'strong' THEN NULL
               WHEN s.Confidence = 'close' THEN
                   /* Never more than R5,000, so no grouping to get wrong. */
                   'The bank is R' + CONVERT(varchar(20), CONVERT(decimal(19, 2), ABS(s.DiffCents) / 100.0))
                   + CASE WHEN s.DiffCents > 0 THEN ' short of the takings' ELSE ' over the takings' END
                   + CASE WHEN s.TokenOk = 0 THEN '; the batch number on the bank line is not on these deposits'
                          WHEN s.Alts = 1    THEN '; one other close reading wants some of these rows'
                          WHEN s.Alts > 1    THEN '; ' + CONVERT(varchar(10), s.Alts) + ' other close readings want some of these rows'
                          ELSE '' END
               WHEN s.TokenOk = 0 THEN 'The batch number on the bank line is not on these deposits'
               WHEN s.Alts = 1    THEN 'One other pairing wants some of these rows'
               WHEN s.Alts > 1    THEN CONVERT(varchar(10), s.Alts) + ' other pairings want some of these rows'
               ELSE 'A simpler reading was undecided about some of these rows' END AS Caution,
           s.Alts                                              AS Alternatives,
           b.Lines                                             AS BankLines,
           b.Total                                             AS BankTotal,
           b.FromD                                             AS BankFrom,
           b.ToD                                               AS BankTo,
           b.Devices                                           AS Devices,
           m.Rows                                              AS MopsRows,
           m.Total                                             AS MopsTotal,
           m.FromD                                             AS MopsFrom,
           m.ToD                                               AS MopsTo,
           m.Batches                                           AS Batches,
           DATEDIFF(day, m.ToD, b.FromD)                       AS LagDays,
           /* The window agora.usp_Recon_ManualMatch has to be given for it to
              find every deposit in this suggestion. */
           CASE WHEN m.FromD < b.FromD THEN m.FromD ELSE b.FromD END AS MatchFrom,
           CASE WHEN m.ToD   > b.ToD   THEN m.ToD   ELSE b.ToD   END AS MatchTo,
           /* Deposits less bank, the sign agora.usp_Recon_ManualMatch records
              its variance in. Zero on every exact suggestion. */
           CONVERT(money, s.DiffCents / 100.0)                 AS DiffAmount
    FROM #S s
    CROSS APPLY (
        SELECT COUNT(*) AS Lines, SUM(x.Amount) AS Total, MIN(x.D) AS FromD, MAX(x.D) AS ToD,
               (SELECT STRING_AGG(g.GroupKey, N', ') WITHIN GROUP (ORDER BY g.GroupKey)
                FROM (SELECT DISTINCT y.GroupKey FROM #B y WHERE y.Sug = s.SuggestionNo) g) AS Devices
        FROM #B x WHERE x.Sug = s.SuggestionNo
    ) b
    CROSS APPLY (
        SELECT COUNT(*) AS Rows, SUM(x.Amount) AS Total, MIN(x.D) AS FromD, MAX(x.D) AS ToD,
               (SELECT STRING_AGG(g.SourceKey, N', ') WITHIN GROUP (ORDER BY g.SourceKey)
                FROM (SELECT DISTINCT y.SourceKey FROM #M y WHERE y.Sug = s.SuggestionNo) g) AS Batches
        FROM #M x WHERE x.Sug = s.SuggestionNo
    ) m
    ORDER BY CASE s.Confidence WHEN 'strong' THEN 0 WHEN 'possible' THEN 1 ELSE 2 END, b.FromD, s.SuggestionNo;

    SELECT x.SuggestionNo, x.Side, x.BankStatementLineID, x.SourceKey, x.SourceDate,
           x.Merchant, x.Amount, x.Description, x.GroupKey
    FROM (
        SELECT b.Sug AS SuggestionNo, 'bank' AS Side, b.Id AS BankStatementLineID,
               CONVERT(nvarchar(100), NULL) AS SourceKey, CONVERT(datetime, b.D) AS SourceDate,
               CONVERT(nvarchar(50), NULL) AS Merchant, b.Amount, b.Descr AS Description, b.GroupKey
        FROM #B b WHERE b.Sug IS NOT NULL
        UNION ALL
        SELECT m.Sug, 'mops', NULL, m.SourceKey, m.TS, m.Merchant, m.Amount, NULL, NULL
        FROM #M m WHERE m.Sug IS NOT NULL
    ) x
    ORDER BY x.SuggestionNo, CASE x.Side WHEN 'bank' THEN 0 ELSE 1 END, x.SourceDate, x.Amount DESC;

    /* In-period rows only. Context lines and the deposits in front of the
       period are machinery; the figures are about the period asked for. */
    SELECT @BranchId                                                                          AS BranchId,
           @ReconArea                                                                         AS ReconArea,
           @BatchPass                                                                         AS BatchPassRan,
           (SELECT COUNT(*) FROM #B WHERE InPeriod = 1)                                       AS BankRows,
           (SELECT ISNULL(SUM(Amount), 0) FROM #B WHERE InPeriod = 1)                         AS BankTotal,
           (SELECT COUNT(*) FROM #B WHERE InPeriod = 1 AND Population = 'standalone')         AS BankStandalone,
           (SELECT COUNT(*) FROM #M WHERE TS >= @FromDate AND TS <= @ToDate)                  AS MopsRows,
           (SELECT ISNULL(SUM(Amount), 0) FROM #M WHERE TS >= @FromDate AND TS <= @ToDate)    AS MopsTotal,
           (SELECT COUNT(*) FROM #B WHERE InPeriod = 1 AND State = 'batch')                   AS BatchBankRows,
           (SELECT ISNULL(SUM(Amount), 0) FROM #B WHERE InPeriod = 1 AND State = 'batch')     AS BatchBankTotal,
           (SELECT COUNT(*) FROM #M WHERE State = 'batch')                                    AS BatchMopsRows,
           (SELECT COUNT(*) FROM #S WHERE Confidence = 'strong')                              AS StrongSuggestions,
           (SELECT COUNT(*) FROM #S WHERE Confidence = 'possible')                            AS PossibleSuggestions,
           (SELECT COUNT(*) FROM #B b JOIN #S s ON s.SuggestionNo = b.Sug WHERE s.Confidence = 'strong')                 AS StrongBankRows,
           (SELECT ISNULL(SUM(b.Amount), 0) FROM #B b JOIN #S s ON s.SuggestionNo = b.Sug WHERE s.Confidence = 'strong')   AS StrongBankTotal,
           (SELECT COUNT(*) FROM #B b JOIN #S s ON s.SuggestionNo = b.Sug WHERE s.Confidence = 'possible')               AS PossibleBankRows,
           (SELECT ISNULL(SUM(b.Amount), 0) FROM #B b JOIN #S s ON s.SuggestionNo = b.Sug WHERE s.Confidence = 'possible') AS PossibleBankTotal,
           (SELECT COUNT(*) FROM #S WHERE Confidence = 'close')                               AS CloseSuggestions,
           (SELECT COUNT(*) FROM #B b JOIN #S s ON s.SuggestionNo = b.Sug WHERE s.Confidence = 'close')                  AS CloseBankRows,
           (SELECT ISNULL(SUM(b.Amount), 0) FROM #B b JOIN #S s ON s.SuggestionNo = b.Sug WHERE s.Confidence = 'close')    AS CloseBankTotal,
           (SELECT CONVERT(money, ISNULL(SUM(DiffCents), 0) / 100.0) FROM #S WHERE Confidence = 'close')                   AS CloseVariance,
           @CloseMax                                                                          AS CloseMax,
           @ClosePct                                                                          AS ClosePct,
           (SELECT COUNT(*) FROM #M WHERE Sug IS NOT NULL)                                    AS SuggestedMopsRows,
           (SELECT COUNT(*) FROM #B WHERE InPeriod = 1 AND State = 'open')                    AS LeftBankRows,
           (SELECT ISNULL(SUM(Amount), 0) FROM #B WHERE InPeriod = 1 AND State = 'open')      AS LeftBankTotal,
           @Sweep                                                                             AS Sweeps,
           @MaxLagDays                                                                        AS MaxLagDays,
           @MaxParts                                                                          AS MaxParts,
           DATEDIFF(millisecond, @Started, SYSDATETIME())                                     AS ElapsedMs;
END
