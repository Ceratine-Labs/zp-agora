<?php

use App\Support\Database\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Fuel reads the two source grains independently; neither view chooses a winning price. */
return new class extends Migration
{
    public function up(): void
    {
        $schema = str_replace(']', ']]', config('agora.schema'));
        $erp = str_replace(']', ']]', config('agora.source_databases.erp'));

        DB::unprepared("CREATE OR ALTER VIEW [{$schema}].[vw_FuelObservation] AS
            SELECT SSBranchId AS BranchId, FuelTypeNo, FuelTypeDescription,
                   [Date] AS TradingDate, S_Date AS SourceDate, POSType, POSIdentifier,
                   IsFleetCard, HandlingFee, EOD_CNTR AS EodCounter, Code,
                   CostPrice, SellingPrice, Margin, SellingPriceMatrix, SalesVariance, MIST_EOD AS MistEod
            FROM [{$erp}].dbo.BRN_FuelPrice;");
        DB::unprepared("CREATE OR ALTER VIEW [{$schema}].[vw_FuelDoePeriod] AS
            SELECT SSBranchId AS BranchId, FuelTypeNo, FuelTypeDescription,
                   StartDate, EndDate, CostPrice, SellingPrice, Margin, FleetMargin, MIST_EOD AS MistEod
            FROM [{$erp}].dbo.BRN_MonthlyDOEFuelPricingMatrix;");

        MigrationHelper::recordVersion('1.45', 'Fuel: read-only POS observations and DOE periods, preserving both source grains.');
    }

    public function down(): void
    {
        // Forward-only; never drop views used by a running application.
    }
};
