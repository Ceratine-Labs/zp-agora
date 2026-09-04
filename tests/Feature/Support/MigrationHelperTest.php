<?php

namespace Tests\Feature\Support;

use App\Support\Database\MigrationHelper;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * The two refusals that protect the customer's database, and the version stamp.
 *
 * Both refusals happen before any statement is sent, so these tests write
 * nothing. That is deliberate: the connection is the customer's production
 * database, and a test for "this must not create a table" that creates one to
 * find out would be self-defeating.
 */
class MigrationHelperTest extends TestCase
{
    public function test_it_refuses_to_create_a_table_outside_the_agora_schema(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/only create objects in the \[agora\] schema/');

        MigrationHelper::table('dbo.SS_Branch', fn () => null);
    }

    public function test_a_bare_table_name_is_qualified_rather_than_refused(): void
    {
        // The refusal is about naming ANOTHER schema, not about omitting one —
        // a migration writing `table('Branch')` is the normal case. It still
        // throws, because agora.Branch already exists; what matters is WHICH
        // error comes back.
        //
        // One catch, not two: QueryException extends RuntimeException, so
        // catching the parent first left the second block unreachable and the
        // assertion that mattered never ran. phpstan caught it.
        try {
            MigrationHelper::table('Branch', fn () => null);
            $this->fail('Expected the create to fail on the table already existing, not on the name.');
        } catch (\Throwable $e) {
            $this->assertStringNotContainsString(
                'only create objects',
                $e->getMessage(),
                'A bare table name must be qualified into the agora schema, not refused.'
            );
            // "There is already an object named 'Branch'" proves the name
            // resolved to agora.Branch and the statement was actually sent.
            $this->assertStringContainsString('Branch', $e->getMessage());
        }
    }

    public function test_a_natural_key_without_the_branch_column_is_refused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/omits BranchId/');

        MigrationHelper::naturalKey('Branch', ['Code', 'Name']);
    }

    public function test_the_schema_reports_its_own_version(): void
    {
        $row = DB::connection(config('agora.connections.app'))
            ->table(config('agora.schema').'.SchemaVersion')
            ->orderByDesc('AppliedAt')
            ->first();

        $this->assertNotNull($row, 'The base migration should have stamped a version.');

        // Not pinned to a particular number: every schema change bumps the
        // minor version, so asserting the current one means this test breaks
        // on each migration that does its job. What matters is that a version
        // is recorded, that it is well formed, and that it carries the group
        // branch rather than a null.
        $this->assertMatchesRegularExpression('/^\d+\.\d+$/', $row->Version);
        $this->assertSame((int) config('agora.group_branch_id'), (int) $row->BranchId);

        // The baseline is always present — a database that has never been
        // migrated should not pass this file at all.
        $versions = DB::connection(config('agora.connections.app'))
            ->table(config('agora.schema').'.SchemaVersion')
            ->pluck('Version')
            ->all();

        $this->assertContains('1.0', $versions);
    }

    public function test_the_legacy_branch_view_aliases_the_branch_column(): void
    {
        // The pattern every legacy table is read through: one name for the
        // branch, whatever the underlying column is called.
        $row = DB::connection(config('agora.connections.app'))
            ->table(config('agora.schema').'.vw_Branch')
            ->where('BranchId', (int) config('agora.group_branch_id'))
            ->first();

        $this->assertNotNull($row);
        $this->assertSame('Zululand Petroleum', trim($row->Name));
    }
}
