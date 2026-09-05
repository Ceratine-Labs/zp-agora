<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Recon — SmartATM gains the deposit row's own key (slot 13e).
 *
 * `BRN_DailyBankingSmartATM` has a key of its own, `DailyBankingSmartATMID`,
 * and the view was not exposing it. Everything downstream was therefore
 * addressing a SmartATM deposit by the composite
 * `TerminalId|TraceNo|UniqueNo` — which works, but is a string built in one
 * procedure and taken apart in another, and `UniqueNo` is a FLOAT, which is
 * not a thing to build a key out of.
 *
 * With the id exposed, agora.usp_Recon_Commit stamps SmartATM deposits by id,
 * the way it already does CashBags. That matters more than tidiness here,
 * because the date basis is changing in the same breath (see
 * v1__13pf_recon_procs) and a stamp guarded by the WRONG date would silently
 * update nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = config('agora.schema');
        $erp = config('agora.source_databases.erp');

        DB::unprepared("
            CREATE OR ALTER VIEW [{$schema}].[vw_DailyBankingSmartATM] AS
            SELECT s.DailyBankingSmartATMID,
                   s.SSBranchId AS BranchId,
                   s.TerminalId,
                   s.TraceNo,
                   s.UniqueNo,
                   /* The date this deposit was FILED under — midnight, always,
                      because it is a trading day rather than a moment. The
                      real timestamp lives on BRN_SmartATM and they disagree on
                      17.5% of rows, by anything from hours to weeks. */
                   s.DepositDateTime,
                   s.Deposited,
                   s.ReconBatchNoPumpIT
            FROM [{$erp}].dbo.BRN_DailyBankingSmartATM s;
        ");
    }

    public function down(): void
    {
        // Forward only; the previous shape is a subset of this one.
    }
};
