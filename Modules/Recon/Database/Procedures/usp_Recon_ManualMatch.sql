/*
 * agora.usp_Recon_ManualMatch — a reconciliation a person made, not a rule.
 *
 * ** THIS PROCEDURE WRITES TO THE CUSTOMER'S ESTATE, in live mode, exactly as
 * ** agora.usp_Recon_Commit does and to the same three places:
 * **   PumpIT.dbo.SS_UniqueNumber              the batch counter, incremented
 * **   PumpIT.dbo.RCN_BankStatementLinesPumpIT ReconBatchNo, ReconState = 2
 * **   PumpIT.dbo.BRN_DailyBanking{Area}       ReconBatchNoPumpIT
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
 * a ReconStamp — the same shapes an automatic one writes. That is deliberate
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
 * Refusals: NOTHING_SELECTED · BANK_ROW_MOVED · MOPS_ROW_MOVED ·
 *           FORCE_REASON_REQUIRED · UNKNOWN_AREA · NO_COUNTER
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
    @UserId      int           = NULL
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

    DECLARE @WantMops TABLE (
        Seq int IDENTITY(1,1) PRIMARY KEY,
        SourceId bigint NULL, SourceKey nvarchar(100), SourceDate date, Amount money
    );

    INSERT INTO @WantMops (SourceId, SourceKey, SourceDate, Amount)
    SELECT TRY_CONVERT(bigint, JSON_VALUE(j.value, '$.id')),
           LTRIM(RTRIM(JSON_VALUE(j.value, '$.key'))),
           TRY_CONVERT(date, JSON_VALUE(j.value, '$.dt')),
           TRY_CONVERT(money, JSON_VALUE(j.value, '$.amt'))
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
        SourceId bigint NULL, SourceKey nvarchar(100), SourceDate date, Amount money, Rn int
    );

    IF @ReconArea = 'ABSA'
        INSERT INTO @Avail (SourceId, SourceKey, SourceDate, Amount, Rn)
        SELECT NULL, LTRIM(RTRIM(CONVERT(nvarchar(100), d.BatchNumber))), CONVERT(date, d.TransactionDate), d.TransactionAmount,
               ROW_NUMBER() OVER (PARTITION BY LTRIM(RTRIM(CONVERT(nvarchar(100), d.BatchNumber))), CONVERT(date, d.TransactionDate), d.TransactionAmount ORDER BY (SELECT NULL))
        FROM agora.vw_DailyBankingABSA d
        WHERE d.BranchId = @BranchId AND d.ReconBatchNoPumpIT = 0
          AND d.TransactionDate >= @FromDate AND d.TransactionDate <= @ToDate;

    ELSE IF @ReconArea = 'FNB'
        INSERT INTO @Avail (SourceId, SourceKey, SourceDate, Amount, Rn)
        SELECT NULL, LTRIM(RTRIM(d.BatchNo)), CONVERT(date, d.TransactionDate), d.Amount,
               ROW_NUMBER() OVER (PARTITION BY LTRIM(RTRIM(d.BatchNo)), CONVERT(date, d.TransactionDate), d.Amount ORDER BY (SELECT NULL))
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
        INSERT INTO @Avail (SourceId, SourceKey, SourceDate, Amount, Rn)
        SELECT d.DailyBankingCashBagID, LTRIM(RTRIM(d.CashBagNo)), CONVERT(date, d.TransactionDate), d.CashBagAmount, 1
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
        SourceId bigint NULL, SourceKey nvarchar(100), SourceDate date, Amount money
    );

    /* The two areas that HAVE an id are matched by it — exact, and it cannot
       reach a row the screen did not show. The other three are matched on the
       triple, with the nth want taking the nth available row. */
    INSERT INTO @Mops (SourceId, SourceKey, SourceDate, Amount)
    SELECT a.SourceId, a.SourceKey, a.SourceDate, a.Amount
    FROM @Avail a
    JOIN (
        SELECT w.SourceId, w.SourceKey, w.SourceDate, w.Amount,
               ROW_NUMBER() OVER (PARTITION BY w.SourceKey, w.SourceDate, w.Amount ORDER BY w.Seq) AS Rn
        FROM @WantMops w
    ) w
      ON ((w.SourceId IS NOT NULL AND a.SourceId = w.SourceId)
       OR (w.SourceId IS NULL AND a.SourceId IS NULL
           AND a.SourceKey = w.SourceKey AND a.SourceDate = w.SourceDate AND a.Amount = w.Amount AND a.Rn = w.Rn));

    IF (SELECT COUNT(*) FROM @Mops) <> (SELECT COUNT(*) FROM @WantMops)
        THROW 51000, 'AGORA:MOPS_ROW_MOVED:One of the deposits is no longer outstanding — something reconciled it since this screen was drawn. Reload and pick again. Nothing was written.', 1;

    /* ---- 4. Balance, and the reason a variance needs ---------------------- */

    DECLARE @BankTotal money = (SELECT SUM(Amount) FROM @Bank),
            @MopsTotal money = (SELECT SUM(Amount) FROM @Mops);

    DECLARE @Diff money = @MopsTotal - @BankTotal;

    IF @Diff <> 0 AND (@Reason IS NULL OR LTRIM(RTRIM(@Reason)) = '')
        THROW 51000, 'AGORA:FORCE_REASON_REQUIRED:The two sides do not balance. A forced match has to say why — it is the only record of the decision, and the variance is carried with the batch wherever it is shown.', 1;

    DECLARE @Forced bit = CASE WHEN @Diff <> 0 THEN 1 ELSE 0 END;

    /* ---- 5. Write it, as a run like any other ----------------------------- */

    DECLARE @Now datetime2(0) = SYSDATETIME();
    DECLARE @Outcome nvarchar(60) = CASE WHEN @Forced = 1 THEN 'Matched by hand - forced' ELSE 'Matched by hand' END;
    DECLARE @RunId bigint, @LineId bigint, @BatchId bigint, @No int;

    BEGIN TRANSACTION;

    INSERT INTO agora.ReconRun
        (BranchId, GroupRef, ReconArea, FromDate, ToDate, Status, StampMode, ProcedureName,
         ParamsJson, TotalRows, MatchedRows, MismatchRows, BankOnlyRows, DepositOnlyRows, OtherRows,
         BankTotal, MopsTotal, MatchedTotal, Note, CommittedAt, CommittedBy, CommittedRows,
         CommittedTotal, CreatedAt, CreatedBy)
    SELECT @BranchId, NEWID(), @ReconArea, @FromDate, CONVERT(date, @ToDate), 'committed', @StampMode,
           'agora.usp_Recon_ManualMatch',
           (SELECT @Reason AS reason, @Forced AS forced, @Diff AS diff FOR JSON PATH, WITHOUT_ARRAY_WRAPPER),
           1, CASE WHEN @Forced = 1 THEN 0 ELSE 1 END, @Forced, 0, 0, 0,
           @BankTotal, @MopsTotal, @BankTotal,
           CASE WHEN @Forced = 1 THEN 'Manual match (forced)' ELSE 'Manual match' END,
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
         SourceId, SourceKeyJson, SourceDate, Amount, PriorReconState, PriorBatchNo, CreatedAt, CreatedBy)
    SELECT @BranchId, @RunId, @LineId, @BatchId, @No, 'mops',
           CASE @ReconArea WHEN 'CashMachine' THEN 'BRN_DailyBankingDeposita'
                           ELSE 'BRN_DailyBanking' + @ReconArea END,
           m.SourceId,
           (SELECT m.SourceKey AS [key], m.SourceKey AS ref, m.SourceDate AS dt, m.Amount AS amt
            FOR JSON PATH, WITHOUT_ARRAY_WRAPPER),
           m.SourceDate, m.Amount, NULL, 0, @Now, @UserId
    FROM @Mops m;

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
            DECLARE @K nvarchar(100), @D date, @A money, @N int;

            DECLARE grp CURSOR LOCAL FAST_FORWARD FOR
                SELECT SourceKey, SourceDate, Amount, COUNT(*)
                FROM @Mops GROUP BY SourceKey, SourceDate, Amount;
            OPEN grp;
            FETCH NEXT FROM grp INTO @K, @D, @A, @N;

            WHILE @@FETCH_STATUS = 0
            BEGIN
                IF @ReconArea = 'ABSA'
                    UPDATE TOP (@N) d SET d.ReconBatchNoPumpIT = @No
                    FROM [PumpIT].dbo.BRN_DailyBankingABSA d
                    WHERE d.SSBranchId = @BranchId AND d.ReconBatchNoPumpIT = 0
                      AND LTRIM(RTRIM(CONVERT(nvarchar(100), d.BatchNumber))) = @K
                      AND CONVERT(date, d.TransactionDate) = @D
                      AND d.TransactionAmount = @A;

                ELSE IF @ReconArea = 'FNB'
                    UPDATE TOP (@N) d SET d.ReconBatchNoPumpIT = @No
                    FROM [PumpIT].dbo.BRN_DailyBankingFNB d
                    WHERE d.SSBranchId = @BranchId AND d.ReconBatchNoPumpIT = 0
                      AND LTRIM(RTRIM(d.BatchNo)) = @K
                      AND CONVERT(date, d.TransactionDate) = @D
                      AND d.Amount = @A;

                ELSE
                    UPDATE TOP (@N) d SET d.ReconBatchNoPumpIT = @No
                    FROM [PumpIT].dbo.BRN_DailyBankingDeposita d
                    WHERE d.SSBranchId = @BranchId AND d.ReconBatchNoPumpIT = 0
                      AND LTRIM(RTRIM(d.SlipNo)) = @K
                      AND CONVERT(date, d.TransactionDate) = @D
                      AND d.DepositaAmount = @A;

                FETCH NEXT FROM grp INTO @K, @D, @A, @N;
            END
            CLOSE grp; DEALLOCATE grp;
        END
    END

    INSERT INTO agora.ReconStamp
        (BranchId, RunId, MatchId, BatchNo, TargetDatabase, TargetTable,
         TargetKeyJson, SetColumns, State, AppliedAt, AppliedBy, CreatedAt, CreatedBy)
    SELECT @BranchId, @RunId, m.Id, @No, 'PumpIT', m.SourceTable,
           ISNULL(m.SourceKeyJson, '{"BankStatementLineID":' + CONVERT(nvarchar(20), m.SourceId) + '}'),
           CASE m.Side WHEN 'bank' THEN 'ReconBatchNo, ReconState' ELSE 'ReconBatchNoPumpIT' END,
           CASE WHEN @StampMode = 'live' THEN 'applied' ELSE 'journal' END,
           CASE WHEN @StampMode = 'live' THEN @Now END,
           CASE WHEN @StampMode = 'live' THEN @UserId END,
           @Now, @UserId
    FROM agora.ReconMatch m
    WHERE m.BranchId = @BranchId AND m.BatchId = @BatchId;

    UPDATE agora.ReconRunLine SET ReconBatchNo = @No
    WHERE BranchId = @BranchId AND Id = @LineId;

    COMMIT TRANSACTION;

    /* The writer status row, in the shape ProcedureService::write() expects,
       with the run's id so the caller can send the person to it. */
    SELECT CONVERT(bit, 1) AS Ok,
           CASE WHEN @Forced = 1 THEN 'MATCHED_FORCED' ELSE 'MATCHED' END AS Code,
           CASE WHEN @Forced = 1
                THEN 'Matched by hand as batch ' + CONVERT(nvarchar(20), @No)
                     + ', forced with a variance of ' + CONVERT(nvarchar(30), @Diff) + '.'
                ELSE 'Matched by hand as batch ' + CONVERT(nvarchar(20), @No) + '.' END AS Message,
           @RunId AS Id,
           @No AS BatchNo,
           @Diff AS Variance;
END
