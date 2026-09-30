<?php

use App\Support\Database\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Stock recon — the pack size each recon item is issued in (slot 14f).
 *
 * The client's rule 5: an over within 10% of a whole issue multiple is an issue
 * not written up. It is captured, not balanced away. The rule needs the
 * multiple, and PumpIT does not carry it: STK_StockMaster.IssueMultiple is 2 or
 * more on ONE of 2,433 items across the nine pack sites (read 30 Sep 2026).
 *
 * Head office built the numbers instead — "ZRP Stock Recon — Item Issue
 * Multiples", staff sheet of 11 Sep 2026 plus 62,456 issue records — and
 * bundled them with the weekly pack, saying an updated workbook follows when
 * they change. This is where that workbook lands. One row per (branch, area,
 * item), the grain of a recon line, so the preview reads it without guessing.
 *
 * It is Agora's own data, not a copy of PumpIT's, and it wins over the master
 * where both have a row — the workbook is the client's statement of the pack
 * size; the master column is empty. Seeded by IssueMultipleSeeder from the CSV
 * beside it; a new workbook is a new seeder, as the ledger requires.
 */
return new class extends Migration
{
    public function up(): void
    {
        MigrationHelper::table('StockIssueMultiple', function (Blueprint $table) {
            $table->integer('AreaNo');
            $table->string('StockItemNo', 10);
            $table->string('POSCode', 50)->nullable();
            // 0 = capture any quantity; 1 = single units. Only 2 or more is a pack.
            MigrationHelper::litres($table, 'IssueMultiple');
            // 'Kilogram / litre' is weighed: never tested against a pack.
            $table->boolean('IsWeighed')->default(false);
            $table->string('Enforcement', 20)->nullable();
            $table->string('SourceOfValue', 100)->nullable();
            // Which workbook said so, so a later one can be told apart.
            $table->string('Workbook', 100);
        });

        MigrationHelper::naturalKey('StockIssueMultiple', ['BranchId', 'AreaNo', 'StockItemNo']);
    }

    /** Local sandbox only. Live and staging are forward-only — see CLAUDE.md. */
    public function down(): void
    {
        MigrationHelper::drop('StockIssueMultiple');
    }
};
