<?php

use App\Support\Database\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agora.StockWeeklyCase', function (Blueprint $table) {
            $table->string('ClosureOutcome', 60)->nullable();
            $table->decimal('RecoveryAmount', 19, 2)->nullable();
            $table->string('RecoveryReference', 1000)->nullable();
            $table->string('CorrectionStatus', 1)->nullable();
            $table->string('CorrectionReference', 1000)->nullable();
            $table->string('ActionTaken', 2000)->nullable();
            $table->date('ActionDate')->nullable();
            $table->string('SignoffNote', 1000)->nullable();
            $table->integer('SignedOffBy')->nullable();
            $table->dateTime('SignedOffAt', 0)->nullable();
            $table->date('ClosedDate')->nullable();
        });
        MigrationHelper::recordVersion('1.43', 'Weekly case recovery evidence, correction record and head-office approval trail.');
    }

    public function down(): void
    {
        // Forward only: preserve investigation and approval evidence.
    }
};
