/*
 * agora.usp_Recon_DashboardFeeds — which recon feeds each site has ever had,
 * per area and side, and whether automatic recon has a rule for it.
 *
 * Read by:  Control -> Money -> Bank recon -> Dashboard, the Pivot
 *           (ReconDashboardService::feeds(), cached for an hour per grant).
 *           usp_Recon_DashboardOutstanding only returns what is outstanding,
 *           so an empty cell there could be a channel the site never used, a
 *           channel used and clear, a bank feed that never arrived, a side
 *           that stopped, or a site with no rule. This tells them apart.
 * Reads:    agora.vw_BankStatementLine, agora.vw_DailyBankingABSA, …FNB,
 *           …Deposita, …CashBags, …SmartATM, agora.vw_SmartATM,
 *           agora.vw_AutoReconCriteria, agora.Branch
 * Writes:   nothing.
 *
 * EVERY ROW EVER, not only what is outstanding: a bank line in any recon
 * state and a deposit row whatever its ReconBatchNoPumpIT, dated up to @AsAt.
 * The two sides are read as usp_Recon_DashboardOutstanding reads them:
 *
 *   Bank     vw_BankStatementLine, IDState = 2 (classified), by Type — the
 *            Type is the area, except CashBags reads 'CashDeposit'.
 *            Unclassified lines (IDState = 1) belong to no area and are not
 *            here.
 *   PumpIT   each area's deposit view. SmartATM is dated by its device row
 *            where there is one, as every recon screen dates it.
 *
 * RULES are counted from agora.vw_AutoReconCriteria by BankReconArea — the
 * customer's rules and Agora's active overrides — which is the test
 * ReconService::branchesWithRules() applies. A site with no rule for an area
 * has its previews refused (NO_CRITERIA), the every-site runner leaves it out,
 * and it can only be matched by hand.
 *
 * Arguments: @BranchIds and @AllowedBranchIds as usp_Recon_DashboardOutstanding
 * reads them (CSV; empty means every site, and an empty grant means the whole
 * estate); @AsAt, the business date the caller passes (NULL: the UTC date).
 *
 * Result set 1: one row per BranchId × ReconArea × Side that has ever had a
 * row — FirstDate, LastDate, Rows.
 * Result set 2: one row per site in scope × each of the five areas — Rules,
 * the number of automatic recon rules (0: manual match only).
 */
CREATE OR ALTER PROCEDURE [agora].[usp_Recon_DashboardFeeds]
    @BranchIds        NVARCHAR(MAX) = NULL,
    @AllowedBranchIds NVARCHAR(MAX) = NULL,
    @AsAt             DATE          = NULL
