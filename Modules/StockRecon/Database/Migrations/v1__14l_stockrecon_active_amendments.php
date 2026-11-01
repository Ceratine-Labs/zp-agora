<?php

use App\Support\Database\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A reversed amendment is history, not a reservation on its run line.
 *
 * Ryan, 5 Oct 2026: committing a reversed run again is allowed, with the old
 * amendments retained. The procedures already read only active amendments;
 * the original unfiltered natural key alone rejected that second commit.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = str_replace(']', ']]', (string) config('agora.schema'));

        // Keep the key replacement and its version stamp atomic. No ledger
        // rows are changed, and BranchId still leads the active natural key.
        DB::transaction(function () use ($schema): void {
            DB::statement("DROP INDEX IF EXISTS [UX_StockReconAmendment_Natural] ON [{$schema}].[StockReconAmendment];");
            DB::statement("
                CREATE UNIQUE INDEX [UX_StockReconAmendment_Natural]
                    ON [{$schema}].[StockReconAmendment] ([BranchId], [RunId], [RunLineId])
                    WHERE [State] = 'active';
            ");

            MigrationHelper::recordVersion(
                '1.36',
                'Stock recon: only active amendments reserve a run line; reversed amendments remain as history when the run is committed again.'
            );
        });
    }

    public function down(): void
    {
        throw new RuntimeException(
            'v1__14l is forward-only: recommitted runs can now have multiple historical amendments per line. Keep that history and use a forward migration.'
        );
    }
};
