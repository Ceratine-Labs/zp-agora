<?php

namespace Tests\Feature\StockRecon;

use App\Exceptions\AgoraProcException;
use App\Support\ProcedureService;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\StockRecon\Models\StockReconRun;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** The real write procedures, with the migration and all fixtures rolled back. */
class AmendmentRecommitTest extends TestCase
{
    private const BRANCH = 999;

    private const AREA = 91;

    private const ITEM = 'TRCM';

    private ?Connection $database = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->skipUnlessLocalStub();
        $this->assertSame(config('agora.connections.app'), config('database.default'));
        $this->database = DB::connection(config('agora.connections.app'));
        $this->database->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->database !== null) {
            $this->database->rollBack();
        }
        parent::tearDown();
    }

    public function test_a_live_run_can_be_committed_and_reversed_twice_without_changing_its_first_history(): void
    {
        $run = $this->newRun();
        $this->proposal($run, 1, [10, 0, 8, 1], [10, 9]);
        $this->proposal($run, 2, [8, 0, 6, 3], [9, 6]);

        // Existing reversed rows must survive the upgrade unchanged.
        $this->assertSame('COMMITTED', $this->commit($run)->Code);
        $this->assertSource([[10, 0, 9, 1], [9, 0, 6, 3]]);
        $this->reverse($run);
        $this->assertSource([[10, 0, 8, 1], [8, 0, 6, 3]]);
        $firstHistory = $this->amendments($run)->orderBy('Id')->get()->all();
        $this->assertCount(2, $firstHistory);

        $this->migrateActiveKey();
        $this->assertEquals($firstHistory, $this->amendments($run)->orderBy('Id')->get()->all());
        $this->assertSame('COMMITTED', $this->commit($run)->Code);
        $this->assertSource([[10, 0, 9, 1], [9, 0, 6, 3]]);
        $this->assertSame(2, $this->amendments($run)->where('State', 'active')->count());
        $this->assertEquals($firstHistory, $this->amendments($run)->where('State', 'reversed')->orderBy('Id')->get()->all());

        $this->reverse($run);
        $this->assertSource([[10, 0, 8, 1], [8, 0, 6, 3]]);
        $this->assertSame(0, $this->amendments($run)->where('State', 'active')->count());
        $this->assertSame(4, $this->amendments($run)->where('State', 'reversed')->count());
        $this->assertEquals($firstHistory, $this->amendments($run)->orderBy('Id')->limit(2)->get()->all());
        $this->assertSame('reversed', $run->fresh()->Status);
        $this->assertSame(['pending'], $this->db()->table('agora.StockReconRunLine')->where('RunId', $run->Id)
            ->distinct()->pluck('CommitState')->all());
    }

    public function test_the_key_retains_reversed_rows_but_still_refuses_two_active_amendments(): void
    {
        $this->migrateActiveKey();
        $index = $this->db()->selectOne("SELECT is_unique, filter_definition FROM sys.indexes
            WHERE object_id = OBJECT_ID('agora.StockReconAmendment') AND name = 'UX_StockReconAmendment_Natural'");
        $this->assertNotNull($index);
        $this->assertEquals(1, $index->is_unique);
        $this->assertSame("State='active'", preg_replace('/[\[\]\s()]/', '', $index->filter_definition));
        $keys = $this->db()->select("SELECT c.name FROM sys.indexes i
            JOIN sys.index_columns ic ON ic.object_id = i.object_id AND ic.index_id = i.index_id
            JOIN sys.columns c ON c.object_id = ic.object_id AND c.column_id = ic.column_id
            WHERE i.object_id = OBJECT_ID('agora.StockReconAmendment') AND i.name = 'UX_StockReconAmendment_Natural'
              AND ic.key_ordinal > 0 ORDER BY ic.key_ordinal");
        $this->assertSame(['BranchId', 'RunId', 'RunLineId'], array_column($keys, 'name'));

        $run = $this->newRun();
        $line = $this->proposal($run, 1, [10, 0, 8, 1], [10, 9]);
        $row = [
            'BranchId' => self::BRANCH, 'RunId' => $run->Id, 'RunLineId' => $line,
            'AreaNo' => self::AREA, 'StockItemNo' => self::ITEM, 'TransactionDate' => '2031-01-01', 'ShiftNo' => 1,
            'PriorQtyOpen' => 10, 'PriorQtyClose' => 8, 'NewQtyOpen' => 10, 'NewQtyClose' => 9,
            'StampMode' => 'journal', 'State' => 'reversed', 'CreatedAt' => now(),
        ];
        $this->db()->table('agora.StockReconAmendment')->insert([$row, $row]);
        $row['State'] = 'active';
        $this->db()->table('agora.StockReconAmendment')->insert($row);
        $otherBranch = $row;
        $otherBranch['BranchId'] = 998;
        $this->db()->table('agora.StockReconAmendment')->insert($otherBranch);

        // A deliberate duplicate-key probe must not doom the fixture's outer
        // rollback transaction. This is not a procedure/business refusal.
        $this->db()->statement('SET XACT_ABORT OFF;');
        try {
            $this->db()->table('agora.StockReconAmendment')->insert($row);
            $this->fail('Two active amendments must not share a run line.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('UX_StockReconAmendment_Natural', $e->getMessage());
        } finally {
            $this->db()->statement('SET XACT_ABORT ON;');
        }
        $this->assertSame(3, $this->amendments($run)->count());
        $this->assertSame(1, $this->amendments($run)->where('State', 'active')->count());
    }

    public function test_another_runs_active_amendment_still_blocks_until_it_is_reversed(): void
    {
        $this->migrateActiveKey();
        $first = $this->newRun();
        $this->proposal($first, 1, [10, 0, 8, 1], [10, 9]);
        $second = $this->newRun();
        $this->proposal($second, 1, [10, 0, 8, 1], [10, 9], source: false);
        $this->commit($first, 'journal');

        try {
            $this->commit($second, 'journal');
            $this->fail('An active amendment from another run must still block this shift.');
        } catch (AgoraProcException $e) {
            $this->assertSame('ALL_SKIPPED', $e->code());
            $this->assertStringContainsString('another run', $e->getMessage());
        }
        $this->assertSame(0, $this->amendments($second)->count());
        $this->assertSame(1, $this->amendments($first)->where('State', 'active')->count());
        $this->assertSource([[10, 0, 8, 1]]);
        $this->reverse($first);
        $this->assertSame('COMMITTED', $this->commit($second, 'journal')->Code);
        $this->assertSame(1, $this->amendments($first)->where('State', 'reversed')->count());
        $this->assertSame(1, $this->amendments($second)->where('State', 'active')->count());
        $this->assertSource([[10, 0, 8, 1]]);
    }

    public function test_a_reset_can_repeat_with_issue_pos_and_the_negative_anchor_line_retained_in_history(): void
    {
        $this->migrateActiveKey();
        $run = $this->newRun();
        $this->proposal($run, 1, [10, 0, 8, 1], [10, 9]);
        $this->source()->where('ShiftNo', 1)->update(['QtyOpen' => 12, 'QtyClose' => 11, 'QtyIssued' => 5, 'QtyComputer' => 2]);
        $this->sourceRow('2030-12-31', 2, [20, 0, 15, 0], [20, 0, 10, 0]);

        $firstHistory = [];
        for ($cycle = 1; $cycle <= 2; $cycle++) {
            $status = $this->write('usp_StockRecon_ResetToOriginal', $run, ['StampMode' => 'live', 'IssueMode' => 'live']);
            $this->assertSame('RESET', $status->Code);
            $this->assertSource([[20, 0, 10, 0], [10, 0, 8, 1]]);
            $this->assertSame(1, $this->amendments($run)->where('State', 'active')->where('RunLineId', '<', 0)->count());
            $current = $this->amendments($run)->where('State', 'active')->where('RunLineId', '>', 0)->sole();
            $this->assertEquals([5, 0, 2, 1], [$current->PriorQtyIssued, $current->NewQtyIssued, $current->PriorQtyPOS, $current->NewQtyPOS]);
            $this->assertEquals($firstHistory, $this->amendments($run)->where('State', 'reversed')->orderBy('Id')->get()->all());

            $this->reverse($run);
            $this->assertSource([[20, 0, 15, 0], [12, 5, 11, 2]]);
            $this->assertSame(0, $this->amendments($run)->where('State', 'active')->count());
            $this->assertSame(2 * $cycle, $this->amendments($run)->where('State', 'reversed')->count());
            $firstHistory = $this->amendments($run)->orderBy('Id')->get()->all();
        }
    }

    /** @param array{int, int, int, int} $counts */
    #[DataProvider('issueChanges')]
    public function test_an_issue_write_can_be_committed_and_reversed_twice(array $counts, int $newIssue): void
    {
        $this->migrateActiveKey();
        $run = $this->newRun();
        $this->proposal($run, 1, $counts, [$counts[0], $counts[2]], $newIssue);
        for ($cycle = 1; $cycle <= 2; $cycle++) {
            $this->assertSame('COMMITTED', $this->commit($run)->Code);
            $this->assertSource([[$counts[0], $newIssue, $counts[2], $counts[3]]]);
            $active = $this->amendments($run)->where('State', 'active')->sole();
            $this->assertEquals($counts[1], $active->PriorQtyIssued);
            $this->assertEquals($newIssue, $active->NewQtyIssued);
            $this->reverse($run);
            $this->assertSource([$counts]);
            $this->assertSame($cycle, $this->amendments($run)->where('State', 'reversed')->count());
            $this->assertSame(0, $this->amendments($run)->where('State', 'active')->count());
        }
    }

    /** @return array<string, array{array{int, int, int, int}, int}> */
    public static function issueChanges(): array
    {
        return ['duplicate removed' => [[10, 4, 8, 2], 0], 'missing issue captured' => [[10, 0, 11, 1], 2]];
    }

    private function newRun(): StockReconRun
    {
        return StockReconRun::create([
            'BranchId' => self::BRANCH, 'GroupRef' => (string) Str::uuid(), 'AreaNo' => self::AREA,
            'FromDate' => '2031-01-01', 'ToDate' => '2031-01-01', 'Status' => 'previewed',
            'ProcedureName' => 'TEST-recommit', 'Note' => 'TEST-recommit history',
        ]);
    }

    /** @param array{int, int, int, int} $counts
     * @param  array{int, int}  $newCounts
     */
    private function proposal(StockReconRun $run, int $shift, array $counts, array $newCounts, ?int $newIssue = null, bool $source = true): int
    {
        if ($source) {
            $this->sourceRow('2031-01-01', $shift, $counts, $counts);
        }

        return $this->db()->table('agora.StockReconRunLine')->insertGetId([
            'BranchId' => self::BRANCH, 'RunId' => $run->Id, 'LineNo' => $shift,
            'AreaNo' => self::AREA, 'StockItemNo' => self::ITEM, 'TransactionDate' => '2031-01-01', 'ShiftNo' => $shift,
            'QtyOpen' => $counts[0], 'QtyIssued' => $counts[1], 'QtyClose' => $counts[2], 'QtyPOS' => $counts[3],
            'QtyOpenNow' => $counts[0], 'QtyCloseNow' => $counts[2],
            'QtyOpenNew' => $newCounts[0], 'QtyCloseNew' => $newCounts[1], 'QtyIssuedNew' => $newIssue,
            'AmendClose' => $newCounts[1] - $counts[2], 'WouldAmend' => true, 'Selected' => true,
            'CountsMove' => $newCounts !== [$counts[0], $counts[2]], 'Outcome' => 'TEST-recommit',
        ], 'Id');
    }

    /** @param array{int, int, int, int} $live
     * @param  array{int, int, int, int}  $original
     */
    private function sourceRow(string $date, int $shift, array $live, array $original): void
    {
        $this->db()->table('PumpIT.dbo.STK_StockReconLine')->insert([
            'SSBranchId' => self::BRANCH, 'AreaNo' => self::AREA, 'StockItemNo' => self::ITEM,
            'TransactionDate' => $date, 'ShiftNo' => $shift, 'SellPrice' => 10,
            'QtyOpen' => $live[0], 'QtyIssued' => $live[1], 'QtyClose' => $live[2], 'QtyComputer' => $live[3],
            'QtyOpen_Original' => $original[0], 'QtyIssued_Original' => $original[1],
            'QtyClose_Original' => $original[2], 'QtyComputer_Original' => $original[3],
        ]);
    }

    /** @param array<int, array{int, int, int, int}> $expected */
    private function assertSource(array $expected): void
    {
        $actual = $this->source()->orderBy('TransactionDate')->orderBy('ShiftNo')->get()
            ->map(fn (object $row) => [(float) $row->QtyOpen, (float) $row->QtyIssued, (float) $row->QtyClose, (float) $row->QtyComputer])->all();
        $this->assertEquals($expected, $actual);
    }

    private function source(): Builder
    {
        return $this->db()->table('PumpIT.dbo.STK_StockReconLine')
            ->where('SSBranchId', self::BRANCH)->where('AreaNo', self::AREA)->where('StockItemNo', self::ITEM);
    }

    private function amendments(StockReconRun $run): Builder
    {
        return $this->db()->table('agora.StockReconAmendment')->where('BranchId', self::BRANCH)->where('RunId', $run->Id);
    }

    private function commit(StockReconRun $run, string $mode = 'live'): object
    {
        return $this->write('usp_StockRecon_Commit', $run, ['StampMode' => $mode, 'IssueMode' => 'live', 'UseOriginalCounts' => 1]);
    }

    private function reverse(StockReconRun $run): void
    {
        $this->assertSame('REVERSED', $this->write('usp_StockRecon_Reverse', $run, ['Reason' => 'TEST-recommit reversal'])->Code);
    }

    /** @param array<string, scalar|null> $params */
    private function write(string $procedure, StockReconRun $run, array $params): object
    {
        return app(ProcedureService::class)->write($procedure, ['BranchId' => self::BRANCH, 'RunId' => $run->Id, ...$params]);
    }

    private function migrateActiveKey(): void
    {
        $migration = require base_path('Modules/StockRecon/Database/Migrations/v1__14l_stockrecon_active_amendments.php');
        $migration->up();
    }

    private function db(): Connection
    {
        return $this->database ?? throw new \LogicException('The local test transaction has not started.');
    }
}
