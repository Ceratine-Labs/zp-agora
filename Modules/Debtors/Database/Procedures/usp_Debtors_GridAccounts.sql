/* ============================================================================
   agora.usp_Debtors_GridAccounts — Debtors statements, one row per account

   WHAT IT IS FOR
   Every account at every site the caller may see that has something to say
   for the period: a balance brought forward, a balance carried, or a posting
   in between. Opening, what was charged, what was paid or credited, and the
   closing balance a statement for the period would show — plus where that
   statement would go and whether the account is on the monthly run.

   WHO READS IT
   Control → Money → Debtors statements, and a site's own My site → Debtors
   statements. A row opens the account's statement. The monthly run
   (debtors:send-statements) reads the same rows, so the screen and the run
   cannot disagree about who is due one.

   THE ARITHMETIC is agora.vw_DebtorLedger's — the POS ledger, account
   postings only, each once:
     Opening  = every posting before @DateFrom
     Debits   = invoices, debit notes and debit journals in the period
     Credits  = payments, credit notes and credit journals in the period
     Closing  = Opening + Debits - Credits
   A NEGATIVE balance is money the debtor has paid ahead, which is how many
   fuel accounts run.

   HOW FAR BACK. The ledger PumpIT holds starts on 1 Jun 2023 at most sites
   (22 Apr 2026 at Teds, 1 Sep 2026 at Wimpy Ladysmith). An account older than
   that has no take-on balance in it, so its opening is whatever moved since —
   which is why sending is off until the customer has checked the balances
   (config debtors.send_mode).

   The grid procedure template (feature-rules §2), nine parameters, plus
   @AllowedBranchIds — every site the caller may see at all. Empty means no
   restriction (the same reading BranchScope applies).

   The window defaults to LAST MONTH: a statement is for a month that has
   finished.

   Result sets: 1 the page, 2 (TotalRows), 3 grand totals.

   READ-ONLY.

     EXEC agora.usp_Debtors_GridAccounts @BranchIds = '25', @DateFrom = '2026-09-01', @DateTo = '2026-09-30';
   ============================================================================ */

