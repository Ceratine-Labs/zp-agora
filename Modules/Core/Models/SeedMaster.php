<?php

namespace Modules\Core\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Which seeders have run here. Laravel's `migrations` table, in this schema.
 *
 * The gate is the class name and nothing else: logged means done, absent means
 * run it. There is no version, because a seeder is a one-shot the way a
 * migration is — to change what was seeded, write another seeder. Only a
 * successful run is logged, so a failure leaves no row and retries next time.
 *
 * @property int $BranchId
 * @property int $Id
 * @property string $Module
 * @property string $SeederClass
 * @property int $Batch
 * @property Carbon|null $ExecutedAt
 * @property Carbon|null $CreatedAt
 * @property Carbon|null $UpdatedAt
 */
class SeedMaster extends BaseModel
{
    protected $table = 'SeedMaster';

    protected $casts = [
        // sqlsrv hands BranchId back as a string; the ledger is read by code
        // that compares it to the configured group id, which is an int.
        'BranchId' => 'integer',
        'Batch' => 'integer',
        'ExecutedAt' => 'datetime',
    ];

    /** Whether this seeder has run here. */
    public static function hasRun(string $seeder): bool
    {
        return static::ledger()->where('SeederClass', $seeder)->exists();
    }

    /**
     * Record a completed run. Called after the seeder returns, never before —
     * a seeder that throws leaves no row, so the next run picks it up again.
     */
    public static function record(string $seeder, string $module, int $batch): self
    {
        $row = static::ledger()->firstOrNew([
            'BranchId' => (int) config('agora.group_branch_id'),
            'SeederClass' => $seeder,
        ]);

        $row->forceFill([
            'BranchId' => (int) config('agora.group_branch_id'),
            'Module' => $module,
            'Batch' => $batch,
            'ExecutedAt' => now(),
            'CreatedAt' => $row->exists ? $row->CreatedAt : now(),
            'UpdatedAt' => now(),
        ])->save();

        return $row;
    }

    public static function nextBatch(): int
    {
        return (int) static::ledger()->max('Batch') + 1;
    }

    /**
     * Clear a seeder's row so it runs again. It opens the gate; it does not
     * undo what the seeder wrote.
     *
     * @return int Rows removed.
     */
    public static function forget(string $seeder): int
    {
        return static::ledger()->where('SeederClass', $seeder)->delete();
    }

    /**
     * Drop the most recent batch, so everything that ran together runs again.
     * Same caveat as {@see forget()}.
     *
     * @return int Rows removed.
     */
    public static function rollbackLastBatch(): int
    {
        $last = static::ledger()->max('Batch');

        return $last ? static::ledger()->where('Batch', $last)->delete() : 0;
    }

    /**
     * The ledger is estate-wide: it records what has been seeded into this
     * database, not what happened at a site. Every row carries the group
     * entity's id, and every read goes past the branch scope deliberately
     * rather than by accident.
     *
     * @return Builder<static>
     */
    public static function ledger(): Builder
    {
        return static::query()->acrossBranches();
    }
}
