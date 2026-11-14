<?php

namespace Tests\Feature\StockRecon;

use App\Support\ProcedureService;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\StockRecon\Models\StockReconRun;
use Modules\StockRecon\Services\StockReconService;
use Tests\TestCase;

/**
 * agora.usp_StockRecon_TillQtyCheck and the warning on the run page (Chantal
 * Peens, 13 Nov 2026, "COMPUTER QTY"): per day, area and item, the recon
 * lines' POS quantity against what the tills sold, read the way her own
 * script reads it.
 *
 *   TTQ1  shift-based area 91: no POS on either shift, the till sold 5.
 *         The whole day is missing ("day", 5 missing).
 *   TTQ2  time-based area 92: 2 on the recon, the till sold 3 + 2, and a
 *         stock adjustment of 100 that is not a sale. One shift is missing
 *         its quantity ("part", 3 missing).
 *   TTQ3  shift-based area 91: recon and till agree. Not reported.
 *   TTQ4  a till sale for an item the run does not carry. Not reported.
 *
 * Branch 999 on the LOCAL container, inside a transaction rolled back in
 * tearDown, as AmendmentRecommitTest does.
 */
class TillQtyCheckTest extends TestCase
{
    private const BRANCH = 999;

    private const DAY = '2031-02-01';

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

    public function test_missing_and_partial_till_quantities_are_reported_per_day_area_and_item(): void
    {
        $run = $this->fixture();

        $gaps = (new StockReconService(new ProcedureService))->tillQtyGaps($run);

        $this->assertSame(['TTQ1', 'TTQ2'], $gaps->pluck('StockItemNo')->sort()->values()->all());
        $day = $gaps->firstWhere('StockItemNo', 'TTQ1');
        $this->assertSame('day', $day->Kind);
        $this->assertEqualsWithDelta(0.0, (float) $day->QtyRecon, 0.0005);
        $this->assertEqualsWithDelta(5.0, (float) $day->QtyTill, 0.0005);
        $this->assertEqualsWithDelta(5.0, (float) $day->QtyMissing, 0.0005);
        $this->assertSame('TEST-Shelf', $day->AreaDescription);
        $this->assertSame('TEST till item one', $day->StockItemDescription);

        $part = $gaps->firstWhere('StockItemNo', 'TTQ2');
        $this->assertSame('part', $part->Kind);
        $this->assertEqualsWithDelta(2.0, (float) $part->QtyRecon, 0.0005);
        $this->assertEqualsWithDelta(5.0, (float) $part->QtyTill, 0.0005, 'The stock adjustment is not a sale.');
        $this->assertEqualsWithDelta(3.0, (float) $part->QtyMissing, 0.0005);
    }

    public function test_the_run_page_warns_that_those_items_cannot_balance_yet(): void
    {
        $run = $this->fixture();
        $gaps = (new StockReconService(new ProcedureService))->tillQtyGaps($run);

        $html = view('stockrecon::partials.till-gaps-notice', ['tillGaps' => $gaps, 'run' => $run])->render();

        $this->assertStringContainsString('Till quantities are missing in PumpIT — 2 items on 1 day cannot balance yet', $html);
        $this->assertStringContainsString('TEST till item one', $html);
        $this->assertStringContainsString('usp_StockRecon_TillQtyCheck', $html);

        $none = view('stockrecon::partials.till-gaps-notice', ['tillGaps' => collect(), 'run' => $run])->render();
        $this->assertSame('', trim($none));
    }

