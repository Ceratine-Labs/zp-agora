<?php

namespace Tests\Feature\Product;

use App\Exceptions\AgoraProcException;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Modules\Product\Services\StockMasterService;
use Modules\Product\Support\StockItemFlags;
use Tests\TestCase;

/**
 * `agora.usp_Product_SetStockItemFlag` — the batch flag action on the stock
 * recon master listing (Ryan, 22 September 2026).
 *
 * THE TEST THAT EARNS THIS FILE is the one that walks every flag in
 * {@see StockItemFlags::FLAGS} through the procedure and reads the column
 * back. PHP holds one list and T-SQL holds another — the procedure's
 * whitelist and its seven CASE expressions — and nothing but a run can prove
 * they are the same list. A flag added to PHP and forgotten in the procedure
 * would pass validation, reach the database, be refused as BAD_FLAG; a flag
 * added to the whitelist and forgotten in the CASE would be accepted and
 * change NOTHING, silently, which is the worse of the two.
 *
 * The second reason is the INSERT arm. Most of the estate is the customer's
 * own rows with no Agora override, so "monitor these forty" is not an update
 * — the row to update does not exist. That path seeds an override from what
 * is in force, and everything it does NOT copy correctly is a price or an
 * area quietly changed by a button that said it was setting a flag.
 *
 * Writes to the LOCAL PumpIT stub and to Agora's own table, branch 999 only.
 */
