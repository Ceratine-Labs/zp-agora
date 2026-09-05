<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Recon — the deposit side gets a reference of its own (slot 13c).
 *
 * Until now a proposal had ONE reference, because the two sides had matched on
 * it. A near-reference pairing breaks that assumption: the bank reads `69744`
 * and the deposit reads `697440`, and the row is about both.
 *
 * `MopsKeyRef` is null on every ordinary row — the two sides agreed, and the
 * one reference is the reference. It is populated only where a pairing was
 * inferred, and it is what the deposit side is ADDRESSED by from then on: the
 * drill reads deposits with it, and agora.usp_Recon_Commit stamps with it.
 * Without it the commit would go looking for deposits under the bank's
 * reference, find none, and skip the row it had just promised to reconcile.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = config('agora.schema');

        DB::statement("
            ALTER TABLE [{$schema}].[ReconRunLine]
                ADD [MopsKeyRef] NVARCHAR(50) NULL,
                    -- The row this one absorbed, kept so the pairing is
                    -- traceable after the absorbed row is gone.
                    [PairedFromLineId] BIGINT NULL;
        ");
    }

    public function down(): void
    {
        $schema = config('agora.schema');

        DB::statement("ALTER TABLE [{$schema}].[ReconRunLine] DROP COLUMN [MopsKeyRef], [PairedFromLineId];");
    }
};
