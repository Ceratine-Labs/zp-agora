<?php

namespace App\Support\Database;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Base class for the migration that deploys a module's stored procedures.
 *
 * A module's procedure migration is always the same four lines, so it is a
 * base class rather than four lines copied into twenty modules — the day the
 * deployment rule changes, it changes once.
 *
 *     return new class extends ProcedureMigration {};
 *
 * Each .sql file is sent as its own batch: T-SQL requires CREATE PROCEDURE to
 * be the first statement in its batch, so concatenating files fails on the
 * second one. Files are deployed in sorted order, which is only significant if
 * one procedure calls another — and a procedure that does should say so in its
 * header rather than relying on the filename.
 *
 * down() drops nothing, deliberately. Live is forward-only, and a rollback
 * that removes a procedure the running application still calls is worse than
 * whatever it was undoing.
 */
abstract class ProcedureMigration extends Migration
{
    /** Where this migration's procedures live, relative to the migration file. */
    protected string $directory = '/../Procedures';

    public function up(): void
    {
        $files = $this->files();

        if ($files === []) {
            throw new RuntimeException(
                static::class.' deploys procedures but '.$this->path().' holds no .sql files. '
                .'An empty procedure migration is almost always a moved directory rather than an intention.'
            );
        }

        foreach ($files as $path) {
            $sql = trim(file_get_contents($path));

            // The gate script enforces this too, but a migration that would
            // create a procedure outside the agora schema must not run at all
            // — check-procs.sh only runs when someone runs it.
            if (preg_match('/CREATE\s+OR\s+ALTER\s+PROCEDURE\s+\[?'.preg_quote(config('agora.schema'), '/').'\]?\./i', $sql) !== 1) {
                throw new RuntimeException(
                    basename($path).' is not a CREATE OR ALTER PROCEDURE in the '
                    .config('agora.schema').' schema. Agora never creates objects anywhere else.'
                );
            }

            DB::unprepared($sql);
        }
    }

    public function down(): void
    {
        // No-op by design — see the class docblock.
    }

    /** @return array<int, string> */
    protected function files(): array
    {
        $files = glob($this->path().'/*.sql') ?: [];
        sort($files);

        return $files;
    }

    protected function path(): string
    {
        return dirname((new \ReflectionClass(static::class))->getFileName()).$this->directory;
    }
}
