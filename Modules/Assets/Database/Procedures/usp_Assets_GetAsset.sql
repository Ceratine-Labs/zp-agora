/* ============================================================================
 * agora.usp_Assets_GetAsset — one asset, whole: what it is, where it is, what
 * it is worth, everything that has happened to it.
 *
 * Read by:  the asset's page (T057).
 * Reads:    agora.Asset, agora.fn_Assets_Depreciation, agora.AssetMovement,
 *           agora.AssetRepair, agora.vw_AssetRepairCall, vw_AssetRepairCost,
 *           the customer's masters through agora.vw_Asset*, agora.vw_Supplier,
 *           agora.Branch, agora.[User].
 * Writes:   nothing.
 *
 * Returns nothing at all when the asset is not on @BranchId's register or the
 * caller may see neither the site it belongs to nor the site it is at
 * (@AllowedBranchIds; empty = all) — the page treats that as not found rather
 * than confirming the asset exists.
 *
 * Result sets:
 *   1. The asset (one row, or none).
 *   2. Its movements, newest first, reversed ones included and marked.
 *   3. Its job cards, newest first, with the comeback flag and the cost.
 * The depreciation schedule is agora.usp_Assets_Depreciate's.
 * ============================================================================ */

CREATE OR ALTER PROCEDURE [agora].[usp_Assets_GetAsset]
    @BranchId         INT,
    @AssetId          BIGINT,
    @RatesJson        NVARCHAR(MAX) = NULL,
    @AsAt             DATE          = NULL,
    @AllowedBranchIds NVARCHAR(MAX) = NULL
