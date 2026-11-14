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
 * agora.usp_StockRecon_PosMovedCheck and the warning on the run page (Caltex
 * Ulundi, 14 Nov 2026): a run committed before the till quantities came
 * through balanced on a POS of 0, and its amendments still stand.
 *
 *   TPM1  active amendment, issue cut 300 -> 240, POS 0 at the run, 63 now.
 *         Reported, with its issue write.
 *   TPM2  active amendment, POS 4 at the run and 4 now. Not reported.
 *   TPM3  POS 0 at the run, 9 now, but the amendment was reversed. Not reported.
 *
 * Branch 999 on the LOCAL container, inside a transaction rolled back in
 * tearDown, as TillQtyCheckTest does.
 */
class PosMovedCheckTest extends TestCase
{
    private const BRANCH = 999;

    private const DAY = '2031-02-03';

    private const AREA = 93;

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

    public function test_standing_amendments_worked_out_on_an_old_pos_are_reported_per_item(): void
    {
        $run = $this->fixture();

        $rows = (new StockReconService(new ProcedureService))->posMovedChains($run);

        $this->assertSame(['TPM1'], $rows->pluck('StockItemNo')->all());
        $row = $rows->first();
        $this->assertSame('TEST-Cigarettes', $row->AreaDescription);
        $this->assertSame('TEST loose cigarette', $row->StockItemDescription);
        $this->assertEquals(1, $row->Shifts);
        $this->assertEquals(1, $row->IssueWrites);
        $this->assertEqualsWithDelta(0.0, (float) $row->QtyPOSRun, 0.0005);
        $this->assertEqualsWithDelta(63.0, (float) $row->QtyPOSNow, 0.0005);
        $this->assertNotNull($row->RunLineId);
    }

    public function test_the_run_page_lists_them_with_a_link_to_reverse_each_item(): void
    {
        $run = $this->fixture();
        $rows = (new StockReconService(new ProcedureService))->posMovedChains($run);

        $html = view('stockrecon::partials.pos-moved-notice', ['posMoved' => $rows, 'run' => $run])->render();

        $this->assertStringContainsString('The POS has changed since this run — 1 item was balanced on old till quantities', $html);
        $this->assertStringContainsString('TEST loose cigarette', $html);
        $this->assertStringContainsString(route('app.stockrecon.line', [$run, $rows->first()->RunLineId]).'#reverse-chain', $html);
        $this->assertStringContainsString('usp_StockRecon_PosMovedCheck', $html);

        $none = view('stockrecon::partials.pos-moved-notice', ['posMoved' => collect(), 'run' => $run])->render();
        $this->assertSame('', trim($none));
    }

    private function fixture(): StockReconRun
    {
        $db = $this->db();
        $db->table('PumpIT.dbo.STK_Area')->insert([
            'SSBranchId' => self::BRANCH, 'AreaNo' => self::AREA, 'AreaDescription' => 'TEST-Cigarettes',
            'DayShift' => 1, 'AfternoonShift' => 0, 'NightShift' => 1, 'ShowReport' => 1,
            'IsCaptureWaste' => 0, 'Shift_TimeBased' => 0,
        ]);
        // [item, name, issued now, POS at the run, POS now, amendment state]
        $items = [
            ['TPM1', 'TEST loose cigarette', 240, 0, 63, 'active'],
            ['TPM2', 'TEST cigarette two', 10, 4, 4, 'active'],
            ['TPM3', 'TEST cigarette three', 10, 0, 9, 'reversed'],
        ];
        foreach ($items as [$item, $name, $issued, , $posNow]) {
            $db->table('PumpIT.dbo.STK_StockMaster')->insert([
                'SSBranchId' => self::BRANCH, 'StockItemNo' => $item, 'StockItemDescription' => $name,
                'UOMCode' => 'EA', 'AreaNo' => self::AREA, 'QtyVarAllowance' => 0, 'IsDoCloseQtyCalc' => 0,
                'POSCode' => 'TEST-'.$item, 'Location' => 'TPOS',
            ]);
            $db->table('PumpIT.dbo.STK_StockReconLine')->insert([
                'SSBranchId' => self::BRANCH, 'AreaNo' => self::AREA, 'StockItemNo' => $item,
                'TransactionDate' => self::DAY, 'ShiftNo' => 1, 'SellPrice' => 2.99,
                'QtyOpen' => 0, 'QtyIssued' => $issued, 'QtyClose' => 239, 'QtyComputer' => $posNow,
                'QtyOpen_Original' => 0, 'QtyIssued_Original' => 300, 'QtyClose_Original' => 239, 'QtyComputer_Original' => $posNow,
            ]);
        }

        $run = StockReconRun::create([
            'BranchId' => self::BRANCH, 'GroupRef' => (string) Str::uuid(), 'AreaNo' => null,
            'FromDate' => self::DAY, 'ToDate' => self::DAY, 'Status' => 'committed',
            'ProcedureName' => 'TEST-posmoved', 'Note' => 'TEST-posmoved',
        ]);
        $profile = $db->table('agora.StockReconBalancingProfile')
            ->where('BranchId', (int) config('agora.group_branch_id', 2))->where('IsActive', 1)->where('IsDefault', 1)->sole();

        $no = 0;
        foreach ($items as [$item, , , $posRun, , $state]) {
            $lineId = $db->table('agora.StockReconRunLine')->insertGetId([
                'BranchId' => self::BRANCH, 'RunId' => $run->Id, 'LineNo' => ++$no,
                'AreaNo' => self::AREA, 'StockItemNo' => $item, 'TransactionDate' => self::DAY, 'ShiftNo' => 1,
                'QtyOpen' => 0, 'QtyIssued' => 300, 'QtyClose' => 239, 'QtyPOS' => $posRun,
                'QtyOpenNow' => 0, 'QtyCloseNow' => 239, 'QtyVar' => -61, 'QtyVarNew' => -1,
                'ContinuitySegment' => 0, 'BalancingProfileId' => $profile->Id, 'BalancingProfileRevision' => $profile->Revision,
                'WasteSourceAuthorised' => 0, 'AuthorizedGoodWaste' => 0,
                'QtyOpenNew' => 0, 'QtyCloseNew' => 239, 'AmendClose' => 0, 'WouldAmend' => true,
                'Selected' => true, 'CountsMove' => false, 'Outcome' => 'TEST-posmoved',
            ]);
            $db->table('agora.StockReconAmendment')->insert([
                'BranchId' => self::BRANCH, 'RunId' => $run->Id, 'RunLineId' => $lineId,
                'AreaNo' => self::AREA, 'StockItemNo' => $item, 'TransactionDate' => self::DAY, 'ShiftNo' => 1,
                'PriorQtyOpen' => 0, 'PriorQtyClose' => 239, 'NewQtyOpen' => 0, 'NewQtyClose' => 239,
                'PriorQtyIssued' => 300, 'NewQtyIssued' => 240,
                'StampMode' => 'live', 'State' => $state, 'CreatedAt' => now(),
            ]);
        }

        return $run;
    }

    private function db(): Connection
    {
        return $this->database ?? throw new \LogicException('The local test transaction has not started.');
    }
}
