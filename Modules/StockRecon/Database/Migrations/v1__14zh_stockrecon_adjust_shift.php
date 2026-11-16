<?php

use App\Support\Database\ProcedureMigration;
use Illuminate\Support\Facades\DB;

/**
 * Stock recon — the opening and the issue set by hand too, not only the
 * closing (slot 14zh). Ryan, 16 Nov 2026: "want to be able to adjust opening,
 * closing and issue values... not just closing, please implement and deploy".
 *
 * agora.StockReconManualAdjustment gets [Field] ('open', 'issue', 'close';
 * every row before this is a closing). CountedClose / PriorCloseNew /
 * NewClose then hold THAT field's counted, proposed and new value, and
 * NextRunLineId the other row it moved (the previous shift for an opening,
 * the next for a closing, none for an issue). Agora's own table only.
 *
 * usp_StockRecon_AdjustShift does the work; usp_StockRecon_AdjustClose calls
 * it with the closing alone. usp_StockRecon_Commit leaves a hand-set issue be
 * in its stale-issue guards — a new PumpIT write of QtyIssued, Ryan's named
 * go-ahead in the same message.
 */
return new class extends ProcedureMigration
{
    public function up(): void
    {
        $schema = config('agora.schema');
        DB::unprepared("IF COL_LENGTH('[{$schema}].[StockReconManualAdjustment]', 'Field') IS NULL
            ALTER TABLE [{$schema}].[StockReconManualAdjustment]
                ADD [Field] VARCHAR(10) NOT NULL
                    CONSTRAINT [DF_StockReconManualAdjustment_Field] DEFAULT ('close') WITH VALUES;");
        parent::up();
    }

    protected function files(): array
    {
        return array_map(fn (string $name): string => $this->path().'/'.$name.'.sql', [
            'usp_StockRecon_AdjustShift', 'usp_StockRecon_AdjustClose', 'usp_StockRecon_Commit',
        ]);
    }
};
