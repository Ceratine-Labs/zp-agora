<?php

use App\Support\Database\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        MigrationHelper::table('StockReconItemRemoval', function (Blueprint $table) {
            $table->integer('AreaNo');
            $table->string('StockItemNo', 10);
            $table->date('LastDate');
            $table->integer('LastShift');
            $table->dateTime('WithdrawnAt')->nullable();
            $table->bigInteger('WithdrawnBy')->nullable();
        });
        $schema = config('agora.schema');
        DB::statement("CREATE UNIQUE INDEX [UX_StockReconItemRemoval_Active] ON [{$schema}].[StockReconItemRemoval] ([BranchId], [AreaNo], [StockItemNo], [LastDate], [LastShift]) WHERE [WithdrawnAt] IS NULL");
    }

    public function down(): void {}
};
