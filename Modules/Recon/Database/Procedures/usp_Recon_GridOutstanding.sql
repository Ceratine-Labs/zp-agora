/*
 * agora.usp_Recon_GridOutstanding — the outstanding rows behind the bank recon
 * dashboard, a page at a time.
 *
 * Read by:  Control -> Money -> Bank recon -> Dashboard, the Items grid.
 * Reads:    the same views as agora.usp_Recon_DashboardOutstanding, with the
 *           same predicates — a figure on the dashboard and the rows here are
 *           one definition of outstanding, not two.
 * Writes:   nothing.
 *
 * The grid procedure template (feature-rules §2), plus three arguments the
 * nine have no room for:
 *
 *   @ReconArea         one area, 'Unclassified' (the bank lines no area can
 *                      match), or NULL for every classified area.
 *   @Side              'Bank', 'PumpIT', or NULL for both.
 *   @AllowedBranchIds  every site this person may see at all; see
 *                      usp_Recon_GridRuns for why that is not @BranchIds.
 *   @AsAt              the business date ages are counted to (South African;
 *                      passed in, never the server's UTC clock).
 *
 * This is the answer to "Excel struggles with it": the rows stay on the
 * server, a page reaches the browser, and the export re-calls this procedure
 * with the same arguments (feature-rules §3.2).
 *
 * Result set 1: the page. Result set 2: TotalRows. Result set 3: the Amount
 * over the whole filtered set.
 */
CREATE OR ALTER PROCEDURE [agora].[usp_Recon_GridOutstanding]
    @BranchIds        NVARCHAR(MAX) = NULL,
    @DateFrom         DATE          = NULL,
    @DateTo           DATE          = NULL,
    @Search           NVARCHAR(200) = NULL,
    @SortColumn       NVARCHAR(80)  = NULL,
    @SortAsc          BIT           = 1,
    @Page             INT           = 1,
    @PageSize         INT           = 50,
    @FiltersJson      NVARCHAR(MAX) = NULL,
    @ReconArea        NVARCHAR(20)  = NULL,
    @Side             NVARCHAR(10)  = NULL,
    @AllowedBranchIds NVARCHAR(MAX) = NULL,
    @AsAt             DATE          = NULL
