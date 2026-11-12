<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Creates the `agora` schema, and nothing else.
 *
 * This exists because of an ordering problem with a sharp edge: Laravel's
 * migration ledger is `agora.Migration`, and `migrate` creates that ledger
 * BEFORE it runs the first migration. So the schema cannot be created by a
 * migration — it has to exist first. Run once per database, then never again.
 *
 * It writes to the configured application database, so it prints the resolved
 * target and confirms outside --force before creating anything.
 */
class InitSchemaCommand extends Command
{
    protected $signature = 'agora:init-schema {--force : Skip the confirmation}';

    protected $description = 'Create the configured Agora schema in the application database (idempotent)';

    public function handle(): int
    {
        $schema = config('agora.schema');
        $connection = config('agora.connections.app');
        $db = DB::connection($connection);
        $database = $db->getDatabaseName();
        $host = $db->getConfig('host');
        $local = in_array(strtolower((string) $host), ['127.0.0.1', 'localhost', '::1'], true);

        $exists = $db->selectOne('SELECT SCHEMA_ID(?) AS id', [$schema])->id !== null;

        if ($exists) {
            $this->info("Schema [{$schema}] already exists in {$database} on {$host}. Nothing to do.");

            return self::SUCCESS;
        }

        $this->warn("About to CREATE SCHEMA [{$schema}] in {$database} on {$host}.");
        $this->line($local
            ? 'This is a write through a local (loopback) connection. Nothing in dbo is touched.'
            : 'This is a write through a remote connection; the target may be customer production. Nothing in dbo is touched.');

        if (! $this->option('force') && ! $this->confirm('Proceed?', false)) {
            $this->line('Aborted. No statement was sent.');

            return self::FAILURE;
        }

        $db->statement("CREATE SCHEMA [{$schema}]");

        $this->info("Created schema [{$schema}] in {$database} on {$host}.");

        return self::SUCCESS;
    }
}
