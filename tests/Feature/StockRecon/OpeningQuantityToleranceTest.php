<?php

namespace Tests\Feature\StockRecon;

use App\Support\ProcedureService;
use Illuminate\Database\Connection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Execute each reference worker in a disposable schema; never deploy the pack. */
class OpeningQuantityToleranceTest extends TestCase
{
    private const SCHEMA = 'TEST_OpeningTolerance';

    private const WORKER = 'sp_RebuildStockReconLine_QtyOpen_FromPriorClose';

    private ?Connection $database = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->skipUnlessLocalStub();
        $this->database = DB::connection(config('agora.connections.app'));
        $this->database->beginTransaction();
        $this->database->unprepared('CREATE SCHEMA '.self::SCHEMA);
        $this->database->unprepared('CREATE TABLE '.self::SCHEMA.'.STK_StockReconLine (
            SSBranchId int, TransactionDate datetime, ShiftNo int, AreaNo int, StockItemNo nvarchar(20),
            SellPrice float, QtyOpen_Original float, QtyOpen float, QtyClose_Original float, QtyClose float,
            QtyIssues float, QtyComputer float,
            PRIMARY KEY (SSBranchId, TransactionDate, ShiftNo, AreaNo, StockItemNo));
            CREATE TABLE '.self::SCHEMA.'.STK_Area (
                SSBranchId int, AreaNo int, DayShift bit, AfternoonShift bit, NightShift bit);
            CREATE TABLE '.self::SCHEMA.'.STK_OpeningFixExclusion (SSBranchId int, StockItemNo nvarchar(20));
            CREATE TABLE '.self::SCHEMA.'.STK_OpeningFixScope (SSBranchId int, SiteName nvarchar(100));
            CREATE TABLE '.self::SCHEMA.'.STK_StockReconLine_OpeningFixLog (
                Id bigint IDENTITY PRIMARY KEY, RunId uniqueidentifier, SSBranchId int, TransactionDate datetime,
                ShiftNo int, AreaNo int, StockItemNo nvarchar(20),
                QtyOpen_Original_Before float, QtyOpen_Original_After float, QtyOpen_Before float, QtyOpen_After float,
                PriorShiftDate datetime, PriorShiftNo int, PriorClose_Original float, PriorClose float, SellPrice float);
            INSERT INTO '.self::SCHEMA.'.STK_Area VALUES (999, 91, 1, 1, 1);
            INSERT INTO '.self::SCHEMA.".STK_OpeningFixScope VALUES (999, 'TEST-Opening tolerance');");
    }

    protected function tearDown(): void
    {
        if ($this->database !== null) {
            $this->database->rollBack();
        }
        parent::tearDown();
    }

    /** @return array<string, array{string}> */
    public static function packages(): array
    {
        return [
            'GO batches' => ['02-proposed-proc.sql'],
            'single-statement batches' => ['02b-deploy-no-GO.sql'],
        ];
    }

    public function test_both_packages_contain_the_same_worker(): void
    {
        $this->assertSame($this->worker('02-proposed-proc.sql'), $this->worker('02b-deploy-no-GO.sql'));
    }

    #[DataProvider('packages')]
    public function test_weighed_boundaries_dry_run_apply_and_idempotence(string $package): void
    {
        $this->db()->unprepared($this->worker($package));
        $deltas = [];
        foreach ([0.00009, 0.0001, 0.00011, 0.00049, 0.0005, 0.00051, 0.001, 0.005] as $magnitude) {
            foreach ([-1, 1] as $sign) {
                $item = 'T'.count($deltas);
                $deltas[$item] = $magnitude * $sign;
                $this->fixture($item, $magnitude * $sign);
            }
        }

        $before = $this->source();
        $preview = $this->runWorker();
        $this->assertEquals(6, $preview[0]->sole()->LinesTouched);
        $this->assertCount(6, $preview[1]);
        $this->assertEquals(12, $this->runWorker(['Tolerance' => 0.0001])[0]->sole()->LinesTouched);
        $this->assertEquals(0, $this->runWorker(['Tolerance' => 0.005])[0]->sole()->LinesTouched);
        $this->assertEquals($before, $this->source(), 'Every what-if must leave the float ledger untouched.');
        $this->assertSame(0, $this->db()->table(self::SCHEMA.'.STK_StockReconLine_OpeningFixLog')->count());

        $applied = $this->runWorker(['WhatIf' => 0]);
        $this->assertEquals(6, $applied[1]->sole()->Candidates);
        $this->assertEquals(6, $applied[1]->sole()->RowsUpdated);
        $this->assertEquals(0, $applied[1]->sole()->RowsSkipped_ChangedUnderUs);
        $log = $this->db()->table(self::SCHEMA.'.STK_StockReconLine_OpeningFixLog')->get()->keyBy('StockItemNo');
        $this->assertCount(6, $log);
        foreach ($this->source() as $row) {
            $delta = $deltas[$row->StockItemNo];
            $shift = (int) $row->ShiftNo;
            $corrected = $shift === 2 && abs($delta) > 0.0005;
            $this->assertEqualsWithDelta($corrected ? 8.355 : 8.355 + ($shift === 2 ? $delta : 0), $row->QtyOpen_Original, 1e-10);
            $this->assertEqualsWithDelta($corrected ? 12.55 : 12.55 - ($shift === 2 ? $delta : 0), $row->QtyOpen, 1e-10);
            $this->assertEqualsWithDelta($shift === 1 ? 8.355 : 7.66, $row->QtyClose_Original, 1e-10);
            $this->assertEqualsWithDelta($shift === 1 ? 12.55 : 11.51, $row->QtyClose, 1e-10);
            $this->assertEqualsWithDelta(2.345, $row->QtyIssues, 1e-10);
            $this->assertEqualsWithDelta(10.005, $row->QtyComputer, 1e-10);
            if ($corrected) {
                $entry = $log->get($row->StockItemNo);
                $this->assertNotNull($entry);
                $this->assertEqualsWithDelta(8.355 + $delta, $entry->QtyOpen_Original_Before, 1e-10);
                $this->assertEqualsWithDelta(12.55 - $delta, $entry->QtyOpen_Before, 1e-10);
                $this->assertEqualsWithDelta(8.355, $entry->QtyOpen_Original_After, 1e-10);
                $this->assertEqualsWithDelta(12.55, $entry->QtyOpen_After, 1e-10);
            }
        }
        $this->assertEquals(0, $this->runWorker()[0]->sole()->LinesTouched);
    }

    #[DataProvider('packages')]
    public function test_wider_repair_tolerance_preserves_the_finer_snapshot_guard(string $package): void
    {
        foreach (['TORIG', 'TWORK', 'TSAME', 'TTINY'] as $item) {
            $this->fixture($item, 0.01);
        }
        // Deterministically simulate edits after #fix, before the real guarded
        // UPDATE. Only this disposable copy receives the test scheduling hook.
        $hook = 'UPDATE '.self::SCHEMA.".STK_StockReconLine
            SET QtyOpen_Original = QtyOpen_Original + CASE StockItemNo WHEN 'TORIG' THEN 0.0002 WHEN 'TTINY' THEN 0.00005 ELSE 0 END,
                QtyOpen = QtyOpen + CASE WHEN StockItemNo = 'TWORK' THEN 0.0002 ELSE 0 END
            WHERE ShiftNo = 2;";
        $this->installWithSnapshotEdit($package, $hook);
        $sets = $this->runWorker(['WhatIf' => 0, 'Tolerance' => 0.005]);
        $this->assertEquals(4, $sets[1]->sole()->Candidates);
        $this->assertEquals(2, $sets[1]->sole()->RowsUpdated);
        $this->assertEquals(2, $sets[1]->sole()->RowsSkipped_ChangedUnderUs);
        $rows = $this->db()->table(self::SCHEMA.'.STK_StockReconLine')->where('ShiftNo', 2)->get()->keyBy('StockItemNo');
        $this->assertEqualsWithDelta(8.3652, $rows['TORIG']->QtyOpen_Original, 1e-10);
        $this->assertEqualsWithDelta(12.54, $rows['TORIG']->QtyOpen, 1e-10);
        $this->assertEqualsWithDelta(8.365, $rows['TWORK']->QtyOpen_Original, 1e-10);
        $this->assertEqualsWithDelta(12.5402, $rows['TWORK']->QtyOpen, 1e-10);
        $log = $this->db()->table(self::SCHEMA.'.STK_StockReconLine_OpeningFixLog')->orderBy('StockItemNo')->get();
        $this->assertSame(['TSAME', 'TTINY'], $log->pluck('StockItemNo')->all());
        $this->assertEqualsWithDelta(8.36505, $log->last()->QtyOpen_Original_Before, 1e-10, 'OUTPUT must retain the actual value replaced, including a permitted sub-tolerance edit.');
        foreach (['TSAME', 'TTINY'] as $item) {
            $this->assertEqualsWithDelta(8.355, $rows[$item]->QtyOpen_Original, 1e-10);
            $this->assertEqualsWithDelta(12.55, $rows[$item]->QtyOpen, 1e-10);
        }
    }

    #[DataProvider('packages')]
    public function test_an_explicit_stricter_tolerance_also_tightens_snapshot_protection(string $package): void
    {
        $this->fixture('TFINE', 0.01);
        $this->installWithSnapshotEdit($package, 'UPDATE '.self::SCHEMA.'.STK_StockReconLine SET QtyOpen = QtyOpen + 0.00005 WHERE ShiftNo = 2;');
        $sets = $this->runWorker(['WhatIf' => 0, 'Tolerance' => 0.00001]);
        $this->assertEquals(1, $sets[1]->sole()->Candidates);
        $this->assertEquals(0, $sets[1]->sole()->RowsUpdated);
        $this->assertEquals(1, $sets[1]->sole()->RowsSkipped_ChangedUnderUs);
        $this->assertSame(0, $this->db()->table(self::SCHEMA.'.STK_StockReconLine_OpeningFixLog')->count());
        $this->assertEqualsWithDelta(12.54005, $this->db()->table(self::SCHEMA.'.STK_StockReconLine')->where('ShiftNo', 2)->value('QtyOpen'), 1e-10);
    }

    private function worker(string $package): string
    {
        $source = file_get_contents(base_path('docs/reference/opening-qty/'.$package));
        $this->assertIsString($source);
        $name = preg_quote(self::WORKER, '/');
        $pattern = $package === '02-proposed-proc.sql'
            ? '/(CREATE OR ALTER PROCEDURE dbo\.'.$name.'\b.*?)(?:\nGO\s*\n)/s'
            : "/EXEC PumpIT\\.sys\\.sp_executesql N'(CREATE OR ALTER PROCEDURE dbo\\.".$name."\\b.*?\nEND;)';/s";
        $this->assertSame(1, preg_match($pattern, $source, $matches));
        $worker = $package === '02-proposed-proc.sql' ? $matches[1] : str_replace("''", "'", $matches[1]);

        return str_replace('dbo.', self::SCHEMA.'.', trim($worker));
    }

    private function installWithSnapshotEdit(string $package, string $hook): void
    {
        $seam = 'DECLARE @applied int, @candidates int = (SELECT COUNT(*) FROM #fix);';
        $sql = str_replace($seam, $seam."\n".$hook, $this->worker($package), $count);
        $this->assertSame(1, $count);
        $this->db()->unprepared($sql);
    }

    private function fixture(string $item, float $delta): void
    {
        foreach ([1, 2] as $shift) {
            $this->db()->table(self::SCHEMA.'.STK_StockReconLine')->insert([
                'SSBranchId' => 999, 'TransactionDate' => '2031-01-01', 'ShiftNo' => $shift,
                'AreaNo' => 91, 'StockItemNo' => $item, 'SellPrice' => 33.99,
                'QtyOpen_Original' => 8.355 + ($shift === 2 ? $delta : 0),
                'QtyOpen' => 12.55 - ($shift === 2 ? $delta : 0),
                'QtyClose_Original' => $shift === 1 ? 8.355 : 7.66,
                'QtyClose' => $shift === 1 ? 12.55 : 11.51,
                'QtyIssues' => 2.345, 'QtyComputer' => 10.005,
            ]);
        }
    }

    /** @param array<string, int|float> $options
     * @return array<int, Collection<int, object>>
     */
    private function runWorker(array $options = []): array
    {
        return (new ProcedureService(config('agora.connections.app')))->callSets(self::SCHEMA.'.'.self::WORKER, array_merge([
            'BranchId' => 999, 'FromDate' => '2031-01-01', 'ToDate' => '2031-01-01', 'WhatIf' => 1,
        ], $options));
    }

    /** @return list<object> */
    private function source(): array
    {
        return $this->db()->table(self::SCHEMA.'.STK_StockReconLine')->orderBy('StockItemNo')->orderBy('ShiftNo')->get()->all();
    }

    private function db(): Connection
    {
        $this->assertNotNull($this->database);

        return $this->database;
    }
}
