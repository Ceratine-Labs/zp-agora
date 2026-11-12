<?php

namespace Tests\Feature\Support;

use App\Support\Database\MigrationHelper;
use App\Support\Database\SchemaVersionLedger;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class SchemaVersionLedgerTest extends TestCase
{
    private string $scratch = '';

    private string $originalSchema;

    private int $originalBranch;

    private bool $transactionStarted = false;

    private bool $committedScratch = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->skipUnlessLocalStub();
        foreach (config('agora.connections') as $name) {
            $this->assertContains(config("database.connections.{$name}.host"), ['127.0.0.1', 'localhost', '::1']);
        }
        $this->originalSchema = config('agora.schema');
        $this->originalBranch = (int) config('agora.group_branch_id');
        $this->scratch = 'TEST_schema_version_'.bin2hex(random_bytes(6));
        $this->assertSame(0, (int) $this->db()->selectOne('SELECT COUNT(*) AS n FROM sys.schemas WHERE name = ?', [$this->scratch])->n);
        $this->assertSame(0, $this->db()->transactionLevel(), 'Scratch-schema tests require their own outer transaction.');
        $this->db()->beginTransaction();
        $this->transactionStarted = true;
        $this->db()->statement("CREATE SCHEMA [{$this->scratch}]");
        config(['agora.schema' => $this->scratch]);
    }

    protected function tearDown(): void
    {
        if ($this->transactionStarted) {
            config(['agora.schema' => $this->originalSchema, 'agora.group_branch_id' => $this->originalBranch]);
            $this->db()->rollBack(0);
            if ($this->committedScratch) {
                // Only failure-path tests commit this random, preflighted
                // schema, so the prior ledger survives an engine-wide abort.
                $this->db()->statement("IF OBJECT_ID('{$this->scratch}.SchemaVersion') IS NOT NULL DROP TABLE [{$this->scratch}].[SchemaVersion]");
                $this->db()->statement("IF SCHEMA_ID('{$this->scratch}') IS NOT NULL EXEC('DROP SCHEMA [{$this->scratch}]')");
            }
            $this->assertSame(0, (int) $this->db()->selectOne('SELECT COUNT(*) AS n FROM sys.schemas WHERE name = ?', [$this->scratch])->n,
                'The transaction must remove every scratch table, index, view and schema.');
        }
        parent::tearDown();
    }

    public function test_forward_upgrade_preserves_every_surviving_value_and_is_repeatable(): void
    {
        $this->legacyLedger();
        $before = $this->rows();
        $columns = array_keys((array) $before[0]);
        $migration = require base_path('Modules/Core/Database/Migrations/v1__01i_core_schema_version_modules.php');
        $migration->up();
        $migration->up();

        $legacy = $this->db()->table($this->table())->where('Module', SchemaVersionLedger::LEGACY_MODULE);
        $this->assertSame($before, $legacy->select($columns)->orderBy('Id')->get()->map(fn ($row) => (array) $row)->all());
        $this->assertSame(3, $legacy->count());
        $this->assertSame(4, $this->db()->table($this->table())->count());
        $this->assertSame(1, $this->db()->table($this->table())->where('Module', 'Core')->where('Version', '1.11')->count());
        $this->assertStringContainsString('overwritten historical notes cannot be recovered',
            $this->db()->table($this->table())->where('Module', 'Core')->where('Version', '1.11')->value('Note'));
        $this->assertSame('TEST-UTC-MARKER: exact prior daylight offsets retained',
            $this->db()->table($this->table())->where('Note', 'like', 'TEST-UTC-MARKER:%')->value('Note'));
        $this->assertModuleKey();
    }

    public function test_same_version_is_isolated_by_module_and_branch_and_idempotent(): void
    {
        $this->legacyLedger();
        MigrationHelper::recordVersion('1.5', 'TEST-Core first stamp', 'Core');
        MigrationHelper::recordVersion('1.5', 'TEST-Product stamp', 'Product');
        $product = $this->db()->table($this->table())->where('Module', 'Product')->first();
        MigrationHelper::recordVersion('1.5', 'TEST-Core updated stamp', 'Core');
        config(['agora.group_branch_id' => 987654]);
        MigrationHelper::recordVersion('1.5', 'TEST-Core other branch', 'Core');

        $this->assertSame((array) $product, (array) $this->db()->table($this->table())->where('Module', 'Product')->first());
        $this->assertSame(1, $this->db()->table($this->table())->where('Module', 'Core')->where('BranchId', $this->originalBranch)->count());
        $this->assertSame('TEST-Core updated stamp', $this->db()->table($this->table())->where('Module', 'Core')->where('BranchId', $this->originalBranch)->value('Note'));
        $this->assertSame('TEST-Core other branch', $this->db()->table($this->table())->where('Module', 'Core')->where('BranchId', 987654)->value('Note'));
        $this->assertSame(3, $this->db()->table($this->table())->where('Module', SchemaVersionLedger::LEGACY_MODULE)->count());
        $this->assertSame(6, $this->db()->table($this->table())->count());
        $this->assertModuleKey();

        // Prove SQL enforces the key even if a caller bypasses the helper.
        // Restore the connection option after the deliberately rejected insert.
        $duplicate = $this->db()->selectOne("DECLARE @no_count BIT = IIF((512 & @@OPTIONS) = 512, 1, 0);
            SET NOCOUNT ON;
            DECLARE @was_on BIT = IIF((16384 & @@OPTIONS) = 16384, 1, 0), @error INT = 0;
            SET XACT_ABORT OFF;
            BEGIN TRY
                INSERT INTO [{$this->scratch}].[SchemaVersion] (BranchId, Module, Version, AppliedAt)
                VALUES (987654, N'Core', N'1.5', GETDATE());
            END TRY
            BEGIN CATCH
                SET @error = ERROR_NUMBER();
            END CATCH;
            IF @was_on = 1 SET XACT_ABORT ON;
            IF @no_count = 0 SET NOCOUNT OFF;
            SELECT @error AS error_number;");
        $this->assertContains((int) $duplicate->error_number, [2601, 2627]);
        $this->assertSame(6, $this->db()->table($this->table())->count());
    }

    public function test_fresh_core_stamp_is_qualified_before_forward_migration_and_unchanged_callers_resolve_modules(): void
    {
        // These are the actual unchanged historical callers, not stand-ins.
        $core = require base_path('Modules/Core/Database/Migrations/v1__01_core_tables.php');
        $core->up();
        $this->assertSame(1, $this->db()->table($this->table())->where('Module', 'Core')->where('Version', '1.0')->count());
        $this->assertSame(0, $this->db()->table($this->table())->where('Module', SchemaVersionLedger::LEGACY_MODULE)->count());
        $this->assertModuleKey();

        MigrationHelper::recordVersion('1.46', 'TEST-Core shares a numeric version', 'Core');
        $email = require base_path('Modules/Email/Database/Migrations/v1__30_email_tables.php');
        $email->up();
        $this->assertSame(['Core', 'Email'], $this->db()->table($this->table())->where('Version', '1.46')->orderBy('Module')->pluck('Module')->all());
        $forward = require base_path('Modules/Core/Database/Migrations/v1__01i_core_schema_version_modules.php');
        $forward->up();
        $forward->up();
        $this->assertSame(4, $this->db()->table($this->table())->count());
        $this->assertSame(0, $this->db()->table($this->table())->where('Module', SchemaVersionLedger::LEGACY_MODULE)->count());
    }

    public function test_unattributed_malformed_and_reserved_callers_are_refused_before_upgrading_or_writing(): void
    {
        $this->legacyLedger();
        $before = $this->rows();
        foreach ([null, '', 'Core/Other', ' Core', 'LegacyUnattributed', 'legacyunattributed', str_repeat('A', 81)] as $module) {
            try {
                MigrationHelper::recordVersion('1.5', 'TEST-must never overwrite the surviving stamp', $module);
                $this->fail('Invalid module attribution must be refused.');
            } catch (RuntimeException $error) {
                $this->assertStringContainsString('module', $error->getMessage());
            }
            $this->assertSame($before, $this->rows());
            $this->assertFalse($this->hasModule());
        }
        SchemaVersionLedger::upgrade();
        $after = $this->rows();
        try {
            MigrationHelper::recordVersion('1.5', 'TEST-cannot replace retained evidence', 'LegacyUnattributed');
            $this->fail('The historical module is reserved after upgrade too.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('reserved', $error->getMessage());
        }
        $this->assertSame($after, $this->rows());
    }

    public function test_incompatible_index_is_refused_without_changing_the_old_ledger(): void
    {
        $this->legacyLedger();
        $before = $this->rows();
        $this->db()->statement("CREATE INDEX [UX_SchemaVersion_Module_Version] ON [{$this->scratch}].[SchemaVersion] ([Version])");
        $this->committedScratch = true;
        $this->db()->commit();
        $this->db()->beginTransaction();
        try {
            MigrationHelper::recordVersion('1.5', 'TEST-must not fall back to version-only', 'Core');
            $this->fail('An unexpected index must stop the migration.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('unexpected definition', $error->getMessage());
        }
        $this->assertFalse($this->hasModule());
        $this->assertSame($before, $this->rows());
        $this->assertSame(1, (int) $this->db()->selectOne('SELECT COUNT(*) AS n FROM sys.indexes WHERE object_id = OBJECT_ID(?) AND name = ?',
            [$this->table(), 'UX_SchemaVersion_Natural'])->n);
    }

    public function test_upgrade_and_stamp_roll_back_together_and_hold_the_lock_until_outer_transaction_ends(): void
    {
        $this->legacyLedger();
        $before = $this->rows();
        $this->db()->beginTransaction();
        MigrationHelper::recordVersion('1.5', 'TEST-atomic stamp', 'Core');
        $this->assertTrue($this->hasModule());
        $this->assertSame(4, $this->db()->table($this->table())->count());
        $this->db()->rollBack();
        $this->assertFalse($this->hasModule());
        $this->assertSame($before, $this->rows());

        MigrationHelper::recordVersion('1.5', 'TEST-lock held', 'Core');
        $name = 'schema_version_lock_probe';
        $original = config("database.connections.{$name}");
        config(["database.connections.{$name}" => $this->db()->getConfig()]);
        $probe = DB::connection($name);
        try {
            $probe->beginTransaction();
            $result = $probe->selectOne("DECLARE @result INT;
                EXEC @result = sys.sp_getapplock @Resource = ?, @LockMode = 'Exclusive', @LockOwner = 'Transaction', @LockTimeout = 0;
                SELECT @result AS result;", ['Agora.SchemaVersion.module-key.'.$this->scratch]);
            $this->assertSame(-1, (int) $result->result, 'A second migration writer must not pass the transaction-owned ledger lock.');
        } finally {
            $probe->rollBack();
            DB::purge($name);
            config(["database.connections.{$name}" => $original]);
        }
    }

    public function test_failed_index_creation_preserves_committed_evidence_with_and_without_an_outer_transaction(): void
    {
        $this->legacyLedger();
        // A malformed legacy ledger is evidence to retain, not permission to
        // discard a duplicate. Fail after adding Module, at index creation.
        $this->db()->statement("DROP INDEX [UX_SchemaVersion_Natural] ON [{$this->scratch}].[SchemaVersion]");
        $this->db()->table($this->table())->insert(['BranchId' => $this->originalBranch, 'Version' => '1.5',
            'Note' => 'TEST-duplicate historical evidence', 'AppliedAt' => '2026-09-30 13:14:15']);
        $before = $this->rows();
        $this->committedScratch = true;
        $this->db()->commit();
        $wasOn = (int) $this->db()->selectOne('SELECT IIF((16384 & @@OPTIONS) = 16384, 1, 0) AS was_on')->was_on;
        try {
            foreach ([[false, false], [true, false], [false, true], [true, true]] as [$outer, $abortOn]) {
                $this->db()->statement('SET XACT_ABORT '.($abortOn ? 'ON' : 'OFF'));
                if ($outer) {
                    $this->db()->beginTransaction();
                }
                try {
                    MigrationHelper::recordVersion('1.5', 'TEST-never falls back to overwriting a duplicate', 'Core');
                    $this->fail('Ambiguous legacy duplicates must stop the upgrade.');
                } catch (QueryException $error) {
                    $this->assertSame(1505, (int) $error->errorInfo[1]);
                }
                $this->assertFalse($this->hasModule());
                $this->assertSame($before, $this->rows());
                $this->assertSame(0, (int) $this->db()->selectOne('SELECT COUNT(*) AS n FROM sys.indexes WHERE object_id = OBJECT_ID(?) AND name = ?',
                    [$this->table(), 'UX_SchemaVersion_Module_Version'])->n);
                $this->assertSame(0, $this->db()->transactionLevel(), 'An engine-aborted transaction must not leave stale Laravel savepoint state.');
                $this->assertSame(0, (int) $this->db()->selectOne('SELECT XACT_STATE() AS state')->state);
            }
        } finally {
            $this->db()->rollBack(0);
            $this->db()->statement('SET XACT_ABORT '.($wasOn === 1 ? 'ON' : 'OFF'));
        }
    }

    private function legacyLedger(): void
    {
        MigrationHelper::table('SchemaVersion', function (Blueprint $table): void {
            $table->string('Version', 20);
            $table->string('Note', 400)->nullable();
            $table->dateTime('AppliedAt', 0);
        });
        MigrationHelper::naturalKey('SchemaVersion', ['BranchId', 'Version']);
        $this->db()->table($this->table())->insert([
            ['BranchId' => $this->originalBranch, 'Version' => '1.5', 'Note' => 'TEST-surviving note; prior module unknown',
                'AppliedAt' => '2026-09-27 10:11:12', 'CreatedAt' => '2026-09-01 01:02:03', 'CreatedBy' => 123,
                'UpdatedAt' => '2026-09-27 10:11:12', 'UpdatedBy' => 456],
            ['BranchId' => $this->originalBranch, 'Version' => '1.11', 'Note' => 'TEST-UTC-MARKER: exact prior daylight offsets retained',
                'AppliedAt' => '2026-09-28 11:12:13', 'CreatedAt' => null, 'CreatedBy' => null, 'UpdatedAt' => null, 'UpdatedBy' => null],
            ['BranchId' => 987654, 'Version' => '1.5', 'Note' => null,
                'AppliedAt' => '2026-09-29 12:13:14', 'CreatedAt' => null, 'CreatedBy' => null, 'UpdatedAt' => null, 'UpdatedBy' => null],
        ]);
    }

    private function assertModuleKey(): void
    {
        $rows = $this->db()->select('SELECT i.name, i.is_unique, c.name AS column_name
            FROM sys.indexes i JOIN sys.index_columns ic ON ic.object_id = i.object_id AND ic.index_id = i.index_id
            JOIN sys.columns c ON c.object_id = ic.object_id AND c.column_id = ic.column_id
            WHERE i.object_id = OBJECT_ID(?) AND i.name = ? AND ic.key_ordinal > 0 ORDER BY ic.key_ordinal',
            [$this->table(), 'UX_SchemaVersion_Module_Version']);
        $this->assertSame(['BranchId', 'Module', 'Version'], array_column($rows, 'column_name'));
        $this->assertSame([1, 1, 1], array_map(fn ($row) => (int) $row->is_unique, $rows));
        $this->assertSame(0, (int) $this->db()->selectOne('SELECT COUNT(*) AS n FROM sys.indexes WHERE object_id = OBJECT_ID(?) AND name = ?',
            [$this->table(), 'UX_SchemaVersion_Natural'])->n);
    }

    private function hasModule(): bool
    {
        return (int) $this->db()->selectOne("SELECT COUNT(*) AS n FROM sys.columns WHERE object_id = OBJECT_ID(?) AND name = 'Module'", [$this->table()])->n === 1;
    }

    /** @return list<array<string, mixed>> */
    private function rows(): array
    {
        return $this->db()->table($this->table())->orderBy('Id')->get()->map(fn ($row) => (array) $row)->all();
    }

    private function table(): string
    {
        return $this->scratch.'.SchemaVersion';
    }

    private function db(): Connection
    {
        return DB::connection(config('agora.connections.app'));
    }
}
