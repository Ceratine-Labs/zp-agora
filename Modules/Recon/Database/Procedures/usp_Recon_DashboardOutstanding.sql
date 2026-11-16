/*
 * agora.usp_Recon_DashboardOutstanding — what is still outstanding in bank
 * recon, per site, per area, per side, aged.
 *
 * Read by:  Control -> Money -> Bank recon -> Dashboard: the Pivot (sites by
 *           area, or sites by day or month; Ryan, 16 Nov 2026) and the
 *           summary and per-site views. Ryan, 13 Oct 2026: "per branch shows
 *           what is outstanding … a summary view and a per branch view …
 *           excel struggles with it".
 * Reads:    agora.vw_BankStatementLine, agora.vw_DailyBankingABSA, …FNB,
 *           …Deposita, …CashBags, …SmartATM, agora.vw_SmartATM, agora.Branch
 * Writes:   nothing.
 *
 * WHY IT AGGREGATES HERE. The two sides are 364,711 bank lines and 1.53
 * million deposit rows (16 Nov 2026); the screen needs a few hundred figures,
 * so the server adds them up and the page never sees a row. The rows
 * themselves are behind agora.usp_Recon_GridOutstanding, a page at a time.
 *
 * OUTSTANDING means exactly what the recon screens mean by it, so a figure
 * here and the count on an area's workbench agree:
 *
 *   Bank     vw_BankStatementLine, IDState = 2 AND ReconState = 1, by Type —
 *            the Type is the area, except CashBags reads 'CashDeposit'
 *            (usp_Recon_GetSides). A line with IDState = 1 was never
 *            classified (no area can match it) and is reported as its own
 *            area, 'Unclassified', rather than dropped: an outstanding total
 *            that hides 75,305 lines is one nobody can explain. Statement
 *            Types with no recon area (Yumbi, Direct, Debtors …) are out.
 *   PumpIT   each area's deposit view, ReconBatchNoPumpIT = 0. SmartATM is
 *            dated by its device row where there is one, as every recon
 *            screen dates it.
 *
 * AGE is in days from the transaction date to @AsAt (the business date the
 * caller passes; South African, never the server's UTC clock), in five
 * buckets: 0–7, 8–30, 31–90, 91–365 and over a year.
 *
 * IN TRANSIT. A deposit reaches the statement one to three days after the
 * takings (cash bags up to seven), and statements are loaded a day or two
 * late. So a PumpIT row is in transit when it is dated on or after its area's
 *
 *     TransitFrom = the latest classified statement line of that area
 *                   (any site, any recon state, up to @AsAt)
 *                   − LagDays + 1
 *
 * (with no line at all, the statements are taken to run to the day before
 * @AsAt). A late statement moves TransitFrom back with it, so a late file
 * reads as timing rather than as money missing at every site. A bank line and
 * an Unclassified line are never in transit. Transit is a label, never a
 * filter: every row stays in every Rows and Amount. The lags are in @Lag
 * below, and may be edited there.
 *
 * Arguments: @BranchIds and @AllowedBranchIds as the grid template reads them
 * (CSV; empty means every site, and an empty grant means the whole estate);
 * @DateFrom / @DateTo bound the transaction date (NULL: no lower bound, and
 * @AsAt as the upper); @Grain is set 4's period: NULL (set 4 empty), 'day' or
 * 'month'. Refusals:
 *
 *   AGORA:BAD_GRAIN       @Grain is neither day nor month.
 *   AGORA:GRAIN_TOO_FINE  day needs @DateFrom, From no later than To, and a
 *                         window of 92 days or fewer.
 *
 * Result sets, always all four and always in this order, so a caller that
 * reads only the first (ProcedureService::call()) still gets set 1:
 *
 *   1  One row per BranchId × ReconArea × Side that has anything outstanding:
 *      BranchId, BranchName, ReconArea, Side, Rows, Amount, OldestDate,
 *      NewestDate and each bucket's rows and amount (Age7, Age30, Age90,
 *      Age365, AgeOver). Those 18 columns are the original contract and do not
 *      change. Then TransitRows and TransitAmount (PumpIT rows in transit; 0
 *      on Bank and Unclassified) and CreditRows and CreditAmount (the rows
 *      with Amount > 0).
 *   2  Sites: every site in scope, outstanding or not, by name — BranchId,
 *      BranchName, Status, IsTrading, IsActive, IsAdminEntity.
 *   3  Coverage: one row per area — ReconArea, LagDays, LastLineDate (NULL
 *      when no line was ever classified to it), TransitFrom. Every non-deleted
 *      site, deliberately not the grant: a date per area discloses no figure.
 *   4  Periods: classified areas only, one row per BranchId × ReconArea × Side
 *      × PeriodStart (the day itself, or the first of its month): Rows,
 *      Amount, TransitRows, TransitAmount. No rows when @Grain is NULL. Added
 *      up over the periods it is set 1, to the cent.
 *
 * Long rows only, no PIVOT: the page turns them into columns.
 */
