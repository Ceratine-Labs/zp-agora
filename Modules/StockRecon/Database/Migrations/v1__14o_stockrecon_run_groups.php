<?php

use App\Support\Database\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        MigrationHelper::table('StockReconRunGroup', function (Blueprint $table) {
            $table->uuid('GroupRef');
            $table->date('FromDate');
            $table->date('ToDate');
            $table->text('BranchIdsJson');
            $table->text('ParamsJson');
            $table->string('Note', 400)->nullable();
        });
        MigrationHelper::naturalKey('StockReconRunGroup', ['BranchId', 'GroupRef']);
        MigrationHelper::recordVersion('1.39', 'Stock recon: resumable, selected-site preview groups.');
    }

    public function down(): void
    {
        MigrationHelper::drop('StockReconRunGroup');
    }
};
