<?php

use App\Support\Database\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * Stock recon — a shift's closing adjusted by hand, with a reason (slot 14zd).
 *
 * Ryan, 16 Nov 2026, after the client meeting: "per detail row ... they want
 * to be able to manually adjust ... a pencil, and it shows a modal which asks
 * for a reason". The adjustment changes the RUN's proposal only (Ryan's choice:
 * it reaches PumpIT through the Amend press, ticked, audited and reversible).
 *
 * agora.StockReconManualAdjustment is the record: which shift, the counted
 * closing, what the run proposed before, what the person set, why, who, when.
 * agora.StockReconRunLine.ManualAdjustmentId marks the two rows it moved (the
 * shift and the next shift's opening), so the commit can tell a deliberate
 * adjustment from a stale saved proposal. Agora's own tables only.
 */
return new class extends Migration
{
    public function up(): void
    {
        MigrationHelper::table('StockReconManualAdjustment', function (Blueprint $table) {
            $table->unsignedBigInteger('RunId');
            $table->unsignedBigInteger('RunLineId');
            $table->unsignedBigInteger('NextRunLineId')->nullable();
            $table->integer('AreaNo');
            $table->string('StockItemNo', 10);
            $table->date('TransactionDate');
            $table->integer('ShiftNo');
            MigrationHelper::litres($table, 'CountedClose');
            MigrationHelper::litres($table, 'PriorCloseNew');
            MigrationHelper::litres($table, 'NewClose');
            $table->string('Reason', 500);
        });

        $schema = config('agora.schema');
        DB::statement("CREATE INDEX [IX_StockReconManualAdjustment_Run] ON [{$schema}].[StockReconManualAdjustment] ([BranchId], [RunId], [RunLineId]);");
        DB::statement("ALTER TABLE [{$schema}].[StockReconRunLine] ADD [ManualAdjustmentId] BIGINT NULL;");
    }

    /** Local sandbox only. Live and staging are forward-only — see CLAUDE.md. */
    public function down(): void
    {
        $schema = config('agora.schema');
        DB::statement("ALTER TABLE [{$schema}].[StockReconRunLine] DROP COLUMN IF EXISTS [ManualAdjustmentId];");
        MigrationHelper::drop('StockReconManualAdjustment');
    }
};
