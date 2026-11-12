<?php

use App\Support\Database\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        MigrationHelper::table('StockReconAreaShiftCompletion', function (Blueprint $table) {
            $table->integer('AreaNo');
            $table->date('TransactionDate');
            $table->integer('ShiftNo');
            $table->dateTime('WithdrawnAt')->nullable();
            $table->integer('WithdrawnBy')->nullable();
        });
        $schema = config('agora.schema');
        DB::statement("CREATE UNIQUE INDEX [UX_StockReconAreaShiftCompletion_Active] ON [{$schema}].[StockReconAreaShiftCompletion] ([BranchId], [AreaNo], [TransactionDate], [ShiftNo]) WHERE [WithdrawnAt] IS NULL");
    }

    public function down(): void {}
};
