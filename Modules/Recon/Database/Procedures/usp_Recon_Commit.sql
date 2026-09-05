/* ============================================================================
   agora.usp_Recon_Commit

   Execute a reconciliation: stamp the bank lines and deposit rows that a
   RECORDED run proposed, and write down exactly what was stamped so it can be
   undone.

   ** THIS IS THE ONLY PROCEDURE IN AGORA THAT WRITES TO THE CUSTOMER'S
   ** ESTATE. It writes three things and nothing else:
   **
   **   PumpIT.dbo.SS_UniqueNumber              the batch counter, incremented
   **   PumpIT.dbo.RCN_BankStatementLinesPumpIT ReconBatchNo, ReconState = 2
   **   PumpIT.dbo.BRN_DailyBanking{Area}       ReconBatchNoPumpIT
   **
   ** Read this header before changing a line of it.

   WHAT MAKES THIS DIFFERENT FROM THE EXECUTABLE
   ---------------------------------------------
   1. It acts on a RUN. The executable previews and executes from two separate
      walks of the data, so what it stamps is not necessarily what was on the
      screen when the operator decided. Here the candidates were written down
      at preview time and this reads them back.

   2. Only rows the run marked WouldReconcile = 1 AND the operator selected.
      That bit is 1 only when both sides exist and their totals are equal. The
      executable's `IF @CurrAmount <> @MOPSAmount` is UNKNOWN when there is no
      deposit at all and falls through to the MATCHED branch (finding 2,
      critical). There is no path from 0 to a stamp here.

   3. It RE-CHECKS every row before writing. A preview may be hours old and the
      executable may have run in between. A candidate is skipped — reported,
      not stamped over — when any of these is no longer true:
        · every bank line it names is still ReconState = 1
        · every deposit row it names is still ReconBatchNoPumpIT = 0
        · the two sides still balance to the cent
      Both sides must also still be non-empty. A batch whose deposit vanished
      between preview and execute is precisely the situation the defect
      created, and it must not reconcile.

   4. It records the PRIOR state of every row, so a reversal restores what was
      there rather than assuming zero.

   5. The batch number is allocated ATOMICALLY from the customer's own counter,
      SS_UniqueNumber SectionId 1 — the same one dbo.sp_GenerateReconBatchNo
      uses, so Agora's numbers and the executable's cannot collide. That
      procedure does SELECT-then-UPDATE with no lock, which is a race; the
      comment in their own source reads "2023/10/27 duplicates for some reason
      ????". A single UPDATE ... OUTPUT cannot hand the same number twice.

   6. Everything is one transaction. Under the executable a failure half way
      leaves some batches stamped and some not.

   @StampMode  'journal' records the decision and writes NOTHING to PumpIT —
               the reviewed worklist offered to ZP on 18 August 2026.
               'live'    applies it.
               The application passes config('recon.stamp_mode'); this
               procedure does not choose.

   Returns the writer status row (Ok, Code, Message, Id = rows committed),
   then a second set: one row per candidate with what happened to it.

   A ROW PAIRED ON A NEAR REFERENCE is stamped like any other, and its deposit
   side is addressed by ReconRunLine.MopsKeyRef rather than by KeyRef —
   `697440` where the bank read `69744`. Such a row was matched on an INFERENCE
   about the customer's configuration rather than on the configured rule, so it
   carries a flag the whole way through; what it does NOT get is a different
   standard of proof. It is re-checked here exactly like everything else.

   Refusals: RUN_NOT_FOUND · RUN_NOT_PREVIEWED · RUN_ALREADY_COMMITTED ·
             NOTHING_SELECTED · NO_COUNTER
   ============================================================================ */

