/*
 * agora.usp_Recon_Trace — one value, and everything it touches.
 *
 * Read by:  Recon -> Trace
 * Reads:    agora.ReconRun, ReconRunLine, ReconBatch, ReconMatch, ReconStamp,
 *           and the legacy estate through agora.vw_* — read-only, always.
 * Writes:   nothing.
 *
 * THE QUESTION THIS ANSWERS. "Where did batch 4412 come from." Until now that
 * meant opening six tables by hand in SSMS, and before Agora it could not be
 * answered at all — the PumpIT executable stamps ReconState and ReconBatchNo
 * and records nothing about why, which is exactly why question 3.7 of the
 * findings ("who stamped the historical reconciliations?") could only be
 * guessed at from 1,570 orphaned bank lines.
 *
 * ONE TERM, SEVEN ANSWERS. The person types a batch number, a bank reference,
 * a bag, a slip, a terminal or a run id — they do not know, and should not
 * have to say, which kind of thing it is. So every set below is asked the same
 * question and each says what it found:
 *
 *   1  runs           previews that proposed anything carrying this value
 *   2  proposals      the lines themselves, with their outcome
 *   3  batches        batch numbers Agora allocated
 *   4  matches        per side, with the PRIOR state of every row touched
 *   5  stamps         what was written, and whether it was applied or reversed
 *   6  bank lines     the legacy statement rows still carrying it
 *   7  deposits       the BRN_DailyBanking family, all five, unioned
 *
 * A set that finds nothing returns no rows, and the screen says which sets
 * were empty — "nothing in the ledger, three rows on the statement" is an
 * answer, and a common one: it is what an unreconciled line looks like.
 *
 * THE DATE WINDOW IS MANDATORY AND THAT IS DELIBERATE.
 * RCN_BankStatementLinesPumpIT is a table in a 249 GB database people are
 * trading on right now. An unbounded LIKE over its Description is a scan of
 * the whole thing, issued from a browser, by somebody who typed four
 * characters. Absent, the window defaults to ninety days back from today, and
 * the screen states the window it used.
 */
CREATE OR ALTER PROCEDURE [agora].[usp_Recon_Trace]
    @Term             NVARCHAR(100),
    @BranchIds        NVARCHAR(MAX) = NULL,
    @DateFrom         DATE          = NULL,
    @DateTo           DATE          = NULL,
    @AllowedBranchIds NVARCHAR(MAX) = NULL,
    @MaxRows          INT           = 200
