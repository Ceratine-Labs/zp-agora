<?php

use App\Support\Database\MigrationHelper;
use App\Support\Database\ProcedureMigration;
use Illuminate\Support\Facades\DB;

/**
 * Stock recon — reverse one item's chain inside a committed run (slot 14q).
 *
 * An engine fault fixed on 12 Nov 2026 (v1__14pzz) left wrongly inserted
 * issues written to PumpIT. Two live runs hold 220 and 63 amendments, but only
 * 3 and 2 item chains in them are wrong, and usp_StockRecon_Reverse undoes a
 * whole run. Ryan approved "a reversal for a single item's chain ... a new
 * PumpIT write" (12 Nov 2026).
 *
 *   - agora.StockReconAmendment gains ReversalReason NVARCHAR(300) NULL: the
 *     reason a chain reversal gives is kept on each amendment it reversed. The
 *     run's own ReversalReason is a run-level reversal's, and stays NULL while
 *     the run stays committed. Guarded, so a database it was applied to by
 *     hand is not refused.
 *   - agora.usp_StockRecon_ReverseChain: usp_StockRecon_Reverse restricted to
 *     one area and item, with the same refusals and the same three "moved
 *     since" guards.
 *
 * Lettered q, not p: the p series ran out at 14pzz, and check-migrations.sh
 * allows three letters. "14q" sorts after "14pzz" and before "15".
 */
return new class extends ProcedureMigration
{
    public function up(): void
    {
        $schema = config('agora.schema');

        DB::statement("
            IF COL_LENGTH('[{$schema}].[StockReconAmendment]', 'ReversalReason') IS NULL
                ALTER TABLE [{$schema}].[StockReconAmendment] ADD [ReversalReason] NVARCHAR(300) NULL;
        ");

        parent::up();

        MigrationHelper::recordVersion(
            '1.45',
            'Stock recon: one item\'s chain can be reversed inside a committed run (usp_StockRecon_ReverseChain); '
            .'its reason is kept on each amendment it reversed (StockReconAmendment.ReversalReason).'
        );
    }

    protected function files(): array
    {
        return [$this->path().'/usp_StockRecon_ReverseChain.sql'];
    }
};
