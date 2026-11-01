<?php

namespace Tests\Feature\StockRecon;

use App\Support\ProcedureService;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\StockRecon\Grids\StockReconExceptionGrid;
use Modules\StockRecon\Models\StockReconRun;
use Modules\StockRecon\Services\StockReconService;
use Tests\TestCase;

/** Local stub only; every fixture and preview is rolled back after each test. */
class CountDriftTest extends TestCase
{
    private const BRANCH = 999;

    private const AREA = 987;

    // The local stock master allows five characters; labels retain TEST-.
    private const ITEM = 'TDRFT';

    private Connection $db;

    protected function setUp(): void
    {
        parent::setUp();

        // Validate every configured SQL connection before obtaining a PDO.
        foreach (config('database.connections') as $name => $connection) {
            if (($connection['driver'] ?? null) !== 'sqlsrv') {
                continue;
            }
            foreach ([$connection['host'] ?? null, $connection['read']['host'] ?? null, $connection['write']['host'] ?? null] as $host) {
                foreach ((array) $host as $value) {
                    if (! in_array($value, ['127.0.0.1', 'localhost', '::1'], true)) {
                        $this->markTestSkipped("Count drift fixtures require every SQL connection to be loopback: {$name}.");
                    }
                }
            }
        }
        $this->skipUnlessLocalStub();
        $this->db = DB::connection(config('agora.connections.app'));
        $this->db->beginTransaction();
        $this->seedFixture();
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->transactionLevel() > 0) {
            $this->db->rollBack();
        }
        parent::tearDown();
    }

    public function test_preview_saves_both_pairs_and_reason_without_writing_the_source(): void
    {
        $before = $this->sourceRows();
        $run = $this->preview();
        $lines = $run->lines->keyBy('LineNo');

        $this->assertSame(['Opening', 'Closing', 'Opening and closing', null, null, null],
            $run->lines->pluck('CountDriftReason')->all());
        $this->assertEquals(10, $lines[1]->QtyOpenOrig);
        $this->assertEquals(11, $lines[1]->QtyOpenNow);
        $this->assertEquals(6, $lines[2]->QtyCloseOrig);
        $this->assertEquals(7, $lines[2]->QtyCloseNow);
        foreach ($run->lines as $line) {
            $this->assertNull($line->AuditCode);
            $this->assertNull($line->ExceptionCode, 'Generic drift never becomes a persisted balancing class.');
            $this->assertFalse($line->RestoresHand);
        }
        $this->assertEquals($before, $this->sourceRows(), 'Preview only records Agora evidence.');
        $this->assertSame(['C1', 'C1', 'C1'], $this->grid($run)->pluck('ExceptionCode')->all());
    }

    public function test_overview_notice_and_code_help_render_the_drift_explanation(): void
    {
        $run = $this->preview();
        $preview = view('stockrecon::panes.overview', [
            'run' => $run,
            'duplicates' => ['cases' => collect(), 'totals' => (object) []],
        ])->render();
        $this->assertStringContainsString('Original/live counts differ', $preview);
        $this->assertStringContainsString('does not change balancing eligibility or restore counts', $preview);
        $help = view('stockrecon::partials.codes-modal')->render();
        $this->assertStringContainsString('id="stockrecon-codes-C1"', $help);
        $this->assertStringContainsString('Information only', $help);
    }

    public function test_count_drift_uses_the_existing_audit_count_tolerance(): void
    {
        foreach ([[1, 10.0004, 8], [2, 8.0005, 6], [3, 6.0006, 4]] as [$shift, $open, $close]) {
            $this->db->table('PumpIT.dbo.STK_StockReconLine')
                ->where('SSBranchId', self::BRANCH)->where('AreaNo', self::AREA)
                ->where('TransactionDate', '2026-07-17')->where('ShiftNo', $shift)
                ->update(['QtyOpen' => $open, 'QtyClose' => $close]);
        }
        $run = $this->preview();
        $this->assertSame([null, null, 'Opening', null, null, null],
            $run->lines->pluck('CountDriftReason')->all());
        $this->assertCount(1, $this->grid($run));
    }

    public function test_reason_filters_and_type_counts_use_the_saved_run_and_branch(): void
    {
        $run = $this->preview();
        $this->db->table('PumpIT.dbo.STK_StockReconLine')
            ->where('SSBranchId', self::BRANCH)->where('AreaNo', self::AREA)
            ->update(['QtyOpen' => 90, 'QtyClose' => 90]);
        $this->preview(); // Another run must never leak into this report.

        foreach (['Opening', 'Closing', 'Opening and closing'] as $reason) {
            $rows = $this->grid($run, [['column' => 'CountDriftReason', 'type' => 'set', 'in' => [$reason]]]);
            $this->assertCount(1, $rows);
            $this->assertSame($reason, $rows->first()->CountDriftReason);
        }
        $this->assertEquals(11, $this->grid($run)->first()->QtyOpenNow, 'It reads the snapshot, not today’s live counts.');
        $this->assertCount(0, $this->grid($run, [['column' => 'CountDriftReason', 'type' => 'set', 'in' => ['not a reason']]]));
        $this->assertCount(0, $this->grid($run, branch: 998));
        $counts = app(StockReconService::class)->exceptionTypes($run);
        $this->assertSame(3, (int) $counts->firstWhere('Kind', 'all')->TotalRows);
        $this->assertSame(3, (int) $counts->firstWhere('Code', 'C1')->TotalRows);

        $grid = new StockReconExceptionGrid;
        $this->assertSame(['Opening', 'Closing', 'Opening and closing'], $grid->filters()['CountDriftReason']->options);
        $this->assertSame(['QtyOpenOrig', 'QtyOpenNow', 'QtyCloseOrig', 'QtyCloseNow'],
            collect($grid->columns())->filter(fn ($column) => in_array($column->key,
                ['QtyOpenOrig', 'QtyOpenNow', 'QtyCloseOrig', 'QtyCloseNow'], true) && $column->visible)->pluck('key')->all());
    }

    public function test_pack_audit_precedes_generic_drift_and_existing_count_classes_keep_priority(): void
    {
        $this->db->table('PumpIT.dbo.STK_StockReconLine')
            ->where('SSBranchId', self::BRANCH)->where('AreaNo', self::AREA)
            ->where('TransactionDate', '2026-07-18')->where('ShiftNo', 3)->update(['QtyClose' => 2]);
        $run = $this->preview();
        $last = $run->lines->last();
        $this->assertSame('S11', $last->AuditCode);
        $this->assertNull($last->CountDriftReason, 'The pack rule owns the finding.');

        // A saved count finding continues to own its row; drift is still filterable.
        $third = $run->lines->firstWhere('LineNo', 3);
        $this->db->table('agora.StockReconRunLine')->where('BranchId', self::BRANCH)
            ->where('Id', $third->Id)->update(['ExceptionCode' => 'U1']);
        $rows = $this->grid($run);
        $this->assertSame('U1', $rows[0]->ExceptionCode);
        $this->assertSame('S11', $rows[1]->AuditCode, 'Pack checks sort before generic drift.');
        $this->assertSame('C1', $rows[2]->ExceptionCode);
        $this->assertSame('U1', $this->grid($run,
            [['column' => 'CountDriftReason', 'type' => 'set', 'in' => ['Opening and closing']]])->first()->ExceptionCode);
        $counts = app(StockReconService::class)->exceptionTypes($run);
        $this->assertSame(4, (int) $counts->firstWhere('Kind', 'all')->TotalRows);
        $this->assertSame(2, (int) $counts->firstWhere('Code', 'C1')->TotalRows);
        $this->assertSame(1, (int) $counts->firstWhere('Code', 'S11')->TotalRows);

        $this->db->table('agora.StockReconRunLine')->where('BranchId', self::BRANCH)
            ->where('Id', $third->Id)->update(['ExceptionCode' => 'A1']);
        $this->assertSame(['A1', null, 'C1', 'C1'], $this->grid($run)->pluck('ExceptionCode')->all(),
            'Historic count codes also retain priority over generic drift.');
    }

    public function test_original_zero_live_nonzero_drift_does_not_restore_a_blocked_dormant_chain(): void
    {
        $this->db->table('PumpIT.dbo.STK_StockReconLine')
            ->where('SSBranchId', self::BRANCH)->where('AreaNo', self::AREA)
            ->update(['QtyOpen' => 0, 'QtyClose' => 0, 'QtyComputer' => 0,
                'QtyOpen_Original' => 0, 'QtyClose_Original' => 0, 'QtyComputer_Original' => 0]);
        $this->db->table('PumpIT.dbo.STK_StockReconLine')
            ->where('SSBranchId', self::BRANCH)->where('AreaNo', self::AREA)
            ->where('TransactionDate', '2026-07-17')->where('ShiftNo', 1)
            ->update(['QtyOpen' => 5, 'QtyClose' => 5]);
        // A negative closing elsewhere blocks the chain independently of drift.
        $this->db->table('PumpIT.dbo.STK_StockReconLine')
            ->where('SSBranchId', self::BRANCH)->where('AreaNo', self::AREA)
            ->where('TransactionDate', '2026-07-18')->where('ShiftNo', 3)
            ->update(['QtyClose' => -1, 'QtyClose_Original' => -1]);
        $run = $this->preview(['HoldBackIncomplete' => 0]);
        $line = $run->lines->first();
        $this->assertSame('Opening and closing', $line->CountDriftReason);
        $this->assertTrue($line->ChainBlocked);
        $this->assertFalse($line->RestoresHand);
        $this->assertFalse($line->WouldAmend);
        $this->assertFalse($line->isWritable());
        $this->assertEquals(0, $line->QtyOpenOrig);
        $this->assertEquals(5, $line->QtyOpenNow);
        $this->assertCount(1, $this->grid($run,
            [['column' => 'CountDriftReason', 'type' => 'set', 'in' => ['Opening and closing']]]));
    }

    public function test_legacy_live_basis_still_saves_the_actual_original_pair_and_old_runs_stay_unknown(): void
    {
        $run = $this->preview();
        $this->db->table('agora.StockReconRunLine')->where('BranchId', self::BRANCH)->where('RunId', $run->Id)->delete();
        app(ProcedureService::class)->write('usp_StockRecon_PreviewBalancing', [
            'RunId' => $run->Id, 'BranchId' => self::BRANCH, 'AreaNo' => self::AREA,
            'FromDate' => '2026-07-17', 'ToDate' => '2026-07-18', 'UseOriginalCounts' => 0,
        ]);
        $line = $run->fresh()->lines->first();
        $this->assertEquals(11, $line->QtyOpen, 'The old live basis is not the original count.');
        $this->assertEquals(10, $line->QtyOpenOrig);
        $this->assertEquals(11, $line->QtyOpenNow);
        $this->assertSame('Opening', $line->CountDriftReason);

        // Nullable columns on historic rows must never be inferred from today’s source.
        $this->db->table('agora.StockReconRunLine')->where('BranchId', self::BRANCH)->where('RunId', $run->Id)
            ->update(['QtyOpenOrig' => null, 'QtyCloseOrig' => null, 'CountDriftReason' => null,
                'ExceptionCode' => null, 'AuditCode' => null]);
        $this->assertCount(0, $this->grid($run));
        $this->assertCount(0, app(StockReconService::class)->exceptionTypes($run));
        $this->assertNull($run->fresh()->lines->first()->QtyOpenOrig);
    }

    /** @param array<string, int> $options */
    private function preview(array $options = []): StockReconRun
    {
        return app(StockReconService::class)->preview(self::BRANCH, self::AREA,
            Carbon::parse('2026-07-17'), Carbon::parse('2026-07-18'), $options, note: 'TEST-count-drift')->load('lines');
    }

    /** @param array<int, array<string, mixed>> $filters
     * @return Collection<int, object>
     */
    private function grid(StockReconRun $run, array $filters = [], int $branch = self::BRANCH): Collection
    {
        return app(ProcedureService::class)->call('usp_StockRecon_GridExceptions', [
            'BranchIds' => (string) $branch, 'RunId' => $run->Id, 'FiltersJson' => json_encode($filters),
        ]);
    }

    /** @return Collection<int, \stdClass> */
    private function sourceRows(): Collection
    {
        return $this->db->table('PumpIT.dbo.STK_StockReconLine')
            ->where('SSBranchId', self::BRANCH)->where('AreaNo', self::AREA)
            ->orderBy('TransactionDate')->orderBy('ShiftNo')->get();
    }

    private function seedFixture(): void
    {
        $this->db->table('PumpIT.dbo.STK_Area')->insert([
            'SSBranchId' => self::BRANCH, 'AreaNo' => self::AREA, 'AreaDescription' => 'TEST-Count drift',
            'AreaGroup' => 'Food Items', 'DayShift' => 1, 'AfternoonShift' => 1, 'NightShift' => 1,
            'ShowReport' => 1, 'IsCaptureWaste' => 0,
        ]);
        $this->db->table('PumpIT.dbo.STK_StockMaster')->insert([
            'SSBranchId' => self::BRANCH, 'StockItemNo' => self::ITEM, 'AreaNo' => self::AREA,
            'StockItemDescription' => 'TEST-Count drift', 'Location' => 'TEST', 'UOMCode' => 'EA',
            'SellingPrice' => 2, 'POSCode' => 'TESTDRIFT', 'QtyVarAllowance' => 0, 'isMonitoredItem' => 0,
        ]);
        foreach ([[10, 8, 2, 11, 8], [8, 6, 2, 8, 7], [6, 4, 2, 7, 5],
            [4, 2, 2, 4, 2], [2, 1, 1, 2, 1], [1, 1, 0, 1, 1]] as $index => [$open, $close, $pos, $liveOpen, $liveClose]) {
            $this->db->table('PumpIT.dbo.STK_StockReconLine')->insert([
                'SSBranchId' => self::BRANCH, 'AreaNo' => self::AREA, 'StockItemNo' => self::ITEM,
                'TransactionDate' => $index < 3 ? '2026-07-17' : '2026-07-18', 'ShiftNo' => $index % 3 + 1,
                'SellPrice' => 2, 'QtyOpen' => $liveOpen, 'QtyClose' => $liveClose,
                'QtyOpen_Original' => $open, 'QtyClose_Original' => $close,
                'QtyIssued' => 0, 'QtyIssued_Original' => 0, 'QtyComputer' => $pos, 'QtyComputer_Original' => $pos,
            ]);
        }
    }
}
