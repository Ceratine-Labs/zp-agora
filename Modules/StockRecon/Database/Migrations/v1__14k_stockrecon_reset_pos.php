<?php

use App\Support\Database\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Stock recon — Reset to original puts the POS back too (slot 14k).
 *
 * The preview calculates on QtyComputer_Original since 7 Oct 2026 (Ryan: "we
 * must use original"), but Reset to original left the live QtyComputer as
 * head office amended it, so after a reset PumpIT's own variance could still
 * disagree with Agora's on a shift whose POS was changed by hand. Ryan's named
 * go-ahead, 8 Oct 2026: the reset writes QtyComputer = QtyComputer_Original.
 *
 * agora.StockReconAmendment gains PriorQtyPOS and NewQtyPOS — NULL unless the
 * reset changed the POS, exactly as the issue's pair is — so Reverse puts the
 * prior back, and the audit trail (v1__22g) shows it.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = config('agora.schema');

        DB::statement("
            ALTER TABLE [{$schema}].[StockReconAmendment]
                ADD [PriorQtyPOS] DECIMAL(18,3) NULL,
                    [NewQtyPOS]   DECIMAL(18,3) NULL;
        ");

        MigrationHelper::recordVersion(
            '1.32',
            'Stock recon: Reset to original also sets QtyComputer back to QtyComputer_Original, its prior kept in '
            .'agora.StockReconAmendment (PriorQtyPOS, NewQtyPOS) for Reverse.'
        );
    }

    /** Local sandbox only. Live and staging are forward-only — see CLAUDE.md. */
    public function down(): void
    {
        $schema = config('agora.schema');

        foreach (['PriorQtyPOS', 'NewQtyPOS'] as $c) {
            DB::statement("ALTER TABLE [{$schema}].[StockReconAmendment] DROP COLUMN IF EXISTS [{$c}];");
        }
    }
};
