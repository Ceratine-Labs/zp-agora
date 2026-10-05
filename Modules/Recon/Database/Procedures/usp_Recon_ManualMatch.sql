/*
 * agora.usp_Recon_ManualMatch — a reconciliation a person made, not a rule.
 *
 * ** THIS PROCEDURE WRITES TO THE CUSTOMER'S ESTATE, in live mode, exactly as
 * ** agora.usp_Recon_Commit does and to the same three places:
 * **   PumpIT.dbo.SS_UniqueNumber              the batch counter, incremented
 * **   PumpIT.dbo.RCN_BankStatementLinesPumpIT ReconBatchNo, ReconState = 2
 * **   PumpIT.dbo.BRN_DailyBanking{Area}       ReconBatchNoPumpIT
 * ** and, on a cash-bag match with a short due, through
 * ** agora.usp_Cash_RaiseStaffShort (whose header lists it): one
 * ** BRN_StaffShorts row per short and SS_UniqueNumber section 2;
 * ** and, on a FORCED FNB match with @AdjustMode 'live', on each ticked
 * **   PumpIT.dbo.BRN_DailyBankingFNB row: Amount, AdjustedBy, Adjusted
 * ** (see THE FNB ADJUSTMENT below).
 * ** Read this header before changing a line of it.
 *
 * WHY A PERSON IS ALLOWED TO DO THIS AT ALL. The previews can only propose
 * what a configured rule can reach. A reference typed wrong at the till, a
 * deposit banked under a colleague's slip, a batch split across two days —
 * none of those has a rule and none ever will, and today they sit on the
 * estate forever. This is the clerk doing what no rule can.
 *
 * WHAT IT REFUSES TO BECOME. It is not a way around the guards. Every row is
 * re-read and re-checked here, the same as under Commit:
 *   · every bank line named must still be ReconState = 1
 *   · every deposit named must still be ReconBatchNoPumpIT = 0
 *   · the count resolved must equal the count asked for — never more
 * A match whose two sides do NOT balance is allowed, because sometimes that is
 * the truth of it, but only with a REASON, and the variance is recorded and
 * travels with the batch everywhere it is shown afterwards. Silence is not an
 * option: @Reason is required the moment the two totals differ.
 *
 * A MANUAL MATCH IS A RUN. It writes agora.ReconRun, one ReconRunLine, a
 * ReconBatch, a ReconMatch per side carrying the PRIOR state of every row, and
 * a ReconStamp — the same shapes an automatic one writes (and, on cash bags,
 * an agora.ReconAllocation line per cashier). That is deliberate
 * and it is the whole design: agora.usp_Recon_Reverse, the trace screen, the
 * run page and the extract all work on it unchanged, and a hand-made
 * reconciliation is undone by exactly the path an automatic one is.
 *
 * @MopsJson  [{"key":"D6974400001","dt":"2026-08-03","amt":33320.00}, …]
 *            The deposit side has no single id across the family, so a row is
 *            named by its key, date and amount. Where two rows are genuinely
 *            indistinguishable, TOP (n) stamps exactly as many as were ticked
 *            — never all of them.
 *
 * @Basis     Set when the clerk accepted a suggestion from
 *            agora.usp_Recon_SuggestMatches rather than ticking the rows
 *            themselves — the suggestion's reading, in words. It changes
 *            nothing about what is checked or stamped; it is recorded on the
 *            run (Note, ParamsJson, Outcome 'Matched by suggestion') so the
 *            ledger can always say which pairings an algorithm proposed and
 *            which a person built. (23 Sep 2026.)
 *            A CLOSE suggestion arrives with a variance, so it also needs
 *            @Reason, exactly as a forced match by hand does; its run says
 *            'Matched by suggestion - forced' and carries both. (23 Sep 2026.)
 *
 * @AllocationJson  CASH BAGS ONLY, and required there (27 Sep 2026, task
 *            01a084fd-0674). A bag belongs to the cashier who dropped it, so a
 *            cash-bag match also says who the cash is accounted to — one line
 *            per cashier among the ticked bags, as the clerk confirmed it:
 *
 *              [{"code":"AM261117","banked":"4200.00","share":"-36.25"},
 *               {"code":null,      "banked":"250.00", "share":"-2.16"}, …]
 *
 *            code    BRN_DropSafe.CashierCode, trimmed; null for the bags that
 *                    record no cashier (no drop-safe row), which are one line
 *                    of their own and never dropped from the split.
 *            banked  that cashier's CashBagAmount total. An ECHO of what the
 *                    screen showed: this procedure groups the bags itself and
 *                    refuses a split whose cashiers or totals differ from its
 *                    own, so the clerk can only confirm the split the bags give.
 *            share   that cashier's share of the variance, bank less bags.
 *
 *            Ryan's decisions of 9 Sep 2026 are the rules: the split is on
 *            CashBagAmount, with BRN_DropSafe.Amount carried beside it (q1);
 *            a variance is shared PRO RATA by what each cashier banked (q2);
 *            only CashBags has a split (q4); and a split that does not add up
 *            is a REFUSAL, ALLOCATION_INCOMPLETE, like every guard above (q5).
 *            "Adds up" is both halves: the banked lines are exactly the bags
 *            ticked, and the shares are exactly the variance, to the cent. So
 *            every line's banked + share — what it is accountable for — sums
 *            to the bank total.
 *
 *            The DERIVED share is computed here (step 4b) and nowhere else is
 *            authoritative: variance x banked / bags total, in whole cents,
 *            half away from zero, with the rounding remainder on the line that
 *            banked most (ties: the code, ordinally). recon-match.js mirrors it
 *            to draw the default; if the two ever disagree, this is right and
 *            the line is labelled 'manual'. The clerk may change a share —
 *            each line is then 'derived' where it equals this procedure's
 *            share and 'manual' where it does not — and the split must still
 *            add up.
 *
 *            The confirmed split is held in @Allocation (cashier, name, bags,
 *            banked, drop-safe total, bag dates, derived share, share,
 *            accountable, basis), validated before the transaction, and
 *            written to agora.ReconAllocation INSIDE it (step 5b), one row per
 *            cashier line, so the batch and its split exist together or not at
 *            all. Task 01a084fd-07f8, 27 Sep 2026.
 *
 *            THE STAFF SHORT. Each line carries a ShortStatus. A short is due
 *            only for a NEGATIVE share (bank less bags: this cashier's bags
 *            came to more than the bank received) on a line with a cashier:
 *              pending     live mode, share < 0 — due, not raised yet
 *              journal     journal mode, share < 0 — never raised; journal
 *                          mode writes nothing to PumpIT
 *              none        share >= 0 — an over, or nothing, raises nothing
 *              no_cashier  the bags with no cashier recorded keep their share
 *                          and raise nothing (Ryan, 27 Sep 2026, PM decision
 *                          01a0e375); the match is not refused for them
 *            In live mode every 'pending' line is RAISED in step 5b, inside
 *            this transaction, through agora.usp_Cash_RaiseStaffShort (task
 *            T040) — never by writing BRN_StaffShorts here — and becomes
 *              raised      StaffShortsNo = the short, waiting for an approver
 *            A short that cannot be raised fails the whole match. The status
 *            row's message names the shorts raised. See the T040 CALL SITE.
 *
 * THE FNB ADJUSTMENT (Chantal Peens, ZP, 1 Oct 2026). A forced FNB match
 * writes its variance to the DEPOSIT side, as PumpIT's own forced batch does:
 * each ticked BRN_DailyBankingFNB row's Amount moves by its share, the same
 * share is added to AdjustedBy and Adjusted is set, so the deposits meet the
 * bank to the cent and the bank line never moves. On live every FNB batch
 * with an AdjustedBy has SUM(Amount) = the bank (40 of 40, 5 Oct 2026), which
 * is what this reproduces. The share is pro rata by Amount in whole cents,
 * the remainder on the largest row (ties: till, shift, merchant) — the cash
 * bags rule (Ryan, 9 Sep 2026, q2). Rows are addressed by the table's primary
 * key (site, till, shift, date, batch, merchant), and each row's prior Amount,
 * Adjusted and AdjustedBy are kept on agora.ReconMatch so usp_Recon_Reverse
 * puts them back exactly. Switched by config('recon.fnb_adjust_mode'), a
 * literal, passed in as @AdjustMode; only in live stamp mode.
 *
 * Refusals: NOTHING_SELECTED · BANK_ROW_MOVED · MOPS_ROW_MOVED · ADJUST_NEGATIVE ·
 *           FORCE_REASON_REQUIRED · ALLOCATION_INCOMPLETE ·
 *           ALLOCATION_NOT_APPLICABLE · UNKNOWN_AREA · NO_COUNTER — and, while
 *           raising a short, agora.usp_Cash_RaiseStaffShort's own (CASH_SHORT_*,
 *           CASH_PUMPIT_TRIGGER), which roll the whole match back
 */
