<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $schema = config('agora.schema');
        DB::statement("ALTER TABLE [{$schema}].[StockReconRunLine] ADD
            [WasteAuthorizationId] BIGINT NULL,
            [AuthorizedGoodWaste] DECIMAL(18,4) NULL,
            [AppliedGoodWaste] DECIMAL(18,4) NULL");
        DB::unprepared("CREATE OR ALTER VIEW [{$schema}].[vw_StockReconRunLineWithWaste] AS
            SELECT v.*, r.WasteAuthorizationId, r.AuthorizedGoodWaste, r.AppliedGoodWaste
            FROM [{$schema}].[vw_StockReconRunLine] v
            JOIN [{$schema}].[StockReconRunLine] r ON r.BranchId = v.BranchId AND r.Id = v.Id");
    }

    public function down(): void
    {
        // Run evidence is retained; production migrations are forward-only.
    }
};