AS
BEGIN
    SET NOCOUNT ON;

    SET @Term    = NULLIF(LTRIM(RTRIM(ISNULL(@Term, ''))), '');
    SET @DateTo   = ISNULL(@DateTo, CONVERT(date, GETDATE()));
    SET @DateFrom = ISNULL(@DateFrom, DATEADD(day, -90, @DateTo));
    SET @MaxRows  = CASE WHEN ISNULL(@MaxRows, 200) BETWEEN 1 AND 5000 THEN @MaxRows ELSE 200 END;

    /* A term of one or two characters matches most of the estate and answers
       nothing. Refused rather than run: an expensive query whose result is
       useless is the worst of both. */
    IF @Term IS NULL OR LEN(@Term) < 3
    BEGIN
        THROW 51000, 'AGORA:TRACE_TERM_TOO_SHORT:Type at least three characters. A shorter value matches most of the estate and answers nothing.', 1;
    END

    DECLARE @Like NVARCHAR(110) = '%' + @Term + '%';
    /* Only a value that IS a number can be a batch number. TRY_CONVERT rather
       than ISNUMERIC, which is true for '1e5', '£' and ',' — and a batch
       number of 100000 conjured out of '1e5' is the kind of match that
       destroys trust in a trace screen. */
    DECLARE @Number INT = TRY_CONVERT(int, @Term);

    /* The empty-string trap. STRING_SPLIT('', ',') returns ONE row holding an
       empty string and TRY_CONVERT(int, '') is 0, not NULL. */
    DECLARE @Branch  TABLE (BranchId int PRIMARY KEY);
    DECLARE @Allowed TABLE (BranchId int PRIMARY KEY);

    INSERT INTO @Branch (BranchId)
    SELECT DISTINCT TRY_CONVERT(int, LTRIM(RTRIM(s.value)))
    FROM STRING_SPLIT(ISNULL(@BranchIds, ''), ',') s
    WHERE LTRIM(RTRIM(s.value)) <> '' AND TRY_CONVERT(int, LTRIM(RTRIM(s.value))) IS NOT NULL;

    INSERT INTO @Allowed (BranchId)
    SELECT DISTINCT TRY_CONVERT(int, LTRIM(RTRIM(s.value)))
    FROM STRING_SPLIT(ISNULL(@AllowedBranchIds, ''), ',') s
    WHERE LTRIM(RTRIM(s.value)) <> '' AND TRY_CONVERT(int, LTRIM(RTRIM(s.value))) IS NOT NULL;

    DECLARE @AllBranches bit = CASE WHEN EXISTS (SELECT 1 FROM @Branch)  THEN 0 ELSE 1 END;
    /* An empty grant means every branch, the same reading BranchScope applies. */
    DECLARE @AllAllowed  bit = CASE WHEN EXISTS (SELECT 1 FROM @Allowed) THEN 0 ELSE 1 END;

    /* ---- The proposal lines that carry this value -------------------------
       Found first, because most of the other sets hang off them: a run is
       interesting because one of its lines mentioned the term, and a batch is
       interesting because it came from one of those lines. */
    DECLARE @Lines TABLE (Id bigint PRIMARY KEY, RunId bigint, BranchId int);

    INSERT INTO @Lines (Id, RunId, BranchId)
    SELECT TOP (@MaxRows) l.Id, l.RunId, l.BranchId
    FROM agora.ReconRunLine l
    JOIN agora.ReconRun r ON r.Id = l.RunId AND r.BranchId = l.BranchId
    WHERE (@AllBranches = 1 OR l.BranchId IN (SELECT BranchId FROM @Branch))
      AND (@AllAllowed  = 1 OR l.BranchId IN (SELECT BranchId FROM @Allowed))
      AND r.FromDate <= @DateTo AND r.ToDate >= @DateFrom
      AND (l.KeyRef        LIKE @Like
        OR l.KeyRef2       LIKE @Like
        OR l.MopsKeyRef    LIKE @Like
        OR l.BankNarrative LIKE @Like
        OR l.DeviceRefs    LIKE @Like
        OR (@Number IS NOT NULL AND (l.ReconBatchNo = @Number OR l.BankLineId = @Number)))
    ORDER BY l.Id DESC;

    /* ---- 1. The runs ------------------------------------------------------
       A run reached either because one of its lines mentions the term, or
       because the term IS the run's own number — "#244" is what a person
       pastes out of a message from a colleague. */
    SELECT TOP (@MaxRows)
        r.Id,
        r.BranchId,
        b.Name AS BranchName,
        r.ReconArea,
        r.Note,
        r.FromDate,
        r.ToDate,
        r.Status,
        r.StampMode,
        r.ProcedureName,
        r.TotalRows,
        r.MatchedRows,
        r.CommittedRows,
        r.CommittedAt,
        r.ReversedAt,
        r.ReversalReason,
        r.FailureCode,
        r.FailureMessage,
        r.CreatedAt,
        u.UserName AS RunBy,
        r.GroupRef
    FROM agora.ReconRun r
    LEFT JOIN agora.Branch b ON b.BranchId = r.BranchId AND b.DeletedAt IS NULL
    LEFT JOIN agora.[User] u ON u.Id = r.CreatedBy
    WHERE (@AllBranches = 1 OR r.BranchId IN (SELECT BranchId FROM @Branch))
      AND (@AllAllowed  = 1 OR r.BranchId IN (SELECT BranchId FROM @Allowed))
      AND (r.Id IN (SELECT RunId FROM @Lines)
        OR (@Number IS NOT NULL AND r.Id = @Number))
    ORDER BY r.Id DESC;

    /* ---- 2. The proposals themselves -------------------------------------- */
    SELECT TOP (@MaxRows)
        l.Id,
        l.RunId,
        l.BranchId,
        b.Name AS BranchName,
        l.ReconArea,
        /* Bracketed: LINENO is a reserved word in T-SQL (SET LINENO), and
           the parser rejects it even qualified. */
        l.[LineNo],
        l.KeyRef,
        l.KeyRef2,
        l.MopsKeyRef,
        l.BankDate,
        l.BankNarrative,
        l.BankLines,
        l.BankTotal,
        l.MopsTxns,
        l.MopsTotal,
        l.DiffAmount,
        l.Outcome,
        l.WouldReconcile,
        l.Selected,
        l.CommitState,
        l.ReconBatchNo,
        l.BlockReason,
        l.NearRefNote,
        l.UsedProcessOrder,
        l.UsedBankStart,
        l.UsedBankLen
    FROM agora.ReconRunLine l
    JOIN @Lines f ON f.Id = l.Id
    LEFT JOIN agora.Branch b ON b.BranchId = l.BranchId AND b.DeletedAt IS NULL
    ORDER BY l.RunId DESC, l.[LineNo];

    /* ---- 3. The batches Agora allocated ----------------------------------- */
    SELECT TOP (@MaxRows)
        bt.Id,
        bt.RunId,
        bt.BranchId,
        b.Name AS BranchName,
        bt.BatchNo,
        bt.ReconArea,
        bt.KeyRef,
        bt.KeyRef2,
        bt.BankLineCount,
        bt.MopsRowCount,
        bt.BankTotal,
        bt.MopsTotal,
        bt.State,
        bt.ReversedAt,
        bt.ReversalReason,
        bt.CreatedAt
    FROM agora.ReconBatch bt
    LEFT JOIN agora.Branch b ON b.BranchId = bt.BranchId AND b.DeletedAt IS NULL
    WHERE (@AllBranches = 1 OR bt.BranchId IN (SELECT BranchId FROM @Branch))
      AND (@AllAllowed  = 1 OR bt.BranchId IN (SELECT BranchId FROM @Allowed))
      AND (bt.KeyRef LIKE @Like
        OR bt.KeyRef2 LIKE @Like
        OR (@Number IS NOT NULL AND bt.BatchNo = @Number)
        OR bt.RunId IN (SELECT RunId FROM @Lines))
    ORDER BY bt.BatchNo DESC;

    /* ---- 4. The rows that were touched, per side --------------------------
       The prior state travels with them. This is the set that makes a reversal
       possible and the one the executable has no equivalent of: it says what
       every row held BEFORE Agora wrote to it, so "put it back" means put back
       that, not zero. */
    SELECT TOP (@MaxRows)
        m.Id,
        m.RunId,
        m.RunLineId,
        m.BranchId,
        b.Name AS BranchName,
        m.BatchNo,
        m.Side,
        m.SourceTable,
        m.SourceId,
        m.SourceKeyJson,
        m.SourceDate,
        m.Amount,
        m.PriorReconState,
        m.PriorBatchNo,
        m.CreatedAt
    FROM agora.ReconMatch m
    LEFT JOIN agora.Branch b ON b.BranchId = m.BranchId AND b.DeletedAt IS NULL
    WHERE (@AllBranches = 1 OR m.BranchId IN (SELECT BranchId FROM @Branch))
      AND (@AllAllowed  = 1 OR m.BranchId IN (SELECT BranchId FROM @Allowed))
      AND (m.RunLineId IN (SELECT Id FROM @Lines)
        OR (@Number IS NOT NULL AND (m.BatchNo = @Number OR m.SourceId = @Number))
        OR m.SourceKeyJson LIKE @Like)
    ORDER BY m.BatchNo DESC, m.Side, m.Id;

    /* ---- 5. What was written to the estate --------------------------------- */
    SELECT TOP (@MaxRows)
        s.Id,
        s.RunId,
        s.MatchId,
        s.BranchId,
        s.BatchNo,
        s.TargetDatabase,
        s.TargetTable,
        s.TargetKeyJson,
        s.SetColumns,
        s.State,
        s.AppliedAt,
        s.RowsAffected,
        s.FailureMessage
    FROM agora.ReconStamp s
    WHERE (@AllBranches = 1 OR s.BranchId IN (SELECT BranchId FROM @Branch))
      AND (@AllAllowed  = 1 OR s.BranchId IN (SELECT BranchId FROM @Allowed))
      AND ((@Number IS NOT NULL AND s.BatchNo = @Number)
        OR s.MatchId IN (
            SELECT m.Id FROM agora.ReconMatch m WHERE m.RunLineId IN (SELECT Id FROM @Lines)
        ))
    ORDER BY s.BatchNo DESC, s.Id;

    /* ---- 6. The legacy statement, as it stands right now -------------------
       Bounded by the date window, which is why the window is not optional. The
       view carries no ReconState filter of its own, so a line that has since
       been stamped still comes back — which is the whole point here. */
    SELECT TOP (@MaxRows)
        l.BankStatementLineID,
        l.BranchId,
        b.Name AS BranchName,
        l.LineDate,
        l.Description,
        l.Amount,
        l.Type,
        l.IDState,
        l.ReconState,
        l.ReconBatchNo
    FROM agora.vw_BankStatementLine l
    LEFT JOIN agora.Branch b ON b.BranchId = l.BranchId AND b.DeletedAt IS NULL
    WHERE (@AllBranches = 1 OR l.BranchId IN (SELECT BranchId FROM @Branch))
      AND (@AllAllowed  = 1 OR l.BranchId IN (SELECT BranchId FROM @Allowed))
      AND l.LineDate >= @DateFrom
      AND l.LineDate <  DATEADD(day, 1, @DateTo)
      AND (l.Description LIKE @Like
        OR (@Number IS NOT NULL AND (l.ReconBatchNo = @Number OR l.BankStatementLineID = @Number)))
    ORDER BY l.LineDate DESC, l.BankStatementLineID DESC;

    /* ---- 7. The deposit side, all five families --------------------------- */
    SELECT TOP (@MaxRows) *
    FROM (
        SELECT 'ABSA' AS Area, a.BranchId, a.TransactionDate AS TxDate,
               CONVERT(nvarchar(50), a.BatchNumber) AS Reference,
               CONVERT(nvarchar(50), a.MerchantNumber) AS Reference2,
               CONVERT(money, a.TransactionAmount) AS Amount,
               a.ReconBatchNoPumpIT
        FROM agora.vw_DailyBankingABSA a
        WHERE a.TransactionDate >= @DateFrom AND a.TransactionDate < DATEADD(day, 1, @DateTo)
          AND (CONVERT(nvarchar(50), a.BatchNumber) LIKE @Like
            OR CONVERT(nvarchar(50), a.MerchantNumber) LIKE @Like
            OR (@Number IS NOT NULL AND a.ReconBatchNoPumpIT = @Number))

        UNION ALL SELECT 'FNB', f.BranchId, f.TransactionDate,
               CONVERT(nvarchar(50), f.BatchNo), CONVERT(nvarchar(50), f.MerchantNo),
               CONVERT(money, f.Amount), f.ReconBatchNoPumpIT
        FROM agora.vw_DailyBankingFNB f
        WHERE f.TransactionDate >= @DateFrom AND f.TransactionDate < DATEADD(day, 1, @DateTo)
          AND (CONVERT(nvarchar(50), f.BatchNo) LIKE @Like
            OR CONVERT(nvarchar(50), f.MerchantNo) LIKE @Like
            OR (@Number IS NOT NULL AND f.ReconBatchNoPumpIT = @Number))

        UNION ALL SELECT 'CashBags', c.BranchId, c.TransactionDate,
               CONVERT(nvarchar(50), c.CashBagNo), NULL,
               CONVERT(money, c.CashBagAmount), c.ReconBatchNoPumpIT
        FROM agora.vw_DailyBankingCashBags c
        WHERE c.TransactionDate >= @DateFrom AND c.TransactionDate < DATEADD(day, 1, @DateTo)
          AND (CONVERT(nvarchar(50), c.CashBagNo) LIKE @Like
            OR (@Number IS NOT NULL AND c.ReconBatchNoPumpIT = @Number))

        UNION ALL SELECT 'CashMachine', d.BranchId, d.TransactionDate,
               CONVERT(nvarchar(50), d.SlipNo), NULL,
               CONVERT(money, d.DepositaAmount), d.ReconBatchNoPumpIT
        FROM agora.vw_DailyBankingDeposita d
        WHERE d.TransactionDate >= @DateFrom AND d.TransactionDate < DATEADD(day, 1, @DateTo)
          AND (CONVERT(nvarchar(50), d.SlipNo) LIKE @Like
            OR (@Number IS NOT NULL AND d.ReconBatchNoPumpIT = @Number))

        UNION ALL SELECT 'SmartATM', s.BranchId, CONVERT(date, s.DepositDateTime),
               CONVERT(nvarchar(50), s.TerminalId), CONVERT(nvarchar(50), s.TraceNo),
               CONVERT(money, s.Deposited), s.ReconBatchNoPumpIT
        FROM agora.vw_DailyBankingSmartATM s
        WHERE s.DepositDateTime >= @DateFrom AND s.DepositDateTime < DATEADD(day, 1, @DateTo)
          AND (CONVERT(nvarchar(50), s.TerminalId) LIKE @Like
            OR CONVERT(nvarchar(50), s.TraceNo) LIKE @Like
            OR CONVERT(nvarchar(50), s.UniqueNo) LIKE @Like
            OR (@Number IS NOT NULL AND s.ReconBatchNoPumpIT = @Number))
    ) deposits
    WHERE (@AllBranches = 1 OR deposits.BranchId IN (SELECT BranchId FROM @Branch))
      AND (@AllAllowed  = 1 OR deposits.BranchId IN (SELECT BranchId FROM @Allowed))
    ORDER BY deposits.TxDate DESC, deposits.Reference;
END