CREATE OR ALTER PROCEDURE [agora].[usp_Debtors_GridAccounts]
    @BranchIds        NVARCHAR(MAX) = NULL,
    @DateFrom         DATE          = NULL,
    @DateTo           DATE          = NULL,
    @Search           NVARCHAR(200) = NULL,
    @SortColumn       NVARCHAR(80)  = NULL,
    @SortAsc          BIT           = 1,
    @Page             INT           = 1,
    @PageSize         INT           = 50,
    @FiltersJson      NVARCHAR(MAX) = NULL,
    @AllowedBranchIds NVARCHAR(MAX) = NULL
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @Today date = CONVERT(date, GETDATE());

    SET @DateTo   = ISNULL(@DateTo, EOMONTH(@Today, -1));
    SET @DateFrom = ISNULL(@DateFrom, DATEFROMPARTS(YEAR(@DateTo), MONTH(@DateTo), 1));
    SET @Page     = CASE WHEN ISNULL(@Page, 1) < 1 THEN 1 ELSE @Page END;
    SET @PageSize = CASE WHEN ISNULL(@PageSize, 50) BETWEEN 1 AND 100000 THEN @PageSize ELSE 50 END;
    SET @Search   = NULLIF(LTRIM(RTRIM(ISNULL(@Search, ''))), '');

    /* The empty-string trap: STRING_SPLIT('', ',') is one empty row and
       TRY_CONVERT(int, '') is 0. */
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
    DECLARE @AllAllowed  bit = CASE WHEN EXISTS (SELECT 1 FROM @Allowed) THEN 0 ELSE 1 END;

    /* The header filters: a JSON ARRAY whose elements carry the column at
       $.column. See docs/grid.md. */
    DECLARE @Filter    TABLE ([Column] nvarchar(80), [Op] nvarchar(20), [Value] nvarchar(400));
    DECLARE @FilterSet TABLE ([Column] nvarchar(80), [Value] nvarchar(400));

    IF @FiltersJson IS NOT NULL AND ISJSON(@FiltersJson) = 1
    BEGIN
        INSERT INTO @Filter ([Column], [Op], [Value])
        SELECT JSON_VALUE(f.value, '$.column'),
               ISNULL(JSON_VALUE(f.value, '$.op'), 'contains'),
               JSON_VALUE(f.value, '$.value')
        FROM OPENJSON(@FiltersJson) f
        WHERE JSON_VALUE(f.value, '$.value') IS NOT NULL;

        INSERT INTO @FilterSet ([Column], [Value])
        SELECT JSON_VALUE(f.value, '$.column'), v.value
        FROM OPENJSON(@FiltersJson) f
        CROSS APPLY OPENJSON(f.value, '$.in') v
        WHERE JSON_VALUE(f.value, '$.type') = 'set';
    END

    DECLARE @fBranch    nvarchar(400) = (SELECT TOP 1 [Value] FROM @Filter WHERE [Column] = 'BranchName'),
            @fBranchOp  nvarchar(20)  = (SELECT TOP 1 [Op]    FROM @Filter WHERE [Column] = 'BranchName'),
            @fAccount   nvarchar(400) = (SELECT TOP 1 [Value] FROM @Filter WHERE [Column] = 'AccountNo'),
            @fAccountOp nvarchar(20)  = (SELECT TOP 1 [Op]    FROM @Filter WHERE [Column] = 'AccountNo'),
            @fName      nvarchar(400) = (SELECT TOP 1 [Value] FROM @Filter WHERE [Column] = 'AccountName'),
            @fNameOp    nvarchar(20)  = (SELECT TOP 1 [Op]    FROM @Filter WHERE [Column] = 'AccountName'),
            @fEmail     nvarchar(400) = (SELECT TOP 1 [Value] FROM @Filter WHERE [Column] = 'Email'),
            @fEmailOp   nvarchar(20)  = (SELECT TOP 1 [Op]    FROM @Filter WHERE [Column] = 'Email'),
            /* A number that does not parse matches nothing — a filter fails
               closed, never quietly lets everything through. */
            @fClosingOp nvarchar(20)  = (SELECT TOP 1 [Op]    FROM @Filter WHERE [Column] = 'Closing'),
            @fClosing   decimal(18,2) = (SELECT TOP 1 TRY_CONVERT(decimal(18,2), [Value]) FROM @Filter WHERE [Column] = 'Closing');

    DECLARE @fStatusAny bit = CASE WHEN EXISTS (SELECT 1 FROM @FilterSet WHERE [Column] = 'Status') THEN 1 ELSE 0 END;

    /* The ledger once, up to the end of the period, per account. */
    SELECT
        l.BranchId,
        l.AccountNo,
        MAX(l.AccountName)                                                                 AS LedgerName,
        CONVERT(decimal(18,2), SUM(CASE WHEN l.TransactionDate <  @DateFrom THEN l.Amount ELSE 0 END)) AS Opening,
        CONVERT(decimal(18,2), SUM(CASE WHEN l.TransactionDate >= @DateFrom THEN l.Debit  ELSE 0 END)) AS Debits,
        CONVERT(decimal(18,2), SUM(CASE WHEN l.TransactionDate >= @DateFrom THEN l.Credit ELSE 0 END)) AS Credits,
        CONVERT(decimal(18,2), SUM(l.Amount))                                              AS Closing,
        SUM(CASE WHEN l.TransactionDate >= @DateFrom THEN 1 ELSE 0 END)                    AS Postings,
        MAX(CASE WHEN l.TranCode = 'R' THEN l.TransactionDate END)                         AS LastPayment,
        MAX(l.TransactionDate)                                                             AS LastActivity
    INTO #Acct
    FROM agora.vw_DebtorLedger l
    WHERE l.TransactionDate <= @DateTo
      AND (@AllBranches = 1 OR l.BranchId IN (SELECT BranchId FROM @Branch))
      AND (@AllAllowed  = 1 OR l.BranchId IN (SELECT BranchId FROM @Allowed))
    GROUP BY l.BranchId, l.AccountNo
    OPTION (RECOMPILE);

    ;WITH base AS (
        SELECT
            a.BranchId,
            b.Name                                                AS BranchName,
            a.AccountNo,
            COALESCE(m.AccountName, a.LedgerName, a.AccountNo)    AS AccountName,
            a.Opening, a.Debits, a.Credits, a.Closing, a.Postings,
            a.LastPayment, a.LastActivity,
            c.Email,
            c.CcEmail,
            CONVERT(bit, ISNULL(c.AutoSend, 0))                   AS AutoSend,
            s.LastSentAt,
            CASE
                WHEN c.Email IS NULL     THEN 'No email'
                WHEN c.AutoSend = 1      THEN 'On the monthly run'
                ELSE 'Not on the monthly run'
            END                                                   AS [Status]
        FROM #Acct a
        LEFT JOIN agora.Branch b ON b.BranchId = a.BranchId
        LEFT JOIN agora.vw_DebtorAccount m ON m.BranchId = a.BranchId AND m.AccountNo = a.AccountNo
        LEFT JOIN agora.DebtorContact c ON c.BranchId = a.BranchId AND c.AccountNo = a.AccountNo
        OUTER APPLY (SELECT MAX(x.CreatedAt) AS LastSentAt FROM agora.DebtorStatementSend x
                     WHERE x.BranchId = a.BranchId AND x.AccountNo = a.AccountNo AND x.[Status] = 'sent' AND x.Mode = 'live') s
        /* Something to say: a balance either side of the period, or a posting
           inside it. An account settled years ago is not a statement. */
        WHERE a.Opening <> 0 OR a.Closing <> 0 OR a.Postings > 0
    )
    SELECT *
    INTO #Rows
    FROM base
    WHERE (@Search IS NULL
           OR base.BranchName  LIKE '%' + @Search + '%'
           OR base.AccountNo   LIKE '%' + @Search + '%'
           OR base.AccountName LIKE '%' + @Search + '%'
           OR base.Email       LIKE '%' + @Search + '%')
      AND (@fBranch IS NULL OR (CASE WHEN @fBranchOp = 'eq' THEN CASE WHEN base.BranchName = @fBranch THEN 1 ELSE 0 END
                                     ELSE CASE WHEN base.BranchName LIKE '%' + @fBranch + '%' THEN 1 ELSE 0 END END) = 1)
      AND (@fAccount IS NULL OR (CASE WHEN @fAccountOp = 'eq' THEN CASE WHEN base.AccountNo = @fAccount THEN 1 ELSE 0 END
                                      ELSE CASE WHEN base.AccountNo LIKE '%' + @fAccount + '%' THEN 1 ELSE 0 END END) = 1)
      AND (@fName IS NULL OR (CASE WHEN @fNameOp = 'eq' THEN CASE WHEN base.AccountName = @fName THEN 1 ELSE 0 END
                                   ELSE CASE WHEN base.AccountName LIKE '%' + @fName + '%' THEN 1 ELSE 0 END END) = 1)
      AND (@fEmail IS NULL OR (CASE WHEN @fEmailOp = 'eq' THEN CASE WHEN base.Email = @fEmail THEN 1 ELSE 0 END
                                    ELSE CASE WHEN base.Email LIKE '%' + @fEmail + '%' THEN 1 ELSE 0 END END) = 1)
      AND (@fClosingOp IS NULL OR (@fClosing IS NOT NULL AND (
                (@fClosingOp = 'eq'  AND base.Closing =  @fClosing) OR (@fClosingOp = 'ne'  AND base.Closing <> @fClosing) OR
                (@fClosingOp = 'gt'  AND base.Closing >  @fClosing) OR (@fClosingOp = 'gte' AND base.Closing >= @fClosing) OR
                (@fClosingOp = 'lt'  AND base.Closing <  @fClosing) OR (@fClosingOp = 'lte' AND base.Closing <= @fClosing))))
      AND (@fStatusAny = 0 OR base.[Status] IN (SELECT [Value] FROM @FilterSet WHERE [Column] = 'Status'))
    OPTION (RECOMPILE);

    DECLARE @Total bigint = (SELECT COUNT_BIG(*) FROM #Rows);

    SELECT BranchId, BranchName, AccountNo, AccountName, Opening, Debits, Credits, Closing, Postings,
           LastPayment, LastActivity, Email, CcEmail, AutoSend, LastSentAt, [Status],
           @DateFrom AS PeriodFrom, @DateTo AS PeriodTo
    FROM #Rows
    ORDER BY
        /* Text and numbers sorted apart so each compares as itself. */
        CASE WHEN @SortAsc = 1 THEN
            CASE @SortColumn WHEN 'BranchName'  THEN BranchName
                             WHEN 'AccountNo'   THEN AccountNo
                             WHEN 'AccountName' THEN AccountName
                             WHEN 'Email'       THEN Email
                             WHEN 'Status'      THEN [Status] END END ASC,
        CASE WHEN @SortAsc = 0 THEN
            CASE @SortColumn WHEN 'BranchName'  THEN BranchName
                             WHEN 'AccountNo'   THEN AccountNo
                             WHEN 'AccountName' THEN AccountName
                             WHEN 'Email'       THEN Email
                             WHEN 'Status'      THEN [Status] END END DESC,
        CASE WHEN @SortAsc = 1 THEN
            CASE @SortColumn WHEN 'Opening' THEN Opening
                             WHEN 'Debits'  THEN Debits
                             WHEN 'Credits' THEN Credits
                             WHEN 'Closing' THEN Closing END END ASC,
        CASE WHEN @SortAsc = 0 THEN
            CASE @SortColumn WHEN 'Opening' THEN Opening
                             WHEN 'Debits'  THEN Debits
                             WHEN 'Credits' THEN Credits
                             WHEN 'Closing' THEN Closing END END DESC,
        CASE WHEN @SortAsc = 1 THEN
            CASE @SortColumn WHEN 'LastActivity' THEN LastActivity
                             WHEN 'LastPayment'  THEN LastPayment END END ASC,
        CASE WHEN @SortAsc = 0 THEN
            CASE @SortColumn WHEN 'LastActivity' THEN LastActivity
                             WHEN 'LastPayment'  THEN LastPayment END END DESC,
        CASE WHEN @SortAsc = 1 THEN CASE @SortColumn WHEN 'LastSentAt' THEN LastSentAt END END ASC,
        CASE WHEN @SortAsc = 0 THEN CASE @SortColumn WHEN 'LastSentAt' THEN LastSentAt END END DESC,
        BranchName, AccountName, AccountNo
    OFFSET (@Page - 1) * @PageSize ROWS FETCH NEXT @PageSize ROWS ONLY;

    SELECT @Total AS TotalRows;

    SELECT CONVERT(decimal(18,2), SUM(Opening)) AS Opening,
           CONVERT(decimal(18,2), SUM(Debits))  AS Debits,
           CONVERT(decimal(18,2), SUM(Credits)) AS Credits,
           CONVERT(decimal(18,2), SUM(Closing)) AS Closing
    FROM #Rows;
END
