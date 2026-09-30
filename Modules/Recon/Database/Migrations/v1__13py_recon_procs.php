<?php

use App\Support\Database\MigrationHelper;
use App\Support\Database\ProcedureMigration;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * Recon — one clock for every timestamp on a run (slot 13py).
 *
 * THE DEFECT. The application writes ReconRun.CreatedAt in UTC (config
 * app.timezone), and every recon procedure stamped its own times from
 * SYSDATETIME(), which on ZP-MIST-SVR is +02:00. A run previewed at 14:26 and
 * committed five minutes later read "committed 16:31", and the run page said
 * "Committed 1 hour from now". usp_Recon_RunFreshness corrected for it;
 * nothing else did.
 *
 * WHAT THIS DOES, in one transaction:
 *
 *  1. Locks the six recon ledger tables and notes the highest Id in each —
 *     BEFORE anything is redeployed, so no row the new procedures write can be
 *     in the set that gets shifted. The locks are held to the end, so nothing
 *     else writes to these tables while it runs.
 *  2. Redeploys Recon's procedures. Every timestamp they write is now
 *     SYSUTCDATETIME(): usp_Recon_Commit, _ManualMatch, _Reverse,
 *     _DiscardRuns (and its age sweep, which compares CreatedAt),
 *     _PairNearReferences, _SaveCriteria, _CopyCriteria. usp_Recon_RunFreshness
 *     no longer adds the server's offset — applied to UTC batches it would miss
 *     every claim made in the two hours after a preview.
 *  3. Moves the rows those procedures already wrote back onto UTC, by the
 *     server's own offset measured now: DATEDIFF(minute, SYSUTCDATETIME(),
 *     SYSDATETIME()). 120 on ZP-MIST-SVR; 0 on a UTC server, where nothing is
 *     touched at all.
 *
 * WHAT IS SHIFTED — only what a procedure is known to have written:
 *
 *  · ReconBatch    CreatedAt, ReversedAt, UpdatedAt      (no PHP writer)
 *  · ReconMatch    CreatedAt                             (no PHP writer)
 *  · ReconStamp    CreatedAt, AppliedAt, UpdatedAt       (no PHP writer)
 *  · ReconCriteria CreatedAt, UpdatedAt                  (no PHP writer)
 *  · ReconRun      CommittedAt, ReversedAt               (procedures only)
 *                  CreatedAt of a usp_Recon_ManualMatch run (the procedure made it)
 *                  UpdatedAt of a manual-match run, and of an application run
 *                    whose UpdatedAt is at least half the offset after its
 *                    CreatedAt. The application writes UpdatedAt only at
 *                    create, alongside CreatedAt; anything later came from
 *                    PairNearReferences, Commit, Reverse or DiscardRuns, and so
 *                    reads a full offset ahead of the run's UTC CreatedAt.
 *  · ReconRunLine  CreatedAt of a manual-match run's line
 *
 * WHAT IS LEFT ALONE, deliberately. ReconRunLine.UpdatedAt: the application
 * writes it on every tick (select() and selectAll()) and the procedures write
 * it at pairing, commit and reversal, so a given row's last writer cannot be
 * told from its value. Nothing displays or compares it. ReconRunGroup is
 * written by the application alone, so it was always UTC. Nothing in PumpIT,
 * MIST_Import or Alteryx is read or written, and the recon stamp columns are
 * batch numbers and states, not times.
 *
 * EXACTLY ONCE. Forward-only and recorded in agora.Migration like every other
 * migration. It also writes its own record to agora.SchemaVersion inside the
 * same transaction — the offset, the six Id cut-offs and the row counts — and
 * refuses to run if that record is already there, so a lost ledger row cannot
 * shift the same rows twice.
 *
 * TO REVERSE IT: add the recorded offset back (DATEADD(minute, +offset, …)) to
 * exactly the columns and conditions above, for rows at or below the recorded
 * cut-offs. That is an exact inverse — the columns are datetime and a shift by
 * whole minutes loses nothing. down() does not do it for you: reversing the
 * data without the procedures would put the two clocks back.
 */
