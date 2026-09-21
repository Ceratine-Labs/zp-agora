<?php

namespace Tests\Feature\Product;

use App\Exceptions\AgoraProcException;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Modules\Product\Services\StockMasterService;
use Tests\TestCase;

/**
 * `agora.usp_Product_SaveStockItem` — the only thing that writes a stock line.
 *
 * The tests worth having here are the refusals, not the happy path. A save
 * that works is visible the moment anybody uses the screen; a rule that does
 * not fire is invisible until a count is valued wrong, and the one that
 * matters most is the POS code.
 *
 * THE POS CODE RULE IS THE REASON THIS FILE EXISTS. A code is unique per
 * (branch, POS SYSTEM), not per branch — across the live estate there are 97
 * collisions on (branch, code) and zero on (branch, system, code), every one
 * a legitimate item carried in two POS systems at one site. Two tests pin both
 * halves: the collision is refused, and the legitimate pair is allowed. The
 * customer's own sp_DuplicatePOSCODE gets the second half wrong.
 *
 * Writes to the LOCAL PumpIT stub and to Agora's own table, branch 999 only.
 */
class SaveStockItemTest extends TestCase
{
    private const BRANCH = 999;

    private StockMasterService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessLocalStub('This fixture writes stock master rows.');
        $this->service = new StockMasterService;
        $this->cleanUp();
        $this->seedArea(1, 'TEST-Shop floor');
        $this->seedLegacy('10', 'TEST-PIE CHICKEN', 'WINBRANCH', '1200');
    }

    protected function tearDown(): void
    {
        if ($this->app !== null) {
            $this->cleanUp();
        }

        parent::tearDown();
    }

    public function test_a_save_writes_an_override_that_the_view_resolves_to(): void
    {
        $this->save('10', 'save', ['StockItemDescription' => 'TEST-PIE CHICKEN 200G']);

        $row = $this->resolved('10');
        $this->assertSame('agora', trim($row->Source));
        $this->assertSame('TEST-PIE CHICKEN 200G', trim((string) $row->StockItemDescription));

        // And PumpIT is untouched. This is the assertion the whole design
        // exists for, so it is made explicitly rather than assumed.
        $legacy = $this->db()->table('PumpIT.dbo.STK_StockMaster')
            ->where('SSBranchId', self::BRANCH)->where('StockItemNo', '10')->first();
        $this->assertSame('TEST-PIE CHICKEN', trim((string) $legacy->StockItemDescription));
    }

    public function test_a_pos_code_already_live_on_the_same_pos_system_is_refused(): void
    {
        $this->seedLegacy('11', 'TEST-OTHER LINE', 'WINBRANCH', '9999');

        $this->expectRefusal('POS_CODE_TAKEN', fn () => $this->save('10', 'save', [
            'POSCode' => '9999',
            'PosSystem' => 'WINBRANCH',
        ]));
    }

    public function test_the_same_pos_code_on_a_different_pos_system_is_allowed(): void
    {
        // The half the customer's own duplicate report gets wrong. 97 pairs
        // across the live estate are exactly this, and every one is correct.
        $this->seedLegacy('11', 'TEST-OTHER LINE', 'ARCH', '9999');

        $this->save('10', 'save', ['POSCode' => '9999', 'PosSystem' => 'WINBRANCH']);

        $this->assertSame('9999', trim((string) $this->resolved('10')->POSCode));
    }

    public function test_a_code_colliding_with_a_legacy_row_agora_has_never_touched_is_still_refused(): void
    {
        // The reason the check reads the VIEW rather than agora.StockItem: the
        // row it collides with may be one Agora has never heard of, and a
        // check against our own table alone would let two live lines share a
        // till code.
        $this->seedLegacy('12', 'TEST-LEGACY ONLY', 'WINBRANCH', '4321');

        $this->expectRefusal('POS_CODE_TAKEN', fn () => $this->save('10', 'save', [
            'POSCode' => '4321',
            'PosSystem' => 'WINBRANCH',
        ]));
    }

    public function test_an_area_that_does_not_exist_at_this_site_is_refused(): void
    {
        // Areas are numbered per site: area 1 is a different shelf at every
        // branch, so this cannot be checked against a list.
        $this->expectRefusal('NO_SUCH_AREA', fn () => $this->save('10', 'save', ['AreaNo' => 77]));
    }

    public function test_a_change_with_no_reason_is_refused(): void
    {
        $this->expectRefusal('REASON_REQUIRED', fn () => $this->save('10', 'save', ['reason' => '   ']));
    }

    public function test_a_price_type_outside_the_three_is_refused(): void
    {
        $this->expectRefusal('BAD_PRICE_TYPE', fn () => $this->save('10', 'save', ['PriceType' => 'Whatever']));
    }

    public function test_a_pos_system_outside_the_six_is_refused(): void
    {
        $this->expectRefusal('BAD_POS_SYSTEM', fn () => $this->save('10', 'save', ['PosSystem' => 'TILLSOFT']));
    }

    public function test_retiring_a_line_that_has_no_override_writes_one_from_what_is_in_force(): void
    {
        // The caller sends one word, not twenty-two columns — sending them is
        // how one of them arrives wrong.
        $this->service->save(self::BRANCH, '10', 'retire', ['reason' => 'TEST-retire'], null);

        $row = $this->resolved('10');
        $this->assertSame(0, (int) $row->IsActive);
        $this->assertSame('agora', trim($row->Source));
        // Everything else came off the legacy row rather than defaulting.
        $this->assertSame('TEST-PIE CHICKEN', trim((string) $row->StockItemDescription));
        $this->assertSame('1200', trim((string) $row->POSCode));
    }

    public function test_parking_an_override_puts_the_customers_row_back_and_keeps_the_reason(): void
    {
        $this->save('10', 'save', ['StockItemDescription' => 'TEST-CHANGED']);
        $this->service->save(self::BRANCH, '10', 'park', ['reason' => 'TEST-wrong call'], null);

        $this->assertSame('legacy', trim($this->resolved('10')->Source));

        $override = $this->db()->table('agora.StockItem')
            ->where('BranchId', self::BRANCH)->where('StockItemNo', '10')->first();
        $this->assertSame(1, (int) $override->IsParked);
        $this->assertSame('TEST-wrong call', $override->Reason);
    }

    public function test_parking_when_there_is_no_override_says_so_rather_than_doing_nothing(): void
    {
        $this->expectRefusal(
            'NOTHING_TO_PARK',
            fn () => $this->service->save(self::BRANCH, '10', 'park', ['reason' => 'TEST-nope'], null)
        );
    }

    public function test_saving_a_parked_override_brings_it_back(): void
    {
        // Editing something and having it stay invisible is not an outcome
        // anyone means.
        $this->save('10', 'save', ['StockItemDescription' => 'TEST-FIRST']);
        $this->service->save(self::BRANCH, '10', 'park', ['reason' => 'TEST-park'], null);
        $this->save('10', 'save', ['StockItemDescription' => 'TEST-SECOND']);

        $row = $this->resolved('10');
        $this->assertSame('agora', trim($row->Source));
        $this->assertSame('TEST-SECOND', trim((string) $row->StockItemDescription));
    }

    public function test_unparking_is_refused_when_it_would_put_two_lines_on_one_till_code(): void
    {
        // A collision that did not exist while the override was parked. If
        // unpark did not re-check, parking and unparking would be a way round
        // the rule.
        $this->save('10', 'save', ['POSCode' => '5555', 'PosSystem' => 'WINBRANCH']);
        $this->service->save(self::BRANCH, '10', 'park', ['reason' => 'TEST-park'], null);
        $this->seedLegacy('13', 'TEST-TOOK THE CODE', 'WINBRANCH', '5555');

        $this->expectRefusal(
            'POS_CODE_TAKEN',
            fn () => $this->service->save(self::BRANCH, '10', 'unpark', ['reason' => 'TEST-back'], null)
        );
    }

    public function test_an_override_can_add_a_line_pumpit_never_had(): void
    {
        $this->save('90', 'save', [
            'StockItemDescription' => 'TEST-AGORA ONLY',
            'POSCode' => 'TEST-90',
        ]);

        $row = $this->resolved('90');
        $this->assertSame('agora', trim($row->Source));
        $this->assertNull($this->db()->table('PumpIT.dbo.STK_StockMaster')
            ->where('SSBranchId', self::BRANCH)->where('StockItemNo', '90')->first());
    }

    /** @param  array<string, mixed>  $changes */
    private function save(string $itemNo, string $action, array $changes): object
    {
        return $this->service->save(self::BRANCH, $itemNo, $action, array_merge([
            'StockItemDescription' => 'TEST-LINE',
            'AreaNo' => 1,
            'PosSystem' => 'WINBRANCH',
            'POSCode' => 'TEST-'.$itemNo,
            'SellingPrice' => 10.5,
            'PriceType' => 'Set Price',
            'UOMCode' => 'Each',
            'IsActive' => true,
            'reason' => 'TEST-fixture',
        ], $changes), null);
    }

    private function expectRefusal(string $code, callable $act): void
    {
        try {
            $act();
        } catch (AgoraProcException $e) {
            $this->assertSame($code, $e->code(), "Refused, but with {$e->code()}: {$e->getMessage()}");

            return;
        }

        $this->fail("Expected AGORA:{$code} and the write was allowed.");
    }

    private function resolved(string $itemNo): object
    {
        $rows = $this->db()->select(
            'SELECT * FROM [agora].[vw_StockItem] WHERE BranchId = ? AND StockItemNo = ?',
            [self::BRANCH, $itemNo]
        );

        $this->assertCount(1, $rows, "Expected exactly one resolved row for item {$itemNo}.");

        return $rows[0];
    }

    private function seedArea(int $areaNo, string $description): void
    {
        $this->db()->table('PumpIT.dbo.STK_Area')->insert([
            'SSBranchId' => self::BRANCH,
            'AreaNo' => $areaNo,
            'AreaDescription' => $description,
            'AreaGroup' => 'Retail Items',
            'DayShift' => 1,
            'AfternoonShift' => 0,
            'NightShift' => 0,
            'ShowReport' => 1,
            'IsCaptureWaste' => 0,
        ]);
    }

    private function seedLegacy(string $itemNo, string $description, string $posSystem, string $posCode): void
    {
        $this->db()->table('PumpIT.dbo.STK_StockMaster')->insert([
            'SSBranchId' => self::BRANCH,
            'StockItemNo' => $itemNo,
            'StockItemDescription' => $description,
            'AreaNo' => 1,
            'Location' => $posSystem,
            'POSCode' => $posCode,
            'SellingPrice' => 29.99,
            'PriceType' => 'Set Price',
            'Factor' => 0,
            'UOMCode' => 'Each',
            'IssueMultiple' => 1,
            'IssueMultiplePercentage' => 0,
            'QtyVarAllowance' => 0,
            'isMonitoredItem' => 0,
            'IsDoCloseQtyCalc' => 1,
            'IsAllowNegativeQtyIssued' => 0,
            'IsAllowNegativeQtyClose' => 0,
            'IsStockItemPreProduction' => 0,
            'isPreProductionItem' => 0,
            'PreProductionTypeNo' => 0,
            'Ratio' => 0,
            'ProduceLimitPercentage' => 0,
            'CreateDateTime' => '2026-01-01 00:00:00',
        ]);
    }

    private function cleanUp(): void
    {
        $this->db()->table('agora.StockItem')->where('BranchId', self::BRANCH)->delete();
        $this->db()->table('PumpIT.dbo.STK_StockMaster')->where('SSBranchId', self::BRANCH)->delete();
        $this->db()->table('PumpIT.dbo.STK_Area')->where('SSBranchId', self::BRANCH)->delete();
    }

    private function db(): Connection
    {
        return DB::connection(config('agora.connections.app'));
    }
}
