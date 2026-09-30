/* ============================================================================
 * agora.usp_Assets_Comebacks — which repairs did not hold, and whose they
 * were.
 *
 * Read by:  Asset repair -> "Comebacks by supplier" (T057), and the
 *           acceptance test that pins the rule.
 * Reads:    agora.vw_AssetRepairCall (THE RULE: a second call on the same
 *           asset within 30 days of the call before it), agora.AssetRepair,
 *           agora.vw_AssetRepairCost, agora.Asset, agora.vw_Supplier,
 *           agora.Branch.
 * Writes:   nothing.
 *
 * WHO A COMEBACK COUNTS AGAINST. The supplier on the PREVIOUS card — the one
 * whose repair did not hold — not whoever was called back. Usually the same
 * supplier; when a site gives up on one and calls another, it is still the
 * first one's comeback.
 *
 * Result sets:
 *   1. The comebacks: the card, the card before it, days between, both
 *      suppliers.
 *   2. By supplier: the calls they took in the window, the comebacks on their
 *      work, the rate, and what the approved requests behind their calls cost
 *      (each request counted once). Ordered worst rate first, because that is
 *      the question — "is this supplier worth keeping" (the mockup's own
 *      words) — and a supplier with one call and one comeback is flagged
 *      rather than hidden by a minimum.
 *
 * Scope as agora.usp_Assets_GridRepairs: the asset's site or the repair's
 * site, asked for (@BranchIds) and allowed (@AllowedBranchIds), by call date.
 * @SupplierCode narrows both sets to one supplier.
 *
 *     EXEC agora.usp_Assets_Comebacks @DateFrom = '2026-01-01', @DateTo = '2026-09-30';
 * ============================================================================ */

CREATE OR ALTER PROCEDURE [agora].[usp_Assets_Comebacks]
    @BranchIds        NVARCHAR(MAX) = NULL,
    @DateFrom         DATE          = NULL,
    @DateTo           DATE          = NULL,
    @SupplierCode     NVARCHAR(16)  = NULL,
    @AllowedBranchIds NVARCHAR(MAX) = NULL
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @Today date = CONVERT(date, GETDATE());
    SET @DateTo       = ISNULL(@DateTo, @Today);
    SET @DateFrom     = ISNULL(@DateFrom, DATEADD(DAY, -365, @DateTo));
    SET @SupplierCode = NULLIF(LTRIM(RTRIM(@SupplierCode)), '');

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

    /* Every live call in scope and in the window, with its comeback facts. */
    DECLARE @Calls TABLE (
        BranchId int, RepairId bigint, JobCardNo varchar(27), AssetId bigint, SiteBranchId int,
        CallDate date, SupplierCode nvarchar(16), TransactionNo nvarchar(30), RepairCost decimal(18,2),
        PrevJobCardNo varchar(27), PrevCallDate date, PrevSupplierCode nvarchar(16), PrevSiteBranchId int,
        DaysSincePrevious int, IsComeback bit);

    INSERT INTO @Calls
    SELECT k.BranchId, k.Id, k.JobCardNo, k.AssetId, k.SiteBranchId, k.CallDate, k.SupplierCode,
           c.TransactionNo, c.RepairCost,
           k.PrevJobCardNo, k.PrevCallDate, k.PrevSupplierCode, k.PrevSiteBranchId,
           k.DaysSincePrevious, k.IsComeback
    FROM agora.vw_AssetRepairCall k
    LEFT JOIN agora.vw_AssetRepairCost c ON c.BranchId = k.BranchId AND c.Id = k.Id
    WHERE k.CallDate BETWEEN @DateFrom AND @DateTo
      AND EXISTS (SELECT 1 FROM (VALUES (k.BranchId), (k.SiteBranchId)) s (b)
                   WHERE (@AllBranches = 1 OR s.b IN (SELECT BranchId FROM @Branch))
                     AND (@AllAllowed  = 1 OR s.b IN (SELECT BranchId FROM @Allowed)));

    /* 1. The comebacks. */
    SELECT c.BranchId,
           c.RepairId,
           c.JobCardNo,
           a.AssetNo,
           a.Make,
           a.Model,
           sb.Name                  AS SiteName,
           c.CallDate,
           c.SupplierCode,
           sp.SupplierName,
           c.PrevJobCardNo,
           c.PrevCallDate,
           c.PrevSupplierCode,
           pp.SupplierName          AS PrevSupplierName,
           c.DaysSincePrevious
    FROM @Calls c
    JOIN agora.Asset a ON a.BranchId = c.BranchId AND a.Id = c.AssetId
    LEFT JOIN agora.Branch sb ON sb.BranchId = c.SiteBranchId
    OUTER APPLY (SELECT TOP 1 s.SupplierName FROM agora.vw_Supplier s
                  WHERE s.BranchId = c.SiteBranchId AND s.SupplierCode = c.SupplierCode) sp
    OUTER APPLY (SELECT TOP 1 s.SupplierName FROM agora.vw_Supplier s
                  WHERE s.BranchId = c.PrevSiteBranchId AND s.SupplierCode = c.PrevSupplierCode) pp
    WHERE c.IsComeback = 1
      AND (@SupplierCode IS NULL OR c.PrevSupplierCode = @SupplierCode OR c.SupplierCode = @SupplierCode)
    ORDER BY c.CallDate DESC, c.RepairId DESC;

    /* 2. By supplier. A supplier appears if they took a call in the window or
          a comeback in the window lands on their earlier work. */
    ;WITH suppliers AS (
        SELECT SupplierCode FROM @Calls WHERE SupplierCode IS NOT NULL
        UNION
        SELECT PrevSupplierCode FROM @Calls WHERE IsComeback = 1 AND PrevSupplierCode IS NOT NULL
    ),
    tally AS (
        SELECT s.SupplierCode,
               (SELECT COUNT(*) FROM @Calls c WHERE c.SupplierCode = s.SupplierCode)                         AS Calls,
               (SELECT COUNT(*) FROM @Calls c WHERE c.IsComeback = 1 AND c.PrevSupplierCode = s.SupplierCode) AS Comebacks,
               (SELECT SUM(q.RepairCost)
                  FROM (SELECT DISTINCT c.SiteBranchId, c.TransactionNo, c.RepairCost
                          FROM @Calls c
                         WHERE c.SupplierCode = s.SupplierCode AND c.TransactionNo IS NOT NULL) q)             AS RepairCost,
               (SELECT MAX(c.CallDate) FROM @Calls c WHERE c.SupplierCode = s.SupplierCode)                  AS LastCall
        FROM suppliers s
        WHERE @SupplierCode IS NULL OR s.SupplierCode = @SupplierCode
    )
    SELECT t.SupplierCode,
           nm.SupplierName,
           t.Calls,
           t.Comebacks,
           CASE WHEN t.Calls > 0 THEN CONVERT(decimal(9,1), 100.0 * t.Comebacks / t.Calls) END AS ComebackPct,
           t.RepairCost,
           t.LastCall
    FROM tally t
    /* Supplier codes are per site; the name is the same at every site on live
       (one row per site, 31 each). TOP 1 so a name lookup cannot fan. */
    OUTER APPLY (SELECT TOP 1 s.SupplierName FROM agora.vw_Supplier s
                  WHERE s.SupplierCode = t.SupplierCode ORDER BY s.BranchId) nm
    ORDER BY CASE WHEN t.Calls > 0 THEN 1.0 * t.Comebacks / t.Calls ELSE 0 END DESC,
             t.Comebacks DESC, t.Calls DESC, t.SupplierCode;
END