CREATE OR ALTER PROCEDURE [agora].[usp_Recon_ManualMatch]
    @BranchId    int,
    @ReconArea   nvarchar(20),
    @FromDate    date,
    @ToDate      datetime,
    @BankLineIds nvarchar(max),
    @MopsJson    nvarchar(max),
    @Reason      nvarchar(300) = NULL,
    @StampMode   varchar(10)   = 'journal',
    @UserId      int           = NULL,
    @Basis       nvarchar(200) = NULL,
    @AllocationJson nvarchar(max) = NULL,
    @AdjustMode  varchar(10)   = 'off'
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    IF @ReconArea NOT IN ('ABSA', 'FNB', 'CashMachine', 'CashBags', 'SmartATM')
        THROW 51000, 'AGORA:UNKNOWN_AREA:That is not a reconciliation area.', 1;

    DECLARE @BankType nvarchar(50) =
        CASE WHEN @ReconArea = 'CashBags' THEN 'CashDeposit' ELSE @ReconArea END;

    /* ---- 1. What was ticked ------------------------------------------------ */

    DECLARE @Want TABLE (Id bigint PRIMARY KEY);

    INSERT INTO @Want (Id)
    SELECT DISTINCT TRY_CONVERT(bigint, LTRIM(RTRIM(s.value)))
    FROM STRING_SPLIT(ISNULL(@BankLineIds, ''), ',') s
    WHERE LTRIM(RTRIM(s.value)) <> '' AND TRY_CONVERT(bigint, LTRIM(RTRIM(s.value))) IS NOT NULL;

    /* ref2 is the deposit's merchant number on ABSA and FNB (GetSides'
       SourceRef2). Two settlements can share batch, date and amount under
       different merchants; without it the procedure could stamp the other
       merchant's row. Absent (an older screen), the row is named by the
       triple as before. */
    DECLARE @WantMops TABLE (
        Seq int IDENTITY(1,1) PRIMARY KEY,
        SourceId bigint NULL, SourceKey nvarchar(100), SourceDate date, Amount money,
        Ref2 nvarchar(50) NULL
    );

    INSERT INTO @WantMops (SourceId, SourceKey, SourceDate, Amount, Ref2)
    SELECT TRY_CONVERT(bigint, JSON_VALUE(j.value, '$.id')),
           LTRIM(RTRIM(JSON_VALUE(j.value, '$.key'))),
           TRY_CONVERT(date, JSON_VALUE(j.value, '$.dt')),
           TRY_CONVERT(money, JSON_VALUE(j.value, '$.amt')),
           CASE WHEN @ReconArea IN ('ABSA', 'FNB') THEN NULLIF(LTRIM(RTRIM(JSON_VALUE(j.value, '$.ref2'))), '') END
    FROM OPENJSON(ISNULL(@MopsJson, '[]')) j;

    IF NOT EXISTS (SELECT 1 FROM @Want) OR NOT EXISTS (SELECT 1 FROM @WantMops)
        THROW 51000, 'AGORA:NOTHING_SELECTED:A match needs at least one row on each side. Tick both.', 1;

    /* ---- 2. The bank side, re-read ---------------------------------------- */

    DECLARE @Bank TABLE (
        BankStatementLineID bigint PRIMARY KEY, LineDate date,
        Amount money, PriorReconState int, PriorBatchNo int
    );

    INSERT INTO @Bank
    SELECT l.BankStatementLineID, CONVERT(date, l.LineDate), l.Amount, l.ReconState, l.ReconBatchNo
    FROM agora.vw_BankStatementLine l
    JOIN @Want w ON w.Id = l.BankStatementLineID
    WHERE l.BranchId = @BranchId
      AND l.Type = @BankType
      AND l.IDState = 2
      /* Still outstanding. A line something else has claimed since the screen
         was drawn is not stamped over — the same guard Commit applies, and for
         the same reason: the estate moves underneath a screen. */
      AND l.ReconState = 1;

    IF (SELECT COUNT(*) FROM @Bank) <> (SELECT COUNT(*) FROM @Want)
        THROW 51000, 'AGORA:BANK_ROW_MOVED:One of the bank lines is no longer outstanding — something reconciled it since this screen was drawn. Reload and pick again. Nothing was written.', 1;

    /* ---- 3. The deposit side, re-read and counted ------------------------- */

    /*
     * Rows are named by key, date and amount because most of the
     * BRN_DailyBanking family has no id of its own. Where two rows are
     * genuinely indistinguishable the count is what matters, so the wants are
     * grouped and exactly that many are claimed — never all of them, which is
     * how a manual match would otherwise quietly stamp a row nobody ticked.
     */
    DECLARE @Avail TABLE (
        Seq int IDENTITY(1,1) PRIMARY KEY,
        SourceId bigint NULL, SourceKey nvarchar(100), SourceDate date, Amount money, Rn int,
        /* ABSA and FNB: the merchant number, and the row's place among those
           sharing key, date, amount AND merchant. */
        Ref2 nvarchar(50) NULL, Rn2 int NULL,
        /* Cash bags only: who dropped the bag and what they declared at the
           safe, read off agora.vw_DailyBankingCashBags (one row per bag). */
        CashierCode nvarchar(50) NULL, CashierName nvarchar(200) NULL, DropSafeAmount money NULL,
        /* FNB only: the rest of the row's primary key, raw, and what it
           already carries in AdjustedBy — see THE FNB ADJUSTMENT. */
        TillNo int NULL, ShiftNo int NULL, TxnAt datetime NULL, BatchNoRaw nvarchar(20) NULL,
        MerchantNo nvarchar(20) NULL, Adjusted bit NULL, AdjustedBy money NULL
    );

    IF @ReconArea = 'ABSA'
        INSERT INTO @Avail (SourceId, SourceKey, SourceDate, Amount, Rn, Ref2, Rn2)
        SELECT NULL, LTRIM(RTRIM(CONVERT(nvarchar(100), d.BatchNumber))), CONVERT(date, d.TransactionDate), d.TransactionAmount,
               ROW_NUMBER() OVER (PARTITION BY LTRIM(RTRIM(CONVERT(nvarchar(100), d.BatchNumber))), CONVERT(date, d.TransactionDate), d.TransactionAmount
                                  ORDER BY LTRIM(RTRIM(d.MerchantNumber))),
               LTRIM(RTRIM(d.MerchantNumber)),
               ROW_NUMBER() OVER (PARTITION BY LTRIM(RTRIM(CONVERT(nvarchar(100), d.BatchNumber))), CONVERT(date, d.TransactionDate), d.TransactionAmount,
                                               LTRIM(RTRIM(d.MerchantNumber)) ORDER BY (SELECT NULL))
        FROM agora.vw_DailyBankingABSA d
        WHERE d.BranchId = @BranchId AND d.ReconBatchNoPumpIT = 0
          AND d.TransactionDate >= @FromDate AND d.TransactionDate <= @ToDate;

    ELSE IF @ReconArea = 'FNB'
        INSERT INTO @Avail (SourceId, SourceKey, SourceDate, Amount, Rn, Ref2, Rn2,
                            TillNo, ShiftNo, TxnAt, BatchNoRaw, MerchantNo, Adjusted, AdjustedBy)
        SELECT NULL, LTRIM(RTRIM(d.BatchNo)), CONVERT(date, d.TransactionDate), d.Amount,
               /* Ordered by the rest of the key, so which of two rows the
                  screen cannot tell apart is claimed is the same every time. */
               ROW_NUMBER() OVER (PARTITION BY LTRIM(RTRIM(d.BatchNo)), CONVERT(date, d.TransactionDate), d.Amount
                                  ORDER BY d.TillNo, d.ShiftNo, d.MerchantNo, d.TransactionDate),
               LTRIM(RTRIM(d.MerchantNo)),
               ROW_NUMBER() OVER (PARTITION BY LTRIM(RTRIM(d.BatchNo)), CONVERT(date, d.TransactionDate), d.Amount,
                                               LTRIM(RTRIM(d.MerchantNo))
                                  ORDER BY d.TillNo, d.ShiftNo, d.TransactionDate),
               d.TillNo, d.ShiftNo, d.TransactionDate, d.BatchNo, d.MerchantNo, d.Adjusted, d.AdjustedBy
        FROM agora.vw_DailyBankingFNB d
        WHERE d.BranchId = @BranchId AND d.ReconBatchNoPumpIT = 0
          AND d.TransactionDate >= @FromDate AND d.TransactionDate <= @ToDate;

    ELSE IF @ReconArea = 'CashMachine'
        INSERT INTO @Avail (SourceId, SourceKey, SourceDate, Amount, Rn)
        SELECT NULL, LTRIM(RTRIM(d.SlipNo)), CONVERT(date, d.TransactionDate), d.DepositaAmount,
               ROW_NUMBER() OVER (PARTITION BY LTRIM(RTRIM(d.SlipNo)), CONVERT(date, d.TransactionDate), d.DepositaAmount ORDER BY (SELECT NULL))
        FROM agora.vw_DailyBankingDeposita d
        WHERE d.BranchId = @BranchId AND d.ReconBatchNoPumpIT = 0
          AND d.TransactionDate >= @FromDate AND d.TransactionDate <= @ToDate;

    ELSE IF @ReconArea = 'CashBags'
        INSERT INTO @Avail (SourceId, SourceKey, SourceDate, Amount, Rn, CashierCode, CashierName, DropSafeAmount)
        SELECT d.DailyBankingCashBagID, LTRIM(RTRIM(d.CashBagNo)), CONVERT(date, d.TransactionDate), d.CashBagAmount, 1,
               /* NULLIF, as GetSides does: a blank code is "no cashier
                  recorded", the same line as a bag with no drop-safe row. */
               NULLIF(d.CashierCode, ''), d.CashierName, d.DropSafeAmount
        FROM agora.vw_DailyBankingCashBags d
        WHERE d.BranchId = @BranchId AND d.ReconBatchNoPumpIT = 0
          AND d.TransactionDate >= @FromDate AND d.TransactionDate <= @ToDate;

    ELSE
        INSERT INTO @Avail (SourceId, SourceKey, SourceDate, Amount, Rn)
        SELECT d.DailyBankingSmartATMID, LTRIM(RTRIM(CONVERT(nvarchar(100), d.TerminalId))), CONVERT(date, d.DepositDateTime), d.Deposited, 1
        FROM agora.vw_DailyBankingSmartATM d
        WHERE d.BranchId = @BranchId AND d.ReconBatchNoPumpIT = 0
          AND d.DepositDateTime >= @FromDate AND d.DepositDateTime <= @ToDate;

    DECLARE @Mops TABLE (
        Seq int IDENTITY(1,1) PRIMARY KEY,
        SourceId bigint NULL, SourceKey nvarchar(100), SourceDate date, Amount money,
        CashierCode nvarchar(50) NULL, CashierName nvarchar(200) NULL, DropSafeAmount money NULL,
        TillNo int NULL, ShiftNo int NULL, TxnAt datetime NULL, BatchNoRaw nvarchar(20) NULL,
        MerchantNo nvarchar(20) NULL, Adjusted bit NULL, AdjustedBy money NULL,
        Cents bigint NULL, Adjustment money NULL, Ref2 nvarchar(50) NULL
    );

    /* The two areas that HAVE an id are matched by it — exact, and it cannot
       reach a row the screen did not show. The other three are matched on the
       triple, with the nth want taking the nth available row — and on ABSA
       and FNB on the merchant too, where the screen sent it (ref2), the nth
       want of that merchant taking the nth row of that merchant. */
    INSERT INTO @Mops (SourceId, SourceKey, SourceDate, Amount, CashierCode, CashierName, DropSafeAmount,
                       TillNo, ShiftNo, TxnAt, BatchNoRaw, MerchantNo, Adjusted, AdjustedBy, Ref2)
    SELECT a.SourceId, a.SourceKey, a.SourceDate, a.Amount, a.CashierCode, a.CashierName, a.DropSafeAmount,
           a.TillNo, a.ShiftNo, a.TxnAt, a.BatchNoRaw, a.MerchantNo, a.Adjusted, a.AdjustedBy, a.Ref2
    FROM @Avail a
    JOIN (
        SELECT w.SourceId, w.SourceKey, w.SourceDate, w.Amount, w.Ref2,
               ROW_NUMBER() OVER (PARTITION BY w.SourceKey, w.SourceDate, w.Amount, w.Ref2 ORDER BY w.Seq) AS Rn
        FROM @WantMops w
    ) w
      ON ((w.SourceId IS NOT NULL AND a.SourceId = w.SourceId)
       OR (w.SourceId IS NULL AND a.SourceId IS NULL
           AND a.SourceKey = w.SourceKey AND a.SourceDate = w.SourceDate AND a.Amount = w.Amount
           AND ((w.Ref2 IS NULL AND a.Rn = w.Rn)
             OR (w.Ref2 IS NOT NULL AND a.Ref2 = w.Ref2 AND a.Rn2 = w.Rn))));

    IF (SELECT COUNT(*) FROM @Mops) <> (SELECT COUNT(*) FROM @WantMops)
        THROW 51000, 'AGORA:MOPS_ROW_MOVED:One of the deposits is no longer outstanding — something reconciled it since this screen was drawn. Reload and pick again. Nothing was written.', 1;

    /* ---- 4. Balance, and the reason a variance needs ---------------------- */

    DECLARE @BankTotal money = (SELECT SUM(Amount) FROM @Bank),
            @MopsTotal money = (SELECT SUM(Amount) FROM @Mops);

    DECLARE @Diff money = @MopsTotal - @BankTotal;

    IF @Diff <> 0 AND (@Reason IS NULL OR LTRIM(RTRIM(@Reason)) = '')
        THROW 51000, 'AGORA:FORCE_REASON_REQUIRED:The two sides do not balance. A forced match has to say why — it is the only record of the decision, and the variance is carried with the batch wherever it is shown.', 1;

    DECLARE @Forced bit = CASE WHEN @Diff <> 0 THEN 1 ELSE 0 END;

    SET @Basis = NULLIF(LTRIM(RTRIM(@Basis)), '');

    /* ---- 4a. The FNB adjustment — a forced FNB match, live only ---------- */

    /*
     * Bank less deposits, shared pro rata by Amount in whole cents, half away
     * from zero, the remainder on the largest row — the same arithmetic as the
     * cash-bag split in 4b. Every ticked row is written, a zero share
     * included, so the whole batch says Adjusted the way PumpIT's own does.
     */
    DECLARE @Adjust bit = CASE WHEN @ReconArea = 'FNB' AND @Forced = 1
                                AND @StampMode = 'live' AND @AdjustMode = 'live' THEN 1 ELSE 0 END;

    IF @Adjust = 1
    BEGIN
        DECLARE @MopsCents bigint, @GapCents bigint;

        UPDATE @Mops SET Cents = CONVERT(bigint, ROUND(Amount * 100, 0));

        SELECT @MopsCents = SUM(Cents) FROM @Mops;
        SET @GapCents = (SELECT SUM(CONVERT(bigint, ROUND(Amount * 100, 0))) FROM @Bank) - @MopsCents;

        UPDATE @Mops
        SET Adjustment = CONVERT(money,
                CASE WHEN @MopsCents = 0 THEN 0
                     ELSE SIGN(@GapCents * Cents) * SIGN(@MopsCents)
                          * ((2 * ABS(@GapCents * Cents) + ABS(@MopsCents)) / (2 * ABS(@MopsCents)))
                END) / 100;

        DECLARE @Left money = CONVERT(money, @GapCents) / 100 - (SELECT SUM(Adjustment) FROM @Mops);

        WITH largest AS (
            SELECT TOP 1 Adjustment FROM @Mops
            ORDER BY Cents DESC, TillNo, ShiftNo, MerchantNo, Seq
        )
        UPDATE largest SET Adjustment = Adjustment + @Left;

        /* A deposit cannot be adjusted below nothing. Bank less deposits never
           takes a positive row there pro rata; a refund row can, and that is
           for a person to split, not this procedure. */
        IF EXISTS (SELECT 1 FROM @Mops WHERE Amount + Adjustment < 0)
            THROW 51000, 'AGORA:ADJUST_NEGATIVE:Writing this variance to the deposits would take one below zero. Match the rows in smaller groups, or adjust it in PumpIT. Nothing was written.', 1;
    END

    /* ---- 4b. Who the cash is accounted to — cash bags only --------------- */

    /*
     * A cash bag belongs to the cashier who dropped it. A match on this area
     * therefore carries the split the clerk confirmed (see the header for the
     * shape and Ryan's four rules), and this step checks it BEFORE anything is
     * written, exactly as the guards above check the rows. Nothing here reads
     * the customer's estate beyond the rows step 3 already resolved: the
     * cashier of each ticked bag came off agora.vw_DailyBankingCashBags with
     * the bag itself, so the split is grouped from precisely the bags being
     * stamped and cannot pick up one that was not ticked.
     */
    DECLARE @Allocation TABLE (
        CashierCode  nvarchar(50)  NULL,   -- NULL: the bags that record no cashier
        CashierName  nvarchar(200) NULL,   -- NULL: no cashier, or a code not on BRN_Employee
        Bags         int           NOT NULL,
        Banked       money         NOT NULL,   -- SUM(CashBagAmount): what the split is on
        DropSafe     money         NULL,       -- SUM(BRN_DropSafe.Amount), shown beside it
        FirstBagDate date          NOT NULL,   -- the span of this cashier's bags,
        LastBagDate  date          NOT NULL,   -- which a short raised from the line is dated by
        BankedCents  bigint        NOT NULL,
        DerivedCents bigint        NULL,
        DerivedShare money         NULL,
        Share        money         NULL,       -- as confirmed
        Accountable  money         NULL,       -- Banked + Share
        Basis        varchar(10)   NULL        -- 'derived' | 'manual'
    );

    IF @ReconArea <> 'CashBags' AND NULLIF(LTRIM(RTRIM(@AllocationJson)), '') IS NOT NULL
        THROW 51000, 'AGORA:ALLOCATION_NOT_APPLICABLE:Only a cash-bag match is split by cashier. The other areas have no cashier per deposit, so a split sent with one is a mistake in the screen, not something to record. Nothing was written.', 1;

    IF @ReconArea = 'CashBags'
    BEGIN
        DECLARE @Msg nvarchar(2048);

        /* Required, not optional: a cash-bag match that says nothing about
           whose cash it was is the gap this step exists to close. */
        IF @AllocationJson IS NULL OR ISJSON(@AllocationJson) <> 1 OR LEFT(LTRIM(@AllocationJson), 1) <> '['
            THROW 51000, 'AGORA:ALLOCATION_INCOMPLETE:A cash-bag match has to say who the cash is accounted to. Confirm the split by cashier and match again. Nothing was written.', 1;

        DECLARE @In TABLE (
            Seq int IDENTITY(1,1) PRIMARY KEY,
            CashierCode nvarchar(50) NULL, Banked money NULL, Share money NULL
        );

        INSERT INTO @In (CashierCode, Banked, Share)
        SELECT NULLIF(LTRIM(RTRIM(JSON_VALUE(j.value, '$.code'))), ''),
               TRY_CONVERT(money, JSON_VALUE(j.value, '$.banked')),
               TRY_CONVERT(money, JSON_VALUE(j.value, '$.share'))
        FROM OPENJSON(@AllocationJson) j;

        /* The split the bags give: one line per cashier, the bags with no
           cashier together on a line of their own (GROUP BY puts the NULLs in
           one group, which is exactly the line wanted). Cents are summed per
           bag, as the screen sums them.

           No aggregate here ever meets a NULL: "Null value is eliminated by an
           aggregate" is a warning in SQL Server and an ERROR through the ODBC
           driver, so the name and the drop-safe amount are aggregated through
           ISNULL and put back. DropSafe is NULL only where no bag on the line
           has a drop-safe row. */
        INSERT INTO @Allocation (CashierCode, CashierName, Bags, Banked, DropSafe, FirstBagDate, LastBagDate, BankedCents)
        SELECT m.CashierCode,
               NULLIF(MAX(ISNULL(m.CashierName, N'')), N''),
               COUNT(*),
               SUM(m.Amount),
               CASE WHEN SUM(CASE WHEN m.DropSafeAmount IS NULL THEN 0 ELSE 1 END) = 0 THEN NULL
                    ELSE SUM(ISNULL(m.DropSafeAmount, 0)) END,
               /* Never NULL: step 3 read only bags dated inside the window. */
               MIN(m.SourceDate),
               MAX(m.SourceDate),
               SUM(CONVERT(bigint, ROUND(m.Amount * 100, 0)))
        FROM @Mops m
        GROUP BY m.CashierCode;

        /*
         * The derived share: variance x banked / total, in whole cents, rounded
         * half away from zero by integer arithmetic so it is exact here and
         * reproducible to the cent in the browser (recon-match.js). The cents
         * the rounding leaves over go to the line that banked most, ties broken
         * by the code in ordinal order, so the shares sum to the variance
         * exactly.
         */
        DECLARE @TotCents bigint = (SELECT SUM(BankedCents) FROM @Allocation),
                @VarCents bigint = (SELECT SUM(CONVERT(bigint, ROUND(Amount * 100, 0))) FROM @Bank)
                                 - (SELECT SUM(BankedCents) FROM @Allocation);

        UPDATE @Allocation
        SET DerivedCents = CASE WHEN @TotCents = 0 THEN 0
                                ELSE SIGN(@VarCents * BankedCents) * SIGN(@TotCents)
                                     * ((2 * ABS(@VarCents * BankedCents) + ABS(@TotCents)) / (2 * ABS(@TotCents)))
                           END;

        DECLARE @Remainder bigint = @VarCents - (SELECT SUM(DerivedCents) FROM @Allocation);

        WITH largest AS (
            SELECT TOP 1 DerivedCents
            FROM @Allocation
            ORDER BY BankedCents DESC, ISNULL(CashierCode, N'') COLLATE Latin1_General_BIN2
        )
        UPDATE largest SET DerivedCents = DerivedCents + @Remainder;

        UPDATE @Allocation SET DerivedShare = CONVERT(money, DerivedCents) / 100;

        /* The split confirmed must name exactly these lines, once each, with
           exactly these banked totals. Anything else is a split of different
           bags — a screen drawn before a drop-safe row changed, or a hand-made
           post — and confirming it would record a decision nobody saw. */
        IF EXISTS (SELECT 1 FROM @In GROUP BY ISNULL(CashierCode, N'') HAVING COUNT(*) > 1)
           OR EXISTS (SELECT 1 FROM @Allocation a
                      WHERE NOT EXISTS (SELECT 1 FROM @In i
                                        WHERE ISNULL(i.CashierCode, N'') = ISNULL(a.CashierCode, N'')
                                          AND i.Banked = a.Banked))
           OR EXISTS (SELECT 1 FROM @In i
                      WHERE NOT EXISTS (SELECT 1 FROM @Allocation a
                                        WHERE ISNULL(a.CashierCode, N'') = ISNULL(i.CashierCode, N'')))
        BEGIN
            SET @Msg = CONCAT(N'AGORA:ALLOCATION_INCOMPLETE:The split confirmed is not the split these bags give. ',
                              N'By the cashier on each bag''s drop-safe row they come to ',
                              (SELECT COUNT(*) FROM @Allocation), N' cashier line(s) and R',
                              CONVERT(nvarchar(30), @MopsTotal), N'. Reload, check the split and match again. Nothing was written.');
            THROW 51000, @Msg, 1;
        END

        UPDATE a SET a.Share = i.Share
        FROM @Allocation a
        JOIN @In i ON ISNULL(i.CashierCode, N'') = ISNULL(a.CashierCode, N'');

        IF EXISTS (SELECT 1 FROM @Allocation WHERE Share IS NULL OR Share <> ROUND(Share, 2))
            THROW 51000, 'AGORA:ALLOCATION_INCOMPLETE:Every cashier in the split needs a share of the variance in rand and cents, zero included. Nothing was written.', 1;

        /* Ryan's q5, literally: the banked lines are the bags ticked, and the
           shares are the variance, to the cent. */
        IF (SELECT SUM(Banked) FROM @Allocation) <> @MopsTotal
           OR (SELECT SUM(Share) FROM @Allocation) <> @BankTotal - @MopsTotal
        BEGIN
            SET @Msg = CONCAT(N'AGORA:ALLOCATION_INCOMPLETE:The split does not add up. The cashiers'' shares come to R',
                              CONVERT(nvarchar(30), (SELECT SUM(Share) FROM @Allocation)),
                              N', and the bank is R', CONVERT(nvarchar(30), @BankTotal),
                              N' against bags of R', CONVERT(nvarchar(30), @MopsTotal),
                              N' - a variance of R', CONVERT(nvarchar(30), @BankTotal - @MopsTotal),
                              N'. Every cent of it has to be accounted to someone. Nothing was written.');
            THROW 51000, @Msg, 1;
        END

        UPDATE @Allocation
        SET Accountable = Banked + Share,
            Basis = CASE WHEN Share = DerivedShare THEN 'derived' ELSE 'manual' END;
    END

    /* ---- 5. Write it, as a run like any other ----------------------------- */

    /* UTC, the application's clock — see usp_Recon_Commit. Every timestamp
       this writes (the run, its line, the batch, matches and stamps) is read
       beside ones PHP wrote, so SYSDATETIME() (+02:00 on ZP-MIST-SVR) would
       put them two hours out. */
    DECLARE @Now datetime2(0) = SYSUTCDATETIME();
    DECLARE @Outcome nvarchar(60) = CASE WHEN @Forced = 1 AND @Basis IS NOT NULL THEN 'Matched by suggestion - forced'
                                         WHEN @Forced = 1 THEN 'Matched by hand - forced'
                                         WHEN @Basis IS NOT NULL THEN 'Matched by suggestion'
                                         ELSE 'Matched by hand' END;
    DECLARE @RunId bigint, @LineId bigint, @BatchId bigint, @No int;

    BEGIN TRANSACTION;

    INSERT INTO agora.ReconRun
        (BranchId, GroupRef, ReconArea, FromDate, ToDate, Status, StampMode, ProcedureName,
         ParamsJson, TotalRows, MatchedRows, MismatchRows, BankOnlyRows, DepositOnlyRows, OtherRows,
         BankTotal, MopsTotal, MatchedTotal, Note, CommittedAt, CommittedBy, CommittedRows,
         CommittedTotal, CreatedAt, CreatedBy)
    SELECT @BranchId, NEWID(), @ReconArea, @FromDate, CONVERT(date, @ToDate), 'committed', @StampMode,
           'agora.usp_Recon_ManualMatch',
           (SELECT @Reason AS reason, @Forced AS forced, @Diff AS diff, @Basis AS basis FOR JSON PATH, WITHOUT_ARRAY_WRAPPER),
           1, CASE WHEN @Forced = 1 THEN 0 ELSE 1 END, @Forced, 0, 0, 0,
           @BankTotal, @MopsTotal, @BankTotal,
           CASE WHEN @Forced = 1 AND @Basis IS NOT NULL THEN LEFT(N'Suggested match (forced) — ' + @Basis, 400)
                WHEN @Forced = 1 THEN 'Manual match (forced)'
                WHEN @Basis IS NOT NULL THEN LEFT(N'Suggested match — ' + @Basis, 400)
                ELSE 'Manual match' END,
           @Now, @UserId, 1, @BankTotal, @Now, @UserId;

    SET @RunId = SCOPE_IDENTITY();

    INSERT INTO agora.ReconRunLine
        (BranchId, RunId, [LineNo], ReconArea, KeyRef, BankDate, BankLines, BankTotal,
         MopsTxns, MopsTotal, DiffAmount, Outcome, WouldReconcile, Selected, CommitState,
         BlockReason, CreatedAt, CreatedBy)
    SELECT @BranchId, @RunId, 1, @ReconArea,
           (SELECT TOP 1 SourceKey FROM @Mops ORDER BY SourceKey),
           (SELECT MIN(LineDate) FROM @Bank),
           (SELECT COUNT(*) FROM @Bank), @BankTotal,
           (SELECT COUNT(*) FROM @Mops), @MopsTotal, @Diff,
           @Outcome,
           /* 1 even when forced: it DID reconcile, by a person's decision. The
              variance is the thing that is flagged, not the match. */
           1, 1, 'committed',
           CASE WHEN @Forced = 1 THEN @Reason END,
           @Now, @UserId;

    SET @LineId = SCOPE_IDENTITY();

    /* The batch number, allocated atomically from the customer's own counter,
       so Agora's numbers and the executable's cannot collide. */
    DECLARE @Alloc TABLE (No int);

    IF @StampMode = 'live'
    BEGIN
        UPDATE u WITH (UPDLOCK, ROWLOCK)
        SET u.NextUniqueNumber = u.NextUniqueNumber + 1
        OUTPUT deleted.NextUniqueNumber INTO @Alloc(No)
        FROM [PumpIT].dbo.SS_UniqueNumber u
        WHERE u.SectionId = 1;
    END
    ELSE
        /* Journal mode never touches the customer's counter. Negative, so a
           journalled number can never be mistaken for a real one. */
        INSERT INTO @Alloc (No) VALUES (-1 * (SELECT COUNT(*) + 1 FROM agora.ReconBatch WHERE BranchId = @BranchId));

    SELECT @No = No FROM @Alloc;

    IF @No IS NULL
    BEGIN
        ROLLBACK TRANSACTION;
        THROW 51000, 'AGORA:NO_COUNTER:SS_UniqueNumber SectionId 1 returned no batch number. Nothing was written.', 1;
    END

    INSERT INTO agora.ReconBatch
        (BranchId, RunId, BatchNo, ReconArea, KeyRef, BankLineCount, MopsRowCount,
         BankTotal, MopsTotal, State, CreatedAt, CreatedBy)
    SELECT @BranchId, @RunId, @No, @ReconArea,
           (SELECT TOP 1 SourceKey FROM @Mops ORDER BY SourceKey),
           (SELECT COUNT(*) FROM @Bank), (SELECT COUNT(*) FROM @Mops),
           @BankTotal, @MopsTotal, 'committed', @Now, @UserId;

    SET @BatchId = SCOPE_IDENTITY();

    /* Every bank line, with what it held before. This is what a reversal
       restores — not zero, but whatever was actually there. */
    INSERT INTO agora.ReconMatch
        (BranchId, RunId, RunLineId, BatchId, BatchNo, Side, SourceTable,
         SourceId, SourceKeyJson, SourceDate, Amount, PriorReconState, PriorBatchNo, CreatedAt, CreatedBy)
    SELECT @BranchId, @RunId, @LineId, @BatchId, @No, 'bank', 'RCN_BankStatementLinesPumpIT',
           b.BankStatementLineID, NULL, b.LineDate, b.Amount, b.PriorReconState, b.PriorBatchNo, @Now, @UserId
    FROM @Bank b;

    INSERT INTO agora.ReconMatch
        (BranchId, RunId, RunLineId, BatchId, BatchNo, Side, SourceTable,
         SourceId, SourceKeyJson, SourceDate, Amount, PriorReconState, PriorBatchNo,
         Adjustment, PriorAmount, PriorAdjusted, PriorAdjustedBy, CreatedAt, CreatedBy)
    SELECT @BranchId, @RunId, @LineId, @BatchId, @No, 'mops',
           CASE @ReconArea WHEN 'CashMachine' THEN 'BRN_DailyBankingDeposita'
                           ELSE 'BRN_DailyBanking' + @ReconArea END,
           m.SourceId,
           /* FNB carries the rest of its primary key, raw, so a reversal can
              name the exact row it puts the prior values back on. */
           (SELECT m.SourceKey AS [key], m.SourceKey AS ref, m.SourceDate AS dt, m.Amount AS amt,
                   m.TillNo AS till, m.ShiftNo AS shift, m.MerchantNo AS merchant,
                   m.BatchNoRaw AS batch, CONVERT(nvarchar(23), m.TxnAt, 126) AS txn
            FOR JSON PATH, WITHOUT_ARRAY_WRAPPER),
           m.SourceDate, m.Amount, NULL, 0,
           CASE WHEN @Adjust = 1 THEN m.Adjustment END,
           CASE WHEN @Adjust = 1 THEN m.Amount END,
           CASE WHEN @Adjust = 1 THEN m.Adjusted END,
           CASE WHEN @Adjust = 1 THEN m.AdjustedBy END,
           @Now, @UserId
    FROM @Mops m;

    /* ---- 5b. The split by cashier, kept with the batch --------------------- */

    /*
     * The split step 4b validated, one row per cashier line, in the same
     * transaction as the batch it belongs to. On every area but CashBags
     * @Allocation is empty (4b refuses a split sent with one), so this writes
     * nothing there.
     *
     * ShortStatus is the sign rule, and the only place it is decided: a short
     * is due for a NEGATIVE share alone — bank less bags, so the bank received
     * less than this cashier's bags — and only where there is a cashier to
     * answer for it. Journal mode writes nothing to PumpIT, and a short is a
     * PumpIT write, so a journalled match never has one due. The table's
     * CK_ReconAllocation_ShortRule states the same rule, so no later writer
     * can disagree with it.
     */
    INSERT INTO agora.ReconAllocation
        (BranchId, RunId, BatchId, BatchNo, CashierCode, CashierName, Bags, Banked, DropSafe,
         FirstBagDate, LastBagDate, DerivedShare, Share, Accountable, Basis,
         ConfirmedBy, ConfirmedAt, ShortStatus, StaffShortsNo, CreatedAt, CreatedBy)
    SELECT @BranchId, @RunId, @BatchId, @No, a.CashierCode, a.CashierName, a.Bags, a.Banked, a.DropSafe,
           a.FirstBagDate, a.LastBagDate, a.DerivedShare, a.Share, a.Accountable, a.Basis,
           @UserId, @Now,
           CASE WHEN a.CashierCode IS NULL THEN 'no_cashier'
                WHEN a.Share >= 0          THEN 'none'
                WHEN @StampMode = 'live'   THEN 'pending'
                ELSE 'journal' END,
           NULL, @Now, @UserId
    FROM @Allocation a
    /* Ids in the order the split is read: largest banked first, the
       no-cashier line where its total puts it. */
    ORDER BY a.Banked DESC, ISNULL(a.CashierCode, N'') COLLATE Latin1_General_BIN2;

    /*
     * T040 CALL SITE — the staff short, raised here, inside this transaction.
     *
     * Ryan, 9 Sep 2026: a confirmed allocation raises a staff short, and ONLY
     * through agora.usp_Cash_RaiseStaffShort (task T040, the port of
     * sp_GenerateStaffShortsNo), so the counter, the approval trail and the
     * posting live in one place. Recon never writes BRN_StaffShorts itself.
     * Ryan, 27 Sep 2026 (PM decision 01a0e388): every negative share is
     * raised, however small — the R3 leniency is the deduction schedule's to
     * apply (T041), not this match's — and a split changed by hand that gives
     * one cashier a short and another an over raises the short alone.
     *
     * Once per 'pending' row of @BatchId — live mode only, because a short is a
     * PumpIT write and journal mode writes nothing there ('journal' rows are
     * never raised):
     *
     *   EmployeeCode    = CashierCode
     *   AmountShort     = -Share  (positive, as every BRN_StaffShorts row is)
     *   TransactionDate = LastBagDate
     *   ReasonCode      = 'cashier_short', the note naming the batch and the
     *                     reason the clerk gave
     *   Source/SourceId = 'recon' / agora.ReconAllocation.Id, so the short
     *                     leads back here and is raised once per line
     *   WriteMode       = @StampMode — Recon's literal switch decides
     *
     * and each row then set to ShortStatus = 'raised', StaffShortsNo = the
     * number it returned. @Quiet = 1 keeps its status row out of this
     * procedure's result sets. No existence test on the procedure, and no
     * catch: a short that cannot be raised (the cashier not on PumpIT's
     * employee list, the counter missing) fails the match, and the whole
     * transaction rolls back — a match is never made with its short skipped.
     */
    DECLARE @Shorts TABLE (StaffShortsNo int, CashierCode nvarchar(50), Amount money);

    IF @StampMode = 'live'
    BEGIN
        DECLARE @AllocId bigint, @ShortCashier nvarchar(50), @ShortAmount money, @ShortDay date,
                @ShortNo int, @ShortNote nvarchar(400), @ShortRef nvarchar(100);

        SET @ShortRef = CONCAT(N'Recon batch ', @No, N' (cash bags)');

        DECLARE shorts CURSOR LOCAL FAST_FORWARD FOR
            SELECT a.Id, a.CashierCode, -a.Share, a.LastBagDate
            FROM agora.ReconAllocation a
            WHERE a.BranchId = @BranchId AND a.BatchId = @BatchId AND a.ShortStatus = 'pending'
            ORDER BY a.Id;
        OPEN shorts;
        FETCH NEXT FROM shorts INTO @AllocId, @ShortCashier, @ShortAmount, @ShortDay;

        WHILE @@FETCH_STATUS = 0
        BEGIN
            SET @ShortNo = NULL;
            SET @ShortNote = LEFT(CONCAT(N'Cash-bag match, recon batch ', @No, N': the bank received R',
                                         CONVERT(nvarchar(30), ABS(@BankTotal - @MopsTotal)), N' less than the bags, and R',
                                         CONVERT(nvarchar(30), @ShortAmount), N' of it is this cashier''s share.',
                                         CASE WHEN @Reason IS NULL THEN N'' ELSE N' Reason given: ' + @Reason END), 400);

            EXEC agora.usp_Cash_RaiseStaffShort
                 @BranchId        = @BranchId,
                 @EmployeeCode    = @ShortCashier,
                 @AmountShort     = @ShortAmount,
                 @TransactionDate = @ShortDay,
                 @ReasonCode      = 'cashier_short',
                 @Note            = @ShortNote,
                 @Source          = 'recon',
                 @SourceId        = @AllocId,
                 @SourceRef       = @ShortRef,
                 @WriteMode       = @StampMode,
                 @UserId          = @UserId,
                 @Quiet           = 1,
                 @StaffShortsNo   = @ShortNo OUTPUT;

            UPDATE agora.ReconAllocation
            SET ShortStatus = 'raised', StaffShortsNo = @ShortNo, UpdatedAt = @Now, UpdatedBy = @UserId
            WHERE BranchId = @BranchId AND Id = @AllocId;

            INSERT INTO @Shorts (StaffShortsNo, CashierCode, Amount) VALUES (@ShortNo, @ShortCashier, @ShortAmount);

            FETCH NEXT FROM shorts INTO @AllocId, @ShortCashier, @ShortAmount, @ShortDay;
        END

        CLOSE shorts;
        DEALLOCATE shorts;
    END

    /* ---- 6. The estate ---------------------------------------------------- */

    IF @StampMode = 'live'
    BEGIN
        /* Bank side, by id, and only while still unreconciled. */
        UPDATE l
        SET l.ReconBatchNo = @No, l.ReconState = 2
        FROM [PumpIT].dbo.RCN_BankStatementLinesPumpIT l
        WHERE l.SSBranchId = @BranchId
          AND l.ReconState = 1
          AND l.BankStatementLineID IN (SELECT BankStatementLineID FROM @Bank);

        IF @ReconArea = 'CashBags'
            UPDATE d SET d.ReconBatchNoPumpIT = @No
            FROM [PumpIT].dbo.BRN_DailyBankingCashBags d
            WHERE d.SSBranchId = @BranchId AND d.ReconBatchNoPumpIT = 0
              AND d.DailyBankingCashBagID IN (SELECT SourceId FROM @Mops WHERE SourceId IS NOT NULL);

        ELSE IF @ReconArea = 'SmartATM'
            UPDATE d SET d.ReconBatchNoPumpIT = @No
            FROM [PumpIT].dbo.BRN_DailyBankingSmartATM d
            WHERE d.SSBranchId = @BranchId AND d.ReconBatchNoPumpIT = 0
              AND d.DailyBankingSmartATMID IN (SELECT SourceId FROM @Mops WHERE SourceId IS NOT NULL);

        ELSE
        BEGIN
            /*
             * The three families with no id of their own.
             *
             * UPDATE TOP (n) per (key, date, amount) group, so exactly as many
             * rows are stamped as were ticked. An unbounded UPDATE on the
             * triple would claim every indistinguishable row — including ones
             * nobody selected — which is the manual equivalent of the defect
             * this whole module exists to fix.
             */
            DECLARE @K nvarchar(100), @D date, @A money, @R nvarchar(50), @N int;

            /* Grouped by the merchant as well on ABSA and FNB (Ref2, the
               claimed row's own), so the stamp lands on that merchant's row. */
            DECLARE grp CURSOR LOCAL FAST_FORWARD FOR
                SELECT SourceKey, SourceDate, Amount, Ref2, COUNT(*)
                FROM @Mops GROUP BY SourceKey, SourceDate, Amount, Ref2;
            OPEN grp;
            FETCH NEXT FROM grp INTO @K, @D, @A, @R, @N;

            WHILE @@FETCH_STATUS = 0
            BEGIN
                IF @ReconArea = 'ABSA'
                    UPDATE TOP (@N) d SET d.ReconBatchNoPumpIT = @No
                    FROM [PumpIT].dbo.BRN_DailyBankingABSA d
                    WHERE d.SSBranchId = @BranchId AND d.ReconBatchNoPumpIT = 0
                      AND LTRIM(RTRIM(CONVERT(nvarchar(100), d.BatchNumber))) = @K
                      AND CONVERT(date, d.TransactionDate) = @D
                      AND d.TransactionAmount = @A
                      AND (@R IS NULL OR LTRIM(RTRIM(d.MerchantNumber)) = @R);

                ELSE IF @ReconArea = 'FNB' AND @Adjust = 0
                    UPDATE TOP (@N) d SET d.ReconBatchNoPumpIT = @No
                    FROM [PumpIT].dbo.BRN_DailyBankingFNB d
                    WHERE d.SSBranchId = @BranchId AND d.ReconBatchNoPumpIT = 0
                      AND LTRIM(RTRIM(d.BatchNo)) = @K
                      AND CONVERT(date, d.TransactionDate) = @D
                      AND d.Amount = @A
                      AND (@R IS NULL OR LTRIM(RTRIM(d.MerchantNo)) = @R);

                ELSE IF @ReconArea = 'FNB'
                    /* Adjusting: stamped after this loop, by primary key,
                       with the adjustment — see THE FNB ADJUSTMENT. */
                    SET @N = @N;

                ELSE
                    UPDATE TOP (@N) d SET d.ReconBatchNoPumpIT = @No
                    FROM [PumpIT].dbo.BRN_DailyBankingDeposita d
                    WHERE d.SSBranchId = @BranchId AND d.ReconBatchNoPumpIT = 0
                      AND LTRIM(RTRIM(d.SlipNo)) = @K
                      AND CONVERT(date, d.TransactionDate) = @D
                      AND d.DepositaAmount = @A;

                FETCH NEXT FROM grp INTO @K, @D, @A, @R, @N;
            END
            CLOSE grp; DEALLOCATE grp;

            /*
             * FNB with the adjustment, by primary key: exactly the rows step 3
             * claimed. The variance goes with the stamp — Amount and AdjustedBy
             * move by the row's share and Adjusted is set — in the same
             * statement, still guarded on the row being unreconciled at the
             * amount it was read at. Without the adjustment FNB is stamped in
             * the loop above, exactly as before 5 Oct 2026.
             */
            IF @ReconArea = 'FNB' AND @Adjust = 1
            BEGIN
                UPDATE d
                SET d.ReconBatchNoPumpIT = @No,
                    d.Amount     = d.Amount + m.Adjustment,
                    d.AdjustedBy = ISNULL(d.AdjustedBy, 0) + m.Adjustment,
                    d.Adjusted   = 1
                FROM [PumpIT].dbo.BRN_DailyBankingFNB d
                JOIN @Mops m
                  ON d.TillNo = m.TillNo AND d.ShiftNo = m.ShiftNo AND d.TransactionDate = m.TxnAt
                 AND d.BatchNo = m.BatchNoRaw AND d.MerchantNo = m.MerchantNo
                WHERE d.SSBranchId = @BranchId
                  AND d.ReconBatchNoPumpIT = 0
                  AND d.Amount = m.Amount;

                IF @@ROWCOUNT <> (SELECT COUNT(*) FROM @Mops)
                    THROW 51000, 'AGORA:MOPS_ROW_MOVED:One of the deposits changed while it was being matched. Reload and pick again. Nothing was written.', 1;
            END
        END
    END

    INSERT INTO agora.ReconStamp
        (BranchId, RunId, MatchId, BatchNo, TargetDatabase, TargetTable,
         TargetKeyJson, SetColumns, Detail, State, AppliedAt, AppliedBy, CreatedAt, CreatedBy)
    SELECT @BranchId, @RunId, m.Id, @No, 'PumpIT', m.SourceTable,
           ISNULL(m.SourceKeyJson, '{"BankStatementLineID":' + CONVERT(nvarchar(20), m.SourceId) + '}'),
           CASE WHEN m.Side = 'bank' THEN 'ReconBatchNo, ReconState'
                WHEN m.Adjustment IS NOT NULL THEN 'ReconBatchNoPumpIT, Amount, AdjustedBy, Adjusted'
                ELSE 'ReconBatchNoPumpIT' END,
           /* The adjustment in words, for the audit trail. */
           CASE WHEN m.Adjustment IS NOT NULL
                THEN CONCAT(N'Amount ', CONVERT(nvarchar(30), CONVERT(decimal(18,2), m.PriorAmount)),
                            N' → ', CONVERT(nvarchar(30), CONVERT(decimal(18,2), m.PriorAmount + m.Adjustment)),
                            N'; AdjustedBy ', CONVERT(nvarchar(30), CONVERT(decimal(18,2), ISNULL(m.PriorAdjustedBy, 0))),
                            N' → ', CONVERT(nvarchar(30), CONVERT(decimal(18,2), ISNULL(m.PriorAdjustedBy, 0) + m.Adjustment)),
                            N'; Adjusted ', ISNULL(CONVERT(nvarchar(1), m.PriorAdjusted), N'0'), N' → 1') END,
           CASE WHEN @StampMode = 'live' THEN 'applied' ELSE 'journal' END,
           CASE WHEN @StampMode = 'live' THEN @Now END,
           CASE WHEN @StampMode = 'live' THEN @UserId END,
           @Now, @UserId
    FROM agora.ReconMatch m
    WHERE m.BranchId = @BranchId AND m.BatchId = @BatchId;

    UPDATE agora.ReconRunLine SET ReconBatchNo = @No
    WHERE BranchId = @BranchId AND Id = @LineId;

    COMMIT TRANSACTION;

    /* On a cash bag match, one more sentence saying whose cash it was, so
       the split the clerk confirmed is answered on the screen they land on. */
    DECLARE @Accounted nvarchar(1000) = CASE
        WHEN @ReconArea <> 'CashBags' THEN N''
        WHEN (SELECT COUNT(*) FROM @Allocation) = 1
        THEN N' Accounted to ' + (SELECT TOP 1 COALESCE(CashierName + N' (' + CashierCode + N')', CashierCode,
                                                        N'no recorded cashier') FROM @Allocation) + N'.'
        /* The bags with no cashier recorded are a line, not a cashier: two
           lines of which one is theirs is one cashier (27 Sep 2026). With two
           or more lines there is always at least one cashier — the split has
           one no-cashier line at most. */
        ELSE N' Split between ' + CONVERT(nvarchar(10), (SELECT COUNT(*) FROM @Allocation WHERE CashierCode IS NOT NULL))
             + CASE WHEN (SELECT COUNT(*) FROM @Allocation WHERE CashierCode IS NOT NULL) = 1 THEN N' cashier' ELSE N' cashiers' END
             + CASE WHEN EXISTS (SELECT 1 FROM @Allocation WHERE CashierCode IS NULL)
                    THEN N' and the bags with no cashier recorded' ELSE N'' END
             + CASE WHEN EXISTS (SELECT 1 FROM @Allocation WHERE Basis = 'manual') THEN N', as changed by hand' ELSE N'' END
             + N'.' END
        /* The shorts step 5b raised, by number, so the clerk knows they exist
           and where they wait (27 Sep 2026, T040). */
        + CASE WHEN NOT EXISTS (SELECT 1 FROM @Shorts) THEN N''
               ELSE N' Staff short' + CASE WHEN (SELECT COUNT(*) FROM @Shorts) = 1 THEN N' ' ELSE N's ' END
                    + (SELECT STRING_AGG(CONVERT(nvarchar(12), StaffShortsNo), N', ') WITHIN GROUP (ORDER BY StaffShortsNo) FROM @Shorts)
                    + N' raised for approval under Cash.' END;

    /* On a forced FNB match, where the variance went. */
    IF @Adjust = 1
        SET @Accounted = CONCAT(N' The variance is written to the deposits in PumpIT (Amount and Adjusted By, ',
                                (SELECT COUNT(*) FROM @Mops), N' row',
                                CASE WHEN (SELECT COUNT(*) FROM @Mops) = 1 THEN N'' ELSE N's' END,
                                N'); the bank is unchanged.');

    /* The variance as the split states it: BANK LESS BAGS (bank less
       deposits, on the other four areas), so negative is money the bank did
       not receive, and the words say which way it went. Until 27 Sep 2026 the
       message carried @Diff, which is bags less bank — the preview's
       Diff_MOPS_BANK convention, still what ReconRunLine.DiffAmount holds —
       so a bank R10.99 short read "a variance of 10.99" directly above a split
       whose shares added up to -10.99. */
    DECLARE @Variance money = @BankTotal - @MopsTotal;
    DECLARE @Deposits nvarchar(10) = CASE WHEN @ReconArea = 'CashBags' THEN N'bags' ELSE N'deposits' END;

    /* The writer status row, in the shape ProcedureService::write() expects,
       with the run's id so the caller can send the person to it. */
    SELECT CONVERT(bit, 1) AS Ok,
           CASE WHEN @Forced = 1 THEN 'MATCHED_FORCED' ELSE 'MATCHED' END AS Code,
           CASE WHEN @Forced = 1
                THEN CASE WHEN @Basis IS NOT NULL THEN N'Matched by suggestion' ELSE N'Matched by hand' END
                     + N' as batch ' + CONVERT(nvarchar(20), @No)
                     /* -R10.99, the way App\Support\Format::r writes it on
                        every screen the clerk will see it on next. */
                     + N', forced with a variance of ' + CASE WHEN @Variance < 0 THEN N'-R' ELSE N'R' END
                     + CONVERT(nvarchar(30), ABS(@Variance))
                     + N', bank less ' + @Deposits + N': the bank is R' + CONVERT(nvarchar(30), ABS(@Variance))
                     + CASE WHEN @Variance < 0 THEN N' short of the ' ELSE N' over the ' END + @Deposits + N'.'
                WHEN @Basis IS NOT NULL
                THEN N'Matched by suggestion as batch ' + CONVERT(nvarchar(20), @No) + N'.'
                ELSE N'Matched by hand as batch ' + CONVERT(nvarchar(20), @No) + N'.' END + @Accounted AS Message,
           @RunId AS Id,
           @No AS BatchNo,
           /* Bank less bags, like the message and the split. Nothing reads it
              yet; it says the same thing as the sentence beside it. */
           @Variance AS Variance;
END
