<?php

namespace Tests\Feature\Support;

use App\Exceptions\AgoraProcException;
use App\Support\ProcedureService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Exercises the procedure layer against the real database.
 *
 * It calls Agora's own agora.usp_Core_* procedures rather than a legacy
 * dbo.sp_*: the connection is the customer's production instance, and calling
 * an unknown legacy procedure to see what it does is not a test, it is an
 * incident. Both procedures here are ours, read-only, and write nothing — so
 * there is nothing for tearDown to clean up.
 *
 * There is no RefreshDatabase and there never will be: it would drop the
 * customer's estate.
 */
class ProcedureServiceTest extends TestCase
{
    private ProcedureService $procedures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->procedures = app(ProcedureService::class);
    }

    public function test_it_calls_a_procedure_with_named_parameters(): void
    {
        $rows = $this->procedures->call('usp_Core_Ping', [
            'BranchId' => (int) config('agora.group_branch_id'),
            'Note' => 'from the test suite',
        ]);

        $this->assertCount(1, $rows);
        $this->assertSame('CORE_PING', $rows->first()->Code);
        $this->assertSame('from the test suite', $rows->first()->Note);

        // The parameter has to arrive as the value that was sent, not as the
        // one that happened to be bound in the same position.
        $this->assertSame(
            (int) config('agora.group_branch_id'),
            (int) $rows->first()->BranchId
        );
    }

    public function test_it_returns_every_result_set_not_just_the_first(): void
    {
        $sets = $this->procedures->callSets('usp_Core_Ping', [
            'BranchId' => (int) config('agora.group_branch_id'),
        ]);

        $this->assertCount(2, $sets, 'A second SELECT in the proc must not be silently dropped.');
        $this->assertSame('CORE_PING', $sets[0]->first()->Code);
        $this->assertSame(
            (int) config('agora.group_branch_id'),
            (int) $sets[1]->first()->BranchId
        );
    }

    public function test_a_bare_name_is_qualified_with_the_agora_schema(): void
    {
        // No schema on the call; it must not resolve against dbo.
        $rows = $this->procedures->call('usp_Core_Ping', ['BranchId' => 2]);

        $this->assertSame('CORE_PING', $rows->first()->Code);
    }

    public function test_a_structured_throw_becomes_an_agora_proc_exception(): void
    {
        try {
            $this->procedures->call('usp_Core_Refuse', ['Reason' => 'The banking day is closed.']);
            $this->fail('The procedure threw, but no AgoraProcException surfaced.');
        } catch (AgoraProcException $e) {
            $this->assertSame('CORE_REFUSED', $e->code());
            $this->assertSame('The banking day is closed.', $e->getMessage());
            $this->assertStringContainsString('usp_Core_Refuse', $e->procedure());
            $this->assertTrue($e->isBusinessRule());
        }
    }

    public function test_a_database_fault_is_not_dressed_up_as_a_business_rule(): void
    {
        // A missing procedure is a fault, not a refusal — it must keep its own
        // exception type so a deploy problem is never shown to a user as a
        // business message.
        $this->expectException(QueryException::class);

        $this->procedures->call('usp_Core_DoesNotExist', ['BranchId' => 2]);
    }

    public function test_the_write_path_refuses_a_procedure_that_returns_not_ok(): void
    {
        // usp_Core_Refuse throws rather than returning Ok = 0, so this asserts
        // the other half of the contract: write() surfaces both refusal shapes
        // as the same exception, and a caller need not know which a given proc
        // uses.
        $this->expectException(AgoraProcException::class);

        $this->procedures->write('usp_Core_Refuse', ['Reason' => 'Nope.']);
    }

    public function test_a_refusal_survives_being_called_inside_a_caller_s_transaction(): void
    {
        // The failure this pins: a writer sets XACT_ABORT ON, so its THROW
        // dooms the whole transaction, and SQL Server will not roll a doomed
        // transaction back to a savepoint. write() used to open a NESTED
        // transaction here, so the rollback failed with "Cannot roll back
        // trans2", that PDOException replaced the refusal, and the connection
        // was left at a transaction level nothing could unwind — the caller's
        // own rollBack() threw as well. One declined write poisoned the
        // connection for the rest of the request.
        $connection = DB::connection(config('agora.connections.app'));
        $connection->beginTransaction();

        try {
            $this->procedures->write('usp_Core_Refuse', ['Reason' => 'Inside a transaction.']);
            $this->fail('The procedure should have refused.');
        } catch (AgoraProcException $e) {
            $this->assertSame('CORE_REFUSED', $e->code());
        } finally {
            // The point of the fix: this still works. Before it, both this and
            // the level assertion below failed.
            $connection->rollBack();
        }

        $this->assertSame(0, $connection->transactionLevel(), 'A refused write must leave the connection usable.');
    }

    public function test_a_writer_joins_an_open_transaction_rather_than_nesting_one(): void
    {
        $connection = DB::connection(config('agora.connections.app'));
        $connection->beginTransaction();

        try {
            // usp_Core_Ping is a reader, but write() is what is under test: it
            // must not push the level to 2, because a savepoint is exactly what
            // a doomed transaction cannot return to.
            try {
                $this->procedures->write('usp_Core_Ping', ['BranchId' => 2]);
            } catch (AgoraProcException) {
                // Ping returns no status row, which is its own refusal — the
                // level is what this test is about.
            }

            $this->assertSame(1, $connection->transactionLevel(), 'write() must join the caller\'s transaction, not nest inside it.');
        } finally {
            $connection->rollBack();
        }
    }

    public function test_it_reaches_the_primary_connection_by_default(): void
    {
        $expected = DB::connection(config('agora.connections.app'))
            ->selectOne('SELECT DB_NAME() AS db')->db;

        $this->assertSame(config('database.connections.agora.database'), $expected);
    }
}
