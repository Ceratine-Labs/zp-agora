/* ============================================================================
 * agora.usp_Assets_Depreciate — straight-line depreciation, monthly, by
 * asset group: what each asset has depreciated by as at a date, what this
 * month's charge is, and — for one asset — the schedule to the end of its life.
 *
 * Read by:  an asset's page (its schedule) and the register's "Depreciation
 *           by group" panel (T057).
 * Reads:    agora.fn_Assets_Depreciation — the ONE place the arithmetic
 *           lives; this procedure asks it questions and adds nothing to the
 *           formula — agora.Asset, agora.vw_AssetGroup, agora.Branch.
 * Writes:   NOTHING. There is no general ledger in Agora v1 (plan §3.16), so
 *           a depreciation run posts nowhere; it is an answer, recomputed
 *           from cost, purchase date and the group's period whenever it is
 *           asked. Changing a period in the config restates every asset in
 *           that group, as at every date, which is what "provisional" means.
 *
 * @RatesJson  {"BE": {"life_months": 72, "residual_pct": 0}, ...} — the SARS
 *             wear-and-tear mapping in Modules/Assets/Config/config.php,
 *             PROVISIONAL (Ryan, 27 Sep 2026) until ZP confirm their policy.
 * @AsAt       valued to the last month end on or before it. Default today.
 *
 * Result sets:
 *   1. Per asset in scope (or the one asset): cost, life, months charged,
 *      accumulated, NBV, and the charge for the last month end on or before
 *      @AsAt (as at 27 September, August's).
 *   2. Per group: the same, added up — the monthly depreciation charge by
 *      group, which is what an accountant asks for.
 *   3. When @AssetId is given: the schedule, one row per month end from the
 *      month of purchase to the end of the life — each row the function asked
 *      at that month end, so the schedule and the register cannot disagree.
 *      The charges add up to exactly the depreciable amount.
 *
 *     EXEC agora.usp_Assets_Depreciate @AssetBranchId = 8, @AssetId = 12,
 *          @RatesJson = N'{"BE":{"life_months":72}}';
 * ============================================================================ */

CREATE OR ALTER PROCEDURE [agora].[usp_Assets_Depreciate]
    @BranchIds        NVARCHAR(MAX) = NULL,
    @AsAt             DATE          = NULL,
    @RatesJson        NVARCHAR(MAX) = NULL,
    @AssetBranchId    INT           = NULL,
    @AssetId          BIGINT        = NULL,
    @AllowedBranchIds NVARCHAR(MAX) = NULL
