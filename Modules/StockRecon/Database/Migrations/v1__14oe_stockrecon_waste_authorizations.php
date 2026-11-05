<?php

use App\Support\Database\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        MigrationHelper::table('StockWasteAuthorization', function (Blueprint $table) {
            $table->date('TransactionDate');
            $table->integer('ShiftNo');
            $table->integer('AreaNo');
            $table->string('StockItemNo', 10);
            $table->decimal('CapturedGood', 18, 4);
            $table->dateTime('CapturedAt')->nullable();
            $table->decimal('AuthorizedGood', 18, 4);
            $table->boolean('IsAuthorized');
        });
        $schema = config('agora.schema');
        DB::statement("CREATE INDEX [IX_StockWasteAuthorization_Line] ON [{$schema}].[StockWasteAuthorization] ([BranchId], [TransactionDate], [ShiftNo], [AreaNo], [StockItemNo], [Id] DESC)");
    }

    public function down(): void
    {
        // Authorization history is retained; production migrations are forward-only.
    }
};
