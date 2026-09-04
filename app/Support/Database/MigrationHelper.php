<?php

namespace App\Support\Database;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * The shape every Agora table shares, in one place.
 *
 * Plan §3.3 fixes the shape: `BranchId` first in the clustered key, a
 * `BIGINT IDENTITY` surrogate, stamped audit columns, a ROWVERSION for
 * optimistic concurrency, and a natural key that always INCLUDES the branch
 * column — a key without it is what made legacy lookups fan out 4-22x.
 *
 * Two guards live here because forgetting either one is expensive and silent:
 *
 *  - `table()` refuses any name that is not in the `agora` schema. Agora
 *    develops against the customer's production database, so a migration that
 *    forgets its schema prefix would create a table in `dbo` — in production,
 *    on the first run, with no warning.
 *  - `dropForeign()` has no counterpart because there are no DB-level foreign
 *    keys inside `Schema::create` (§3.3 puts them in one late file per module,
 *    since the reference graph has cycles SQL Server will not accept).
 */
class MigrationHelper
{
    /**
     * Create an `agora` table with the standard spine already in place.
     *
     * The callback receives the Blueprint after BranchId and Id exist, so a
     * migration only ever writes the columns that are actually its own.
     */
    public static function table(string $name, callable $columns): void
    {
        $qualified = self::qualify($name);

        Schema::create($qualified, function (Blueprint $table) use ($columns) {
            self::branchKey($table);
            $columns($table);
            self::addAuditColumns($table);
        });

        // Laravel's bigIncrements makes Id the primary key on its own. The
        // clustered key has to be (BranchId, Id), so the generated one is
        // dropped and replaced. Done in raw T-SQL because the Blueprint has no
        // vocabulary for "replace the primary key I just made".
        $bare = self::bare($name);
        $schema = config('agora.schema');
        DB::statement("
            DECLARE @pk SYSNAME = (
                SELECT name FROM sys.key_constraints
                WHERE type = 'PK' AND parent_object_id = OBJECT_ID('{$schema}.{$bare}')
            );
            IF @pk IS NOT NULL EXEC('ALTER TABLE [{$schema}].[{$bare}] DROP CONSTRAINT [' + @pk + ']');
            ALTER TABLE [{$schema}].[{$bare}]
                ADD CONSTRAINT [PK_{$bare}] PRIMARY KEY CLUSTERED ([BranchId], [Id]);
        ");
    }

    /**
     * The natural key. Always includes BranchId, and this refuses to build one
     * that does not — a unique index on the business columns alone is the bug
     * that let one branch's row block another's.
     */
    /** @param  array<int, string>  $columns */
    public static function naturalKey(string $table, array $columns): void
    {
        if (! in_array('BranchId', $columns, true)) {
            throw new RuntimeException(
                "Natural key on {$table} omits BranchId. Every unique key in Agora includes the branch column (plan §3.3)."
            );
        }

        $schema = config('agora.schema');
        $bare = self::bare($table);
        $cols = collect($columns)->map(fn ($c) => "[{$c}]")->implode(', ');

        DB::statement("CREATE UNIQUE INDEX [UX_{$bare}_Natural] ON [{$schema}].[{$bare}] ({$cols});");
    }

    /** ROWVERSION for optimistic concurrency on an editable row. */
    public static function addRowVersion(string $table): void
    {
        self::rowVersion($table);
    }

    /** Soft-delete columns, under the name plan §3.3 uses. */
    public static function addSoftDeletes(string $table): void
    {
        self::softDeletes($table);
    }

    /** ROWVERSION for optimistic concurrency on an editable row. */
    public static function rowVersion(string $table): void
    {
        $schema = config('agora.schema');
        $bare = self::bare($table);

        DB::statement("ALTER TABLE [{$schema}].[{$bare}] ADD [RowVer] ROWVERSION;");
    }

    /** Soft-delete columns. Masters soft-delete; transactions are reversed, never deleted. */
    public static function softDeletes(string $table): void
    {
        $schema = config('agora.schema');
        $bare = self::bare($table);

        DB::statement("ALTER TABLE [{$schema}].[{$bare}] ADD [DeletedAt] DATETIME2(0) NULL, [DeletedBy] INT NULL;");
    }

    /** Drop, for a down() that is allowed to run (local only — live is forward-only). */
    public static function drop(string $name): void
    {
        Schema::dropIfExists(self::qualify($name));
    }

    /**
     * The two columns that open every agora table, in this order.
     *
     * BranchId first is not cosmetic: it leads the clustered index, so a
     * branch-scoped read — which is every read in the system — walks one
     * contiguous run of the table instead of seeking across the whole of it.
     * Reversing the pair is the difference between a range scan and a scan.
     */
    public static function branchKey(Blueprint $table): void
    {
        $table->integer('BranchId');
        $table->bigIncrements('Id');
    }

    /**
     * Who wrote the row and when.
     *
     * Nullable, because a seeder writing reference data has no user to
     * attribute and stamping a fake id would be worse than an honest null.
     * The procedure sets them from its @UserId argument; PHP does not
     * (plan §3.3).
     */
    public static function addAuditColumns(Blueprint $table): void
    {
        $table->dateTime('CreatedAt', 0)->nullable();
        $table->integer('CreatedBy')->nullable();
        $table->dateTime('UpdatedAt', 0)->nullable();
        $table->integer('UpdatedBy')->nullable();
    }

    /** Timestamps without the who — for rows nothing edits. */
    public static function addTimestamps(Blueprint $table): void
    {
        $table->dateTime('CreatedAt', 0)->nullable();
        $table->dateTime('UpdatedAt', 0)->nullable();
    }

    /**
     * Money. DECIMAL(18,2), never float.
     *
     * A float cannot hold 0.10 exactly, so a column of them does not add up to
     * what the till said. On a system whose entire job is making the dip tie to
     * the pump and the declaration tie to the bank, that is not a rounding
     * detail, it is the product failing.
     */
    public static function money(Blueprint $table, string $column, bool $nullable = false): void
    {
        $definition = $table->decimal($column, 18, 2);
        $nullable ? $definition->nullable() : $definition->default(0);
    }

    /** Litres. Three decimal places — fuel is dipped and metered to the millilitre. */
    public static function litres(Blueprint $table, string $column, bool $nullable = false): void
    {
        $definition = $table->decimal($column, 18, 3);
        $nullable ? $definition->nullable() : $definition->default(0);
    }

    /**
     * A percentage or a cents-per-litre rate. DECIMAL(9,4).
     *
     * Four places because fuel margin is quoted in cents per litre and a
     * two-place column silently rounds a 1.75c margin into either 1.8 or 1.7,
     * which across a month of volume is a real number.
     */
    public static function pct(Blueprint $table, string $column, bool $nullable = false): void
    {
        $definition = $table->decimal($column, 9, 4);
        $nullable ? $definition->nullable() : $definition->default(0);
    }

    /**
     * Record that the schema moved to a new version.
     *
     * Called by the migration that introduces the change, so the database can
     * say what it is running without anyone reading the migrations folder.
     * Idempotent: re-running a migration does not double-stamp.
     */
    public static function recordVersion(string $version, string $note = ''): void
    {
        $schema = config('agora.schema');
        $branchId = (int) config('agora.group_branch_id');

        DB::table("{$schema}.SchemaVersion")->updateOrInsert(
            ['BranchId' => $branchId, 'Version' => $version],
            ['Note' => $note !== '' ? $note : null, 'AppliedAt' => now(), 'UpdatedAt' => now()],
        );
    }

    /**
     * Refuse anything outside the agora schema. A bare name is qualified; a
     * name already carrying a DIFFERENT schema is a mistake, not a shortcut.
     */
    protected static function qualify(string $name): string
    {
        $schema = config('agora.schema');

        if (! str_contains($name, '.')) {
            return "{$schema}.{$name}";
        }

        if (! str_starts_with($name, "{$schema}.")) {
            throw new RuntimeException(
                "Refusing to create [{$name}]: Agora migrations only create objects in the [{$schema}] schema. "
                ."Nothing in the customer's legacy estate is altered by a migration."
            );
        }

        return $name;
    }

    protected static function bare(string $name): string
    {
        return str_contains($name, '.') ? explode('.', $name, 2)[1] : $name;
    }
}
