<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Stock recon — give the runs already recorded the label the new ones get (14c).
 *
 * v1__14pc reworded one outcome so it follows the same "Label: explanation"
 * shape as the other fifteen, and the screen now shows the label and hovers the
 * rest. A run PREVIEWED BEFORE that deploy stored the old wording, which has no
 * colon — so on every run Ryan currently has, that pill would still render its
 * whole 45-character sentence and the Outcome column would still be the widest
 * thing on the table. Which is to say: the fix would have shipped and he would
 * have seen no change on the screen he was looking at.
 *
 * WHY THIS IS NOT REWRITING HISTORY, and where the line is. The recorded
 * outcome is a sentence the procedure wrote to describe a row, and this changes
 * four words of that sentence into a label and a colon while saying exactly the
 * same thing about exactly the same row. No figure moves, no flag moves, no
 * decision changes — outcomeKey(), the tone, WouldAmend and the commit all read
 * the stored FLAGS and never the string. A recorded QUANTITY would be a
 * different matter and does not get touched by a migration.
 *
 * Idempotent and forward-only: it matches the old sentence exactly, so running
 * it twice changes nothing the second time, and there is nothing to undo.
 */
return new class extends Migration
{
    private const WAS = 'Opening follows the amended closing before it';

    private const NOW = 'Opening only: follows the amended closing before it';

    public function up(): void
    {
        $schema = config('agora.schema');

        $rows = DB::update(
            "UPDATE [{$schema}].[StockReconRunLine] SET Outcome = ? WHERE Outcome = ?",
            [self::NOW, self::WAS],
        );

        echo "  v1__14c: {$rows} recorded outcome(s) relabelled".PHP_EOL;
    }

    /** Local sandbox only. Live and staging are forward-only — see CLAUDE.md. */
    public function down(): void
    {
        $schema = config('agora.schema');

        DB::update(
            "UPDATE [{$schema}].[StockReconRunLine] SET Outcome = ? WHERE Outcome = ?",
            [self::WAS, self::NOW],
        );
    }
};
