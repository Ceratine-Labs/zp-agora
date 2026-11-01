<?php

use App\Support\Database\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agora.StockWeeklySnapshot', function (Blueprint $table) {
            $table->date('PeriodFrom')->nullable();
            $table->date('PeriodTo')->nullable();
            $table->date('PeriodThrough')->nullable();
            $table->date('ReportingAsOf')->nullable();
        });
        Schema::table('agora.StockWeeklyCase', function (Blueprint $table) {
            $table->string('RegisterName', 40)->default('Item Register');
            $table->string('RegisterKey', 100)->default('week');
            $table->string('ResponsibleRole', 100)->nullable();
            $table->date('EvidenceDate')->nullable();
            $table->decimal('UnitCost', 19, 6)->nullable();
            $table->string('CostBasis', 100)->nullable();
            $table->decimal('ReconValueCost', 19, 4)->nullable();
            $table->decimal('CountValueCost', 19, 4)->nullable();
            $table->decimal('StoreroomValueCost', 19, 4)->nullable();
        });
        // Preserve uniqueness while widening the key to distinguish registers.
        DB::statement('CREATE UNIQUE INDEX UX_StockWeeklyCase_Registers ON agora.StockWeeklyCase (BranchId, SnapshotRef, RegisterKey, AreaNo, StockItemNo)');
        DB::statement('DROP INDEX UX_StockWeeklyCase_Natural ON agora.StockWeeklyCase');
        MigrationHelper::recordVersion('1.44', 'Separate weekly and control periods, count-window cost evidence and actionable registers.');
    }

    public function down(): void
    {
        // Forward only: register evidence and action history must survive.
    }
};
