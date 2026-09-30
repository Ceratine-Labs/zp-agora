/* ============================================================================
 * agora.usp_Assets_PickAssets — the assets a person may choose from on a
 * movement or a repair form.
 *
 * Read by:  Asset movement -> Record a movement, Asset repair -> Log a
 *           repair (T057).
 * Reads:    agora.Asset, agora.vw_AssetType, agora.Branch.
 * Writes:   nothing.
 *
 * Only assets still on a register: a disposed or written-off asset cannot be
 * moved or repaired (agora.usp_Assets_Move and usp_Assets_SaveRepair refuse it
 * too — this list is a convenience, not the rule). Scope as the register: the
 * asset's own site or the site it is at, asked for and allowed.
 *
 * `PickKey` is "{BranchId}:{AssetId}" — an asset is identified by its owning
 * site AND its id, and the form sends both in one value.
 * ============================================================================ */

CREATE OR ALTER PROCEDURE [agora].[usp_Assets_PickAssets]
    @BranchIds        NVARCHAR(MAX) = NULL,
    @AllowedBranchIds NVARCHAR(MAX) = NULL
AS
BEGIN
    SET NOCOUNT ON;

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

    SELECT CONCAT(a.BranchId, ':', a.Id)                     AS PickKey,
           a.BranchId,
           a.Id                                              AS AssetId,
           a.AssetNo,
           CONCAT(a.AssetNo, ' · ', ISNULL(t.AssetTypeDescription, a.AssetTypeCode), ' · ', a.Make,
                  CASE WHEN a.Model IS NULL THEN '' ELSE CONCAT(' ', a.Model) END,
                  ' · at ', lb.Name,
                  CASE WHEN a.[Status] = 'AT_REPAIR' THEN ' (at repair)' ELSE '' END) AS Label,
           a.[Status],
           a.LocatedBranchId,
           lb.Name                                           AS LocatedAt
    FROM agora.Asset a
    LEFT JOIN agora.vw_AssetType t ON t.AssetGroupCode = a.AssetGroupCode AND t.AssetTypeCode = a.AssetTypeCode
    LEFT JOIN agora.Branch lb ON lb.BranchId = a.LocatedBranchId
    WHERE a.DeletedAt IS NULL
      AND a.[Status] IN ('IN_SERVICE', 'AT_REPAIR')
      AND EXISTS (SELECT 1 FROM (VALUES (a.BranchId), (a.LocatedBranchId)) s (b)
                   WHERE (@AllBranches = 1 OR s.b IN (SELECT BranchId FROM @Branch))
                     AND (@AllAllowed  = 1 OR s.b IN (SELECT BranchId FROM @Allowed)))
    ORDER BY lb.Name, a.AssetNo;
END
