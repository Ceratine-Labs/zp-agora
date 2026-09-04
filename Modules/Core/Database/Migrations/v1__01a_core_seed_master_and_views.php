<?php

use App\Support\Database\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * Core, follow-on A: the seed ledger, the first legacy view, and the version stamp.
 *
 * A lettered follow-on rather than an edit to v1__01: PumpIT is production from
 * day one, so a table that already exists there can never be changed by
 * rewriting the migration that made it. Every change reaches an existing
 * database as a new file, always.
 *
 * Three things land here:
 *
 *  - `agora.SeedMaster`, the ledger that records which seeders have run in
 *    which environment. T004 builds the orchestrator on top of it; the table
 *    belongs with the schema conventions, which is this task.
 *  - `agora.vw_Branch`, the first of the `vw_*` views through which the legacy
 *    estate is read. It exists to prove the pattern as much as to be used:
 *    alias the legacy branch column to BranchId, and let everything downstream
 *    speak one name.
 *  - The version stamp itself, so the database can say what it is running.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Which seeders have run, where. A seeder is identified by class name,
        // and the environment is part of the key because "seeded" is true of a
        // machine, not of the code.
        MigrationHelper::table('SeedMaster', function (Blueprint $table) {
            $table->string('SeederClass', 200);
            $table->string('Environment', 40);
            $table->string('Checksum', 64)->nullable();
            $table->dateTime('RanAt', 0);
            $table->integer('RowsAffected')->nullable();
            $table->string('Note', 400)->nullable();
        });
        MigrationHelper::naturalKey('SeedMaster', ['BranchId', 'SeederClass', 'Environment']);

        $schema = config('agora.schema');

        /*
         * The first legacy view.
         *
         * Every legacy table Agora reads is reached through one of these, for
         * four reasons set out in plan §3.3: alias SSBranchId to BranchId so
         * one name is used everywhere; hide the _OLD / _DEFUNCT / PREPROD_
         * twins; bracket reserved-word columns; and apply whatever dedupe rule
         * the join map recorded for that table.
         *
         * Only the first applies to SS_Branch, which is precisely why it is a
         * good place to establish the pattern — nothing here is load-bearing
         * yet, so a mistake is cheap.
         *
         * CREATE OR ALTER so re-running is safe and a change shows in the diff
         * as an edit rather than a drop and recreate.
         */
        DB::unprepared("
            CREATE OR ALTER VIEW [{$schema}].[vw_Branch] AS
            SELECT
                b.SSBranchId    AS BranchId,
                b.BranchName    AS Name,
                b.BrandId,
                b.RegionId,
                b.ClassId,
                b.IsActive
            FROM dbo.SS_Branch b;
        ");

        MigrationHelper::recordVersion('1.0', 'Core baseline: identity, branches, the database-driven menu, the seed ledger and the first legacy view.');
    }

    public function down(): void
    {
        // Forward-only on anything that is not the local sandbox. Kept honest
        // rather than empty so a local database can still be unwound.
        DB::unprepared('DROP VIEW IF EXISTS ['.config('agora.schema').'].[vw_Branch];');
        MigrationHelper::drop('SeedMaster');
    }
};
