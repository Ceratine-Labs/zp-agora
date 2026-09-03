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
 * It is also the first statement Agora ever writes to the customer's
 * production database, which is why it prints what it is about to do and
 * confirms outside --force.
 */
class InitSchemaCommand extends Command
{
    protected $signature = 'agora:init-schema {--force : Skip the confirmation}';

    protected $description = 'Create the agora schema in the PumpIT database (idempotent)';

    public function handle(): int
    {
        $schema = config('agora.schema');
        $connection = config('agora.connections.primary');
        $database = config("database.connections.{$connection}.database");
        $host = config("database.connections.{$connection}.host");

        $exists = DB::connection($connection)
            ->selectOne('SELECT SCHEMA_ID(?) AS id', [$schema])->id !== null;

        if ($exists) {
            $this->info("Schema [{$schema}] already exists in {$database}. Nothing to do.");

            return self::SUCCESS;
        }

        $this->warn("About to CREATE SCHEMA [{$schema}] in {$database} on {$host}.");
        $this->line('This is a write to the customer production database. Nothing in dbo is touched.');

        if (! $this->option('force') && ! $this->confirm('Proceed?', false)) {
            $this->line('Aborted. No statement was sent.');

            return self::FAILURE;
        }

        DB::connection($connection)->statement("CREATE SCHEMA [{$schema}]");

        $this->info("Created schema [{$schema}] in {$database}.");

        return self::SUCCESS;
    }
}
