/*
 * agora.usp_Recon_DashboardOutstanding — what is still outstanding in bank
 * recon, per site, per area, per side, aged.
 *
 * Read by:  Control -> Money -> Bank recon -> Dashboard (summary and per-site
 *           views). Ryan, 13 Oct 2026: "per branch shows what is outstanding
 *           … a summary view and a per branch view … excel struggles with it".
 * Reads:    agora.vw_BankStatementLine, agora.vw_DailyBankingABSA, …FNB,
 *           …Deposita, …CashBags, …SmartATM, agora.vw_SmartATM, agora.Branch
 * Writes:   nothing.
 *
 * WHY IT AGGREGATES HERE. The two sides are 347,045 bank lines and 1.38
 * million deposit rows; the screen needs a few hundred figures, so the server
 * adds them up and the page never sees a row. The rows themselves are behind
 * agora.usp_Recon_GridOutstanding, a page at a time.
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
 * Arguments: @BranchIds and @AllowedBranchIds as the grid template reads them
 * (CSV; empty means every site, and an empty grant means the whole estate);
 * @DateFrom / @DateTo bound the transaction date (NULL: no lower bound, and
 * @AsAt as the upper).
 *
 * Result set 1: one row per BranchId × ReconArea × Side that has anything
 * outstanding — Rows, Amount, OldestDate, NewestDate and each bucket's rows
 * and amount.
 */
CREATE OR ALTER PROCEDURE [agora].[usp_Recon_DashboardOutstanding]
    @BranchIds        NVARCHAR(MAX) = NULL,
    @AllowedBranchIds NVARCHAR(MAX) = NULL,
    @DateFrom         DATE          = NULL,
    @DateTo           DATE          = NULL,
    @AsAt             DATE          = NULL
AS
BEGIN
    SET NOCOUNT ON;
    /* Its only writes are to its own #temp tables; set because check-procs
       counts those, and an aborted read should leave nothing half-built. */
    SET XACT_ABORT ON;

    SET @AsAt   = ISNULL(@AsAt, CONVERT(date, SYSUTCDATETIME()));
    SET @DateTo = ISNULL(@DateTo, @AsAt);

    DECLARE @From datetime = ISNULL(CONVERT(datetime, @DateFrom), '19000101'),
            @To   datetime = DATEADD(day, 1, CONVERT(datetime, @DateTo));

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

    /* The sites in scope, once, so every side below filters on one table. */
    CREATE TABLE #Site (BranchId int PRIMARY KEY);

    INSERT INTO #Site (BranchId)
    SELECT b.BranchId
    FROM agora.Branch b
    WHERE b.DeletedAt IS NULL
      AND (@AllBranches = 1 OR b.BranchId IN (SELECT BranchId FROM @Branch))
      AND (@AllAllowed  = 1 OR b.BranchId IN (SELECT BranchId FROM @Allowed));

    /* Every outstanding row reduced to (site, area, side, date, amount) and
       grouped by day straight away, so the table below holds days, not rows. */
    CREATE TABLE #Day (
        BranchId  int          NOT NULL,
        ReconArea nvarchar(20) COLLATE DATABASE_DEFAULT NOT NULL,
        Side      nvarchar(10) COLLATE DATABASE_DEFAULT NOT NULL,
        TxnDate   date         NOT NULL,
        [Rows]    int          NOT NULL,
        Amount    money        NOT NULL
    );

    /* The bank side, all areas in one pass. */
    INSERT INTO #Day (BranchId, ReconArea, Side, TxnDate, [Rows], Amount)
    SELECT l.BranchId,
           CASE WHEN l.IDState = 1 THEN 'Unclassified'
                WHEN l.Type = 'CashDeposit' THEN 'CashBags'
                ELSE l.Type END,
           'Bank', CONVERT(date, l.LineDate), COUNT(*), ISNULL(SUM(l.Amount), 0)
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
    INSERT INTO #Day (BranchId, ReconArea, Side, TxnDate, [Rows], Amount)
    SELECT d.BranchId, 'ABSA', 'PumpIT', CONVERT(date, d.TransactionDate), COUNT(*), ISNULL(SUM(d.TransactionAmount), 0)
    FROM agora.vw_DailyBankingABSA d
    JOIN #Site s ON s.BranchId = d.BranchId
    WHERE d.ReconBatchNoPumpIT = 0 AND d.TransactionDate >= @From AND d.TransactionDate < @To
    GROUP BY d.BranchId, CONVERT(date, d.TransactionDate);

    INSERT INTO #Day (BranchId, ReconArea, Side, TxnDate, [Rows], Amount)
    SELECT d.BranchId, 'FNB', 'PumpIT', CONVERT(date, d.TransactionDate), COUNT(*), ISNULL(SUM(d.Amount), 0)
    FROM agora.vw_DailyBankingFNB d
    JOIN #Site s ON s.BranchId = d.BranchId
    WHERE d.ReconBatchNoPumpIT = 0 AND d.TransactionDate >= @From AND d.TransactionDate < @To
    GROUP BY d.BranchId, CONVERT(date, d.TransactionDate);

    INSERT INTO #Day (BranchId, ReconArea, Side, TxnDate, [Rows], Amount)
    SELECT d.BranchId, 'CashMachine', 'PumpIT', CONVERT(date, d.TransactionDate), COUNT(*), ISNULL(SUM(d.DepositaAmount), 0)
    FROM agora.vw_DailyBankingDeposita d
    JOIN #Site s ON s.BranchId = d.BranchId
    WHERE d.ReconBatchNoPumpIT = 0 AND d.TransactionDate >= @From AND d.TransactionDate < @To
    GROUP BY d.BranchId, CONVERT(date, d.TransactionDate);

    INSERT INTO #Day (BranchId, ReconArea, Side, TxnDate, [Rows], Amount)
    SELECT d.BranchId, 'CashBags', 'PumpIT', CONVERT(date, d.TransactionDate), COUNT(*), ISNULL(SUM(d.CashBagAmount), 0)
    FROM agora.vw_DailyBankingCashBags d
    JOIN #Site s ON s.BranchId = d.BranchId
    WHERE d.ReconBatchNoPumpIT = 0 AND d.TransactionDate >= @From AND d.TransactionDate < @To
    GROUP BY d.BranchId, CONVERT(date, d.TransactionDate);

    INSERT INTO #Day (BranchId, ReconArea, Side, TxnDate, [Rows], Amount)
    SELECT x.BranchId, 'SmartATM', 'PumpIT', CONVERT(date, x.TxnDate), COUNT(*), ISNULL(SUM(x.Deposited), 0)
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

    /* Aged and pivoted. A date after @AsAt (a future-dated row) is age 0. */
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
           ISNULL(b.Name, CONCAT('Site ', a.BranchId))                     AS BranchName,
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
           SUM(CASE WHEN a.Bucket = 5 THEN a.Amount ELSE 0 END)            AS AgeOverAmount
    FROM aged a
    LEFT JOIN agora.Branch b ON b.BranchId = a.BranchId AND b.DeletedAt IS NULL
    GROUP BY a.BranchId, b.Name, a.ReconArea, a.Side
    ORDER BY ISNULL(b.Name, CONCAT('Site ', a.BranchId)), a.ReconArea, a.Side;

    DROP TABLE #Day;
    DROP TABLE #Site;
END
