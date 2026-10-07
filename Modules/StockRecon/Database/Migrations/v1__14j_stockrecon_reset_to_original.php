<?php

use App\Support\Database\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Stock recon — Reset to original (slot 14j).
 *
 * Ryan, 7 Oct 2026, with head office's own script: "update the amended with
 * the original column values". Per shift: QtyOpen = QtyOpen_Original,
 * QtyIssued = QtyIssued_Original, QtyClose = QtyClose_Original — the hand
 * balancing taken off, so the next preview balances from the staff counts.
 * agora.usp_StockRecon_ResetToOriginal (v1__14pl) does it for the shifts of
 * one run, with each row's prior in agora.StockReconAmendment, so the run's
 * Reverse puts it back exactly.
 *
 *   StockReconRun gains
 *     CommitKind   'reset' on a run whose commit was a reset to the originals;
 *                  NULL for a balancing commit. The audit trail reads it to
 *                  name the change (v1__22e).
 *
 * Agora's own table. The PumpIT write is the procedure's, and is listed in
 * CLAUDE.md rule 1.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = config('agora.schema');

        DB::statement("ALTER TABLE [{$schema}].[StockReconRun] ADD [CommitKind] NVARCHAR(20) NULL;");

        MigrationHelper::recordVersion(
            '1.28',
            'Stock recon: Reset to original puts a run\'s shifts back to the staff\'s original open, issue and close '
            .'in PumpIT, recorded with each prior so the run\'s Reverse undoes it (StockReconRun.CommitKind).'
        );
    }

    /** Local sandbox only. Live and staging are forward-only — see CLAUDE.md. */
    public function down(): void
    {
        $schema = config('agora.schema');

        DB::statement("ALTER TABLE [{$schema}].[StockReconRun] DROP COLUMN IF EXISTS [CommitKind];");
    }
};
