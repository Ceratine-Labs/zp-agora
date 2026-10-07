<?php

use App\Support\Database\MigrationHelper;
use App\Support\Database\ProcedureMigration;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * Stock recon — one clock for every timestamp on a run (slot 14po), the same
 * fix v1__13py made to Recon on 24 Sep 2026.
 *
 * THE DEFECT. The application writes StockReconRun.CreatedAt in UTC (config
 * app.timezone), and every stock recon procedure stamped its own times from
 * SYSDATETIME(), which on ZP-MIST-SVR is +02:00. On live every committed run
 * read at least two hours between its preview and its commit (run 10051:
 * previewed 09:22, committed 11:45 — 143 minutes for a press made minutes
 * later), so "Committed …" on the run page read in the future, and the audit
 * trail had to read this one ledger raw while it converted every other one
 * (Ryan, 7 Oct 2026: "we are utc+2 … should we not correct that").
 *
 * WHAT THIS DOES, in one transaction:
 *
 *  1. Locks the four stock recon ledger tables and notes the highest Id in
 *     each — BEFORE anything is redeployed, so no row the new procedures write
 *     can be in the set that gets shifted.
 *  2. Redeploys StockRecon's procedures. Every timestamp they write is now
 *     SYSUTCDATETIME(): usp_StockRecon_PreviewBalancing, _Commit, _Reverse,
 *     _ResetToOriginal, _ReleaseDuplicate, _WithdrawRelease.
 *  3. Moves the rows those procedures already wrote back onto UTC, by the
 *     server's own offset measured now: DATEDIFF(minute, SYSUTCDATETIME(),
 *     SYSDATETIME()). 120 on ZP-MIST-SVR; 0 on a UTC server, where nothing is
 *     touched.
 *
 * WHAT IS SHIFTED — only what a procedure is known to have written:
 *
 *  · StockReconRun              CommittedAt, ReversedAt          (procedures only)
 *                               UpdatedAt where it is at least half the offset
 *                                 after CreatedAt. The application writes
 *                                 UpdatedAt at create and again with PreviewMs
 *                                 seconds later; anything later came from
 *                                 Commit, ResetToOriginal or Reverse, and so
 *                                 reads a full offset ahead of the run's UTC
 *                                 CreatedAt.
 *  · StockReconRunLine          CreatedAt                         (the preview inserts every line)
 *  · StockReconAmendment        CreatedAt, ReversedAt, UpdatedAt  (no PHP writer)
 *  · StockReconDuplicateRelease CreatedAt, WithdrawnAt, UpdatedAt (no PHP writer)
 *
 * WHAT IS LEFT ALONE, deliberately. StockReconRunLine.UpdatedAt: the
 * application writes it on every tick (StockReconService::select()) and the
 * procedures at commit, reset and reversal, so a row's last writer cannot be
 * told from its value. Nothing displays or compares it. StockReconRun.CreatedAt
 * was always the application's, so always UTC. Nothing in PumpIT is read or
 * written.
 *
 * The audit trail reads the stock recon ledger as UTC from v1__22f, which runs
 * after this.
 *
 * EXACTLY ONCE. Forward-only and recorded in agora.Migration like every other
 * migration; it also writes its own record to agora.SchemaVersion inside the
 * same transaction — the offset, the four Id cut-offs and the row counts — and
 * refuses to run if that record is already there.
 *
 * TO REVERSE IT: add the recorded offset back (DATEADD(minute, +offset, …)) to
 * exactly the columns and conditions above, for rows at or below the recorded
 * cut-offs. The columns are datetime and the shift is whole minutes, so that
 * is exact. down() does not do it: reversing the data without the procedures
 * would put the two clocks back.
 */
