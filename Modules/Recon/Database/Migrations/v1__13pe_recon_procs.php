<?php

use App\Support\Database\ProcedureMigration;
use Illuminate\Support\Facades\DB;

/**
 * Recon — near references become matches (slot 13pe).
 *
 * Replaces usp_Recon_FlagNearReferences, which only annotated the two orphans,
 * with usp_Recon_PairNearReferences, which joins them into the one
 * reconciliation they are. Also redeploys usp_Recon_DrillMops, which now takes
 *
 * @MopsKeyRef so the deposit side can be addressed by its own reference.
 */
return new class extends ProcedureMigration
{
    public function up(): void
    {
        parent::up();

        // The old name described behaviour that no longer exists. An object
        // nothing calls, that still reads like the rule the system follows, is
        // the worst kind of dead code in a database the customer opens.
        DB::unprepared('DROP PROCEDURE IF EXISTS ['.config('agora.schema').'].[usp_Recon_FlagNearReferences];');
    }
};
