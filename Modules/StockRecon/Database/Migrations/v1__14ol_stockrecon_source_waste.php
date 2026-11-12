<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $schema = config('agora.schema');
        $erp = config('agora.source_databases.erp');
        DB::unprepared("CREATE OR ALTER VIEW [{$schema}].[vw_StockWasteLine] AS
            SELECT w.SSBranchId AS BranchId, w.TransactionDate, w.ShiftNo, w.AreaNo,
                   w.StockItemNo, w.QtyGoodWaste, w.QtyBadWaste, w.CreateDateTime,
                   w.WasteReason, w.isWasteQtyAuthorised, w.WasteQtyAuthorisedDate, w.WasteQtyAuthorisedUser
            FROM [{$erp}].dbo.STK_StockWasteLine w");
        DB::statement("ALTER TABLE [{$schema}].[StockReconRunLine] ADD
            [WasteSourceAuthorised] BIT NULL, [WasteSourceAuthorisedAt] DATETIME NULL,
            [WasteSourceAuthorisedBy] NVARCHAR(50) NULL");
    }

    public function down(): void {}
};
