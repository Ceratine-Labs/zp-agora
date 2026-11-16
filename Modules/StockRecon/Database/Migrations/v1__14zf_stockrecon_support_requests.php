<?php

use App\Support\Database\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * Stock recon — a support request raised on a chain (slot 14zf).
 *
 * Ryan, 16 Nov 2026, after the client meeting: "per overview row a support
 * button with a table that logs the support tickets ... passes over the chain
 * detail ... date range and the area and all of the detail that you would
 * need, and then it allows them to put a message", with "a screenshot that the
 * user can edit in line with a couple of tools like Circle or FreeDraw".
 *
 * The chain is copied onto the request as it was when raised (site, area, item,
 * period, run), so the request still reads after the run is discarded or
 * previewed again. The screenshot is a PNG on the local disk, never public,
 * served through a permission-checked route. Agora's own table only.
 */
return new class extends Migration
{
    public function up(): void
    {
        MigrationHelper::table('StockReconSupportRequest', function (Blueprint $table) {
            $table->unsignedBigInteger('RunId');
            $table->unsignedBigInteger('RunLineId')->nullable();
            $table->integer('AreaNo');
            $table->string('AreaDescription', 100)->nullable();
            $table->string('StockItemNo', 10);
            $table->string('ItemDescription', 200)->nullable();
            $table->date('ChainFrom');
            $table->date('ChainTo');
            $table->string('Message', 2000);
            $table->string('ScreenshotPath', 300)->nullable();
            // open | resolved
            $table->string('Status', 20)->default('open');
            MigrationHelper::stamp($table, 'ResolvedAt');
            $table->integer('ResolvedBy')->nullable();
            $table->string('ResolutionNote', 1000)->nullable();
        });

        $schema = config('agora.schema');
        DB::statement("CREATE INDEX [IX_StockReconSupportRequest_Status] ON [{$schema}].[StockReconSupportRequest] ([BranchId], [Status], [CreatedAt]);");
    }

    /** Local sandbox only. Live and staging are forward-only — see CLAUDE.md. */
    public function down(): void
    {
        MigrationHelper::drop('StockReconSupportRequest');
    }
};