CREATE OR ALTER PROCEDURE [agora].[usp_Recon_DashboardOutstanding]
    @BranchIds        NVARCHAR(MAX) = NULL,
    @AllowedBranchIds NVARCHAR(MAX) = NULL,
    @DateFrom         DATE          = NULL,
    @DateTo           DATE          = NULL,
    @AsAt             DATE          = NULL,
    @Grain            NVARCHAR(10)  = NULL
AS
BEGIN
    SET NOCOUNT ON;
    /* Its only writes are to its own #temp tables; set because check-procs
       counts those, and an aborted read should leave nothing half-built. */
    SET XACT_ABORT ON;

    SET @AsAt   = ISNULL(@AsAt, CONVERT(date, SYSUTCDATETIME()));
    SET @DateTo = ISNULL(@DateTo, @AsAt);

    /* A day per column is only readable over a short window: 92 days is
       2,892 set-4 rows on live (16 Nov 2026), every day since 2023 22,667. */
    SET @Grain = NULLIF(LTRIM(RTRIM(ISNULL(@Grain, ''))), '');
    IF @Grain IS NOT NULL AND @Grain NOT IN ('day', 'month')
        THROW 51000, 'AGORA:BAD_GRAIN:A period is a day or a month.', 1;
    IF @Grain = 'day' AND (@DateFrom IS NULL OR @DateFrom > @DateTo OR DATEDIFF(day, @DateFrom, @DateTo) > 91)
        THROW 51000, 'AGORA:GRAIN_TOO_FINE:Day columns need a window of 92 days or fewer.', 1;

    DECLARE @From    datetime = ISNULL(CONVERT(datetime, @DateFrom), '19000101'),
            @To      datetime = DATEADD(day, 1, CONVERT(datetime, @DateTo)),
            @AsAtEnd datetime = DATEADD(day, 1, CONVERT(datetime, @AsAt));

    /* Days a deposit takes to reach the statement, measured on reconciled pairs Jul–Nov 2026:
       ABSA 99.0%, FNB 99.6%, cash machine 99.5%, Smart ATM 99.8% within 3 days; cash bags 95% within 7. */
    DECLARE @Lag TABLE (ReconArea nvarchar(20) PRIMARY KEY, LagDays int NOT NULL);
    INSERT INTO @Lag (ReconArea, LagDays)
    VALUES ('ABSA', 3), ('FNB', 3), ('CashMachine', 3), ('SmartATM', 3), ('CashBags', 7);

    /* The empty-string trap: STRING_SPLIT('') is one empty row, and
       TRY_CONVERT(int, '') is 0 — see usp_Recon_GridRuns. */
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

    DECLARE @AllBranches bit = CASE WHEN EXISTS (SELECT 1 FROM @Branch)  THEN 0 ELSE 1 END,
            @AllAllowed  bit = CASE WHEN EXISTS (SELECT 1 FROM @Allowed) THEN 0 ELSE 1 END;

    /* The sites in scope, once, so every side below filters on one table —
       with what the page needs to know about each (set 2). Name and Status
       are agora.Branch's own types: set 1's BranchName is ISNULL(s.Name, …),
       which takes s.Name's type, and it was nvarchar(100) before the Pivot. */
    CREATE TABLE #Site (
        BranchId      int           PRIMARY KEY,
        Name          nvarchar(100) COLLATE DATABASE_DEFAULT NOT NULL,
        Status        nvarchar(20)  COLLATE DATABASE_DEFAULT NOT NULL,
        IsTrading     bit           NOT NULL,
        IsActive      bit           NOT NULL,
        IsAdminEntity bit           NOT NULL
    );

    INSERT INTO #Site (BranchId, Name, Status, IsTrading, IsActive, IsAdminEntity)
    SELECT b.BranchId, b.Name, b.Status, b.IsTrading, b.IsActive, b.IsAdminEntity
    FROM agora.Branch b
    WHERE b.DeletedAt IS NULL
      AND (@AllBranches = 1 OR b.BranchId IN (SELECT BranchId FROM @Branch))
      AND (@AllAllowed  = 1 OR b.BranchId IN (SELECT BranchId FROM @Allowed));

    /* Every outstanding row reduced to (site, area, side, date, amount) and
       grouped by day straight away, so the table below holds days, not rows.
       CreditRows / CreditAmount are the rows with Amount > 0. InTransit is
       set once, below, after the statement coverage is known. */
    CREATE TABLE #Day (
        BranchId     int          NOT NULL,
        ReconArea    nvarchar(20) COLLATE DATABASE_DEFAULT NOT NULL,
        Side         nvarchar(10) COLLATE DATABASE_DEFAULT NOT NULL,
        TxnDate      date         NOT NULL,
        [Rows]       int          NOT NULL,
        Amount       money        NOT NULL,
        CreditRows   int          NOT NULL,
        CreditAmount money        NOT NULL,
        InTransit    bit          NOT NULL DEFAULT 0
    );

    /* The bank side, all areas in one pass. */
    INSERT INTO #Day (BranchId, ReconArea, Side, TxnDate, [Rows], Amount, CreditRows, CreditAmount)
    SELECT l.BranchId,
           CASE WHEN l.IDState = 1 THEN 'Unclassified'
                WHEN l.Type = 'CashDeposit' THEN 'CashBags'
                ELSE l.Type END,
           'Bank', CONVERT(date, l.LineDate), COUNT(*), ISNULL(SUM(l.Amount), 0),
           SUM(CASE WHEN l.Amount > 0 THEN 1 ELSE 0 END),
           ISNULL(SUM(CASE WHEN l.Amount > 0 THEN l.Amount ELSE 0 END), 0)
    FROM agora.vw_BankStatementLine l
    JOIN #Site s ON s.BranchId = l.BranchId
    WHERE l.ReconState = 1
      AND (l.IDState = 1
           OR (l.IDState = 2 AND l.Type IN ('ABSA', 'FNB', 'CashMachine', 'SmartATM', 'CashDeposit')))
      AND l.LineDate >= @From AND l.LineDate < @To
    GROUP BY l.BranchId,
             CASE WHEN l.IDState = 1 THEN 'Unclassified'
                  WHEN l.Type = 'CashDeposit' THEN 'CashBags'
                  ELSE l.Type END,
             CONVERT(date, l.LineDate);

    /* The PumpIT side, one deposit view per area. */
    INSERT INTO #Day (BranchId, ReconArea, Side, TxnDate, [Rows], Amount, CreditRows, CreditAmount)
    SELECT d.BranchId, 'ABSA', 'PumpIT', CONVERT(date, d.TransactionDate), COUNT(*), ISNULL(SUM(d.TransactionAmount), 0),
           SUM(CASE WHEN d.TransactionAmount > 0 THEN 1 ELSE 0 END),
           ISNULL(SUM(CASE WHEN d.TransactionAmount > 0 THEN d.TransactionAmount ELSE 0 END), 0)
    FROM agora.vw_DailyBankingABSA d
    JOIN #Site s ON s.BranchId = d.BranchId
    WHERE d.ReconBatchNoPumpIT = 0 AND d.TransactionDate >= @From AND d.TransactionDate < @To
    GROUP BY d.BranchId, CONVERT(date, d.TransactionDate);

    INSERT INTO #Day (BranchId, ReconArea, Side, TxnDate, [Rows], Amount, CreditRows, CreditAmount)
    SELECT d.BranchId, 'FNB', 'PumpIT', CONVERT(date, d.TransactionDate), COUNT(*), ISNULL(SUM(d.Amount), 0),
           SUM(CASE WHEN d.Amount > 0 THEN 1 ELSE 0 END),
           ISNULL(SUM(CASE WHEN d.Amount > 0 THEN d.Amount ELSE 0 END), 0)
    FROM agora.vw_DailyBankingFNB d
    JOIN #Site s ON s.BranchId = d.BranchId
    WHERE d.ReconBatchNoPumpIT = 0 AND d.TransactionDate >= @From AND d.TransactionDate < @To
    GROUP BY d.BranchId, CONVERT(date, d.TransactionDate);

    INSERT INTO #Day (BranchId, ReconArea, Side, TxnDate, [Rows], Amount, CreditRows, CreditAmount)
    SELECT d.BranchId, 'CashMachine', 'PumpIT', CONVERT(date, d.TransactionDate), COUNT(*), ISNULL(SUM(d.DepositaAmount), 0),
           SUM(CASE WHEN d.DepositaAmount > 0 THEN 1 ELSE 0 END),
           ISNULL(SUM(CASE WHEN d.DepositaAmount > 0 THEN d.DepositaAmount ELSE 0 END), 0)
    FROM agora.vw_DailyBankingDeposita d
    JOIN #Site s ON s.BranchId = d.BranchId
    WHERE d.ReconBatchNoPumpIT = 0 AND d.TransactionDate >= @From AND d.TransactionDate < @To
    GROUP BY d.BranchId, CONVERT(date, d.TransactionDate);

    INSERT INTO #Day (BranchId, ReconArea, Side, TxnDate, [Rows], Amount, CreditRows, CreditAmount)
    SELECT d.BranchId, 'CashBags', 'PumpIT', CONVERT(date, d.TransactionDate), COUNT(*), ISNULL(SUM(d.CashBagAmount), 0),
           SUM(CASE WHEN d.CashBagAmount > 0 THEN 1 ELSE 0 END),
           ISNULL(SUM(CASE WHEN d.CashBagAmount > 0 THEN d.CashBagAmount ELSE 0 END), 0)
    FROM agora.vw_DailyBankingCashBags d
    JOIN #Site s ON s.BranchId = d.BranchId
    WHERE d.ReconBatchNoPumpIT = 0 AND d.TransactionDate >= @From AND d.TransactionDate < @To
    GROUP BY d.BranchId, CONVERT(date, d.TransactionDate);

    INSERT INTO #Day (BranchId, ReconArea, Side, TxnDate, [Rows], Amount, CreditRows, CreditAmount)
    SELECT x.BranchId, 'SmartATM', 'PumpIT', CONVERT(date, x.TxnDate), COUNT(*), ISNULL(SUM(x.Deposited), 0),
           SUM(CASE WHEN x.Deposited > 0 THEN 1 ELSE 0 END),
           ISNULL(SUM(CASE WHEN x.Deposited > 0 THEN x.Deposited ELSE 0 END), 0)
    FROM (
        SELECT d.BranchId, d.Deposited, ISNULL(a.DepositDateTime, d.DepositDateTime) AS TxnDate
        FROM agora.vw_DailyBankingSmartATM d
        JOIN #Site s ON s.BranchId = d.BranchId
        LEFT JOIN agora.vw_SmartATM a
               ON a.BranchId = d.BranchId AND a.TerminalId = d.TerminalId
              AND a.TraceNo  = d.TraceNo  AND a.UniqueNo   = d.UniqueNo
        WHERE d.ReconBatchNoPumpIT = 0
    ) x
    WHERE x.TxnDate >= @From AND x.TxnDate < @To
    GROUP BY x.BranchId, CONVERT(date, x.TxnDate);

    /* How far each area's statements are loaded, and so where its transit
       starts (see IN TRANSIT above). One pass over the classified lines of
       every non-deleted site, whatever their recon state. */
    CREATE TABLE #Coverage (
        ReconArea    nvarchar(20) COLLATE DATABASE_DEFAULT PRIMARY KEY,
        LagDays      int  NOT NULL,
        LastLineDate date NULL,
        TransitFrom  date NOT NULL
    );

    INSERT INTO #Coverage (ReconArea, LagDays, LastLineDate, TransitFrom)
    SELECT g.ReconArea, g.LagDays, c.LastLineDate,
           DATEADD(day, 1 - g.LagDays, ISNULL(c.LastLineDate, DATEADD(day, -1, @AsAt)))
    FROM @Lag g
    LEFT JOIN (
        SELECT CASE WHEN l.Type = 'CashDeposit' THEN 'CashBags' ELSE l.Type END AS ReconArea,
               MAX(CONVERT(date, l.LineDate)) AS LastLineDate
        FROM agora.vw_BankStatementLine l
        WHERE l.IDState = 2
          AND l.Type IN ('ABSA', 'FNB', 'CashMachine', 'SmartATM', 'CashDeposit')
          AND l.LineDate < @AsAtEnd
          AND EXISTS (SELECT 1 FROM agora.Branch b WHERE b.BranchId = l.BranchId AND b.DeletedAt IS NULL)
        GROUP BY CASE WHEN l.Type = 'CashDeposit' THEN 'CashBags' ELSE l.Type END
    ) c ON c.ReconArea = g.ReconArea;

    /* In transit, decided once for sets 1 and 4: a PumpIT day on or after its
       area's TransitFrom. Bank and Unclassified days never are. */
    UPDATE d
    SET d.InTransit = 1
    FROM #Day d
    JOIN #Coverage c ON c.ReconArea = d.ReconArea
    WHERE d.Side = 'PumpIT' AND d.TxnDate >= c.TransitFrom;

    /* Set 1. Aged and pivoted. A date after @AsAt (a future-dated row) is age 0. */
    ;WITH aged AS (
        SELECT d.*,
               CASE WHEN DATEDIFF(day, d.TxnDate, @AsAt) <= 7   THEN 1
                    WHEN DATEDIFF(day, d.TxnDate, @AsAt) <= 30  THEN 2
                    WHEN DATEDIFF(day, d.TxnDate, @AsAt) <= 90  THEN 3
                    WHEN DATEDIFF(day, d.TxnDate, @AsAt) <= 365 THEN 4
                    ELSE 5 END AS Bucket
        FROM #Day d
    )
    SELECT a.BranchId,
           ISNULL(s.Name, CONCAT('Site ', a.BranchId))                     AS BranchName,
           a.ReconArea,
           a.Side,
           SUM(a.[Rows])                                                   AS [Rows],
           SUM(a.Amount)                                                   AS Amount,
           MIN(a.TxnDate)                                                  AS OldestDate,
           MAX(a.TxnDate)                                                  AS NewestDate,
           SUM(CASE WHEN a.Bucket = 1 THEN a.[Rows] ELSE 0 END)            AS Age7Rows,
           SUM(CASE WHEN a.Bucket = 1 THEN a.Amount ELSE 0 END)            AS Age7Amount,
           SUM(CASE WHEN a.Bucket = 2 THEN a.[Rows] ELSE 0 END)            AS Age30Rows,
           SUM(CASE WHEN a.Bucket = 2 THEN a.Amount ELSE 0 END)            AS Age30Amount,
           SUM(CASE WHEN a.Bucket = 3 THEN a.[Rows] ELSE 0 END)            AS Age90Rows,
           SUM(CASE WHEN a.Bucket = 3 THEN a.Amount ELSE 0 END)            AS Age90Amount,
           SUM(CASE WHEN a.Bucket = 4 THEN a.[Rows] ELSE 0 END)            AS Age365Rows,
           SUM(CASE WHEN a.Bucket = 4 THEN a.Amount ELSE 0 END)            AS Age365Amount,
           SUM(CASE WHEN a.Bucket = 5 THEN a.[Rows] ELSE 0 END)            AS AgeOverRows,
           SUM(CASE WHEN a.Bucket = 5 THEN a.Amount ELSE 0 END)            AS AgeOverAmount,
           SUM(CASE WHEN a.InTransit = 1 THEN a.[Rows] ELSE 0 END)         AS TransitRows,
           SUM(CASE WHEN a.InTransit = 1 THEN a.Amount ELSE 0 END)         AS TransitAmount,
           SUM(a.CreditRows)                                               AS CreditRows,
           SUM(a.CreditAmount)                                             AS CreditAmount
    FROM aged a
    LEFT JOIN #Site s ON s.BranchId = a.BranchId
    GROUP BY a.BranchId, s.Name, a.ReconArea, a.Side
    ORDER BY ISNULL(s.Name, CONCAT('Site ', a.BranchId)), a.ReconArea, a.Side;

    /* Set 2. Sites: every site in scope, so a clear one can still be a row. */
    SELECT s.BranchId,
           s.Name AS BranchName,
           s.Status,
           s.IsTrading,
           s.IsActive,
           s.IsAdminEntity
    FROM #Site s
    ORDER BY s.Name, s.BranchId;

    /* Set 3. Coverage. */
    SELECT c.ReconArea, c.LagDays, c.LastLineDate, c.TransitFrom
    FROM #Coverage c
    ORDER BY c.ReconArea;

    /* Set 4. Periods: the same days, classified only, by day or by month. */
    SELECT d.BranchId,
           d.ReconArea,
           d.Side,
           p.PeriodStart,
           SUM(d.[Rows])                                           AS [Rows],
           SUM(d.Amount)                                           AS Amount,
           SUM(CASE WHEN d.InTransit = 1 THEN d.[Rows] ELSE 0 END) AS TransitRows,
           SUM(CASE WHEN d.InTransit = 1 THEN d.Amount ELSE 0 END) AS TransitAmount
    FROM #Day d
    CROSS APPLY (SELECT CASE WHEN @Grain = 'day' THEN d.TxnDate
                             ELSE DATEFROMPARTS(YEAR(d.TxnDate), MONTH(d.TxnDate), 1) END AS PeriodStart) p
    WHERE @Grain IS NOT NULL
      AND d.ReconArea <> 'Unclassified'
    GROUP BY d.BranchId, d.ReconArea, d.Side, p.PeriodStart
    ORDER BY d.BranchId, d.ReconArea, d.Side, p.PeriodStart;

    DROP TABLE #Coverage;
    DROP TABLE #Day;
    DROP TABLE #Site;
END
