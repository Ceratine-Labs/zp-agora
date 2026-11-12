/* ============================================================================
   agora.usp_Debtors_GetStatement — one debtor's statement of account

   WHAT IT IS FOR
   ZP's debtor statement (detailed template, 12 Nov 2026): who the account is,
   the balance brought forward, every document in the period — invoices and
   credit notes from MIST_Trans_Customer, payments from the bank lines — with
   litres, fuel type, KM, registration and order number, and the running
   balance after each. The screen, the PDF and the mail all render THIS, so
   what somebody checked on the screen is what the debtor receives.

   THE LEDGER is agora.vw_DebtorStatementLedger (its migration is 18a):
     invoices / credit notes  MIST_Trans_Customer, one row per document,
                              a document MIST loaded twice counted once
     payments                 RCN_BankStatementLinesPumpIT, Type 'Debtors',
                              whose CustomerCode is one of this till
                              account's customers (BRN_Customers.DebtorID)
     taken on                 BRN_Customers.OpeningPumpITBalance
   and agrees with agora.usp_Debtors_GridAccounts row for row:
     Opening = every posting before @DateFrom — the take-on balance included,
               so the running balance starts where the account really stood
     Closing = Opening + the period's debits - its credits

   ACCOUNT NUMBER on the statement is the PumpIT customer code (NGS00013),
   which is what the debtor quotes on a payment; the screen and its URL stay
   keyed on the till account (1002). A till account two customer codes share
   shows both.

   KM AND REG come from MIST_Trans_Customer.ODO and .REGNO, which ZP are
   adding (12 Nov 2026). The procedure looks for the columns every time it
   runs: until they exist KM and REG are blank, and the day they appear they
   fill — no change here. The lookup is per document (one vehicle per slip).

   AGEING, as at @DateTo, the way a balance-forward statement ages: payments
   are taken to settle the OLDEST debits first, so what is still owed is the
   newest debits that add up to the closing balance. Each of those is aged
   from its own date: under 30 days is Current, then 30, 60, 90 and 120+. A
   closing balance of nil or in credit is all Current.

   LEDGER FROM is the site's first MIST document — set only for an account
   with no take-on balance, which is the case the screen warns about.

   Result sets:
     1  the account and its balances, one row
     2  the period's documents, oldest first, each with the running balance
     3  the last 20 times this account's statement was mailed (or tried)

   RULES (a refusal is THROW 51000 'AGORA:{Code}:{message}'):
     BRANCH_NOT_ALLOWED  the site is outside @AllowedBranchIds
     UNKNOWN_ACCOUNT     no posting and no customer or master row for the
                         account there
     BAD_PERIOD          @DateFrom after @DateTo

   READ-ONLY.

     EXEC agora.usp_Debtors_GetStatement @BranchId = 25, @AccountNo = '1002',
          @DateFrom = '2026-09-01', @DateTo = '2026-10-31';
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

    SELECT l.TransactionDate, l.TranCode, l.TranType, l.DocumentNo, l.[Description], l.OrderNo,
           l.Litres, l.FuelType, l.Debit, l.Credit, l.Amount, l.AccountName
    INTO #Ledger
    FROM agora.vw_DebtorStatementLedger l
    WHERE l.BranchId = @BranchId
      AND l.AccountNo = @AccountNo
      AND l.TransactionDate <= @DateTo
    OPTION (RECOMPILE);

    /* The PumpIT customer(s) behind the till account: the account number the
       statement shows, and the name. */
    SELECT LTRIM(RTRIM(c.CustomerCode)) AS CustomerCode, NULLIF(LTRIM(RTRIM(c.CustomerName)), '') AS CustomerName
    INTO #Customer
    FROM [PumpIT].dbo.BRN_Customers c
    WHERE c.SSBranchId = @BranchId AND LTRIM(RTRIM(c.DebtorID)) = @AccountNo;

    IF NOT EXISTS (SELECT 1 FROM #Ledger)
       AND NOT EXISTS (SELECT 1 FROM #Customer)
       AND NOT EXISTS (SELECT 1 FROM agora.vw_DebtorAccount m WHERE m.BranchId = @BranchId AND m.AccountNo = @AccountNo)
        THROW 51000, 'AGORA:UNKNOWN_ACCOUNT:There is no such account at that site.', 1;

    DECLARE @Opening decimal(18,2) = ISNULL((SELECT SUM(Amount) FROM #Ledger WHERE TransactionDate < @DateFrom), 0),
            @Debits  decimal(18,2) = ISNULL((SELECT SUM(Debit)  FROM #Ledger WHERE TransactionDate >= @DateFrom), 0),
            @Credits decimal(18,2) = ISNULL((SELECT SUM(Credit) FROM #Ledger WHERE TransactionDate >= @DateFrom), 0);
    DECLARE @Closing decimal(18,2) = @Opening + @Debits - @Credits;

    /* KM and REG: per document, from MIST_Trans_Customer.ODO / .REGNO — only
       once those columns exist. Dynamic SQL because a statement that names a
       missing column does not compile; this one is only built when they are
       there. The window is padded a week each side because a document's date
       is that of its FIRST load and a reload can carry the next day's. */
    CREATE TABLE #Vehicle (DocumentNo nvarchar(40)  COLLATE DATABASE_DEFAULT NOT NULL,
                           Reference  nvarchar(510) COLLATE DATABASE_DEFAULT NULL,
                           Km         nvarchar(20)  COLLATE DATABASE_DEFAULT NULL,
                           Reg        nvarchar(20)  COLLATE DATABASE_DEFAULT NULL);

    DECLARE @HasOdo bit = CASE WHEN COL_LENGTH('PumpIT.dbo.MIST_Trans_Customer', 'ODO')   IS NULL THEN 0 ELSE 1 END,
            @HasReg bit = CASE WHEN COL_LENGTH('PumpIT.dbo.MIST_Trans_Customer', 'REGNO') IS NULL THEN 0 ELSE 1 END;

    IF @HasOdo = 1 OR @HasReg = 1
    BEGIN
        DECLARE @Sql nvarchar(max) = N'
            SELECT LTRIM(RTRIM(t.TransactionNumber)), NULLIF(LTRIM(RTRIM(t.DocumentNumber)), N''''),
                   MAX(' + CASE WHEN @HasOdo = 1 THEN N'NULLIF(LTRIM(RTRIM(CONVERT(nvarchar(20), t.ODO))), N'''')'
                                ELSE N'CONVERT(nvarchar(20), NULL)' END + N'),
                   MAX(' + CASE WHEN @HasReg = 1 THEN N'NULLIF(LTRIM(RTRIM(CONVERT(nvarchar(20), t.REGNO))), N'''')'
                                ELSE N'CONVERT(nvarchar(20), NULL)' END + N')
            FROM [PumpIT].dbo.MIST_Trans_Customer t
            WHERE t.MIST_BranchId = @BranchId
              AND LTRIM(RTRIM(t.BusinessPartnerCode)) = @AccountNo
              AND t.TransactionType IN (''I'', ''C'')
              AND t.TransactionDate >= DATEADD(day, -7, @DateFrom)
              AND t.TransactionDate <  DATEADD(day, 8, @DateTo)
            GROUP BY LTRIM(RTRIM(t.TransactionNumber)), NULLIF(LTRIM(RTRIM(t.DocumentNumber)), N'''');';

        INSERT INTO #Vehicle (DocumentNo, Reference, Km, Reg)
        EXEC sys.sp_executesql @Sql,
             N'@BranchId int, @AccountNo nvarchar(10), @DateFrom date, @DateTo date',
             @BranchId = @BranchId, @AccountNo = @AccountNo, @DateFrom = @DateFrom, @DateTo = @DateTo;
    END

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
                   SUM(Debit) OVER (ORDER BY TransactionDate DESC, DocumentNo DESC
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
        SELECT @Current = ISNULL(SUM(CASE WHEN Age < 30 THEN Owed ELSE 0 END), 0),
               @D30     = ISNULL(SUM(CASE WHEN Age >= 30 AND Age < 60  THEN Owed ELSE 0 END), 0),
               @D60     = ISNULL(SUM(CASE WHEN Age >= 60 AND Age < 90  THEN Owed ELSE 0 END), 0),
               @D90     = ISNULL(SUM(CASE WHEN Age >= 90 AND Age < 120 THEN Owed ELSE 0 END), 0),
               @D120    = ISNULL(SUM(CASE WHEN Age >= 120 THEN Owed ELSE 0 END), 0)
        FROM o;
    END

    DECLARE @LedgerFrom date = CASE WHEN EXISTS (SELECT 1 FROM #Ledger WHERE TranCode = 'O') THEN NULL
                                    ELSE (SELECT CONVERT(date, MIN(t.EODDate)) FROM [PumpIT].dbo.MIST_Trans_Customer t
                                          WHERE t.MIST_BranchId = @BranchId) END;

    -- 1  the account
    SELECT
        @BranchId                                           AS BranchId,
        b.Name                                              AS BranchName,
        @AccountNo                                          AS AccountNo,
        COALESCE((SELECT STRING_AGG(CustomerCode, ', ') WITHIN GROUP (ORDER BY CustomerCode) FROM #Customer),
                 @AccountNo)                                AS StatementAccountNo,
        COALESCE((SELECT TOP 1 CustomerName FROM #Customer WHERE CustomerName IS NOT NULL ORDER BY CustomerCode),
                 m.AccountName,
                 (SELECT TOP 1 AccountName FROM #Ledger WHERE AccountName IS NOT NULL ORDER BY TransactionDate DESC),
                 @AccountNo)                                AS AccountName,
        m.Contact, m.Address1, m.Address2, m.Address3, m.Address4,
        m.Postal1, m.Postal2, m.Postal3, m.Postal4,
        m.Telephone, m.Cellphone, m.VatNo, m.CompanyReg, m.CreditLimit, ISNULL(m.IsBlocked, 0) AS IsBlocked,
        @DateFrom AS PeriodFrom, @DateTo AS PeriodTo,
        @Opening AS Opening, @Debits AS Debits, @Credits AS Credits, @Closing AS Closing,
        (SELECT MAX(TransactionDate) FROM #Ledger WHERE TranCode <> 'O') AS LastTransactionDate,
        @Current AS AgeCurrent, @D30 AS Age30, @D60 AS Age60, @D90 AS Age90, @D120 AS Age120,
        @LedgerFrom AS LedgerFrom,
        CONVERT(bit, CASE WHEN @HasOdo = 1 AND @HasReg = 1 THEN 1 ELSE 0 END) AS HasVehicle,
        c.Email, c.CcEmail, CONVERT(bit, ISNULL(c.AutoSend, 0)) AS AutoSend, c.Note,
        c.UpdatedAt AS ContactUpdatedAt
    FROM (SELECT 1 AS one) x
    LEFT JOIN agora.Branch b ON b.BranchId = @BranchId
    LEFT JOIN agora.vw_DebtorAccount m ON m.BranchId = @BranchId AND m.AccountNo = @AccountNo
    LEFT JOIN agora.DebtorContact c ON c.BranchId = @BranchId AND c.AccountNo = @AccountNo;

    /* 2  the period, oldest first: on a day, a take-on first, then invoices,
          then credit notes, then payments — so a slip paid the day it was
          raised reads as raised, then paid. */
    SELECT l.TransactionDate, l.TranCode, l.TranType, l.DocumentNo,
           l.[Description], l.Litres, l.FuelType, v.Km, v.Reg, l.OrderNo,
           l.Debit, l.Credit, l.Amount,
           CONVERT(decimal(18,2), @Opening + SUM(l.Amount) OVER (
               ORDER BY l.TransactionDate,
                        CASE l.TranCode WHEN 'O' THEN 0 WHEN 'I' THEN 1 WHEN 'C' THEN 2 ELSE 3 END,
                        l.DocumentNo
               ROWS UNBOUNDED PRECEDING)) AS Balance
    FROM #Ledger l
    LEFT JOIN #Vehicle v
      ON l.TranCode IN ('I', 'C') AND v.DocumentNo = l.DocumentNo AND ISNULL(v.Reference, N'') = ISNULL(l.[Description], N'')
    WHERE l.TransactionDate >= @DateFrom
    ORDER BY l.TransactionDate,
             CASE l.TranCode WHEN 'O' THEN 0 WHEN 'I' THEN 1 WHEN 'C' THEN 2 ELSE 3 END,
             l.DocumentNo;

    -- 3  what was mailed
    SELECT TOP 20 s.CreatedAt AS SentAt, s.PeriodFrom, s.PeriodTo, s.ClosingBalance, s.SentTo, s.Mode,
           s.[Trigger], s.[Status], s.Error, u.UserName AS SentBy
    FROM agora.DebtorStatementSend s
    LEFT JOIN agora.[User] u ON u.Id = s.CreatedBy
    WHERE s.BranchId = @BranchId AND s.AccountNo = @AccountNo
    ORDER BY s.CreatedAt DESC, s.Id DESC;
END
