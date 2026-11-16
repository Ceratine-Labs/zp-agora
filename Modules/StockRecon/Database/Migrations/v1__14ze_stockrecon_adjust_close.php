<?php

use App\Support\Database\ProcedureMigration;
use Illuminate\Support\Facades\DB;

/**
 * Stock recon — the procedure behind the pencil, and the commit that honours
 * it (slot 14ze, Ryan, 16 Nov 2026). usp_StockRecon_AdjustClose sets one
 * shift's proposed closing by hand with a reason (Agora's own tables only);
 * usp_StockRecon_Commit no longer refuses the whole commit as SHORT_MOVED for a
 * row a person adjusted on purpose. vw_StockReconRunLineWithWaste, which the
 * run line model reads, carries ManualAdjustmentId.
 */
return new class extends ProcedureMigration
{
    public function up(): void
    {
        $schema = config('agora.schema');
        DB::unprepared("CREATE OR ALTER VIEW [{$schema}].[vw_StockReconRunLineWithWaste] AS
            SELECT v.*, r.WasteAuthorizationId, r.AuthorizedGoodWaste, r.AppliedGoodWaste,
                   r.BalancingProfileId, r.BalancingProfileName, r.BalancingProfileRevision,
                   r.ProfileMaxAmendment, r.ProfileMaxAmendmentPct, r.ProfileMinShifts, r.ProfileKeepBreaksUpTo,
                   r.ContinuitySegment, r.ManualAdjustmentId
            FROM [{$schema}].[vw_StockReconRunLine] v
            JOIN [{$schema}].[StockReconRunLine] r ON r.BranchId = v.BranchId AND r.Id = v.Id");
        parent::up();
    }

    protected function files(): array
    {
        return [
            $this->path().'/usp_StockRecon_AdjustClose.sql',
            $this->path().'/usp_StockRecon_Commit.sql',
        ];
    }
};
