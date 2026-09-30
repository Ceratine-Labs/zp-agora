/* ============================================================================
   agora.usp_Cash_SaveTillBalance

   Capture a till balance: move an amount of one cashup's variance onto another
   cashup at the same site — PumpIT's own till-balance capture, ported.

   ** ONE OF THE PROCEDURES IN AGORA THAT WRITE TO THE CUSTOMER'S ESTATE.
   ** Approved by Ryan on 27 Sep 2026 (PM decisions 01a0e22a-97be /
   ** 01a0e22a-9aa9: till balance captured in Agora writes the legacy
   ** BRN_TillBalance tables). What it writes, and nothing else:
   **
   **   SS_UniqueNumber   UPDATE NextUniqueNumber (+1), SectionId 3 only — the
   **                     till balance counter, as dbo.sp_GenerateTillBalanceNo
   **   BRN_TillBalance   INSERT two rows, one per cashup:
   **                       SSBranchId, TillNo, ShiftNo, TransactionDate,
   **                       LogFile, TransactionAmount, OriginalEmployeeAmount,
   **                       EmployeeCode, EmployeeAmount, CreatedByUserId,
   **                       TillBalanceNo, CreateDateTime
   **                     (Approved, ApprovedByUserId, IsDeclined and
   **                     DeclinedByUserId take the table's 0 defaults, as they
   **                     do under dbo.sp_InsertTillBalance)
   **
   ** Both, and agora.TillBalanceEvent with exactly what was written, in ONE
   ** transaction. Read this header before changing a line of it.

   THE PORT
     dbo.sp_GenerateTillBalanceNo   the number is SS_UniqueNumber.NextUniqueNumber
                                    for SectionId 3, and the counter moves on by
                                    one. The legacy reads and then updates with
                                    no lock; here it is one UPDATE ... OUTPUT
                                    under UPDLOCK, HOLDLOCK, inside the capture's
                                    transaction, so a number is never handed out
                                    twice and never burned by a capture that
                                    fails (PumpIT burned 9037 and 9772 in 2026).
     dbo.sp_InsertTillBalance       called twice by the PumpIT client, once per
                                    cashup. Here both rows go in together. Where
                                    the legacy silently skips a row whose key
                                    already exists, this refuses — the number is
                                    new, so an existing key means the counter is
                                    behind the table, and that wants a person.
     dbo.sp_Insert_TillBalanceTransactions   NOT PORTED — see below.

   THE TWO ROWS, as the PumpIT client writes them (every row of the last week
   on the customer's instance, 27 Sep 2026, agrees):
     the FROM cashup   TransactionAmount = -@Amount   (it gives the amount up)
     the TO cashup     TransactionAmount = +@Amount   (it takes it)
     each row          LogFile                = the LOGFILE of the one Z-read
                                                allocated to the cashup
                       EmployeeCode           = the cashup's person
                       OriginalEmployeeAmount = the cashup's variance now, as
                                                the Daily Banking grid computes
                                                it (agora.vw_CashupTotals)
                       EmployeeAmount         = OriginalEmployeeAmount
                                                + TransactionAmount — what the
                                                variance becomes once posted
                       CreatedByUserId        = the person's PumpIT user id
                                                (agora.User.LegacyUserId), NULL
                                                for somebody PumpIT never knew;
                                                Agora's own user is on the event
                       TransactionDate        = the cashup's day (midnight, as
                                                the legacy stores it)
                       CreateDateTime         = GETDATE() — PumpIT's clock

   WHAT IT DOES NOT DO: APPROVE OR POST. In PumpIT an approver passes the till
   balance and dbo.sp_Insert_TillBalanceTransactions posts each row: a
   system-generated cash purchase request (INSERT BRN_Transaction and
   BRN_TransactionLine, 'Till Balance Expense' supplier, 'Till Balance Control'
   expense, the number in QuoteNo), a line on the cashup's EXPENSES leg
   (INSERT BRN_DailyBankingExpenses, amount = TransactionAmount), and the
   header's totals (dbo.sp_UpdateDailyBankingTotals). Those tables were not
   named in Ryan's go-ahead for till balance, so a capture here stays PENDING
   (Approved 0, IsDeclined 0) — exactly the state PumpIT's own approval list
   (dbo.sp_SelectUnApprovedTillBalanceByApproverUser) picks up, approves and
   posts from. Nothing here moves a cashup's figures.

   THE RULES, in order:
     · @WriteMode must be 'live' (cash.tillbalance.write_mode, a literal); the
       application does not call this when it is 'off' — this is the second
       lock.
     · Two different cashups at the same site, and an amount above nil in
       rands and cents.
     · No enabled trigger on BRN_TillBalance or SS_UniqueNumber.
     · Each cashup must exist in PumpIT, with exactly one Z-read allocated to
       its till and shift that day (its LOGFILE is part of the row's key).
     · Neither cashup may be closed in Agora — a closed cashup is not changed
       until a manager reopens it, and a till balance exists to change one.
     · The same amount between the same two cashups, the same way round, may
       not be waiting for approval already — a second press is refused, naming
       the number it would duplicate.
     · Nothing is ever deleted. A till balance captured wrongly is declined by
       the approver in PumpIT and captured again.

   TWO CLOCKS: Agora's rows are stamped SYSUTCDATETIME(); PumpIT's
   CreateDateTime keeps GETDATE(), which is what the customer's own screens read.

   Returns the writer status row (Ok, Code, Message, Id = the till balance
   number) with TillBalanceNo, Amount, FromVarianceBefore, FromVarianceAfter,
   ToVarianceBefore, ToVarianceAfter and SaveRef.

   Refusals: CASH_TILLBAL_USER · CASH_WRITES_OFF · CASH_TILLBAL_KEY ·
             CASH_TILLBAL_SITE · CASH_TILLBAL_AMOUNT · CASH_TILLBAL_SAME ·
             CASH_PUMPIT_TRIGGER · CASH_TILLBAL_NO_CASHUP · CASH_TILLBAL_ZREAD ·
             CASH_CASHUP_CLOSED · CASH_TILLBAL_DUPLICATE · CASH_TILLBAL_COUNTER ·
             CASH_TILLBAL_NUMBER_TAKEN

     EXEC agora.usp_Cash_SaveTillBalance @BranchId = 999,
          @FromDate = '2026-06-12', @FromTillNo = 51, @FromShiftNo = 1,
          @ToDate   = '2026-06-12', @ToTillNo   = 51, @ToShiftNo   = 3,
          @Amount = 63.67, @WriteMode = 'live', @UserId = 1;
   ============================================================================ */