AS
BEGIN
    SET NOCOUNT ON;

    SET @AsAt = ISNULL(@AsAt, CONVERT(date, GETDATE()));

    DECLARE @Allowed TABLE (BranchId int PRIMARY KEY);
    INSERT INTO @Allowed (BranchId)
    SELECT DISTINCT TRY_CONVERT(int, LTRIM(RTRIM(s.value)))
    FROM STRING_SPLIT(ISNULL(@AllowedBranchIds, ''), ',') s
    WHERE LTRIM(RTRIM(s.value)) <> ''   -- TRY_CONVERT(int, '') is 0, not NULL
      AND TRY_CONVERT(int, LTRIM(RTRIM(s.value))) IS NOT NULL;

    DECLARE @AllAllowed bit = CASE WHEN EXISTS (SELECT 1 FROM @Allowed) THEN 0 ELSE 1 END;

    DECLARE @Visible bit = CASE WHEN EXISTS (
        SELECT 1 FROM agora.Asset a
         WHERE a.BranchId = @BranchId AND a.Id = @AssetId AND a.DeletedAt IS NULL
           AND (@AllAllowed = 1
                OR a.BranchId IN (SELECT BranchId FROM @Allowed)
                OR a.LocatedBranchId IN (SELECT BranchId FROM @Allowed))) THEN 1 ELSE 0 END;

    /* 1. The asset. */
    SELECT a.BranchId,
           a.Id                                   AS AssetId,
           a.AssetNo,
           a.AssetGroupCode,
           g.AssetGroupDescription,
           a.AssetTypeCode,
           t.AssetTypeDescription,
           a.Make,
           a.Model,
           a.SerialNo,
           a.[Description],
           ob.Name                                AS BranchName,
           a.LocatedBranchId,
           lb.Name                                AS LocatedAt,
           a.AssetOwner,
           a.SupplierCode,
           sp.SupplierName,
           a.DatePurchased,
           a.DateAllocated,
           a.Cost,
           a.Condition                            AS ConditionCode,
           c.Label                                AS Condition,
           a.[Status]                             AS StatusCode,
           CASE a.[Status] WHEN 'IN_SERVICE'  THEN 'In service'
                           WHEN 'AT_REPAIR'   THEN 'At repair'
                           WHEN 'DISPOSED'    THEN 'Disposed of'
                           WHEN 'WRITTEN_OFF' THEN 'Written off'
                           ELSE a.[Status] END    AS [Status],
           a.DisposedOn,
           a.DisposalProceeds,
           a.Reason,
           d.LifeMonths,
           d.MonthsCharged,
           d.MonthlyCharge,
           d.Accumulated,
           d.Nbv,
           d.ChargeForMonth,
           d.NbvAtDisposal,
           d.ProfitOnDisposal,
           d.DepreciationState,
           d.AsAtMonthEnd,
           a.CreatedAt,
           cu.UserName                            AS CreatedByName,
           a.UpdatedAt,
           uu.UserName                            AS UpdatedByName,
           CASE WHEN EXISTS (SELECT 1 FROM agora.AssetMovement m WHERE m.BranchId = a.BranchId AND m.AssetId = a.Id)
                  OR EXISTS (SELECT 1 FROM agora.AssetRepair r WHERE r.BranchId = a.BranchId AND r.AssetId = a.Id)
                THEN CONVERT(bit, 1) ELSE CONVERT(bit, 0) END AS HasHistory
    FROM agora.Asset a
    JOIN agora.fn_Assets_Depreciation(@RatesJson, @AsAt) d ON d.BranchId = a.BranchId AND d.AssetId = a.Id
    LEFT JOIN agora.vw_AssetGroup g ON g.AssetGroupCode = a.AssetGroupCode
    LEFT JOIN agora.vw_AssetType t ON t.AssetGroupCode = a.AssetGroupCode AND t.AssetTypeCode = a.AssetTypeCode
    LEFT JOIN agora.vw_AssetCondition c ON c.Condition = a.Condition
    LEFT JOIN agora.Branch ob ON ob.BranchId = a.BranchId
    LEFT JOIN agora.Branch lb ON lb.BranchId = a.LocatedBranchId
    OUTER APPLY (SELECT TOP 1 s.SupplierName FROM agora.vw_Supplier s
                  WHERE s.BranchId = a.BranchId AND s.SupplierCode = a.SupplierCode) sp
    OUTER APPLY (SELECT TOP 1 u.UserName FROM agora.[User] u WHERE u.Id = a.CreatedBy) cu
    OUTER APPLY (SELECT TOP 1 u.UserName FROM agora.[User] u WHERE u.Id = a.UpdatedBy) uu
    WHERE @Visible = 1 AND a.BranchId = @BranchId AND a.Id = @AssetId;

    /* 2. Its movements. `IsLatest` marks the one movement that may be
          reversed: the newest still in force. */
    SELECT m.Id                                   AS MovementId,
           m.MovementRef,
           m.MovementType,
           ISNULL(k.MovementTypeDescription, m.MovementType) AS MovementTypeDescription,
           m.MovementDate,
           fb.Name                                AS FromSite,
           tb.Name                                AS ToSite,
           m.FromStatus,
           m.ToStatus,
           m.Proceeds,
           m.Reason,
           m.ReversedAt,
           m.ReverseReason,
           ru.UserName                            AS ReversedByName,
           cu.UserName                            AS CapturedBy,
           m.CreatedAt                            AS CapturedAt,
           CASE WHEN m.ReversedAt IS NULL AND NOT EXISTS (
                    SELECT 1 FROM agora.AssetMovement x
                     WHERE x.BranchId = m.BranchId AND x.AssetId = m.AssetId AND x.ReversedAt IS NULL
                       AND (x.MovementDate > m.MovementDate OR (x.MovementDate = m.MovementDate AND x.Id > m.Id)))
                THEN CONVERT(bit, 1) ELSE CONVERT(bit, 0) END AS IsLatest
    FROM agora.AssetMovement m
    OUTER APPLY (SELECT TOP 1 x.MovementTypeDescription FROM agora.vw_AssetMovementType x
                  WHERE x.MovementType = m.MovementType) k
    LEFT JOIN agora.Branch fb ON fb.BranchId = m.FromBranchId
    LEFT JOIN agora.Branch tb ON tb.BranchId = m.ToBranchId
    OUTER APPLY (SELECT TOP 1 u.UserName FROM agora.[User] u WHERE u.Id = m.CreatedBy) cu
    OUTER APPLY (SELECT TOP 1 u.UserName FROM agora.[User] u WHERE u.Id = m.ReversedBy) ru
    WHERE @Visible = 1 AND m.BranchId = @BranchId AND m.AssetId = @AssetId
    ORDER BY m.MovementDate DESC, m.Id DESC;

    /* 3. Its job cards. */
    SELECT r.Id                                   AS RepairId,
           r.JobCardNo,
           r.CallDate,
           sb.Name                                AS SiteName,
           r.SupplierCode,
           sp.SupplierName,
           r.Problem,
           r.TransactionNo,
           cost.RequestState,
           cost.RepairCost,
           cost.SharedBy,
           r.DateExpectedReturn,
           r.DateActualReturn,
           r.IsCancelled,
           k.PrevJobCardNo,
           k.DaysSincePrevious,
           ISNULL(k.IsComeback, CONVERT(bit, 0))  AS IsComeback
    FROM agora.AssetRepair r
    LEFT JOIN agora.vw_AssetRepairCall k ON k.BranchId = r.BranchId AND k.Id = r.Id
    LEFT JOIN agora.vw_AssetRepairCost cost ON cost.BranchId = r.BranchId AND cost.Id = r.Id
    LEFT JOIN agora.Branch sb ON sb.BranchId = r.SiteBranchId
    OUTER APPLY (SELECT TOP 1 s.SupplierName FROM agora.vw_Supplier s
                  WHERE s.BranchId = r.SiteBranchId AND s.SupplierCode = r.SupplierCode) sp
    WHERE @Visible = 1 AND r.BranchId = @BranchId AND r.AssetId = @AssetId
    ORDER BY r.CallDate DESC, r.Id DESC;
END
