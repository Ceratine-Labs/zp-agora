<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Proves each connection reaches the database it claims to.
 *
 * "The config looks right" is not the same fact as "the query came back", and
 * on a project whose dev target IS production the difference matters. Run it
 * after any change to a connection, and on a fresh checkout before anything
 * else.
 */
class DbCheckCommand extends Command
{
    protected $signature = 'agora:db-check {--anchors : Also count the five anchor tables (slower)}';

    protected $description = 'Check every customer connection and report what answered';

    /** Tables whose row counts tell you the restore or the link is real. */
    private const ANCHORS = [
        'dbo.BRN_DailyBanking',
        'dbo.STK_StockReconLine',
        'dbo.RCN_BankStatementLinesPumpIT',
        'dbo.SS_Branch',
        'dbo.SS_Users',
    ];

    public function handle(): int
    {
        $failed = 0;

        foreach (config('agora.connections') as $role => $name) {
            $this->line('');
            $this->components->info("{$name}  ({$role})");

            try {
                $row = DB::connection($name)->selectOne('
                    SELECT DB_NAME() AS db,
                           SUSER_NAME() AS login,
                           (SELECT COUNT(*) FROM sys.tables) AS tables,
                           (SELECT COUNT(*) FROM sys.procedures) AS procs,
                           DATABASEPROPERTYEX(DB_NAME(), \'Recovery\') AS recovery,
                           DATABASEPROPERTYEX(DB_NAME(), \'Collation\') AS collation
                ');

                $this->components->twoColumnDetail('database', $row->db);
                $this->components->twoColumnDetail('login', $row->login);
                $this->components->twoColumnDetail('tables / procedures', "{$row->tables} / {$row->procs}");
                $this->components->twoColumnDetail('recovery / collation', "{$row->recovery} / {$row->collation}");

                foreach (DB::connection($name)->select('
                    SELECT s.name AS schemaName, COUNT(*) AS n
                    FROM sys.tables t JOIN sys.schemas s ON s.schema_id = t.schema_id
                    GROUP BY s.name ORDER BY n DESC
                ') as $s) {
                    $this->components->twoColumnDetail("schema {$s->schemaName}", (string) $s->n.' tables');
                }

                if ($this->option('anchors') && $role === 'primary') {
                    foreach (self::ANCHORS as $table) {
                        try {
                            $n = DB::connection($name)->selectOne("SELECT COUNT(*) AS n FROM {$table}")->n;
                            $this->components->twoColumnDetail($table, number_format($n));
                        } catch (Throwable $e) {
                            $this->components->twoColumnDetail($table, '<fg=yellow>not present</>');
                        }
                    }
                }
            } catch (Throwable $e) {
                $failed++;
                $this->components->error(substr($e->getMessage(), 0, 200));
            }
        }

        $this->line('');

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
