<?php

namespace Tests\Feature\Reports;

use App\Support\ProcedureService;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Local-only schema parity: full stock codes must survive storage and reporting. */
class StockItemCodeWidthTest extends TestCase
{
    private const BRANCH = 999;

    private const AREA = 98765;

    private const DAY = '2040-02-01';

    private const CODES = ['TEST-A', 'TEST-AB', 'TEST-ABC', 'TEST-ABCD', 'TEST-ABCDE'];

    private Connection $local;

    protected function setUp(): void
    {
        parent::setUp();
        $this->skipUnlessLocalStub('Stock width fixtures and temporary tables belong only on local SQL.');
        $this->local = DB::connection(config('agora.connections.app'));
        $this->local->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->local) && $this->local->transactionLevel() > 0) {
            $this->local->rollBack();
        }
        parent::tearDown();
    }

    public function test_fresh_stub_tables_accept_six_to_ten_character_codes(): void
    {
        $source = file_get_contents(database_path('stubs/pumpit-reports.sql'));
        $this->assertIsString($source);

        foreach ($this->fixtures() as $table => $rows) {
            $this->assertSame(1, preg_match('/CREATE TABLE dbo\.'.preg_quote($table, '/').' \(\n.*?\n\);/s', $source, $matches));
            $temporary = '#Width_'.$table;
            $this->local->unprepared(str_replace('dbo.'.$table, $temporary, $matches[0]));
            $this->local->table($temporary)->insert($rows);
            $this->assertSame(self::CODES, $this->local->table($temporary)->orderBy('StockItemNo')->pluck('StockItemNo')->all(), $table);
        }
    }

    public function test_existing_local_columns_preserve_codes_through_views_and_reports(): void
    {
        foreach ($this->fixtures() as $table => $rows) {
            $this->assertFalse($this->local->table('PumpIT.dbo.'.$table)->where('SSBranchId', self::BRANCH)
                ->where('AreaNo', self::AREA)->exists(), 'The test territory must start empty.');
            $this->local->table('PumpIT.dbo.'.$table)->insert($rows);
        }

        foreach (['PumpIT.dbo.SS_Branch' => ['SSBranchId' => self::BRANCH, 'BranchName' => 'TEST stock width', 'IsActive' => 0],
            'agora.Branch' => ['BranchId' => self::BRANCH, 'Name' => 'TEST stock width', 'IsTrading' => 0, 'IsActive' => 0]] as $table => $row) {
            $key = array_key_first($row);
            if (! $this->local->table($table)->where($key, self::BRANCH)->exists()) {
                $this->local->table($table)->insert($row);
            }
        }

        $this->local->table('PumpIT.dbo.STK_Area')->insert([
            'SSBranchId' => self::BRANCH, 'AreaNo' => self::AREA, 'AreaDescription' => 'TEST stock width',
            'AreaGroup' => 'TEST', 'DayShift' => 1, 'AfternoonShift' => 0, 'NightShift' => 0,
            'ShowReport' => 1, 'IsCaptureWaste' => 1,
        ]);

        foreach (['vw_StockReconLine', 'vw_StockMaster', 'vw_StockWasteLine'] as $view) {
            $this->assertSame(self::CODES, $this->local->table('agora.'.$view)
                ->where('BranchId', self::BRANCH)->where('AreaNo', self::AREA)
                ->orderBy('StockItemNo')->pluck('StockItemNo')->all(), $view);
        }

        foreach (['usp_Reports_GridStockCount', 'usp_Reports_GridWaste', 'usp_StockRecon_GridVariance'] as $procedure) {
            $sets = app(ProcedureService::class)->callSets($procedure, [
                'BranchIds' => (string) self::BRANCH, 'DateFrom' => self::DAY, 'DateTo' => self::DAY,
                'Search' => 'TEST-', 'SortColumn' => 'StockItemNo', 'SortAsc' => 1,
            ]);
            $this->assertSame(self::CODES, $sets[0]->pluck('StockItemNo')->all(), $procedure);
            $this->assertSame(5, (int) $sets[1][0]->TotalRows, $procedure);
            foreach ($sets[0] as $row) {
                $this->assertSame('TEST width '.$row->StockItemNo, $row->StockItemDescription, 'The full code must join to its own master.');
            }
        }
    }

    /** @return array<string, list<array<string, int|float|string>>> */
    private function fixtures(): array
    {
        $fixtures = ['STK_StockReconLine' => [], 'STK_StockMaster' => [], 'STK_StockWasteLine' => []];
        foreach (self::CODES as $code) {
            $key = ['SSBranchId' => self::BRANCH, 'AreaNo' => self::AREA, 'StockItemNo' => $code];
            $line = $key + ['TransactionDate' => self::DAY, 'ShiftNo' => 1];
            $fixtures['STK_StockMaster'][] = $key + [
                'StockItemDescription' => 'TEST width '.$code, 'Location' => 'TEST', 'UOMCode' => 'EACH',
                'SellingPrice' => 10, 'QtyVarAllowance' => 0, 'isMonitoredItem' => 1,
            ];
            $fixtures['STK_StockReconLine'][] = $line + [
                'SellPrice' => 10, 'QtyOpen_Original' => 10, 'QtyIssued_Original' => 0,
                'QtyClose_Original' => 8, 'QtyComputer_Original' => 0,
                'QtyOpen' => 10, 'QtyIssued' => 0, 'QtyClose' => 8, 'QtyComputer' => 0,
            ];
            $fixtures['STK_StockWasteLine'][] = $line + ['QtyGoodWaste' => 1, 'QtyBadWaste' => 0];
        }

        return $fixtures;
    }
}