    private function fixture(): StockReconRun
    {
        $db = $this->db();
        foreach ([[91, 'TEST-Shelf', 0], [92, 'TEST-Kitchen', 1]] as [$area, $name, $timeBased]) {
            $db->table('PumpIT.dbo.STK_Area')->insert([
                'SSBranchId' => self::BRANCH, 'AreaNo' => $area, 'AreaDescription' => $name,
                'DayShift' => 1, 'AfternoonShift' => 0, 'NightShift' => 1, 'ShowReport' => 1,
                'IsCaptureWaste' => 0, 'Shift_TimeBased' => $timeBased,
            ]);
        }
        foreach ([['TTQ1', 91, 'TEST-TQ1', 'TEST till item one'], ['TTQ2', 92, 'TEST-TQ2', 'TEST till item two'],
            ['TTQ3', 91, 'TEST-TQ3', 'TEST till item three'], ['TTQ4', 91, 'TEST-TQ4', 'TEST till item four']] as [$item, $area, $code, $name]) {
            $db->table('PumpIT.dbo.STK_StockMaster')->insert([
                'SSBranchId' => self::BRANCH, 'StockItemNo' => $item, 'StockItemDescription' => $name,
                'UOMCode' => 'EA', 'AreaNo' => $area, 'QtyVarAllowance' => 0, 'IsDoCloseQtyCalc' => 0,
                'POSCode' => $code, 'Location' => 'TPOS',
            ]);
        }

        // Recon lines: [item, area, shift, POS quantity]
        foreach ([['TTQ1', 91, 1, 0], ['TTQ1', 91, 3, 0], ['TTQ2', 92, 1, 2], ['TTQ2', 92, 3, 0],
            ['TTQ3', 91, 1, 4], ['TTQ3', 91, 3, 1]] as [$item, $area, $shift, $pos]) {
            $db->table('PumpIT.dbo.STK_StockReconLine')->insert([
                'SSBranchId' => self::BRANCH, 'AreaNo' => $area, 'StockItemNo' => $item,
                'TransactionDate' => self::DAY, 'ShiftNo' => $shift, 'SellPrice' => 10,
                'QtyOpen' => 10, 'QtyIssued' => 0, 'QtyClose' => 10, 'QtyComputer' => $pos,
                'QtyOpen_Original' => 10, 'QtyIssued_Original' => 0, 'QtyClose_Original' => 10, 'QtyComputer_Original' => $pos,
            ]);
        }

        // Shift-based till sales, matched by POS code and location.
        foreach ([['TEST-TQ1', 3, 1], ['TEST-TQ1', 2, 3], ['TEST-TQ3', 5, 1], ['TEST-TQ4', 7, 1]] as [$code, $qty, $shift]) {
            $db->table('PumpIT.dbo.DBF_P3TRANS_STKRECON')->insert([
                'SSBranchId' => self::BRANCH, 'DATE' => self::DAY.' 10:00', 'CODE' => $code,
                'LOCATION' => 'TPOS', 'Qty' => $qty, 'ShiftNo' => $shift,
            ]);
        }
        // Time-based till sales, by area and item; the adjustment is not a sale.
        foreach ([[3, 'Sale'], [2, 'Sale'], [100, 'Stock Adjustment']] as [$qty, $origin]) {
            $db->table('PumpIT.dbo.DBF_P3TRANS_STOCKRECON_ShiftTimeBased')->insert([
                'SSBranchId' => self::BRANCH, 'DATE' => self::DAY.' 12:00', 'CODE' => 'TEST-TQ2',
                'origindescription' => $origin, 'quantity' => $qty, 'AreaNo' => 92, 'StockItemNo' => 'TTQ2',
            ]);
        }

        $run = StockReconRun::create([
            'BranchId' => self::BRANCH, 'GroupRef' => (string) Str::uuid(), 'AreaNo' => null,
            'FromDate' => self::DAY, 'ToDate' => self::DAY, 'Status' => 'previewed',
            'ProcedureName' => 'TEST-tillqty', 'Note' => 'TEST-tillqty',
        ]);

        $profile = $db->table('agora.StockReconBalancingProfile')
            ->where('BranchId', (int) config('agora.group_branch_id', 2))->where('IsActive', 1)->where('IsDefault', 1)->sole();
        $line = 0;
        foreach ([['TTQ1', 91], ['TTQ2', 92], ['TTQ3', 91]] as [$item, $area]) {
            $db->table('agora.StockReconRunLine')->insert([
                'BranchId' => self::BRANCH, 'RunId' => $run->Id, 'LineNo' => ++$line,
                'AreaNo' => $area, 'StockItemNo' => $item, 'TransactionDate' => self::DAY, 'ShiftNo' => 1,
                'QtyOpen' => 10, 'QtyIssued' => 0, 'QtyClose' => 10, 'QtyPOS' => 0,
                'QtyOpenNow' => 10, 'QtyCloseNow' => 10, 'QtyVar' => 0, 'QtyVarNew' => 0,
                'ContinuitySegment' => 0, 'BalancingProfileId' => $profile->Id, 'BalancingProfileRevision' => $profile->Revision,
                'WasteSourceAuthorised' => 0, 'AuthorizedGoodWaste' => 0,
                'QtyOpenNew' => 10, 'QtyCloseNew' => 10, 'AmendClose' => 0, 'WouldAmend' => false,
                'Selected' => false, 'CountsMove' => false, 'Outcome' => 'TEST-tillqty',
            ]);
        }

        return $run;
    }

    private function db(): Connection
    {
        return $this->database ?? throw new \LogicException('The local test transaction has not started.');
    }
}
