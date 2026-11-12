<?php

namespace Tests\Feature\Support;

use App\Support\Database\MigrationHelper;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The audit stamps MigrationHelper adds are DATETIME2(0), read back from the
 * catalogue rather than from the PHP that asked for them.
 *
 * `dateTime($column, 0)` reads as DATETIME2(0) but Laravel's SQL Server
 * grammar treats precision 0 as none and emits DATETIME, so only the column
 * metadata proves the type. This writes two TEST- scratch tables in the agora
 * schema and drops them again in tearDown.
 */
class MigrationHelperAuditColumnsTest extends TestCase
{
    private const AUDITED = 'TEST-AuditColumns';

    private const STAMPED = 'TEST-Timestamps';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dropScratch();
    }

    protected function tearDown(): void
    {
        $this->dropScratch();
        parent::tearDown();
    }

    public function test_table_creates_its_audit_stamps_as_datetime2_0(): void
    {
        MigrationHelper::table(self::AUDITED, function (Blueprint $table) {
            $table->string('Code', 20);
        });

        $columns = $this->columns(self::AUDITED);

        foreach (['CreatedAt', 'UpdatedAt'] as $stamp) {
            $this->assertArrayHasKey($stamp, $columns);
            $this->assertSame('datetime2', $columns[$stamp]->DATA_TYPE, "{$stamp} must be DATETIME2, not legacy DATETIME.");
            $this->assertSame(0, (int) $columns[$stamp]->DATETIME_PRECISION, "{$stamp} must carry whole seconds.");
            $this->assertSame('YES', $columns[$stamp]->IS_NULLABLE);
        }

        foreach (['CreatedBy', 'UpdatedBy'] as $who) {
            $this->assertSame('int', $columns[$who]->DATA_TYPE);
            $this->assertSame('YES', $columns[$who]->IS_NULLABLE);
        }
    }

    public function test_add_timestamps_creates_datetime2_0(): void
    {
        Schema::create(config('agora.schema').'.'.self::STAMPED, function (Blueprint $table) {
            MigrationHelper::branchKey($table);
            MigrationHelper::addTimestamps($table);
        });

        $columns = $this->columns(self::STAMPED);

        foreach (['CreatedAt', 'UpdatedAt'] as $stamp) {
            $this->assertSame('datetime2', $columns[$stamp]->DATA_TYPE);
            $this->assertSame(0, (int) $columns[$stamp]->DATETIME_PRECISION);
            $this->assertSame('YES', $columns[$stamp]->IS_NULLABLE);
        }
    }

    /** @return array<string, object> */
    private function columns(string $table): array
    {
        return collect(DB::connection(config('agora.connections.app'))->select(
            'SELECT COLUMN_NAME, DATA_TYPE, DATETIME_PRECISION, IS_NULLABLE
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
            [config('agora.schema'), $table],
        ))->keyBy('COLUMN_NAME')->all();
    }

    private function dropScratch(): void
    {
        foreach ([self::AUDITED, self::STAMPED] as $table) {
            Schema::dropIfExists(config('agora.schema').'.'.$table);
        }
    }
}
