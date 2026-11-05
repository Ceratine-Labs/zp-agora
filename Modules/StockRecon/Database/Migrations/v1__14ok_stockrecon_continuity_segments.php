<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $schema = config('agora.schema');
        DB::statement("ALTER TABLE [{$schema}].[StockReconRunLine] ADD [ContinuitySegment] INT NULL");
        DB::unprepared("CREATE OR ALTER VIEW [{$schema}].[vw_StockReconRunLineWithWaste] AS
            SELECT v.*, r.WasteAuthorizationId, r.AuthorizedGoodWaste, r.AppliedGoodWaste,
                   r.BalancingProfileId, r.BalancingProfileName, r.BalancingProfileRevision,
                   r.ProfileMaxAmendment, r.ProfileMaxAmendmentPct, r.ProfileMinShifts, r.ProfileKeepBreaksUpTo,
                   r.ContinuitySegment
            FROM [{$schema}].[vw_StockReconRunLine] v
            JOIN [{$schema}].[StockReconRunLine] r ON r.BranchId = v.BranchId AND r.Id = v.Id");
    }

    public function down(): void {}
};