CREATE OR ALTER PROCEDURE [agora].[usp_Recon_Commit]
    @RunId     bigint,
    @BranchId  int,
    @StampMode varchar(10) = 'journal',
    @UserId    int         = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE @Area nvarchar(20), @Status nvarchar(20), @From date, @To date, @Params nvarchar(max);

    SELECT @Area = r.ReconArea, @Status = r.Status, @From = r.FromDate,
           @To = r.ToDate, @Params = r.ParamsJson
    FROM agora.ReconRun r
    WHERE r.Id = @RunId AND r.BranchId = @BranchId;

    IF @Area IS NULL
        THROW 51000, 'AGORA:RUN_NOT_FOUND:That run does not exist on this branch.', 1;
    IF @Status = 'committed'
        THROW 51000, 'AGORA:RUN_ALREADY_COMMITTED:This run has already been executed. Preview again to reconcile anything further.', 1;
    IF @Status <> 'previewed'
        THROW 51000, 'AGORA:RUN_NOT_PREVIEWED:Only a completed preview can be executed.', 1;

    /* The readings the RUN used, not today's defaults. The customer edits
       BRN_AutoReconCriteria, so re-resolving with anything else could stamp
       rows the operator never saw. */
    DECLARE @RuleOrder      varchar(12) = ISNULL(JSON_VALUE(@Params, '$.RuleOrder'), 'specific'),
            @BatchKey       varchar(10) = ISNULL(JSON_VALUE(@Params, '$.BatchKey'), 'numeric'),
            @MatchMode      varchar(10) = ISNULL(JSON_VALUE(@Params, '$.MatchMode'), 'contains'),
            @MopsConvention varchar(10) = ISNULL(JSON_VALUE(@Params, '$.MopsConvention'), 'length'),
            @BankStart      int         = TRY_CONVERT(int, JSON_VALUE(@Params, '$.BankStartOverride')),
            @BankLen        int         = TRY_CONVERT(int, JSON_VALUE(@Params, '$.BankLenOverride'));

    DECLARE @ToInclusive datetime = DATEADD(second, -1, DATEADD(day, 1, CONVERT(datetime, @To)));

    /* ---- 1. Candidates ----------------------------------------------------- */

    DECLARE @Rows TABLE (
        RunLineId  bigint PRIMARY KEY,
        KeyRef     nvarchar(50), KeyRef2 nvarchar(50), BankLineId bigint,
        /* Null on an ordinary row. Set where a near-reference pairing was
           inferred, and then it — not KeyRef — is what the deposits are found
           and stamped by. Getting this wrong means promising a reconciliation
           and then updating nothing. */
        MopsRef    nvarchar(50),
        WindowFrom datetime, WindowTo datetime,
        BankTotal  money, MopsTotal money,
        BatchNo    int NULL, BatchId bigint NULL,
        Outcome    nvarchar(200) NULL
    );

    INSERT INTO @Rows (RunLineId, KeyRef, KeyRef2, MopsRef, BankLineId, WindowFrom, WindowTo, BankTotal, MopsTotal)
    SELECT l.Id, l.KeyRef, l.KeyRef2, ISNULL(l.MopsKeyRef, l.KeyRef), l.BankLineId, l.WindowFrom, l.WindowTo, l.BankTotal, l.MopsTotal
    FROM agora.ReconRunLine l
    WHERE l.BranchId = @BranchId AND l.RunId = @RunId
      AND l.WouldReconcile = 1 AND l.Selected = 1 AND l.CommitState = 'pending';

    IF NOT EXISTS (SELECT 1 FROM @Rows)
        THROW 51000, 'AGORA:NOTHING_SELECTED:Nothing on this run is both selected and reconcilable.', 1;

    /* ---- 2. Resolve both sides through the drill --------------------------- */

    DECLARE @Bank TABLE (
        RunLineId bigint, BankStatementLineID bigint, LineDate datetime,
        Description nvarchar(400), Amount money,
        ExtractedRef nvarchar(50), ExtractedRef2 nvarchar(50), Leg nvarchar(2),
        UsedProcessOrder int, UsedBankStart int, UsedBankLen int,
        ReconState int, ReconBatchNo int
    );
    DECLARE @Mops TABLE (
        RunLineId bigint, SourceRef nvarchar(50), SourceDate datetime,
        Amount money, Detail nvarchar(200),
        SourceId bigint, SourceKey nvarchar(200)
    );

    DECLARE @Drill TABLE (
        BankStatementLineID bigint, LineDate datetime, Description nvarchar(400),
        Amount money, ExtractedRef nvarchar(50), ExtractedRef2 nvarchar(50),
        Leg nvarchar(2), UsedProcessOrder int, UsedBankStart int, UsedBankLen int,
        ReconState int, ReconBatchNo int
    );
    DECLARE @DrillM TABLE (
        SourceRef nvarchar(50), SourceDate datetime, Amount money, Detail nvarchar(200),
        SourceId bigint, SourceKey nvarchar(200)
    );

    DECLARE @Id bigint, @K nvarchar(50), @K2 nvarchar(50), @MK nvarchar(50), @BL bigint,
            @WF datetime, @WT datetime;

    DECLARE cand CURSOR LOCAL FAST_FORWARD FOR
        SELECT RunLineId, KeyRef, KeyRef2, MopsRef, BankLineId, WindowFrom, WindowTo FROM @Rows;
    OPEN cand;
    FETCH NEXT FROM cand INTO @Id, @K, @K2, @MK, @BL, @WF, @WT;

    WHILE @@FETCH_STATUS = 0
    BEGIN
        DELETE FROM @Drill; DELETE FROM @DrillM;

        INSERT INTO @Drill
        EXEC agora.usp_Recon_DrillBank
            @ReconArea = @Area, @BranchId = @BranchId, @FromDate = @From, @ToDate = @ToInclusive,
            @KeyRef = @K, @KeyRef2 = @K2, @BankLineId = @BL, @WindowFrom = @WF, @WindowTo = @WT,
            @RuleOrder = @RuleOrder, @BatchKey = @BatchKey, @MatchMode = @MatchMode,
            @MopsConvention = @MopsConvention, @BankStartOverride = @BankStart, @BankLenOverride = @BankLen,
            /* Reconciled lines included, so a line something else has claimed
               since the preview is VISIBLE here and can be reported precisely
               rather than showing up as a balance that no longer adds. */
            @IncludeReconciled = 1;

        INSERT INTO @DrillM
        EXEC agora.usp_Recon_DrillMops
            @ReconArea = @Area, @BranchId = @BranchId, @FromDate = @From, @ToDate = @ToInclusive,
            @KeyRef = @K, @KeyRef2 = @K2, @BankLineId = @BL, @WindowFrom = @WF, @WindowTo = @WT,
            @RuleOrder = @RuleOrder, @BatchKey = @BatchKey, @MatchMode = @MatchMode,
            @MopsConvention = @MopsConvention, @BankStartOverride = @BankStart, @BankLenOverride = @BankLen,
            @MopsKeyRef = @MK;

        INSERT INTO @Bank SELECT @Id, * FROM @Drill;
        INSERT INTO @Mops SELECT @Id, * FROM @DrillM;

        FETCH NEXT FROM cand INTO @Id, @K, @K2, @MK, @BL, @WF, @WT;
    END
    CLOSE cand; DEALLOCATE cand;

    /* ---- 3. Re-check. A row that moved is skipped, never stamped over ------ */

    UPDATE r SET Outcome = 'The bank lines behind this are no longer on the statement in this period.'
    FROM @Rows r WHERE NOT EXISTS (SELECT 1 FROM @Bank b WHERE b.RunLineId = r.RunLineId);

    UPDATE r SET Outcome = 'The deposit rows behind this are no longer outstanding.'
    FROM @Rows r WHERE r.Outcome IS NULL
      AND NOT EXISTS (SELECT 1 FROM @Mops m WHERE m.RunLineId = r.RunLineId);

    UPDATE r SET Outcome = 'A bank line has been reconciled by something else since the preview.'
    FROM @Rows r WHERE r.Outcome IS NULL
      AND EXISTS (SELECT 1 FROM @Bank b WHERE b.RunLineId = r.RunLineId AND b.ReconState <> 1);

    UPDATE r SET Outcome = 'The two sides no longer balance — the data has changed since the preview.'
    FROM @Rows r WHERE r.Outcome IS NULL
      AND (SELECT SUM(b.Amount) FROM @Bank b WHERE b.RunLineId = r.RunLineId)
       <> (SELECT SUM(m.Amount) FROM @Mops m WHERE m.RunLineId = r.RunLineId);

    /* ---- 4. Stamp ---------------------------------------------------------- */

    DECLARE @Committed int = 0, @CommittedTotal money = 0;

    BEGIN TRANSACTION;

    DECLARE @No int, @BatchId bigint, @Now datetime2(0) = SYSDATETIME();

    DECLARE go CURSOR LOCAL FAST_FORWARD FOR
        SELECT RunLineId, KeyRef, KeyRef2, MopsRef FROM @Rows WHERE Outcome IS NULL;
    OPEN go;
    FETCH NEXT FROM go INTO @Id, @K, @K2, @MK;

    WHILE @@FETCH_STATUS = 0
    BEGIN
        /* Atomic. Read and increment in ONE statement, so two callers cannot
           be handed the same number — which is what the customer's own
           generator does, and what their comment says already went wrong. */
        DECLARE @Alloc TABLE (No int);
        DELETE FROM @Alloc;

        IF @StampMode = 'live'
        BEGIN
            UPDATE u WITH (UPDLOCK, ROWLOCK)
            SET u.NextUniqueNumber = u.NextUniqueNumber + 1
            OUTPUT deleted.NextUniqueNumber INTO @Alloc(No)
            FROM [PumpIT].dbo.SS_UniqueNumber u
            WHERE u.SectionId = 1;
        END
        ELSE
            /* Journal mode never touches the customer's counter. Negative, so
               a journalled number can never be mistaken for a real one. */
            INSERT INTO @Alloc (No) VALUES (-1 * (SELECT COUNT(*) + 1 FROM agora.ReconBatch WHERE BranchId = @BranchId));

        SELECT @No = No FROM @Alloc;

        IF @No IS NULL
        BEGIN
            ROLLBACK TRANSACTION;
            THROW 51000, 'AGORA:NO_COUNTER:SS_UniqueNumber SectionId 1 returned no batch number. Nothing was written.', 1;
        END

        INSERT INTO agora.ReconBatch
            (BranchId, RunId, BatchNo, ReconArea, KeyRef, KeyRef2,
             BankLineCount, MopsRowCount, BankTotal, MopsTotal, State, CreatedAt, CreatedBy)
        SELECT @BranchId, @RunId, @No, @Area, @K, @K2,
               (SELECT COUNT(*) FROM @Bank b WHERE b.RunLineId = @Id),
               (SELECT COUNT(*) FROM @Mops m WHERE m.RunLineId = @Id),
               (SELECT SUM(b.Amount) FROM @Bank b WHERE b.RunLineId = @Id),
               (SELECT SUM(m.Amount) FROM @Mops m WHERE m.RunLineId = @Id),
               'committed', @Now, @UserId;

        SET @BatchId = SCOPE_IDENTITY();

        /* Every bank line, with what it held before. */
        INSERT INTO agora.ReconMatch
            (BranchId, RunId, RunLineId, BatchId, BatchNo, Side, SourceTable,
             SourceId, SourceKeyJson, SourceDate, Amount, PriorReconState, PriorBatchNo, CreatedAt, CreatedBy)
        SELECT @BranchId, @RunId, @Id, @BatchId, @No, 'bank', 'RCN_BankStatementLinesPumpIT',
               b.BankStatementLineID, NULL, CONVERT(date, b.LineDate), b.Amount,
               l.ReconState, l.ReconBatchNo, @Now, @UserId
        FROM @Bank b
        JOIN agora.vw_BankStatementLine l ON l.BankStatementLineID = b.BankStatementLineID AND l.BranchId = @BranchId
        WHERE b.RunLineId = @Id;

        /* The deposit side has no single id on most of the family, so its key
           travels as JSON — enough to find the row again and no more. */
        INSERT INTO agora.ReconMatch
            (BranchId, RunId, RunLineId, BatchId, BatchNo, Side, SourceTable,
             SourceId, SourceKeyJson, SourceDate, Amount, PriorReconState, PriorBatchNo, CreatedAt, CreatedBy)
        SELECT @BranchId, @RunId, @Id, @BatchId, @No, 'mops',
               CASE @Area WHEN 'CashMachine' THEN 'BRN_DailyBankingDeposita'
                          ELSE 'BRN_DailyBanking' + @Area END,
               m.SourceId,
               (SELECT m.SourceKey AS [key], m.SourceRef AS ref, m.SourceDate AS dt, m.Amount AS amt
                FOR JSON PATH, WITHOUT_ARRAY_WRAPPER),
               CONVERT(date, m.SourceDate), m.Amount, NULL, 0, @Now, @UserId
        FROM @Mops m WHERE m.RunLineId = @Id;

        IF @StampMode = 'live'
        BEGIN
            /* Bank side. By id, so only the lines this run named are touched,
               and only while they are still unreconciled. */
            UPDATE l
            SET l.ReconBatchNo = @No, l.ReconState = 2
            FROM [PumpIT].dbo.RCN_BankStatementLinesPumpIT l
            WHERE l.SSBranchId = @BranchId
              AND l.ReconState = 1
              AND l.BankStatementLineID IN (SELECT b.BankStatementLineID FROM @Bank b WHERE b.RunLineId = @Id);

            /* Deposit side, one statement per area. Date-scoped and
               ReconBatchNoPumpIT = 0, so it can only reach rows the preview
               included — the same guard the executable uses. */
            IF @Area = 'ABSA'
                UPDATE d SET d.ReconBatchNoPumpIT = @No
                FROM [PumpIT].dbo.BRN_DailyBankingABSA d
                WHERE d.SSBranchId = @BranchId AND d.ReconBatchNoPumpIT = 0
                  AND d.TransactionDate >= @From AND d.TransactionDate <= @ToInclusive
                  AND TRY_CONVERT(bigint, CONVERT(nvarchar(50), d.BatchNumber)) = TRY_CONVERT(bigint, @MK)
                  AND (@K2 IS NULL OR TRY_CONVERT(int, d.MerchantNumber) = TRY_CONVERT(int, @K2));

            ELSE IF @Area = 'FNB'
                UPDATE d SET d.ReconBatchNoPumpIT = @No
                FROM [PumpIT].dbo.BRN_DailyBankingFNB d
                WHERE d.SSBranchId = @BranchId AND d.ReconBatchNoPumpIT = 0
                  AND d.TransactionDate >= @From AND d.TransactionDate <= @ToInclusive
                  AND CONVERT(nvarchar(50), TRY_CONVERT(bigint, LTRIM(RTRIM(d.BatchNo)))) = @MK
                  AND (@K2 IS NULL OR LTRIM(RTRIM(d.MerchantNo)) = @K2);

            ELSE IF @Area = 'CashMachine'
                UPDATE d SET d.ReconBatchNoPumpIT = @No
                FROM [PumpIT].dbo.BRN_DailyBankingDeposita d
                WHERE d.SSBranchId = @BranchId AND d.ReconBatchNoPumpIT = 0
                  AND d.TransactionDate >= @From AND d.TransactionDate <= @ToInclusive
                  AND LTRIM(RTRIM(d.SlipNo)) IN
                      (SELECT m.SourceKey FROM @Mops m WHERE m.RunLineId = @Id);

            ELSE IF @Area = 'CashBags'
                /* The one deposit table with a key of its own, so this is by
                   id and cannot reach a row the drill did not return. */
                UPDATE d SET d.ReconBatchNoPumpIT = @No
                FROM [PumpIT].dbo.BRN_DailyBankingCashBags d
                WHERE d.SSBranchId = @BranchId AND d.ReconBatchNoPumpIT = 0
                  AND d.DailyBankingCashBagID IN
                      (SELECT m.SourceId FROM @Mops m WHERE m.RunLineId = @Id AND m.SourceId IS NOT NULL);

            ELSE IF @Area = 'SmartATM'
                /* By id. The date guard the other areas carry is deliberately
                   absent here: SmartATM is scoped on the DEVICE timestamp and
                   this column holds the CASHUP date, so a guard written on it
                   would exclude the very rows the drill just returned. The id
                   is exact and came from that drill, which is a tighter
                   guarantee than a date range, not a looser one. */
                UPDATE d SET d.ReconBatchNoPumpIT = @No
                FROM [PumpIT].dbo.BRN_DailyBankingSmartATM d
                WHERE d.SSBranchId = @BranchId AND d.ReconBatchNoPumpIT = 0
                  AND d.DailyBankingSmartATMID IN
                      (SELECT m.SourceId FROM @Mops m WHERE m.RunLineId = @Id AND m.SourceId IS NOT NULL);
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

        UPDATE @Rows SET BatchNo = @No, BatchId = @BatchId, Outcome = NULL WHERE RunLineId = @Id;

        UPDATE agora.ReconRunLine
        SET CommitState = 'committed', ReconBatchNo = @No, UpdatedAt = @Now, UpdatedBy = @UserId
        WHERE BranchId = @BranchId AND Id = @Id;

        SET @Committed = @Committed + 1;
        SET @CommittedTotal = @CommittedTotal + (SELECT SUM(b.Amount) FROM @Bank b WHERE b.RunLineId = @Id);

        FETCH NEXT FROM go INTO @Id, @K, @K2, @MK;
    END
    CLOSE go; DEALLOCATE go;

    /* Everything that was skipped says so on the row, so the screen can show
       why without the operator guessing. */
    UPDATE l
    SET l.CommitState = 'blocked', l.BlockReason = r.Outcome, l.UpdatedAt = @Now, l.UpdatedBy = @UserId
    FROM agora.ReconRunLine l
    JOIN @Rows r ON r.RunLineId = l.Id
    WHERE l.BranchId = @BranchId AND r.Outcome IS NOT NULL;

    UPDATE agora.ReconRun
    SET Status = 'committed', StampMode = @StampMode,
        CommittedAt = @Now, CommittedBy = @UserId,
        CommittedRows = @Committed, CommittedTotal = @CommittedTotal,
        UpdatedAt = @Now, UpdatedBy = @UserId
    WHERE BranchId = @BranchId AND Id = @RunId;

    COMMIT TRANSACTION;

    SELECT CONVERT(bit, 1) AS Ok,
           CASE WHEN @StampMode = 'live' THEN 'COMMITTED' ELSE 'JOURNALLED' END AS Code,
           CONVERT(nvarchar(10), @Committed) + ' of '
             + CONVERT(nvarchar(10), (SELECT COUNT(*) FROM @Rows))
             + CASE WHEN @StampMode = 'live' THEN ' reconciled.' ELSE ' recorded (journal mode — nothing in PumpIT changed).' END AS Message,
           CONVERT(bigint, @Committed) AS Id;

    SELECT RunLineId, KeyRef, KeyRef2, BatchNo,
           CASE WHEN Outcome IS NULL THEN 'committed' ELSE 'blocked' END AS State,
           Outcome AS Reason
    FROM @Rows ORDER BY RunLineId;
END