return new class extends ProcedureMigration
{
    private const VERSION = '1.30';

    private const MARKER = 'StockRecon clock: UTC';

    /** In the order they are locked. */
    private const TABLES = ['StockReconRun', 'StockReconRunLine', 'StockReconAmendment', 'StockReconDuplicateRelease'];

    public function up(): void
    {
        $app = config('agora.connections.app');

        // parent::up() deploys through the default connection, so the default
        // has to BE Agora's own database or the procedures and the data fix
        // would land in different places.
        if (config('database.default') !== $app) {
            throw new RuntimeException(
                'v1__14po deploys procedures through the default connection ['.config('database.default').'], '
                ."which is not Agora's own [{$app}]. Nothing was changed."
            );
        }

        $db = DB::connection($app);
        $schema = config('agora.schema');

        $db->transaction(function (Connection $db) use ($schema) {
            $already = $db->table("{$schema}.SchemaVersion")
                ->where('Note', 'like', self::MARKER.'%')
                ->value('Note');

            if ($already !== null) {
                throw new RuntimeException(
                    "v1__14po has already moved the stock recon timestamps onto UTC ({$already}). "
                    .'Running it again would shift the same rows a second time. Nothing was changed.'
                );
            }

            // A clerk's press beats this migration: if the two collide, SQL
            // Server rolls THIS back — nothing changed, run it again.
            $db->unprepared('SET DEADLOCK_PRIORITY LOW;');

            try {
                $offset = (int) $db->selectOne(
                    'SELECT DATEDIFF(minute, SYSUTCDATETIME(), SYSDATETIME()) AS Offset'
                )->Offset;

                // 1. The cut-offs, locked, before the new procedures exist.
                $cut = [];
                foreach (self::TABLES as $table) {
                    $cut[$table] = (int) ($db->selectOne(
                        "SELECT MAX(Id) AS Id FROM [{$schema}].[{$table}] WITH (TABLOCKX, HOLDLOCK)"
                    )->Id ?? 0);
                }

                // 2. The procedures, on UTC.
                parent::up();

                // 3. The rows already written, onto UTC.
                $shifted = $offset === 0
                    ? array_fill_keys(['run_updated', 'run_committed', 'run_reversed', 'line_created', 'amendment', 'release'], 0)
                    : $this->shift($db, $schema, $offset, $cut);

                $note = self::MARKER." — {$offset} min shifted off "
                    .'Run(Upd '.$shifted['run_updated'].', Com '.$shifted['run_committed'].', Rev '.$shifted['run_reversed'].')'
                    .' Line(Crt '.$shifted['line_created'].') Amendment '.$shifted['amendment'].' Release '.$shifted['release']
                    .'; Id cut-offs '.implode(' ', array_map(fn ($t) => "{$t}<={$cut[$t]}", self::TABLES))
                    .' (v1__14po)';

                MigrationHelper::recordVersion(self::VERSION, $note);

                echo "  v1__14po: {$note}".PHP_EOL;
            } finally {
                $db->unprepared('SET DEADLOCK_PRIORITY NORMAL;');
            }
        });
    }

    /**
     * One UPDATE per column group, each bounded by its cut-off. DATEADD on a
     * NULL is NULL, so an unset CommittedAt, ReversedAt or WithdrawnAt stays
     * unset.
     *
     * @param  array<string, int>  $cut
     * @return array<string, int>
     */
    private function shift(Connection $db, string $schema, int $offset, array $cut): array
    {
        $m = -$offset;
        $half = $offset * 30; // half the offset, in seconds

        return [
            'run_updated' => $db->update(
                "UPDATE [{$schema}].[StockReconRun] SET UpdatedAt = DATEADD(minute, ?, UpdatedAt)
                 WHERE Id <= ? AND UpdatedAt IS NOT NULL AND UpdatedAt >= DATEADD(second, ?, CreatedAt)",
                [$m, $cut['StockReconRun'], $half],
            ),
            'run_committed' => $db->update(
                "UPDATE [{$schema}].[StockReconRun] SET CommittedAt = DATEADD(minute, ?, CommittedAt)
                 WHERE Id <= ? AND CommittedAt IS NOT NULL",
                [$m, $cut['StockReconRun']],
            ),
            'run_reversed' => $db->update(
                "UPDATE [{$schema}].[StockReconRun] SET ReversedAt = DATEADD(minute, ?, ReversedAt)
                 WHERE Id <= ? AND ReversedAt IS NOT NULL",
                [$m, $cut['StockReconRun']],
            ),
            'line_created' => $db->update(
                "UPDATE [{$schema}].[StockReconRunLine] SET CreatedAt = DATEADD(minute, ?, CreatedAt)
                 WHERE Id <= ? AND CreatedAt IS NOT NULL",
                [$m, $cut['StockReconRunLine']],
            ),
            'amendment' => $db->update(
                "UPDATE [{$schema}].[StockReconAmendment]
                 SET CreatedAt = DATEADD(minute, ?, CreatedAt),
                     ReversedAt = DATEADD(minute, ?, ReversedAt),
                     UpdatedAt = DATEADD(minute, ?, UpdatedAt)
                 WHERE Id <= ?",
                [$m, $m, $m, $cut['StockReconAmendment']],
            ),
            'release' => $db->update(
                "UPDATE [{$schema}].[StockReconDuplicateRelease]
                 SET CreatedAt = DATEADD(minute, ?, CreatedAt),
                     WithdrawnAt = DATEADD(minute, ?, WithdrawnAt),
                     UpdatedAt = DATEADD(minute, ?, UpdatedAt)
                 WHERE Id <= ?",
                [$m, $m, $m, $cut['StockReconDuplicateRelease']],
            ),
        ];
    }

    /**
     * Refuses rather than pretending — see the header for the exact inverse,
     * from the offset and cut-offs recorded in agora.SchemaVersion by up().
     */
    public function down(): void
    {
        throw new RuntimeException(
            'v1__14po is forward-only. To undo the data shift by hand, add the offset recorded in '
            .'agora.SchemaVersion (Note starting "'.self::MARKER.'") back to the columns listed in this '
            .'migration\'s header, for rows at or below the recorded Id cut-offs.'
        );
    }
};