CREATE OR ALTER PROCEDURE [agora].[usp_Cash_SaveTillBalance]
    @BranchId     INT,
    @FromDate     DATE,
    @FromTillNo   INT,
    @FromShiftNo  INT,
    @ToDate       DATE,
    @ToTillNo     INT,
    @ToShiftNo    INT,
    @Amount       MONEY,
    @Note         NVARCHAR(400) = NULL,
    @WriteMode    NVARCHAR(10)  = 'off',
    @UserId       INT
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    SET @Note = NULLIF(LTRIM(RTRIM(@Note)), '');

    IF @UserId IS NULL
        THROW 51000, 'AGORA:CASH_TILLBAL_USER:A till balance is captured by a person, and none was given.', 1;
    IF ISNULL(@WriteMode, 'off') <> 'live'
        THROW 51000, 'AGORA:CASH_WRITES_OFF:Capturing till balances to PumpIT is switched off. Nothing was written.', 1;
    IF @BranchId IS NULL OR @FromDate IS NULL OR @FromTillNo IS NULL OR @FromShiftNo IS NULL
       OR @ToDate IS NULL OR @ToTillNo IS NULL OR @ToShiftNo IS NULL
        THROW 51000, 'AGORA:CASH_TILLBAL_KEY:A till balance moves an amount from one cashup to another. Name both — site, day, till and shift.', 1;
    IF NOT EXISTS (SELECT 1 FROM agora.Branch WHERE BranchId = @BranchId)
        THROW 51000, 'AGORA:CASH_TILLBAL_SITE:That site is not in Agora''s branch list.', 1;
    IF @Amount IS NULL OR @Amount <= 0 OR ROUND(@Amount, 2) <> @Amount
        THROW 51000, 'AGORA:CASH_TILLBAL_AMOUNT:Give the amount to move, above nil, in rands and cents.', 1;
    IF @FromDate = @ToDate AND @FromTillNo = @ToTillNo AND @FromShiftNo = @ToShiftNo
        THROW 51000, 'AGORA:CASH_TILLBAL_SAME:A till balance moves an amount between two different cashups. Both sides name the same one.', 1;

    IF EXISTS (SELECT 1 FROM [PumpIT].sys.triggers t
               WHERE t.parent_id IN (OBJECT_ID('[PumpIT].dbo.BRN_TillBalance'), OBJECT_ID('[PumpIT].dbo.SS_UniqueNumber'))
                 AND t.is_disabled = 0)
        THROW 51000, 'AGORA:CASH_PUMPIT_TRIGGER:A trigger is enabled on a PumpIT table this capture writes (BRN_TillBalance or SS_UniqueNumber). Agora does not write through one. Nothing was written.', 1;

    DECLARE @FromAt DATETIME = @FromDate, @ToAt DATETIME = @ToDate;
    DECLARE @Now DATETIME2(0) = SYSUTCDATETIME();
    DECLARE @Ref UNIQUEIDENTIFIER = NEWID();
    DECLARE @Msg NVARCHAR(400);

    /* ---- 1. The two cashups, as the Daily Banking grid reads them ------------ */
    DECLARE @Side TABLE (
        Side          VARCHAR(4)    PRIMARY KEY,
        TxDate        DATE          NOT NULL,
        TillNo        INT           NOT NULL,
        ShiftNo       INT           NOT NULL,
        TxAt          DATETIME      NOT NULL,
        TxAmount      MONEY         NOT NULL,
        HasCashup     BIT           NOT NULL DEFAULT 0,
        EmployeeCode  NVARCHAR(11)  NULL,
        Variance      MONEY         NULL,
        Zreads        INT           NULL,
        LogFile       NVARCHAR(10)  NULL,
        Label         NVARCHAR(120) NULL
    );

    INSERT INTO @Side (Side, TxDate, TillNo, ShiftNo, TxAt, TxAmount, Label)
    VALUES ('from', @FromDate, @FromTillNo, @FromShiftNo, @FromAt, -@Amount,
            CONCAT('till ', @FromTillNo, ', shift ', @FromShiftNo, ' on ', CONVERT(CHAR(10), @FromDate, 23))),
           ('to',   @ToDate,   @ToTillNo,   @ToShiftNo,   @ToAt,    @Amount,
            CONCAT('till ', @ToTillNo, ', shift ', @ToShiftNo, ' on ', CONVERT(CHAR(10), @ToDate, 23)));

    UPDATE s
       SET HasCashup = 1, EmployeeCode = LEFT(t.EmployeeCode, 11), Variance = t.Variance
    FROM @Side s
    JOIN agora.vw_CashupTotals t
      ON t.BranchId = @BranchId AND t.TillNo = s.TillNo AND t.ShiftNo = s.ShiftNo AND t.TransactionAt = s.TxAt;

    UPDATE s
       SET Zreads = z.n, LogFile = z.LogFile
    FROM @Side s
    CROSS APPLY (SELECT COUNT(*) AS n, MAX(x.LogFile) AS LogFile
                 FROM agora.vw_CashZread x
                 WHERE x.BranchId = @BranchId AND x.ZReadAt = s.TxAt AND x.TillNo = s.TillNo AND x.ShiftNo = s.ShiftNo) z;

    IF EXISTS (SELECT 1 FROM @Side WHERE HasCashup = 0)
    BEGIN
        SET @Msg = (SELECT TOP 1 CONCAT('AGORA:CASH_TILLBAL_NO_CASHUP:PumpIT has no cashup for ', Label,
                                        ', so there is no variance to move. Nothing was written.')
                    FROM @Side WHERE HasCashup = 0 ORDER BY Side);
        THROW 51000, @Msg, 1;
    END

    IF EXISTS (SELECT 1 FROM @Side WHERE ISNULL(Zreads, 0) <> 1 OR ISNULL(LogFile, '') = '' OR LEN(LogFile) > 10)
    BEGIN
        SET @Msg = (SELECT TOP 1 CONCAT('AGORA:CASH_TILLBAL_ZREAD:The cashup for ', Label, ' has ',
                                        CASE WHEN ISNULL(Zreads, 0) = 0 THEN 'no Z-read allocated'
                                             WHEN Zreads > 1 THEN CONCAT(Zreads, ' Z-reads allocated')
                                             ELSE 'a Z-read with no usable log file' END,
                                        ' — a till balance carries the log file of the one Z-read behind it. Correct the allocation first.')
                    FROM @Side WHERE ISNULL(Zreads, 0) <> 1 OR ISNULL(LogFile, '') = '' OR LEN(LogFile) > 10 ORDER BY Side);
        THROW 51000, @Msg, 1;
    END

    DECLARE @ErpUserId INT = (SELECT TOP 1 u.LegacyUserId FROM agora.[User] u WHERE u.Id = @UserId);
    DECLARE @No INT;
    DECLARE @Alloc TABLE (Prior INT, New INT);
    DECLARE @FromBefore MONEY, @FromAfter MONEY, @ToBefore MONEY, @ToAfter MONEY;

    BEGIN TRY
        BEGIN TRANSACTION;

        /* ---- 2. Neither cashup closed in Agora (locked, so a close cannot
                   land between the check and the write) ----------------------- */
        IF EXISTS (SELECT 1
                   FROM agora.Cashup c WITH (UPDLOCK, HOLDLOCK)
                   JOIN @Side s ON c.BranchId = @BranchId AND c.TransactionDate = s.TxDate
                               AND c.TillNo = s.TillNo AND c.ShiftNo = s.ShiftNo
                   WHERE c.[Status] = 'closed')
        BEGIN
            SET @Msg = (SELECT TOP 1 CONCAT('AGORA:CASH_CASHUP_CLOSED:The cashup for ', s.Label,
                                            ' has been closed by a manager and is not changed until it is reopened.')
                        FROM agora.Cashup c
                        JOIN @Side s ON c.BranchId = @BranchId AND c.TransactionDate = s.TxDate
                                    AND c.TillNo = s.TillNo AND c.ShiftNo = s.ShiftNo
                        WHERE c.[Status] = 'closed' ORDER BY s.Side);
            THROW 51000, @Msg, 1;
        END

        /* ---- 3. Not already waiting for approval, the same way round -------- */
        DECLARE @Twin INT = (
            SELECT TOP 1 f.TillBalanceNo
            FROM [PumpIT].dbo.BRN_TillBalance f WITH (UPDLOCK, HOLDLOCK)
            JOIN [PumpIT].dbo.BRN_TillBalance t WITH (UPDLOCK, HOLDLOCK)
              ON t.SSBranchId = f.SSBranchId AND t.TillBalanceNo = f.TillBalanceNo
            WHERE f.SSBranchId = @BranchId
              AND f.TillNo = @FromTillNo AND f.ShiftNo = @FromShiftNo AND f.TransactionDate = @FromAt
              AND f.TransactionAmount = -@Amount
              AND t.TillNo = @ToTillNo AND t.ShiftNo = @ToShiftNo AND t.TransactionDate = @ToAt
              AND t.TransactionAmount = @Amount
              AND f.Approved = 0 AND f.IsDeclined = 0 AND t.Approved = 0 AND t.IsDeclined = 0
            ORDER BY f.TillBalanceNo DESC);

        IF @Twin IS NOT NULL
        BEGIN
            SET @Msg = CONCAT('AGORA:CASH_TILLBAL_DUPLICATE:Till balance ', @Twin, ' already moves R', FORMAT(@Amount, 'N2'),
                              ' this way between these two cashups and is waiting for approval in PumpIT. Nothing was written.');
            THROW 51000, @Msg, 1;
        END

        /* ---- 4. The number: dbo.sp_GenerateTillBalanceNo, under lock -------- */
        UPDATE u
           SET u.NextUniqueNumber = u.NextUniqueNumber + 1
        OUTPUT deleted.NextUniqueNumber, inserted.NextUniqueNumber INTO @Alloc (Prior, New)
        FROM [PumpIT].dbo.SS_UniqueNumber u WITH (UPDLOCK, HOLDLOCK)
        WHERE u.SectionId = 3;

        IF (SELECT COUNT(*) FROM @Alloc) <> 1
            THROW 51000, 'AGORA:CASH_TILLBAL_COUNTER:PumpIT''s till balance counter (SS_UniqueNumber, section 3) is missing or held twice, so no number can be given. Nothing was written.', 1;

        SELECT @No = Prior FROM @Alloc;

        IF EXISTS (SELECT 1 FROM [PumpIT].dbo.BRN_TillBalance WITH (UPDLOCK, HOLDLOCK)
                   WHERE SSBranchId = @BranchId AND TillBalanceNo = @No)
        BEGIN
            SET @Msg = CONCAT('AGORA:CASH_TILLBAL_NUMBER_TAKEN:PumpIT''s till balance counter gave ', @No,
                              ', which this site already uses — the counter is behind the table. Nothing was written.');
            THROW 51000, @Msg, 1;
        END

        /* ---- 5. The two rows: dbo.sp_InsertTillBalance, once per cashup ----- */
        -- INSERT SSBranchId, TillNo, ShiftNo, TransactionDate, LogFile, TransactionAmount,
        --        OriginalEmployeeAmount, EmployeeCode, EmployeeAmount, CreatedByUserId,
        --        TillBalanceNo, CreateDateTime
        INSERT INTO [PumpIT].dbo.BRN_TillBalance
            (SSBranchId, TillNo, ShiftNo, TransactionDate, LogFile, TransactionAmount,
             OriginalEmployeeAmount, EmployeeCode, EmployeeAmount, CreatedByUserId, TillBalanceNo, CreateDateTime)
        SELECT @BranchId, s.TillNo, s.ShiftNo, s.TxAt, s.LogFile, s.TxAmount,
               s.Variance, s.EmployeeCode, s.Variance + s.TxAmount, @ErpUserId, @No, GETDATE()
        FROM @Side s
        ORDER BY s.Side;   -- 'from' before 'to', as the PumpIT client sends them

        IF @@ROWCOUNT <> 2
            THROW 51000, 'AGORA:CASH_TILLBAL_KEY:The two rows of the till balance were not both written. Nothing was written.', 1;

        SELECT @FromBefore = Variance, @FromAfter = Variance + TxAmount FROM @Side WHERE Side = 'from';
        SELECT @ToBefore = Variance, @ToAfter = Variance + TxAmount FROM @Side WHERE Side = 'to';

        /* ---- 6. Agora's record: what was written, row by row ---------------- */
        DECLARE @Writes NVARCHAR(MAX) = (
            SELECT
                JSON_QUERY((SELECT 'SS_UniqueNumber' AS [table], 3 AS sectionId, a.Prior AS prior, a.New AS new
                            FROM @Alloc a FOR JSON PATH, WITHOUT_ARRAY_WRAPPER)) AS counter,
                JSON_QUERY((
                    SELECT 'insert' AS [action], 'BRN_TillBalance' AS [table], s.Side AS side,
                           JSON_QUERY((SELECT @BranchId AS SSBranchId, s.TillNo, s.ShiftNo,
                                              CONVERT(CHAR(10), s.TxDate, 23) AS TransactionDate, s.LogFile, @No AS TillBalanceNo
                                       FOR JSON PATH, WITHOUT_ARRAY_WRAPPER)) AS [key],
                           JSON_QUERY((SELECT s.TxAmount AS TransactionAmount, s.Variance AS OriginalEmployeeAmount,
                                              s.EmployeeCode, s.Variance + s.TxAmount AS EmployeeAmount,
                                              @ErpUserId AS CreatedByUserId
                                       FOR JSON PATH, WITHOUT_ARRAY_WRAPPER, INCLUDE_NULL_VALUES)) AS new
                    FROM @Side s ORDER BY s.Side
                    FOR JSON PATH)) AS [rows]
            FOR JSON PATH, WITHOUT_ARRAY_WRAPPER);

        INSERT INTO agora.TillBalanceEvent
            (BranchId, TillBalanceNo, [Event], Amount, FromDate, FromTillNo, FromShiftNo, ToDate, ToTillNo, ToShiftNo,
             WriteMode, SaveRef, WritesJson, Note, CreatedAt, CreatedBy)
        VALUES
            (@BranchId, @No, 'captured', @Amount, @FromDate, @FromTillNo, @FromShiftNo, @ToDate, @ToTillNo, @ToShiftNo,
             'live', @Ref, @Writes, @Note, @Now, @UserId);

        COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        THROW;
    END CATCH;

    SELECT
        CAST(1 AS BIT)                                      AS Ok,
        'CASH_TILLBAL_CAPTURED'                             AS Code,
        CONVERT(NVARCHAR(400), CONCAT(
            'Till balance ', @No, ' captured in PumpIT: R', FORMAT(@Amount, 'N2'), ' from ',
            (SELECT Label FROM @Side WHERE Side = 'from'), N' (variance ', FORMAT(@FromBefore, 'N2'), N' → ', FORMAT(@FromAfter, 'N2'),
            ') to ', (SELECT Label FROM @Side WHERE Side = 'to'), N' (', FORMAT(@ToBefore, 'N2'), N' → ', FORMAT(@ToAfter, 'N2'),
            '). It waits for approval in PumpIT, which posts it to both cashups.')) AS Message,
        @No                                                 AS Id,
        @No                                                 AS TillBalanceNo,
        @Amount                                             AS Amount,
        @FromBefore                                         AS FromVarianceBefore,
        @FromAfter                                          AS FromVarianceAfter,
        @ToBefore                                           AS ToVarianceBefore,
        @ToAfter                                            AS ToVarianceAfter,
        @Ref                                                AS SaveRef;
END
