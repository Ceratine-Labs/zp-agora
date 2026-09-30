/* ============================================================================
 * agora.usp_Assets_GetRepair — one repair job card, with the call before it
 * and the purchase request that pays for it.
 *
 * Read by:  the job card's page (T057).
 * Reads:    agora.AssetRepair, agora.vw_AssetRepairCall (the comeback rule),
 *           agora.vw_AssetRepairCost, agora.Asset, agora.vw_AssetType,
 *           agora.vw_Supplier, agora.Branch, agora.[User], and the customer's
 *           purchase request lines (PumpIT.dbo.BRN_TransactionLine, through
 *           agora.vw_PurchaseRequestLine) — read-only.
 * Writes:   nothing.
 *
 * Returns nothing when the card is not on @BranchId's register or the caller
 * may see neither the asset's site nor the repair's (@AllowedBranchIds).
 *
 * Result sets:
 *   1. The card (one row, or none).
 *   2. The purchase request's lines, when one is attached and found — so the
 *      person looking at the cost can see what it is made of: call-out,
 *      labour, gas, parts.
 * ============================================================================ */

CREATE OR ALTER PROCEDURE [agora].[usp_Assets_GetRepair]
    @BranchId         INT,
    @RepairId         BIGINT,
    @AllowedBranchIds NVARCHAR(MAX) = NULL
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @Today date = CONVERT(date, GETDATE());

    DECLARE @Allowed TABLE (BranchId int PRIMARY KEY);
    INSERT INTO @Allowed (BranchId)
    SELECT DISTINCT TRY_CONVERT(int, LTRIM(RTRIM(s.value)))
    FROM STRING_SPLIT(ISNULL(@AllowedBranchIds, ''), ',') s
    WHERE LTRIM(RTRIM(s.value)) <> ''   -- TRY_CONVERT(int, '') is 0, not NULL
      AND TRY_CONVERT(int, LTRIM(RTRIM(s.value))) IS NOT NULL;

    DECLARE @AllAllowed bit = CASE WHEN EXISTS (SELECT 1 FROM @Allowed) THEN 0 ELSE 1 END;

    DECLARE @Site int, @TransactionNo nvarchar(30), @Visible bit = 0;

    SELECT @Site = r.SiteBranchId, @TransactionNo = r.TransactionNo,
           @Visible = CASE WHEN @AllAllowed = 1
                             OR r.BranchId IN (SELECT BranchId FROM @Allowed)
                             OR r.SiteBranchId IN (SELECT BranchId FROM @Allowed) THEN 1 ELSE 0 END
    FROM agora.AssetRepair r
    WHERE r.BranchId = @BranchId AND r.Id = @RepairId;

    SELECT r.BranchId,
           r.Id                                   AS RepairId,
           r.JobCardNo,
           r.AssetId,
           a.AssetNo,
           a.Make,
           a.Model,
           a.SerialNo,
           t.AssetTypeDescription,
           ob.Name                                AS BranchName,
           r.SiteBranchId,
           sb.Name                                AS SiteName,
           r.CallDate,
           r.Problem,
           r.SupplierCode,
           sp.SupplierName,
           r.SupplierReference,
           r.TransactionNo,
           c.TypeCode,
           c.RequestState,
           c.RequestDate,
           c.RequestAmount,
           c.RequestSupplierCode,
           c.RepairCost,
           c.SharedBy,
           r.DateExpectedReturn,
           r.DateActualReturn,
           CASE WHEN r.IsCancelled = 0
                THEN DATEDIFF(DAY, r.CallDate, ISNULL(r.DateActualReturn, @Today)) END AS DaysOut,
           r.IsCancelled,
           r.CancelledAt,
           r.CancelReason,
           xu.UserName                            AS CancelledByName,
           k.PrevRepairId,
           k.PrevJobCardNo,
           k.PrevCallDate,
           k.PrevSupplierCode,
           pp.SupplierName                        AS PrevSupplierName,
           k.DaysSincePrevious,
           k.WindowDays,
           ISNULL(k.IsComeback, CONVERT(bit, 0))  AS IsComeback,
           r.CreatedAt,
           cu.UserName                            AS CreatedByName,
           r.UpdatedAt,
           uu.UserName                            AS UpdatedByName
    FROM agora.AssetRepair r
    JOIN agora.Asset a ON a.BranchId = r.BranchId AND a.Id = r.AssetId
    LEFT JOIN agora.vw_AssetRepairCall k ON k.BranchId = r.BranchId AND k.Id = r.Id
    LEFT JOIN agora.vw_AssetRepairCost c ON c.BranchId = r.BranchId AND c.Id = r.Id
    LEFT JOIN agora.vw_AssetType t ON t.AssetGroupCode = a.AssetGroupCode AND t.AssetTypeCode = a.AssetTypeCode
    LEFT JOIN agora.Branch ob ON ob.BranchId = r.BranchId
    LEFT JOIN agora.Branch sb ON sb.BranchId = r.SiteBranchId
    OUTER APPLY (SELECT TOP 1 s.SupplierName FROM agora.vw_Supplier s
                  WHERE s.BranchId = r.SiteBranchId AND s.SupplierCode = r.SupplierCode) sp
    OUTER APPLY (SELECT TOP 1 s.SupplierName FROM agora.vw_Supplier s
                  WHERE s.BranchId = k.PrevSiteBranchId AND s.SupplierCode = k.PrevSupplierCode) pp
    OUTER APPLY (SELECT TOP 1 u.UserName FROM agora.[User] u WHERE u.Id = r.CreatedBy) cu
    OUTER APPLY (SELECT TOP 1 u.UserName FROM agora.[User] u WHERE u.Id = r.UpdatedBy) uu
    OUTER APPLY (SELECT TOP 1 u.UserName FROM agora.[User] u WHERE u.Id = r.CancelledBy) xu
    WHERE @Visible = 1 AND r.BranchId = @BranchId AND r.Id = @RepairId;

    SELECT l.ItemNo,
           l.[Description],
           l.Justification,
           l.PurchaseAmount,
           l.InvoiceNo,
           l.InvoiceDate
    FROM agora.vw_PurchaseRequestLine l
    WHERE @Visible = 1 AND @TransactionNo IS NOT NULL
      AND l.BranchId = @Site AND l.TransactionNo = @TransactionNo
    ORDER BY l.ItemNo;
END