AS
BEGIN
    SET NOCOUNT ON;
    /* Its only writes are to its own #temp tables; set because check-procs
       counts those, and an aborted read should leave nothing half-built. */
    SET XACT_ABORT ON;

    SET @Page     = CASE WHEN ISNULL(@Page, 1) < 1 THEN 1 ELSE @Page END;
    SET @PageSize = CASE WHEN ISNULL(@PageSize, 50) BETWEEN 1 AND 100000 THEN @PageSize ELSE 50 END;
    SET @Search   = NULLIF(LTRIM(RTRIM(ISNULL(@Search, ''))), '');
    SET @AsAt     = ISNULL(@AsAt, CONVERT(date, SYSUTCDATETIME()));
    SET @ReconArea = NULLIF(LTRIM(RTRIM(ISNULL(@ReconArea, ''))), '');
    SET @Side      = NULLIF(LTRIM(RTRIM(ISNULL(@Side, ''))), '');

    DECLARE @Offset INT = (@Page - 1) * @PageSize;
    DECLARE @From datetime = ISNULL(CONVERT(datetime, @DateFrom), '19000101'),
            @To   datetime = DATEADD(day, 1, CONVERT(datetime, ISNULL(@DateTo, @AsAt)));

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

    CREATE TABLE #Site (BranchId int PRIMARY KEY, BranchName nvarchar(200) COLLATE DATABASE_DEFAULT NULL);

    INSERT INTO #Site (BranchId, BranchName)
    SELECT b.BranchId, b.Name
    FROM agora.Branch b
    WHERE b.DeletedAt IS NULL
      AND (@AllBranches = 1 OR b.BranchId IN (SELECT BranchId FROM @Branch))
      AND (@AllAllowed  = 1 OR b.BranchId IN (SELECT BranchId FROM @Allowed));

    /* The header filters, in the shape the grid sends: a JSON array, the
       column inside each element (see usp_Recon_GridRuns). */
    DECLARE @Filter TABLE ([Column] nvarchar(80), [Op] nvarchar(20), [Value] nvarchar(400));
    DECLARE @FilterSet TABLE ([Column] nvarchar(80), [Value] nvarchar(400));

    IF @FiltersJson IS NOT NULL
    BEGIN
        INSERT INTO @Filter ([Column], [Op], [Value])
        SELECT JSON_VALUE(f.value, '$.column'), ISNULL(JSON_VALUE(f.value, '$.op'), 'contains'),
               JSON_VALUE(f.value, '$.value')
        FROM OPENJSON(@FiltersJson) f
        WHERE JSON_VALUE(f.value, '$.value') IS NOT NULL;

        INSERT INTO @FilterSet ([Column], [Value])
        SELECT JSON_VALUE(f.value, '$.column'), v.value
        FROM OPENJSON(@FiltersJson) f
        CROSS APPLY OPENJSON(f.value, '$.in') v
        WHERE JSON_VALUE(f.value, '$.type') = 'set';
    END

    DECLARE @fRef    nvarchar(400) = (SELECT TOP 1 [Value] FROM @Filter WHERE [Column] = 'Reference'),
            @fDetail nvarchar(400) = (SELECT TOP 1 [Value] FROM @Filter WHERE [Column] = 'Detail'),
            @fSite   nvarchar(400) = (SELECT TOP 1 [Value] FROM @Filter WHERE [Column] = 'BranchName'),
            @fAreaAny bit = CASE WHEN EXISTS (SELECT 1 FROM @FilterSet WHERE [Column] = 'ReconArea') THEN 1 ELSE 0 END,
            @fSideAny bit = CASE WHEN EXISTS (SELECT 1 FROM @FilterSet WHERE [Column] = 'Side') THEN 1 ELSE 0 END;

    CREATE TABLE #Rows (
        BranchId  int            NOT NULL,
        ReconArea nvarchar(20) COLLATE DATABASE_DEFAULT   NOT NULL,
        Side      nvarchar(10) COLLATE DATABASE_DEFAULT   NOT NULL,
        SourceId  bigint         NULL,
        TxnDate   datetime       NOT NULL,
        Reference nvarchar(100) COLLATE DATABASE_DEFAULT  NULL,
        Detail    nvarchar(400) COLLATE DATABASE_DEFAULT  NULL,
        Amount    money          NOT NULL
    );

    DECLARE @WantBank   bit = CASE WHEN @Side IS NULL OR @Side = 'Bank'   THEN 1 ELSE 0 END,
            @WantPumpIT bit = CASE WHEN @Side IS NULL OR @Side = 'PumpIT' THEN 1 ELSE 0 END;

    IF @WantBank = 1
        INSERT INTO #Rows (BranchId, ReconArea, Side, SourceId, TxnDate, Reference, Detail, Amount)
        SELECT x.BranchId, x.ReconArea, 'Bank', x.BankStatementLineID, x.LineDate,
               CONVERT(nvarchar(100), x.BankStatementLineID), LEFT(LTRIM(RTRIM(x.Description)), 400), ISNULL(x.Amount, 0)
        FROM (
            SELECT l.*, CASE WHEN l.IDState = 1 THEN 'Unclassified'
                             WHEN l.Type = 'CashDeposit' THEN 'CashBags' ELSE l.Type END AS ReconArea
            FROM agora.vw_BankStatementLine l
            JOIN #Site s ON s.BranchId = l.BranchId
            WHERE l.ReconState = 1
              AND (l.IDState = 1
                   OR (l.IDState = 2 AND l.Type IN ('ABSA', 'FNB', 'CashMachine', 'SmartATM', 'CashDeposit')))
              AND l.LineDate >= @From AND l.LineDate < @To
        ) x
        /* No area is every CLASSIFIED area, as the dashboard's headline figures
           are; the unclassified lines are asked for by name (86,586 on live back
           to 2015 — mixed in, they would bury everything else). */
        WHERE (@ReconArea IS NULL AND x.ReconArea <> 'Unclassified') OR x.ReconArea = @ReconArea;

    IF @WantPumpIT = 1 AND (@ReconArea IS NULL OR @ReconArea = 'ABSA')
        INSERT INTO #Rows (BranchId, ReconArea, Side, SourceId, TxnDate, Reference, Detail, Amount)
        SELECT d.BranchId, 'ABSA', 'PumpIT', NULL, d.TransactionDate,
               LTRIM(RTRIM(CONVERT(nvarchar(50), d.BatchNumber))),
               'Merchant ' + LTRIM(RTRIM(d.MerchantNumber)), ISNULL(d.TransactionAmount, 0)
        FROM agora.vw_DailyBankingABSA d
        JOIN #Site s ON s.BranchId = d.BranchId
        WHERE d.ReconBatchNoPumpIT = 0 AND d.TransactionDate >= @From AND d.TransactionDate < @To;

    IF @WantPumpIT = 1 AND (@ReconArea IS NULL OR @ReconArea = 'FNB')
        INSERT INTO #Rows (BranchId, ReconArea, Side, SourceId, TxnDate, Reference, Detail, Amount)
        SELECT d.BranchId, 'FNB', 'PumpIT', NULL, d.TransactionDate,
               LTRIM(RTRIM(d.BatchNo)), 'Merchant ' + LTRIM(RTRIM(d.MerchantNo)), ISNULL(d.Amount, 0)
        FROM agora.vw_DailyBankingFNB d
        JOIN #Site s ON s.BranchId = d.BranchId
        WHERE d.ReconBatchNoPumpIT = 0 AND d.TransactionDate >= @From AND d.TransactionDate < @To;

    IF @WantPumpIT = 1 AND (@ReconArea IS NULL OR @ReconArea = 'CashMachine')
        INSERT INTO #Rows (BranchId, ReconArea, Side, SourceId, TxnDate, Reference, Detail, Amount)
        SELECT d.BranchId, 'CashMachine', 'PumpIT', NULL, d.TransactionDate,
               LTRIM(RTRIM(d.SlipNo)), 'Deposita slip', ISNULL(d.DepositaAmount, 0)
        FROM agora.vw_DailyBankingDeposita d
        JOIN #Site s ON s.BranchId = d.BranchId
        WHERE d.ReconBatchNoPumpIT = 0 AND d.TransactionDate >= @From AND d.TransactionDate < @To;

    IF @WantPumpIT = 1 AND (@ReconArea IS NULL OR @ReconArea = 'CashBags')
        INSERT INTO #Rows (BranchId, ReconArea, Side, SourceId, TxnDate, Reference, Detail, Amount)
        SELECT d.BranchId, 'CashBags', 'PumpIT', d.DailyBankingCashBagID, d.TransactionDate,
               LTRIM(RTRIM(d.CashBagNo)), 'Cash bag', ISNULL(d.CashBagAmount, 0)
        FROM agora.vw_DailyBankingCashBags d
        JOIN #Site s ON s.BranchId = d.BranchId
        WHERE d.ReconBatchNoPumpIT = 0 AND d.TransactionDate >= @From AND d.TransactionDate < @To;

    IF @WantPumpIT = 1 AND (@ReconArea IS NULL OR @ReconArea = 'SmartATM')
        INSERT INTO #Rows (BranchId, ReconArea, Side, SourceId, TxnDate, Reference, Detail, Amount)
        SELECT x.BranchId, 'SmartATM', 'PumpIT', NULL, x.TxnDate, x.Reference, x.Detail, x.Amount
        FROM (
            SELECT d.BranchId, ISNULL(a.DepositDateTime, d.DepositDateTime) AS TxnDate,
                   LTRIM(RTRIM(CONVERT(nvarchar(50), d.TerminalId))) AS Reference,
                   'Trace ' + LTRIM(RTRIM(CONVERT(nvarchar(50), d.TraceNo))) AS Detail,
                   ISNULL(d.Deposited, 0) AS Amount
            FROM agora.vw_DailyBankingSmartATM d
            JOIN #Site s ON s.BranchId = d.BranchId
            LEFT JOIN agora.vw_SmartATM a
                   ON a.BranchId = d.BranchId AND a.TerminalId = d.TerminalId
                  AND a.TraceNo  = d.TraceNo  AND a.UniqueNo   = d.UniqueNo
            WHERE d.ReconBatchNoPumpIT = 0
        ) x
        WHERE x.TxnDate >= @From AND x.TxnDate < @To;

    SELECT r.BranchId, s.BranchName, r.ReconArea, r.Side, r.SourceId,
           CONVERT(date, r.TxnDate) AS TxnDate, r.Reference, r.Detail, r.Amount,
           CASE WHEN DATEDIFF(day, r.TxnDate, @AsAt) < 0 THEN 0 ELSE DATEDIFF(day, r.TxnDate, @AsAt) END AS AgeDays
    INTO #Page
    FROM #Rows r
    JOIN #Site s ON s.BranchId = r.BranchId
    WHERE (@Search IS NULL
           OR r.Reference LIKE '%' + @Search + '%'
           OR r.Detail    LIKE '%' + @Search + '%'
           OR s.BranchName LIKE '%' + @Search + '%')
      AND (@fRef    IS NULL OR r.Reference  LIKE '%' + @fRef    + '%')
      AND (@fDetail IS NULL OR r.Detail     LIKE '%' + @fDetail + '%')
      AND (@fSite   IS NULL OR s.BranchName LIKE '%' + @fSite   + '%')
      AND (@fAreaAny = 0 OR r.ReconArea IN (SELECT [Value] FROM @FilterSet WHERE [Column] = 'ReconArea'))
      AND (@fSideAny = 0 OR r.Side      IN (SELECT [Value] FROM @FilterSet WHERE [Column] = 'Side'));

    SELECT BranchId, BranchName, ReconArea, Side, SourceId, TxnDate, Reference, Detail, Amount, AgeDays
    FROM #Page
    ORDER BY
        CASE WHEN @SortAsc = 1 THEN
            CASE @SortColumn WHEN 'BranchName' THEN BranchName WHEN 'ReconArea' THEN ReconArea
                             WHEN 'Side' THEN Side WHEN 'Reference' THEN Reference WHEN 'Detail' THEN Detail END
        END ASC,
        CASE WHEN @SortAsc = 0 THEN
            CASE @SortColumn WHEN 'BranchName' THEN BranchName WHEN 'ReconArea' THEN ReconArea
                             WHEN 'Side' THEN Side WHEN 'Reference' THEN Reference WHEN 'Detail' THEN Detail END
        END DESC,
        CASE WHEN @SortAsc = 1 AND @SortColumn = 'Amount'  THEN Amount END ASC,
        CASE WHEN @SortAsc = 0 AND @SortColumn = 'Amount'  THEN Amount END DESC,
        CASE WHEN @SortAsc = 1 AND @SortColumn = 'AgeDays' THEN AgeDays END ASC,
        CASE WHEN @SortAsc = 0 AND @SortColumn = 'AgeDays' THEN AgeDays END DESC,
        CASE WHEN @SortAsc = 1 AND @SortColumn = 'TxnDate' THEN TxnDate END ASC,
        CASE WHEN @SortAsc = 0 AND @SortColumn = 'TxnDate' THEN TxnDate END DESC,
        /* Oldest first by default: the oldest item is the one to chase. */
        TxnDate ASC, BranchName ASC, ReconArea ASC, Side ASC, Reference ASC
    OFFSET @Offset ROWS FETCH NEXT @PageSize ROWS ONLY;

    SELECT COUNT_BIG(*) AS TotalRows FROM #Page;

    /* Result set 3: the grand total over the whole filtered set, keyed by
       column (ProcedureSource::grandTotals), so the footer is not one page's. */
    SELECT ISNULL(SUM(Amount), 0) AS Amount FROM #Page;

    DROP TABLE #Page;
    DROP TABLE #Rows;
    DROP TABLE #Site;
END
