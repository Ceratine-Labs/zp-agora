/* ============================================================================
   agora.usp_Debtors_GetStatement — one debtor's statement of account

   WHAT IT IS FOR
   The statement a debtor is sent: who they are, the balance brought forward,
   every posting in the period with the balance after it, the balance carried,
   and how old what they owe is. The screen, the PDF and the mail all render
   THIS, so what somebody checked on the screen is what the debtor receives.

   THE ARITHMETIC is agora.vw_DebtorLedger's (account postings only, each
   once) and agrees with agora.usp_Debtors_GridAccounts row for row:
     Opening = every posting before @DateFrom
     Closing = Opening + the period's debits - its credits

   AGEING, as at @DateTo, the way a balance-forward statement ages: payments
   are taken to settle the OLDEST debits first, so what is still owed is the
   newest debits that add up to the closing balance. Each of those is aged
   from its own date: under 30 days is Current, then 30, 60, 90 and 120+. A
   closing balance of nil or in credit is all Current.

   LEDGER FROM is the site's first posting PumpIT holds. An account older than
   that has no take-on balance in the ledger; the screen says so.

   Result sets:
     1  the account and its balances, one row
     2  the period's postings, oldest first, each with the running balance
     3  the last 20 times this account's statement was mailed (or tried)

   RULES (a refusal is THROW 51000 'AGORA:{Code}:{message}'):
     BRANCH_NOT_ALLOWED  the site is outside @AllowedBranchIds
     UNKNOWN_ACCOUNT     no posting and no master row for the account there
     BAD_PERIOD          @DateFrom after @DateTo

   READ-ONLY.

     EXEC agora.usp_Debtors_GetStatement @BranchId = 25, @AccountNo = '114',
          @DateFrom = '2026-09-01', @DateTo = '2026-09-30';
   ============================================================================ */

