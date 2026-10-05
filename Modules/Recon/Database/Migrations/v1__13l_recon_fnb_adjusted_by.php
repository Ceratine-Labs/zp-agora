<?php

use App\Support\Database\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Recon — a forced FNB match writes its variance to the deposits (slot 13l).
 *
 * Chantal Peens (ZP), 1 Oct 2026: forcing a manual FNB batch stamped both
 * sides and left the deposit amount as it was. PumpIT's own forced batch
 * moves the deposit to meet the bank and records the change in AdjustedBy —
 * on live, every FNB batch with an AdjustedBy has SUM(Amount) equal to the
 * bank to the cent (5 Oct 2026, 40 of 40 checked). The bank never moves.
 * v1__13pl teaches usp_Recon_ManualMatch to do the same; this adds what it
 * needs to read and to record. Agora's own database only.
 *
 *   vw_DailyBankingFNB  gains TillNo, ShiftNo, Adjusted and AdjustedBy. With
 *                       BatchNo, MerchantNo and the raw TransactionDate that
 *                       is BRN_DailyBankingFNB's primary key, so a match can
 *                       name the exact row it adjusts.
 *   ReconMatch          gains Adjustment (what was added to Amount and to
 *                       AdjustedBy on this deposit row; NULL = nothing) and the
 *                       row's PriorAmount, PriorAdjusted and PriorAdjustedBy,
 *                       which is what usp_Recon_Reverse puts back.
 *   ReconStamp          gains Detail — the change in words, for the audit
 *                       trail, so agora.vw_PumpItChange need not join for it.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = config('agora.schema');
        $erp = config('agora.source_databases.erp');

        DB::statement("
            ALTER TABLE [{$schema}].[ReconMatch]
                ADD [Adjustment]      DECIMAL(18,2) NULL,
                    [PriorAmount]     DECIMAL(18,2) NULL,
                    [PriorAdjusted]   BIT           NULL,
                    [PriorAdjustedBy] DECIMAL(18,2) NULL;
        ");

        DB::statement("ALTER TABLE [{$schema}].[ReconStamp] ADD [Detail] NVARCHAR(400) NULL;");

        DB::unprepared("
            CREATE OR ALTER VIEW [{$schema}].[vw_DailyBankingFNB] AS
            SELECT f.SSBranchId AS BranchId,
                   f.TransactionDate,
                   f.BatchNo,
                   f.MerchantNo,
                   f.Amount,
                   f.ReconBatchNoPumpIT,
                   f.TillNo,
                   f.ShiftNo,
                   f.Adjusted,
                   f.AdjustedBy
            FROM [{$erp}].dbo.BRN_DailyBankingFNB f;
        ");

        MigrationHelper::recordVersion(
            '1.23',
            'Recon: a forced FNB match writes its variance to the deposit rows (Amount and AdjustedBy, '
            .'Adjusted = 1), recorded with each row\'s prior values in agora.ReconMatch so a reversal puts '
            .'them back; vw_DailyBankingFNB carries the table\'s full key.'
        );
    }

    /** Local sandbox only. Live and staging are forward-only — see CLAUDE.md. */
    public function down(): void
    {
        $schema = config('agora.schema');
        $erp = config('agora.source_databases.erp');

        DB::unprepared("
            CREATE OR ALTER VIEW [{$schema}].[vw_DailyBankingFNB] AS
            SELECT f.SSBranchId AS BranchId,
                   f.TransactionDate,
                   f.BatchNo,
                   f.MerchantNo,
                   f.Amount,
                   f.ReconBatchNoPumpIT
            FROM [{$erp}].dbo.BRN_DailyBankingFNB f;
        ");

        // One column per statement: IF EXISTS covers only the first name in a list.
        foreach (['Adjustment', 'PriorAmount', 'PriorAdjusted', 'PriorAdjustedBy'] as $c) {
            DB::statement("ALTER TABLE [{$schema}].[ReconMatch] DROP COLUMN IF EXISTS [{$c}];");
        }

        DB::statement("ALTER TABLE [{$schema}].[ReconStamp] DROP COLUMN IF EXISTS [Detail];");
    }
};
