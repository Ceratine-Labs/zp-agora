<?php

use App\Support\Database\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        MigrationHelper::table('StockWeeklySnapshot', function (Blueprint $table) {
            $table->uuid('SnapshotRef');
            $table->date('WeekStart');
            $table->date('WeekEnd');
            $table->date('CompleteThrough');
            $table->dateTime('SourceReadAt', 0);
            $table->string('RuleVersion', 40);
            $table->text('SourceJson');
            $table->text('CoverageJson');
        });
        MigrationHelper::naturalKey('StockWeeklySnapshot', ['BranchId', 'SnapshotRef']);
        MigrationHelper::table('StockWeeklyCase', function (Blueprint $table) {
            $table->uuid('SnapshotRef');
            $table->integer('AreaNo');
            $table->string('StockItemNo', 10);
            $table->string('AreaDescription', 160);
            $table->string('Description', 240);
            $table->string('SourceSystem', 20);
            $table->string('POSCode', 80);
            foreach (['ReconQty', 'OriginalReconQty', 'CountQty', 'PreviousCountQty', 'StoreroomQty', 'UnitSellingPrice', 'IssuedQty', 'SalesQty'] as $column) {
                $table->decimal($column, 19, 4)->nullable();
            }
            $table->integer('CountRows');
            $table->date('LastCountDate')->nullable();
            $table->integer('SharedCountCases');
            $table->string('Scenario', 8);
            $table->string('ScenarioName', 160);
            $table->string('NextAction', 500);
            $table->string('Priority', 10);
            $table->decimal('ValueAtRiskSelling', 19, 2)->nullable();
            $table->text('EvidenceJson');
            $table->string('Status', 20)->default('Open');
            $table->integer('OwnerId')->nullable();
            $table->date('DueDate')->nullable();
            $table->string('Finding', 2000)->nullable();
            $table->string('EvidenceReference', 1000)->nullable();
        });
        MigrationHelper::naturalKey('StockWeeklyCase', ['BranchId', 'SnapshotRef', 'AreaNo', 'StockItemNo']);
        MigrationHelper::addRowVersion('StockWeeklyCase');
        MigrationHelper::table('StockWeeklyCaseChange', function (Blueprint $table) {
            $table->bigInteger('CaseId');
            $table->text('PriorJson');
            $table->text('NewJson');
            $table->string('Reason', 400);
        });
        MigrationHelper::recordVersion('1.40', 'Weekly stock investigations: immutable source snapshots, classified cases and audited owner actions.');
    }

    public function down(): void
    {
        // Forward only: snapshots and investigation findings are business records.
    }
};
