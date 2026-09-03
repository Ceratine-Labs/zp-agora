<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Deploys Core's stored procedures (slot 01p).
 *
 * Every .sql file in Database/Procedures is one CREATE OR ALTER PROCEDURE, so
 * re-running is safe and a change to a proc shows up in `git diff` as an edit
 * to its body rather than a drop and recreate.
 *
 * Each file is sent as its own batch: T-SQL requires CREATE PROCEDURE to be
 * the first statement in its batch, and a file concatenated with others would
 * fail on the second one.
 *
 * down() intentionally drops nothing. Live is forward-only, and a rollback
 * that removes a procedure the running application calls is worse than the
 * change it was undoing.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach ($this->files() as $path) {
            DB::unprepared(file_get_contents($path));
        }
    }

    public function down(): void
    {
        // No-op by design — see the class docblock.
    }

    /** @return array<int, string> */
    private function files(): array
    {
        $files = glob(__DIR__.'/../Procedures/*.sql') ?: [];
        sort($files);

        return $files;
    }
};
