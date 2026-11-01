<?php

use App\Support\Database\ProcedureMigration;
use Illuminate\Support\Facades\DB;

/** Read-only source adapters for weekly report 1237 and ACT/THEO report 1317. */
return new class extends ProcedureMigration
{
    public function up(): void
    {
        $schema = str_replace(']', ']]', config('agora.schema'));
        $landing = str_replace(']', ']]', config('agora.source_databases.pos_landing'));

        // Explicit columns keep the source facts visible without multiplying
        // transactions through product-master joins. No customer objects change.
        $views = [
            'vw_WeeklyRecipeWinbranch' => "
                SELECT MIST_BranchId AS BranchId, [DATE] AS SourceDate,
                       [DRC] AS Direction, [TYPE] AS TransactionType, [REF] AS SourceReference,
                       [CODE] AS SourceCode, LTRIM(RTRIM([CODE])) AS POSCode, [DESC] AS SourceDescription,
                       QTY AS SourceQuantity, UNIT AS SourceUnit, COST AS SourceCost,
                       PRICE AS SourcePrice, SALES AS SourceSalesExcl, TOTAL AS SourceSalesIncl,
                       VAT AS SourceVAT, VATCODE AS SourceVATCode, EOD_CNTR AS EodCounter,
                       SYSTIME AS SourceTime, REPCODE AS RepCode, PROMCODE AS PromoCode,
                       TENDER AS Tender, ALTCODE AS AltCode, ExportRegisterId
                FROM [{$landing}].dbo.WINBRANCH_INVHISTF",
            'vw_WeeklyRecipePilotPrep' => "
                SELECT MIST_BranchId AS BranchId, PrepsheetHistoryId AS SourceId,
                       StoreId, TDATE AS SourceDate, LTRIM(RTRIM(PROD)) AS POSCode,
                       NAME AS SourceDescription, DPT AS Department, Updated AS CountCapturedAt,
                       OSTK AS OpeningQuantity, MAKE AS ReceivedMadeQuantity,
                       TRANS AS TransferQuantity, ISSUE AS IssuedQuantity, CSTK AS ClosingQuantity,
                       SALES AS TheoreticalQuantity, LOSS AS RecordedWasteQuantity,
                       COST AS UnitCost, IsHourlyPrep
                FROM [{$landing}].dbo.PILOT_PrepsheetHistory",
            'vw_WeeklyRecipePilotMaster' => "
                SELECT MIST_BranchId AS BranchId, masterID AS MasterId, Updated,
                       LTRIM(RTRIM(PROD)) AS POSCode, NAME AS Description,
                       UNIT AS Unit, CAT AS Category
                FROM [{$landing}].dbo.PILOT_MasterFile",
            'vw_WeeklyRecipePilotCategory' => "
                SELECT MIST_BranchId AS BranchId, CAT AS Category,
                       [DESC] AS CategoryName, CCNT AS CostCentre
                FROM [{$landing}].dbo.PILOT_Vzcat",
            'vw_WeeklyRecipePilotWaste' => "
                SELECT MIST_BranchId AS BranchId, prepID AS SourceId, IDATE AS SourceDate,
                       LTRIM(RTRIM(PROD)) AS POSCode, LossValue
                FROM [{$landing}].dbo.PILOT_PrepVariance",
        ];

        foreach ($views as $name => $sql) {
            DB::unprepared("CREATE OR ALTER VIEW [{$schema}].[{$name}] AS {$sql};");
        }

        parent::up();
    }

    protected function files(): array
    {
        return [$this->path().'/usp_StockRecon_WeeklyRecipeEvidence.sql'];
    }
};