CREATE OR ALTER PROCEDURE [agora].[usp_Debtors_GetStatement]
    @BranchId         INT,
    @AccountNo        NVARCHAR(10),
    @DateFrom         DATE          = NULL,
    @DateTo           DATE          = NULL,
    @AllowedBranchIds NVARCHAR(MAX) = NULL
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @Today date = CONVERT(date, GETDATE());

    SET @AccountNo = NULLIF(LTRIM(RTRIM(@AccountNo)), '');
    SET @DateTo    = ISNULL(@DateTo, EOMONTH(@Today, -1));
    SET @DateFrom  = ISNULL(@DateFrom, DATEFROMPARTS(YEAR(@DateTo), MONTH(@DateTo), 1));

    IF @DateFrom > @DateTo
        THROW 51000, 'AGORA:BAD_PERIOD:The statement period starts after it ends.', 1;

    IF NULLIF(LTRIM(RTRIM(ISNULL(@AllowedBranchIds, ''))), '') IS NOT NULL
       AND NOT EXISTS (SELECT 1 FROM STRING_SPLIT(@AllowedBranchIds, ',') s
                       WHERE TRY_CONVERT(int, LTRIM(RTRIM(s.value))) = @BranchId)
        THROW 51000, 'AGORA:BRANCH_NOT_ALLOWED:That site is not one of yours.', 1;

    SELECT l.TransactionDate, l.TranCode, l.TranType, l.Reference, l.[Description], l.OrderNo, l.TillNo,
           l.Debit, l.Credit, l.Amount, l.AccountName
    INTO #Ledger
    FROM agora.vw_DebtorLedger l
    WHERE l.BranchId = @BranchId
      AND l.AccountNo = @AccountNo
      AND l.TransactionDate <= @DateTo
    OPTION (RECOMPILE);

    IF NOT EXISTS (SELECT 1 FROM #Ledger)
       AND NOT EXISTS (SELECT 1 FROM agora.vw_DebtorAccount m WHERE m.BranchId = @BranchId AND m.AccountNo = @AccountNo)
        THROW 51000, 'AGORA:UNKNOWN_ACCOUNT:There is no such account at that site.', 1;

    DECLARE @Opening decimal(18,2) = ISNULL((SELECT SUM(Amount) FROM #Ledger WHERE TransactionDate < @DateFrom), 0),
            @Debits  decimal(18,2) = ISNULL((SELECT SUM(Debit)  FROM #Ledger WHERE TransactionDate >= @DateFrom), 0),
            @Credits decimal(18,2) = ISNULL((SELECT SUM(Credit) FROM #Ledger WHERE TransactionDate >= @DateFrom), 0);
    DECLARE @Closing decimal(18,2) = @Opening + @Debits - @Credits;

    /* What is still owed is the newest debits that add up to the closing
       balance; each is aged from its own date. */
    DECLARE @Current decimal(18,2) = 0, @D30 decimal(18,2) = 0, @D60 decimal(18,2) = 0,
            @D90 decimal(18,2) = 0, @D120 decimal(18,2) = 0;

    IF @Closing <= 0
        SET @Current = @Closing;
    ELSE
    BEGIN
        ;WITH d AS (
            SELECT TransactionDate, Debit,
                   SUM(Debit) OVER (ORDER BY TransactionDate DESC, Reference DESC
                                    ROWS UNBOUNDED PRECEDING) AS Cum
            FROM #Ledger
            WHERE Debit > 0
        ), o AS (
            SELECT DATEDIFF(day, TransactionDate, @DateTo) AS Age,
                   CASE WHEN Cum - Debit >= @Closing THEN 0
                        WHEN Cum <= @Closing          THEN Debit
                        ELSE @Closing - (Cum - Debit) END AS Owed
            FROM d
        )
        SELECT @Current = ISNULL(SUM(CASE WHEN Age < 30 THEN Owed END), 0),
               @D30     = ISNULL(SUM(CASE WHEN Age >= 30 AND Age < 60  THEN Owed END), 0),
               @D60     = ISNULL(SUM(CASE WHEN Age >= 60 AND Age < 90  THEN Owed END), 0),
               @D90     = ISNULL(SUM(CASE WHEN Age >= 90 AND Age < 120 THEN Owed END), 0),
               @D120    = ISNULL(SUM(CASE WHEN Age >= 120 THEN Owed END), 0)
        FROM o;
    END

    DECLARE @LedgerFrom date = (SELECT MIN(TransactionDate) FROM agora.vw_DebtorLedger WHERE BranchId = @BranchId);

    -- 1  the account
    SELECT
        @BranchId                                           AS BranchId,
        b.Name                                              AS BranchName,
        @AccountNo                                          AS AccountNo,
        COALESCE(m.AccountName, (SELECT TOP 1 AccountName FROM #Ledger WHERE AccountName IS NOT NULL
                                 ORDER BY TransactionDate DESC), @AccountNo) AS AccountName,
        m.Contact, m.Address1, m.Address2, m.Address3, m.Address4,
        m.Postal1, m.Postal2, m.Postal3, m.Postal4,
        m.Telephone, m.Cellphone, m.VatNo, m.CompanyReg, m.CreditLimit, ISNULL(m.IsBlocked, 0) AS IsBlocked,
        @DateFrom AS PeriodFrom, @DateTo AS PeriodTo,
        @Opening AS Opening, @Debits AS Debits, @Credits AS Credits, @Closing AS Closing,
        @Current AS AgeCurrent, @D30 AS Age30, @D60 AS Age60, @D90 AS Age90, @D120 AS Age120,
        @LedgerFrom AS LedgerFrom,
        c.Email, c.CcEmail, CONVERT(bit, ISNULL(c.AutoSend, 0)) AS AutoSend, c.Note,
        c.UpdatedAt AS ContactUpdatedAt
    FROM (SELECT 1 AS one) x
    LEFT JOIN agora.Branch b ON b.BranchId = @BranchId
    LEFT JOIN agora.vw_DebtorAccount m ON m.BranchId = @BranchId AND m.AccountNo = @AccountNo
    LEFT JOIN agora.DebtorContact c ON c.BranchId = @BranchId AND c.AccountNo = @AccountNo;

    -- 2  the period, oldest first, debits before credits on a day
    SELECT TransactionDate, TranCode, TranType, Reference, [Description], OrderNo, TillNo, Debit, Credit,
           CONVERT(decimal(18,2), @Opening + SUM(Amount) OVER (
               ORDER BY TransactionDate, CASE WHEN Amount >= 0 THEN 0 ELSE 1 END, Reference
               ROWS UNBOUNDED PRECEDING)) AS Balance
    FROM #Ledger
    WHERE TransactionDate >= @DateFrom
    ORDER BY TransactionDate, CASE WHEN Amount >= 0 THEN 0 ELSE 1 END, Reference;

    -- 3  what was mailed
    SELECT TOP 20 s.CreatedAt AS SentAt, s.PeriodFrom, s.PeriodTo, s.ClosingBalance, s.SentTo, s.Mode,
           s.[Trigger], s.[Status], s.Error, u.UserName AS SentBy
    FROM agora.DebtorStatementSend s
    LEFT JOIN agora.[User] u ON u.Id = s.CreatedBy
    WHERE s.BranchId = @BranchId AND s.AccountNo = @AccountNo
    ORDER BY s.CreatedAt DESC, s.Id DESC;
END