AS
BEGIN
    SET NOCOUNT ON;
    /* Its only writes are to its own #temp tables; an aborted read should
       leave nothing half-built. */
    SET XACT_ABORT ON;

    SET @AsAt = ISNULL(@AsAt, CONVERT(date, SYSUTCDATETIME()));

    DECLARE @To datetime = DATEADD(day, 1, CONVERT(datetime, @AsAt));

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

    CREATE TABLE #Site (BranchId int PRIMARY KEY);

    INSERT INTO #Site (BranchId)
    SELECT b.BranchId
    FROM agora.Branch b
    WHERE b.DeletedAt IS NULL
      AND (@AllBranches = 1 OR b.BranchId IN (SELECT BranchId FROM @Branch))
      AND (@AllAllowed  = 1 OR b.BranchId IN (SELECT BranchId FROM @Allowed));

    CREATE TABLE #Feed (
        BranchId  int          NOT NULL,
        ReconArea nvarchar(20) COLLATE DATABASE_DEFAULT NOT NULL,
        Side      nvarchar(10) COLLATE DATABASE_DEFAULT NOT NULL,
        FirstDate date         NOT NULL,
        LastDate  date         NOT NULL,
        [Rows]    int          NOT NULL,
        PRIMARY KEY (BranchId, ReconArea, Side)
    );

    /* The bank side, all areas in one pass, reconciled or not. */
    INSERT INTO #Feed (BranchId, ReconArea, Side, FirstDate, LastDate, [Rows])
    SELECT l.BranchId,
           CASE WHEN l.Type = 'CashDeposit' THEN 'CashBags' ELSE l.Type END,
           'Bank', MIN(CONVERT(date, l.LineDate)), MAX(CONVERT(date, l.LineDate)), COUNT(*)
    FROM agora.vw_BankStatementLine l
    JOIN #Site s ON s.BranchId = l.BranchId
    WHERE l.IDState = 2
      AND l.Type IN ('ABSA', 'FNB', 'CashMachine', 'SmartATM', 'CashDeposit')
      AND l.LineDate < @To
    GROUP BY l.BranchId, CASE WHEN l.Type = 'CashDeposit' THEN 'CashBags' ELSE l.Type END;

    /* The PumpIT side, one deposit view per area, whatever its batch number. */
    INSERT INTO #Feed (BranchId, ReconArea, Side, FirstDate, LastDate, [Rows])
    SELECT d.BranchId, 'ABSA', 'PumpIT', MIN(CONVERT(date, d.TransactionDate)), MAX(CONVERT(date, d.TransactionDate)), COUNT(*)
    FROM agora.vw_DailyBankingABSA d
    JOIN #Site s ON s.BranchId = d.BranchId
    WHERE d.TransactionDate < @To
    GROUP BY d.BranchId;

    INSERT INTO #Feed (BranchId, ReconArea, Side, FirstDate, LastDate, [Rows])
    SELECT d.BranchId, 'FNB', 'PumpIT', MIN(CONVERT(date, d.TransactionDate)), MAX(CONVERT(date, d.TransactionDate)), COUNT(*)
    FROM agora.vw_DailyBankingFNB d
    JOIN #Site s ON s.BranchId = d.BranchId
    WHERE d.TransactionDate < @To
    GROUP BY d.BranchId;

    INSERT INTO #Feed (BranchId, ReconArea, Side, FirstDate, LastDate, [Rows])
    SELECT d.BranchId, 'CashMachine', 'PumpIT', MIN(CONVERT(date, d.TransactionDate)), MAX(CONVERT(date, d.TransactionDate)), COUNT(*)
    FROM agora.vw_DailyBankingDeposita d
    JOIN #Site s ON s.BranchId = d.BranchId
    WHERE d.TransactionDate < @To
    GROUP BY d.BranchId;

    INSERT INTO #Feed (BranchId, ReconArea, Side, FirstDate, LastDate, [Rows])
    SELECT d.BranchId, 'CashBags', 'PumpIT', MIN(CONVERT(date, d.TransactionDate)), MAX(CONVERT(date, d.TransactionDate)), COUNT(*)
    FROM agora.vw_DailyBankingCashBags d
    JOIN #Site s ON s.BranchId = d.BranchId
    WHERE d.TransactionDate < @To
    GROUP BY d.BranchId;

    INSERT INTO #Feed (BranchId, ReconArea, Side, FirstDate, LastDate, [Rows])
    SELECT x.BranchId, 'SmartATM', 'PumpIT', MIN(CONVERT(date, x.TxnDate)), MAX(CONVERT(date, x.TxnDate)), COUNT(*)
    FROM (
        SELECT d.BranchId, ISNULL(a.DepositDateTime, d.DepositDateTime) AS TxnDate
        FROM agora.vw_DailyBankingSmartATM d
        JOIN #Site s ON s.BranchId = d.BranchId
        LEFT JOIN agora.vw_SmartATM a
               ON a.BranchId = d.BranchId AND a.TerminalId = d.TerminalId
              AND a.TraceNo  = d.TraceNo  AND a.UniqueNo   = d.UniqueNo
    ) x
    WHERE x.TxnDate < @To
    GROUP BY x.BranchId;

    /* Set 1. */
    SELECT f.BranchId, f.ReconArea, f.Side, f.FirstDate, f.LastDate, f.[Rows]
    FROM #Feed f
    ORDER BY f.BranchId, f.ReconArea, f.Side;

    /* Set 2. Every site in scope × every area, so 0 is said rather than
       implied by a missing row. */
    SELECT s.BranchId, a.ReconArea, COUNT(c.BranchId) AS Rules
    FROM #Site s
    CROSS JOIN (VALUES (N'ABSA'), (N'FNB'), (N'CashMachine'), (N'CashBags'), (N'SmartATM')) a (ReconArea)
    LEFT JOIN agora.vw_AutoReconCriteria c
           ON c.BranchId = s.BranchId AND c.BankReconArea = a.ReconArea
    GROUP BY s.BranchId, a.ReconArea
    ORDER BY s.BranchId, a.ReconArea;

    DROP TABLE #Feed;
    DROP TABLE #Site;
END