class StockItemFlagsTest extends TestCase
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
        $this->seedLegacy('10', 'TEST-PIE CHICKEN', 'WINBRANCH', 'TEST-1200');
        $this->seedLegacy('11', 'TEST-PIE STEAK', 'WINBRANCH', 'TEST-1201');
    }

    protected function tearDown(): void
    {
        if ($this->app !== null) {
            $this->cleanUp();
        }

        parent::tearDown();
    }

    public function test_every_flag_the_screen_offers_is_one_the_procedure_sets(): void
    {
        foreach (StockItemFlags::keys() as $flag) {
            // Each flag is driven to the OPPOSITE of what the line already
            // holds, so a procedure that accepted the name and changed nothing
            // fails here rather than passing on a value that was already right.
            $before = (int) $this->resolved('10')->{$flag};
            $target = $before === 1 ? false : true;

            $this->service->setFlag($this->items('10'), $flag, $target, 'TEST-every flag', null);

            $this->assertSame(
                (int) $target,
                (int) $this->resolved('10')->{$flag},
                "{$flag} is offered by the screen but the procedure did not set it. ".
                'Check its whitelist AND its CASE expressions — a name in one and not the other is accepted and ignored.'
            );
        }
    }

    public function test_setting_a_flag_on_a_legacy_line_writes_an_override_and_copies_the_rest_of_it(): void
    {
        $legacy = $this->resolved('10');
        $this->assertSame('legacy', trim($legacy->Source), 'The fixture should start as the customer\'s own row.');

        $this->service->setFlag($this->items('10'), 'IsMonitoredItem', true, 'TEST-monitor it', null);

        $after = $this->resolved('10');
        $this->assertSame('agora', trim($after->Source));
        $this->assertSame(1, (int) $after->IsMonitoredItem);

        // Everything the batch did NOT name is what it must not have changed.
        // A price or an area moved by a button labelled "set a flag" is the
        // failure this assertion exists for.
        foreach (['StockItemDescription', 'AreaNo', 'PosSystem', 'POSCode', 'SellingPrice',
            'PriceType', 'UOMCode', 'IsActive', 'IsDoCloseQtyCalc'] as $column) {
            $this->assertSame(
                trim((string) $legacy->{$column}),
                trim((string) $after->{$column}),
                "The batch changed {$column}, which it was not asked to touch."
            );
        }

        // And PumpIT is untouched, which is the rule the whole design exists
        // for.
        $row = $this->db()->table('PumpIT.dbo.STK_StockMaster')
            ->where('SSBranchId', self::BRANCH)->where('StockItemNo', '10')->first();
        $this->assertSame(0, (int) $row->isMonitoredItem);
    }

    public function test_one_press_reaches_every_selected_line(): void
    {
        $result = $this->service->setFlag(
            array_merge($this->items('10'), $this->items('11')),
            'IsActive',
            false,
            'TEST-retire the pair',
            null
        );

        $this->assertSame(0, (int) $this->resolved('10')->IsActive);
        $this->assertSame(0, (int) $this->resolved('11')->IsActive);
        $this->assertSame(2, (int) $result->Id, 'The status row reports how many lines were in the selection.');
        $this->assertSame(2, (int) $result->Created, 'Both lines were the customer\'s, so both gained an override.');
    }

    public function test_a_line_already_overridden_is_updated_rather_than_duplicated(): void
    {
        $this->service->setFlag($this->items('10'), 'IsMonitoredItem', true, 'TEST-first', null);
        $result = $this->service->setFlag($this->items('10'), 'IsMonitoredItem', false, 'TEST-second', null);

        $this->assertSame(0, (int) $result->Created);
        $this->assertSame(1, (int) $result->Updated);
        $this->assertSame(0, (int) $this->resolved('10')->IsMonitoredItem);

        $this->assertSame(1, $this->db()->table('agora.StockItem')
            ->where('BranchId', self::BRANCH)->where('StockItemNo', '10')->count());
    }

    public function test_the_selection_is_keyed_on_branch_as_well_as_item(): void
    {
        // An item number is per branch. A procedure keyed on the number alone
        // would reach the same number at another site, which across the live
        // estate is 653 numbers over 22 sites.
        $this->service->setFlag(
            [['BranchId' => self::BRANCH, 'StockItemNo' => '10']],
            'IsMonitoredItem',
            true,
            'TEST-one site only',
            null
        );

        $this->assertSame(0, $this->db()->table('agora.StockItem')
            ->where('BranchId', '<>', self::BRANCH)->where('StockItemNo', '10')->count());
    }

    public function test_a_batch_with_no_reason_is_refused(): void
    {
        $this->expectRefusal('REASON_REQUIRED', fn () => $this->service->setFlag(
            $this->items('10'), 'IsActive', false, '   ', null
        ));
    }

    public function test_a_flag_that_is_not_a_stock_line_flag_is_refused(): void
    {
        // IsParked is a real column on agora.StockItem and is deliberately NOT
        // settable here: it is a property of the override, not of the item.
        $this->expectRefusal('BAD_FLAG', fn () => $this->service->setFlag(
            $this->items('10'), 'IsParked', true, 'TEST-should not be allowed', null
        ));
    }

    public function test_an_empty_selection_is_refused(): void
    {
        $this->expectRefusal('NOTHING_SELECTED', fn () => $this->service->setFlag(
            [], 'IsActive', false, 'TEST-nothing ticked', null
        ));
    }

    public function test_a_line_that_does_not_exist_at_that_site_stops_the_whole_batch(): void
    {
        $this->expectRefusal('NO_SUCH_ITEM', fn () => $this->service->setFlag(
            array_merge($this->items('10'), [['BranchId' => self::BRANCH, 'StockItemNo' => 'ZZZZZ']]),
            'IsActive',
            false,
            'TEST-stale page',
            null
        ));

        // Stopped means stopped: the good line in the same batch is untouched.
        $this->assertSame('legacy', trim($this->resolved('10')->Source));
    }

    public function test_a_parked_override_stops_the_batch_rather_than_being_restored(): void
    {
        $this->service->setFlag($this->items('10'), 'IsMonitoredItem', true, 'TEST-make an override', null);
        $this->service->save(self::BRANCH, '10', 'park', ['reason' => 'TEST-park it'], null);

        $this->expectRefusal('OVERRIDE_PARKED', fn () => $this->service->setFlag(
            $this->items('10'), 'IsMonitoredItem', false, 'TEST-should refuse', null
        ));

        // Still parked, so the customer's row is still what is in force.
        $this->assertSame('legacy', trim($this->resolved('10')->Source));
    }

    /** @return array<int, array{BranchId: int, StockItemNo: string}> */
    private function items(string $itemNo): array
    {
        return [['BranchId' => self::BRANCH, 'StockItemNo' => $itemNo]];
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
