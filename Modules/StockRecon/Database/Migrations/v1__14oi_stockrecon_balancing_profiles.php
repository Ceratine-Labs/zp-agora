<?php

use App\Support\Database\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        MigrationHelper::table('StockReconBalancingProfile', function (Blueprint $table) {
            $table->string('Name', 100);
            $table->boolean('IsDefault')->default(false);
            $table->decimal('MaxAmendment', 18, 4);
            $table->decimal('MaxAmendmentPct', 9, 4);
            $table->integer('MinShiftsInChain');
            $table->decimal('KeepBreaksUpTo', 18, 4);
            $table->boolean('HoldBackIncomplete')->nullable();
            $table->boolean('IsActive')->default(true);
            $table->integer('Revision')->default(1);
        });
        MigrationHelper::table('StockReconItemProfile', function (Blueprint $table) {
            $table->integer('AreaNo');
            $table->string('StockItemNo', 10);
            $table->unsignedBigInteger('ProfileId');
        });
        MigrationHelper::table('StockReconProfileEvent', function (Blueprint $table) {
            $table->string('Action', 30);
            $table->text('Details');
        });
        MigrationHelper::naturalKey('StockReconItemProfile', ['BranchId', 'AreaNo', 'StockItemNo']);
        $schema = config('agora.schema');
        MigrationHelper::naturalKey('StockReconBalancingProfile', ['BranchId', 'Name']);
        DB::statement("CREATE UNIQUE INDEX [UX_StockReconBalancingProfile_Default] ON [{$schema}].[StockReconBalancingProfile] ([BranchId], [IsDefault]) WHERE [IsDefault] = 1");
        DB::table("{$schema}.StockReconBalancingProfile")->insert([
            'BranchId' => (int) config('agora.group_branch_id', 2), 'Name' => 'Generic', 'IsDefault' => true,
            'MaxAmendment' => 9999, 'MaxAmendmentPct' => 1, 'MinShiftsInChain' => 2,
            'KeepBreaksUpTo' => 3, 'HoldBackIncomplete' => false, 'IsActive' => true, 'CreatedAt' => now('UTC'),
        ]);
        DB::statement("ALTER TABLE [{$schema}].[StockReconRunLine] ADD [BalancingProfileId] BIGINT NULL, [BalancingProfileName] NVARCHAR(100) NULL, [BalancingProfileRevision] INT NULL, [ProfileMaxAmendment] DECIMAL(18,4) NULL, [ProfileMaxAmendmentPct] DECIMAL(9,4) NULL, [ProfileMinShifts] INT NULL, [ProfileKeepBreaksUpTo] DECIMAL(18,4) NULL");
    }

    public function down(): void {}
};
