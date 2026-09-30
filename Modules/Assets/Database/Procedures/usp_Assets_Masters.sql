/* ============================================================================
 * agora.usp_Assets_Masters — the lists every asset form and the masters
 * screen choose from.
 *
 * Read by:  Setup -> People and assets -> Asset groups and types (T057), and
 *           every asset form (register, move, repair) for its choices.
 * Reads:    agora.vw_AssetGroup, vw_AssetType, vw_AssetOwner,
 *           vw_AssetMovementType — the customer's four masters, read-only —
 *           and agora.Asset, to say how many assets use each group and type.
 * Writes:   nothing. Agora never writes the customer's asset masters (Ryan,
 *           27 Sep 2026, PM decision 01a0e309); a new group or type is added in
 *           PumpIT and appears here.
 *
 * Result sets, in order:
 *   1. Groups, with the depreciation period passed in @RatesJson beside each —
 *      the SARS wear-and-tear mapping from Modules/Assets/Config/config.php,
 *      PROVISIONAL until ZP confirm their own policy. A group with no period
 *      reads "Not depreciated".
 *   2. Types, per group. CE28 appears under two groups on live, which is why
 *      the key is (group, type) and never type alone.
 *   3. Owners.
 *   4. Movement types, with what each one does (Kind). RR is Agora's own.
 *   5. Conditions — agora.vw_AssetCondition, the vocabulary
 *      agora.usp_Assets_SaveAsset checks against. Nowhere in PHP.
 *
 *     EXEC agora.usp_Assets_Masters @RatesJson = N'{"BE":{"life_months":72}}';
 * ============================================================================ */

CREATE OR ALTER PROCEDURE [agora].[usp_Assets_Masters]
    @RatesJson NVARCHAR(MAX) = NULL
AS
BEGIN
    SET NOCOUNT ON;

    ;WITH rate AS (
        SELECT j.[key] COLLATE DATABASE_DEFAULT                                  AS AssetGroupCode,
               TRY_CONVERT(int, JSON_VALUE(j.value, '$.life_months'))              AS LifeMonths,
               TRY_CONVERT(decimal(9,4), JSON_VALUE(j.value, '$.residual_pct'))    AS ResidualPct,
               JSON_VALUE(j.value, '$.sars_class')                                 AS SarsClass,
               JSON_VALUE(j.value, '$.note')                                       AS RateNote
        FROM OPENJSON(ISNULL(@RatesJson, N'{}')) j
    )
    SELECT g.AssetGroupCode,
           g.AssetGroupDescription,
           (SELECT COUNT(*) FROM agora.vw_AssetType t WHERE t.AssetGroupCode = g.AssetGroupCode) AS Types,
           (SELECT COUNT(*) FROM agora.Asset a
             WHERE a.AssetGroupCode = g.AssetGroupCode AND a.DeletedAt IS NULL)              AS Assets,
           r.SarsClass,
           CASE WHEN r.LifeMonths > 0 THEN r.LifeMonths END                                  AS LifeMonths,
           CASE WHEN r.LifeMonths > 0 THEN CONVERT(decimal(9,2), r.LifeMonths / 12.0) END     AS LifeYears,
           CASE WHEN r.LifeMonths > 0 THEN CONVERT(decimal(9,2), 1200.0 / r.LifeMonths) END   AS AnnualRatePct,
           ISNULL(r.ResidualPct, 0)                                                          AS ResidualPct,
           r.RateNote,
           CASE WHEN r.LifeMonths > 0 THEN 'Straight-line, monthly' ELSE 'Not depreciated' END AS Basis
    FROM agora.vw_AssetGroup g
    LEFT JOIN rate r
           ON r.AssetGroupCode = g.AssetGroupCode
    ORDER BY g.AssetGroupCode;

    SELECT t.AssetGroupCode,
           t.AssetTypeCode,
           t.AssetTypeDescription,
           (SELECT COUNT(*) FROM agora.Asset a
             WHERE a.AssetGroupCode = t.AssetGroupCode AND a.AssetTypeCode = t.AssetTypeCode
               AND a.DeletedAt IS NULL) AS Assets
    FROM agora.vw_AssetType t
    ORDER BY t.AssetGroupCode, t.AssetTypeCode;

    SELECT o.AssetOwner,
           (SELECT COUNT(*) FROM agora.Asset a WHERE a.AssetOwner = o.AssetOwner AND a.DeletedAt IS NULL) AS Assets
    FROM agora.vw_AssetOwner o
    ORDER BY o.AssetOwner;

    SELECT m.MovementType,
           m.MovementTypeDescription,
           m.Kind,
           CASE m.Kind
                WHEN 'TRANSFER'    THEN 'Moves the asset to another site. It stays on its own site''s register.'
                WHEN 'TO_REPAIR'   THEN 'Sends the asset away for repair. It is marked at repair until it comes back.'
                WHEN 'FROM_REPAIR' THEN 'Brings the asset back from repair, and closes its open job card.'
                WHEN 'DISPOSAL'    THEN 'Takes the asset off the register, with what it fetched. Depreciation stops.'
                WHEN 'WRITE_OFF'   THEN 'Takes the asset off the register for nothing. Depreciation stops.'
                ELSE 'Not understood by Agora — refused until someone says what it does.'
           END AS WhatItDoes,
           m.[Source]
    FROM agora.vw_AssetMovementType m
    ORDER BY CASE m.Kind WHEN 'TRANSFER' THEN 1 WHEN 'TO_REPAIR' THEN 2 WHEN 'FROM_REPAIR' THEN 3
                         WHEN 'DISPOSAL' THEN 4 WHEN 'WRITE_OFF' THEN 5 ELSE 6 END,
             m.MovementType;

    SELECT c.Condition, c.Label, c.SortOrder
    FROM agora.vw_AssetCondition c
    ORDER BY c.SortOrder;
END
