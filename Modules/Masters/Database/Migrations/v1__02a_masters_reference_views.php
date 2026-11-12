<?php

use App\Support\Database\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The reference masters (T022): the PumpIT side, as Agora is allowed to see it.
 *
 * WHY THESE ARE LISTINGS AND NOT EDITORS. Every master below lives in PumpIT,
 * and PumpIT is still the system the sites trade on: the cashups Agora writes
 * land in BRN_DailyBanking against PumpIT's own till and shift numbers, the
 * pump readings against PumpIT's pumps, the accounting export reads PumpIT's
 * GL codes. A copy edited in Agora would be a second truth PumpIT never sees,
 * and writing PumpIT needs Ryan's named go-ahead (CLAUDE.md rule 1). So each
 * is a read-only listing over a view, until a master is formally adopted or
 * migrated — the task says which, and none of these has said so yet.
 *
 * Views that already exist and carry what a listing needs are reused, not
 * copied: vw_ProfitCentre (Core), vw_Expense, vw_ErpUser, vw_Till, vw_Shift,
 * vw_UtilityMeter, vw_UtilityType and vw_UtilityTransaction (Reports). The
 * ones below are new because nothing exposes these tables, or the existing
 * view leaves out what the master is for (BRN_Pump.[Order], the fuel type's
 * POS code, a transaction type's number prefix).
 *
 * Plan §3.3 rules: SSBranchId becomes BranchId; a global table carries the
 * group entity's id, never NULL; reserved words are bracketed once, here
 * (BRN_Pump.[Order]); every column is ENUMERATED, never `*`; floats become
 * decimals (a GP band is DECIMAL(9,4), plan §3.3); and nothing is filtered —
 * an "only the active ones" rule belongs in the procedure, where the customer
 * can read it.
 *
 * BRN_FuelType.CostPrice / .SellingPrice are not exposed: 0 on every row
 * (docs/reference/pumpit-dead-columns.md). The price is vw_FuelPrice.
 *
 * Reads only. Nothing here writes PumpIT.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = config('agora.schema');
        $erp = config('agora.source_databases.erp');
        $group = (int) config('agora.group_branch_id');

        $views = [
            // Group-wide: 54 rows, no branch in PumpIT.
            'vw_MasterStandardCategory' => "
                SELECT CONVERT(int, {$group})                     AS BranchId,
                       s.StandardCategoryId,
                       LTRIM(RTRIM(s.StandardCategory))           AS StandardCategory,
                       NULLIF(LTRIM(RTRIM(s.SupplierGLCode)), '') AS SupplierGLCode,
                       NULLIF(LTRIM(RTRIM(s.SalesGLCode)), '')    AS SalesGLCode,
                       s.IsActive
                FROM [{$erp}].dbo.SS_StandardCategory s",

            // One row per (branch, POS system, POS category) — unique on the
            // live instance, trimmed or not (12 Nov 2026). The profit centre
            // and the standard category are TEXT here, not ids; their names
            // are unique, so the name join cannot fan out.
            'vw_MasterCategoryGl' => "
                SELECT g.SSBranchId                                AS BranchId,
                       g.POSSystemId,
                       LTRIM(RTRIM(g.Category))                    AS Category,
                       NULLIF(LTRIM(RTRIM(g.StandardCategory)), '') AS StandardCategory,
                       NULLIF(LTRIM(RTRIM(g.SupplierGLCode)), '')  AS SupplierGLCode,
                       NULLIF(LTRIM(RTRIM(g.SalesGLCode)), '')     AS SalesGLCode,
                       NULLIF(LTRIM(RTRIM(g.ProfitCentre)), '')    AS ProfitCentre,
                       g.IsActive,
                       CONVERT(decimal(9, 4), g.MINGP)             AS MinGP,
                       CONVERT(decimal(9, 4), g.MAXGP)             AS MaxGP,
                       CONVERT(decimal(9, 2), g.MINDaysCover)      AS MinDaysCover,
                       CONVERT(decimal(9, 2), g.MAXDaysCover)      AS MaxDaysCover
                FROM [{$erp}].dbo.SDK_CategoryGLCode g",

            // AlpNum is the prefix on the document number (CA, CR) and HighNum
            // the last number issued under it.
            'vw_MasterTransactionType' => "
                SELECT t.SSBranchId           AS BranchId,
                       t.TypeCode,
                       t.TypeDescription,
                       t.AlpNum               AS NumberPrefix,
                       t.HighNum              AS LastNumber,
                       t.CreateDateTime
                FROM [{$erp}].dbo.BRN_TransactionType t",

            // Per branch: FuelTypeNo 2 and 5 mean different fuels at different
            // sites, so a fuel type is always (BranchId, FuelTypeNo).
            'vw_MasterFuelType' => "
                SELECT f.SSBranchId           AS BranchId,
                       f.FuelTypeNo,
                       f.FuelTypeDescription,
                       NULLIF(RTRIM(f.Code), '') AS FuelCode,
                       f.POSType,
                       f.POSIdentifier,
                       f.IsFleetCard,
                       f.HandlingFee,
                       f.CreateDateTime
                FROM [{$erp}].dbo.BRN_FuelType f",

            'vw_MasterTank' => "
                SELECT t.SSBranchId           AS BranchId,
                       t.TankNo,
                       t.TankDescription,
                       t.CreateDateTime
                FROM [{$erp}].dbo.BRN_Tank t",

            // [Order] is a reserved word, bracketed here once.
            'vw_MasterPump' => "
                SELECT p.SSBranchId           AS BranchId,
                       p.PumpNo,
                       p.FuelTypeNo,
                       p.TankNo,
                       p.[Order]              AS PumpOrder,
                       p.POSType,
                       p.POSIdentifier,
                       p.IsActive,
                       p.CreateDateTime
                FROM [{$erp}].dbo.BRN_Pump p",

            // Three PumpIT tables, one master: the numbers a site's card,
            // Smart ATM and cash-device settlements arrive under. TerminalKey
            // names the row across all three. The cash-device table has no
            // IsActive column, so it says NULL rather than claiming one.
            'vw_MasterTerminal' => "
                SELECT a.SSBranchId           AS BranchId,
                       N'ABSA merchant'       AS Kind,
                       CONCAT(N'ABSA:', a.ABSAMerchantNoId) AS TerminalKey,
                       LTRIM(RTRIM(a.ABSA_MerchantNo)) AS Number,
                       a.[Description],
                       a.IsActive
                FROM [{$erp}].dbo.SS_Branch_ABSA_MerchantNo a
                UNION ALL
                SELECT s.SSBranchId, N'Smart ATM terminal', CONCAT(N'SMARTATM:', s.SMARTATMTerminalNoId),
                       LTRIM(RTRIM(s.SMARTATM_TerminalNo)), s.[Description], s.IsActive
                FROM [{$erp}].dbo.SS_Branch_SMARTATM_TerminalNo s
                UNION ALL
                SELECT c.SSBranchId, N'Cash device terminal', CONCAT(N'CASHDEVICE:', c.CASHDEVICETerminalNoId),
                       LTRIM(RTRIM(c.CASHDEVICE_TerminalNo)), c.[Description], CONVERT(bit, NULL)
                FROM [{$erp}].dbo.SS_Branch_CASHDEVICE_TerminalNo c",

            // The OLDER recon criteria: a statement-reference rule per branch
            // and tender group. The current auto-recon rules are
            // BRN_AutoReconCriteria, which Modules/Recon reads and overrides.
            'vw_MasterReconCriteria' => "
                SELECT c.SSBranchId           AS BranchId,
                       c.CriteriaId,
                       c.CriteriaGroupId,
                       g.Code                 AS GroupCode,
                       g.[Description]        AS GroupName,
                       g.SQLTableName         AS GroupTable,
                       c.Code,
                       c.[Description],
                       c.Reference,
                       c.ReferenceStartPosition,
                       c.ReferenceEndPosition,
                       c.ReconStartPosition,
                       c.ReconEndPosition,
                       c.IsAutoReconcile
                FROM [{$erp}].dbo.SS_ReconCriteria c
                LEFT JOIN [{$erp}].dbo.SS_ReconCriteriaGroup g ON g.CriteriaGroupId = c.CriteriaGroupId",
        ];

        foreach ($views as $name => $body) {
            DB::unprepared("CREATE OR ALTER VIEW [{$schema}].[{$name}] AS{$body};");
        }

        MigrationHelper::recordVersion(
            '1.48',
            'Reference masters (T022): read-only views over PumpIT for standard categories, category GL codes, '
            .'transaction types, fuel types, tanks, pumps, merchant/terminal numbers and the older recon criteria.'
        );
    }

    /** Local only; live is forward-only. Views hold no data. */
    public function down(): void
    {
        $schema = config('agora.schema');

        foreach (['vw_MasterReconCriteria', 'vw_MasterTerminal', 'vw_MasterPump', 'vw_MasterTank', 'vw_MasterFuelType',
            'vw_MasterTransactionType', 'vw_MasterCategoryGl', 'vw_MasterStandardCategory'] as $view) {
            DB::unprepared("DROP VIEW IF EXISTS [{$schema}].[{$view}];");
        }
    }
};
