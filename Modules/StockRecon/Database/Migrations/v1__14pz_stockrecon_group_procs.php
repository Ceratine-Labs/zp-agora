<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(file_get_contents(__DIR__.'/../Procedures/usp_StockRecon_GetGroup.sql'));
        DB::unprepared(file_get_contents(__DIR__.'/../Procedures/usp_StockRecon_GetGroups.sql'));
    }

    public function down(): void {}
};
