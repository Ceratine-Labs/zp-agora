<?php

namespace App\Support\Database;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/** Module-qualified stamps, with the surviving pre-upgrade evidence retained. */
class SchemaVersionLedger
{
    public const LEGACY_MODULE = 'LegacyUnattributed';

    private const INDEX = 'UX_SchemaVersion_Module_Version';

    public static function record(string $module, string $version, string $note = ''): void
    {
        if (! preg_match('/^[A-Za-z][A-Za-z0-9]{0,79}$/D', $module)
            || strcasecmp($module, self::LEGACY_MODULE) === 0) {
            throw new RuntimeException('SchemaVersion requires a valid module; LegacyUnattributed is reserved for retained historical rows.');
        }
        if ($version === '' || mb_strlen($version) > 20) {
            throw new RuntimeException('SchemaVersion requires a non-empty version of at most 20 characters.');
        }

        self::withLock(function (Connection $db, string $schema) use ($module, $version, $note): void {
            self::upgradeLocked($db, $schema);
            $db->table("{$schema}.SchemaVersion")->updateOrInsert(
                ['BranchId' => (int) config('agora.group_branch_id'), 'Module' => $module, 'Version' => $version],
                ['Note' => $note !== '' ? $note : null, 'AppliedAt' => now(), 'UpdatedAt' => now()],
            );
        });
    }

    public static function upgrade(): void
    {
        self::withLock(fn (Connection $db, string $schema) => self::upgradeLocked($db, $schema));
    }

    /** @param  Closure(Connection, string): void  $operation */
    private static function withLock(Closure $operation): void
    {
        $schema = (string) config('agora.schema');
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $schema) || strtolower($schema) === 'dbo') {
            throw new RuntimeException('SchemaVersion requires an Agora-owned schema.');
        }

        $db = DB::connection();
        $db->beginTransaction();
        try {
            // Transaction ownership holds the lock through both DDL and stamp.
            // All new writers serialize here, including a fresh Core baseline
            // before the forward migration has been reached. Errors roll back
            // the whole transition; there is no Version-only fallback.
            $lock = $db->selectOne("DECLARE @result INT;
                EXEC @result = sys.sp_getapplock @Resource = ?, @LockMode = 'Exclusive',
                    @LockOwner = 'Transaction', @LockTimeout = 15000;
                SELECT @result AS result;", ["Agora.SchemaVersion.module-key.{$schema}"]);
            if ($lock === null || (int) $lock->result < 0) {
                throw new RuntimeException('Could not lock the SchemaVersion ledger for a module-qualified stamp.');
            }

            $operation($db, $schema);
            $db->commit();
        } catch (Throwable $error) {
            // A failed stamp fails the enclosing migration too. SQL Server
            // DDL can discard savepoints even with XACT_ABORT OFF; a later
            // committable XACT_STATE does not prove the savepoint survived.
            // Always abort/reset the whole migration transaction instead of
            // attempting a missing savepoint or retaining unstamped changes.
            $db->rollBack(0);

            throw $error;
        }
    }

    private static function upgradeLocked(Connection $db, string $schema): void
    {
        $table = "{$schema}.SchemaVersion";
        $columns = $db->select('SELECT name, TYPE_NAME(system_type_id) AS type_name, max_length, is_nullable
            FROM sys.columns WHERE object_id = OBJECT_ID(?)', [$table]);
        if ($columns === []) {
            throw new RuntimeException('Create SchemaVersion before recording a migration stamp.');
        }
        $module = collect($columns)->firstWhere('name', 'Module');
        if ($module !== null && ($module->type_name !== 'nvarchar' || (int) $module->max_length !== 160 || (bool) $module->is_nullable)) {
            throw new RuntimeException('SchemaVersion.Module has an unexpected definition; refusing to reinterpret existing evidence.');
        }

        $indexes = self::indexes($db, $table);
        $expected = ['BranchId', 'Module', 'Version'];
        if (isset($indexes[self::INDEX]) && ($indexes[self::INDEX]['columns'] !== $expected
            || ! $indexes[self::INDEX]['unique'] || $indexes[self::INDEX]['filtered'] || $indexes[self::INDEX]['disabled'])) {
            throw new RuntimeException('SchemaVersion module index has an unexpected definition.');
        }

        if ($module === null) {
            // Do not touch Note, AppliedAt, or any existing audit value. No
            // surviving value can prove which earlier module was overwritten.
            $db->statement("ALTER TABLE [{$schema}].[SchemaVersion] ADD [Module] NVARCHAR(80) NOT NULL
                CONSTRAINT [DF_SchemaVersion_Module] DEFAULT N'".self::LEGACY_MODULE."' WITH VALUES");
        }
        if (! isset($indexes[self::INDEX])) {
            $db->statement('CREATE UNIQUE INDEX ['.self::INDEX."] ON [{$schema}].[SchemaVersion] ([BranchId], [Module], [Version])");
        }

        // Retire only the exact superseded natural key; keep all other indexes
        // and the BranchId + Id primary key. Support either an index or a
        // unique constraint without dropping any rows.
        foreach ($indexes as $name => $index) {
            if (! $index['unique'] || $index['primary'] || $index['columns'] !== ['BranchId', 'Version']) {
                continue;
            }
            $quoted = str_replace(']', ']]', $name);
            $db->statement($index['constraint']
                ? "ALTER TABLE [{$schema}].[SchemaVersion] DROP CONSTRAINT [{$quoted}]"
                : "DROP INDEX [{$quoted}] ON [{$schema}].[SchemaVersion]");
        }
    }

    /** @return array<string, array{columns: list<string>, unique: bool, primary: bool, constraint: bool, filtered: bool, disabled: bool}> */
    private static function indexes(Connection $db, string $table): array
    {
        $indexes = [];
        foreach ($db->select('SELECT i.name, i.is_unique, i.is_primary_key, i.is_unique_constraint,
                i.has_filter, i.is_disabled, c.name AS column_name
            FROM sys.indexes i
            JOIN sys.index_columns ic ON ic.object_id = i.object_id AND ic.index_id = i.index_id
            JOIN sys.columns c ON c.object_id = ic.object_id AND c.column_id = ic.column_id
            WHERE i.object_id = OBJECT_ID(?) AND ic.key_ordinal > 0
            ORDER BY i.index_id, ic.key_ordinal', [$table]) as $row) {
            if (! isset($indexes[$row->name])) {
                $indexes[$row->name] = [
                    'columns' => [], 'unique' => (bool) $row->is_unique, 'primary' => (bool) $row->is_primary_key,
                    'constraint' => (bool) $row->is_unique_constraint, 'filtered' => (bool) $row->has_filter,
                    'disabled' => (bool) $row->is_disabled,
                ];
            }
            $indexes[$row->name]['columns'][] = $row->column_name;
        }

        return $indexes;
    }
}
