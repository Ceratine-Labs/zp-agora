<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Recon — near-reference detection (slot 13b).
 *
 * A lettered follow-on: v1__13 has run against the customer's instance.
 *
 * Ryan found this in real data on 4 September 2026. At branch 9, CashMachine,
 * the bank narrative yields `69744` and the deposit slip yields `697440` — the
 * same reference, read one character too long on the deposit side because
 * `MOPS_EndPosition` is stored as an end position there and was being read as
 * a length (finding 9: the column means different things in different areas).
 *
 * The effect is worse than a wrong number. The two sides never meet, so a
 * single reconciliation is reported as TWO unrelated findings sitting at
 * opposite ends of the screen — a bank line with no deposit, and a deposit
 * with no bank line — and nothing on either says they are the same thing. On
 * that branch it turned 20 real pairings into 31 orphans.
 *
 * Reading it the other way collapses all 11 deposit orphans and produces 11
 * honest amount mismatches instead. But which reading is right is a per-branch
 * fact about the customer's configuration, and guessing it is exactly what
 * this module refuses to do. So instead of guessing, the run says so: when a
 * bank-only row and a deposit-only row carry references that differ by one or
 * two characters at one end, both are flagged and pointed at each other.
 *
 * That makes a configuration error visible as a configuration error, on the
 * screen, at the moment it happens — rather than as two orphans somebody has
 * to notice are related.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = config('agora.schema');

        DB::statement("
            ALTER TABLE [{$schema}].[ReconRunLine]
                ADD [NearRefLineId] BIGINT NULL,
                    [NearRefNote]   NVARCHAR(200) NULL;
        ");

        // The lookup the screen makes: given a row, is there a counterpart?
        DB::statement("
            CREATE INDEX [IX_ReconRunLine_NearRef]
                ON [{$schema}].[ReconRunLine] ([BranchId], [RunId], [NearRefLineId])
                WHERE [NearRefLineId] IS NOT NULL;
        ");
    }

    public function down(): void
    {
        $schema = config('agora.schema');

        DB::statement("DROP INDEX IF EXISTS [IX_ReconRunLine_NearRef] ON [{$schema}].[ReconRunLine];");
        DB::statement("ALTER TABLE [{$schema}].[ReconRunLine] DROP COLUMN [NearRefLineId], [NearRefNote];");
    }
};