return new class extends ProcedureMigration
{
    private const VERSION = '1.10';

    private const MARKER = 'Recon clock: UTC';

    private const MANUAL_MATCH = 'agora.usp_Recon_ManualMatch';

    /** In the order they are locked, which every run of this uses. */
    private const TABLES = ['ReconRun', 'ReconRunLine', 'ReconBatch', 'ReconMatch', 'ReconStamp', 'ReconCriteria'];

    public function up(): void
    {
        $app = config('agora.connections.app');

        // parent::up() deploys through the default connection, so the default
        // has to BE Agora's own database or the procedures and the data fix
        // would land in different places — and neither may land anywhere else.
        if (config('database.default') !== $app) {
            throw new RuntimeException(
                'v1__13py deploys procedures through the default connection ['.config('database.default').'], '
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
                    "v1__13py has already moved the recon timestamps onto UTC ({$already}). "
                    .'Running it again would shift the same rows a second time. Nothing was changed.'
                );
            }

            // A clerk's press beats this migration: if the two collide, SQL
            // Server rolls THIS back — nothing changed, run it again — rather
            // than failing their reconciliation.
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
                    ? array_fill_keys(['run_updated', 'run_created', 'run_committed', 'run_reversed', 'line_created', 'batch', 'match', 'stamp', 'criteria'], 0)
                    : $this->shift($db, $schema, $offset, $cut);

                $note = self::MARKER." — {$offset} min shifted off "
                    .'Run(Upd '.$shifted['run_updated'].', Crt '.$shifted['run_created']
                    .', Com '.$shifted['run_committed'].', Rev '.$shifted['run_reversed'].')'
                    .' Line(Crt '.$shifted['line_created'].') Batch '.$shifted['batch']
                    .' Match '.$shifted['match'].' Stamp '.$shifted['stamp'].' Criteria '.$shifted['criteria']
                    .'; Id cut-offs '.implode(' ', array_map(fn ($t) => "{$t}<={$cut[$t]}", self::TABLES))
                    .' (v1__13py)';

                MigrationHelper::recordVersion(self::VERSION, $note);

                echo "  v1__13py: {$note}".PHP_EOL;
            } finally {
                $db->unprepared('SET DEADLOCK_PRIORITY NORMAL;');
            }
        });
    }

    /**
     * One UPDATE per table (two per ReconRun column group), each bounded by its
     * cut-off. DATEADD on a NULL is NULL, so an unset ReversedAt stays unset.
     *
     * @param  array<string, int>  $cut
     * @return array<string, int>
     */
    private function shift(Connection $db, string $schema, int $offset, array $cut): array
    {
        $m = -$offset;
        $half = $offset * 30; // half the offset, in seconds
        $mm = self::MANUAL_MATCH;

        return [
            // UpdatedAt first and on its own: its test reads CreatedAt, which
            // the next statement moves for manual-match runs.
            'run_updated' => $db->update(
                "UPDATE [{$schema}].[ReconRun] SET UpdatedAt = DATEADD(minute, ?, UpdatedAt)
                 WHERE Id <= ? AND UpdatedAt IS NOT NULL
                   AND (ProcedureName = ? OR UpdatedAt >= DATEADD(second, ?, CreatedAt))",
                [$m, $cut['ReconRun'], $mm, $half],
            ),
            'run_created' => $db->update(
                "UPDATE [{$schema}].[ReconRun] SET CreatedAt = DATEADD(minute, ?, CreatedAt)
                 WHERE Id <= ? AND ProcedureName = ?",
                [$m, $cut['ReconRun'], $mm],
            ),
            'run_committed' => $db->update(
                "UPDATE [{$schema}].[ReconRun] SET CommittedAt = DATEADD(minute, ?, CommittedAt)
                 WHERE Id <= ? AND CommittedAt IS NOT NULL",
                [$m, $cut['ReconRun']],
            ),
            'run_reversed' => $db->update(
                "UPDATE [{$schema}].[ReconRun] SET ReversedAt = DATEADD(minute, ?, ReversedAt)
                 WHERE Id <= ? AND ReversedAt IS NOT NULL",
                [$m, $cut['ReconRun']],
            ),
            'line_created' => $db->update(
                "UPDATE l SET l.CreatedAt = DATEADD(minute, ?, l.CreatedAt)
                 FROM [{$schema}].[ReconRunLine] l
                 JOIN [{$schema}].[ReconRun] r ON r.Id = l.RunId AND r.BranchId = l.BranchId
                 WHERE l.Id <= ? AND r.ProcedureName = ?",
                [$m, $cut['ReconRunLine'], $mm],
            ),
            'batch' => $db->update(
                "UPDATE [{$schema}].[ReconBatch]
                 SET CreatedAt = DATEADD(minute, ?, CreatedAt),
                     ReversedAt = DATEADD(minute, ?, ReversedAt),
                     UpdatedAt = DATEADD(minute, ?, UpdatedAt)
                 WHERE Id <= ?",
                [$m, $m, $m, $cut['ReconBatch']],
            ),
            'match' => $db->update(
                "UPDATE [{$schema}].[ReconMatch] SET CreatedAt = DATEADD(minute, ?, CreatedAt)
                 WHERE Id <= ?",
                [$m, $cut['ReconMatch']],
            ),
            'stamp' => $db->update(
                "UPDATE [{$schema}].[ReconStamp]
                 SET CreatedAt = DATEADD(minute, ?, CreatedAt),
                     AppliedAt = DATEADD(minute, ?, AppliedAt),
                     UpdatedAt = DATEADD(minute, ?, UpdatedAt)
                 WHERE Id <= ?",
                [$m, $m, $m, $cut['ReconStamp']],
            ),
            'criteria' => $db->update(
                "UPDATE [{$schema}].[ReconCriteria]
                 SET CreatedAt = DATEADD(minute, ?, CreatedAt),
                     UpdatedAt = DATEADD(minute, ?, UpdatedAt)
                 WHERE Id <= ?",
                [$m, $m, $cut['ReconCriteria']],
            ),
        ];
    }

    /**
     * Refuses rather than pretending. The procedures cannot be put back (the
     * old text is gone, and ProcedureMigration never drops), and reversing the
     * data alone would put the two clocks back. The exact inverse is in the
     * header: +offset on the same columns, at or below the cut-offs recorded
     * in agora.SchemaVersion by up().
     */
    public function down(): void
    {
        throw new RuntimeException(
            'v1__13py is forward-only. To undo the data shift by hand, add the offset recorded in '
            .'agora.SchemaVersion (Note starting "'.self::MARKER.'") back to the columns listed in this '
            .'migration\'s header, for rows at or below the recorded Id cut-offs.'
        );
    }
};