AS
BEGIN
    SET NOCOUNT ON;

    SET @AsAt = ISNULL(@AsAt, CONVERT(date, GETDATE()));

    DECLARE @Branch  TABLE (BranchId int PRIMARY KEY);
    DECLARE @Allowed TABLE (BranchId int PRIMARY KEY);

    INSERT INTO @Branch (BranchId)
    SELECT DISTINCT TRY_CONVERT(int, LTRIM(RTRIM(s.value)))
    FROM STRING_SPLIT(ISNULL(@BranchIds, ''), ',') s
    WHERE LTRIM(RTRIM(s.value)) <> ''   -- TRY_CONVERT(int, '') is 0, not NULL
      AND TRY_CONVERT(int, LTRIM(RTRIM(s.value))) IS NOT NULL;

    INSERT INTO @Allowed (BranchId)
    SELECT DISTINCT TRY_CONVERT(int, LTRIM(RTRIM(s.value)))
    FROM STRING_SPLIT(ISNULL(@AllowedBranchIds, ''), ',') s
    WHERE LTRIM(RTRIM(s.value)) <> ''
      AND TRY_CONVERT(int, LTRIM(RTRIM(s.value))) IS NOT NULL;

    DECLARE @AllBranches bit = CASE WHEN EXISTS (SELECT 1 FROM @Branch)  THEN 0 ELSE 1 END;
    DECLARE @AllAllowed  bit = CASE WHEN EXISTS (SELECT 1 FROM @Allowed) THEN 0 ELSE 1 END;

    DECLARE @Assets TABLE (
        BranchId int, AssetId bigint, AssetNo nvarchar(30), AssetGroupCode nvarchar(10),
        AssetGroupDescription nvarchar(100), BranchName nvarchar(200), LocatedAt nvarchar(200),
        Cost decimal(18,2), DatePurchased date, DisposedOn date, LifeMonths int, MonthsCharged int,
        MonthlyCharge decimal(18,2), Accumulated decimal(18,2), ChargeForMonth decimal(18,2),
        Nbv decimal(18,2), NbvAtDisposal decimal(18,2), ProfitOnDisposal decimal(18,2),
        DepreciationState varchar(20), AsAtMonthEnd date);

    INSERT INTO @Assets
    SELECT d.BranchId, d.AssetId, a.AssetNo, a.AssetGroupCode, g.AssetGroupDescription,
           ob.Name, lb.Name,
           d.Cost, d.DatePurchased, d.DisposedOn, d.LifeMonths, d.MonthsCharged,
           d.MonthlyCharge, d.Accumulated, d.ChargeForMonth, d.Nbv, d.NbvAtDisposal, d.ProfitOnDisposal,
           d.DepreciationState, d.AsAtMonthEnd
    FROM agora.fn_Assets_Depreciation(@RatesJson, @AsAt) d
    JOIN agora.Asset a ON a.BranchId = d.BranchId AND a.Id = d.AssetId
    LEFT JOIN agora.vw_AssetGroup g ON g.AssetGroupCode = a.AssetGroupCode
    LEFT JOIN agora.Branch ob ON ob.BranchId = a.BranchId
    LEFT JOIN agora.Branch lb ON lb.BranchId = a.LocatedBranchId
    WHERE (@AssetId IS NOT NULL AND d.BranchId = @AssetBranchId AND d.AssetId = @AssetId)
       OR (@AssetId IS NULL
           AND EXISTS (SELECT 1 FROM (VALUES (a.BranchId), (a.LocatedBranchId)) s (b)
                        WHERE (@AllBranches = 1 OR s.b IN (SELECT BranchId FROM @Branch))
                          AND (@AllAllowed  = 1 OR s.b IN (SELECT BranchId FROM @Allowed))));

    /* 1. Per asset. */
    SELECT *
    FROM @Assets
    ORDER BY BranchName, AssetGroupCode, AssetNo;

    /* 2. Per group, as at the last month end on or before @AsAt — the month
          whose charge ChargeForMonth is. */
    SELECT x.AssetGroupCode,
           x.AssetGroupDescription,
           COUNT(*)                                                         AS Assets,
           SUM(CASE WHEN x.DepreciationState <> 'STOPPED' THEN 1 ELSE 0 END) AS OnRegister,
           MAX(x.LifeMonths)                                                AS LifeMonths,
           SUM(CASE WHEN x.DepreciationState <> 'STOPPED' THEN x.Cost END)  AS Cost,
           SUM(CASE WHEN x.DepreciationState <> 'STOPPED' THEN x.Accumulated END) AS Accumulated,
           SUM(x.Nbv)                                                       AS Nbv,
           SUM(x.ChargeForMonth)                                            AS ChargeForMonth,
           MAX(x.AsAtMonthEnd)                                              AS MonthEnd
    FROM @Assets x
    GROUP BY x.AssetGroupCode, x.AssetGroupDescription
    ORDER BY x.AssetGroupCode;

    /* 3. One asset's schedule: the function asked at every month end of its
          life. Nothing is recomputed here. */
    IF @AssetId IS NOT NULL
    BEGIN
        DECLARE @Purchased date, @Life int;

        SELECT @Purchased = x.DatePurchased, @Life = x.LifeMonths FROM @Assets x;

        IF @Life IS NOT NULL
        BEGIN
            ;WITH n AS (
                SELECT TOP (CASE WHEN @Life > 600 THEN 600 ELSE @Life END)
                       ROW_NUMBER() OVER (ORDER BY (SELECT NULL)) AS MonthNo
                FROM sys.all_objects o1 CROSS JOIN sys.all_objects o2
            )
            SELECT n.MonthNo,
                   EOMONTH(@Purchased, n.MonthNo - 1) AS MonthEnd,
                   d.ChargeForMonth                   AS Charge,
                   d.Accumulated,
                   d.Nbv,
                   d.DepreciationState
            FROM n
            CROSS APPLY agora.fn_Assets_Depreciation(@RatesJson, EOMONTH(@Purchased, n.MonthNo - 1)) d
            WHERE d.BranchId = @AssetBranchId AND d.AssetId = @AssetId
            ORDER BY n.MonthNo;
        END
        ELSE
            SELECT CONVERT(int, NULL) AS MonthNo, CONVERT(date, NULL) AS MonthEnd, CONVERT(decimal(18,2), NULL) AS Charge,
                   CONVERT(decimal(18,2), NULL) AS Accumulated, CONVERT(decimal(18,2), NULL) AS Nbv,
                   CONVERT(varchar(20), NULL) AS DepreciationState
            WHERE 1 = 0;
    END
END
