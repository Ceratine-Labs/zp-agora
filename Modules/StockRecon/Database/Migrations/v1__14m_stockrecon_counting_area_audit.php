<?php

use App\Support\Database\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        MigrationHelper::addRowVersion('StockAreaSetting');
        MigrationHelper::table('StockAreaSettingChange', function (Blueprint $table) {
            $table->integer('AreaNo');
            $table->boolean('PriorIsPreparedFood');
            $table->boolean('NewIsPreparedFood');
            $table->string('PriorFoodBasis', 60)->nullable();
            $table->dateTime('PriorConfirmedAt', 0)->nullable();
            $table->integer('PriorConfirmedBy')->nullable();
            $table->string('Reason', 400);
        });
        MigrationHelper::recordVersion('1.37', 'Counting areas: explicit prepared-food confirmation, optimistic concurrency and immutable Agora change history.');
    }

    public function down(): void
    {
        // Forward only: confirmations and their history are business records.
    }
};
