<?php

namespace Tests\Feature\Product;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `agora.vw_StockItem` — which of the two masters answers.
 *
 * The stock master is the customer's, in PumpIT, and Agora never writes it.
 * `agora.StockItem` shadows it: a live override replaces its legacy row whole,
 * a parked one leaves the legacy row in force, and an override with no legacy
 * counterpart adds an item PumpIT never had. Those three cases are the whole
 * contract, and each is one test here.
 *
 * Two more things the view is responsible for and which a reader would not
 * guess from the SQL:
 *
 *  · `IsActive`. PumpIT's table has no active flag at all, which is why 4,434
 *    of 7,447 live items had not been counted anywhere since 1 August 2026 and
 *    nothing could hide them. A legacy row therefore reads as active, and
 *    retiring an item IS writing an override with IsActive = 0.
 *  · The cost side. `vw_StockItemPos` joins DBF_STDB on (branch, code, POS
 *    system) because that is the table's own primary key. On branch and code
 *    alone the same query attaches a DIFFERENT PRODUCT's cost — one site
 *    really does carry one code in two POS systems — so there is a test for
 *    that too, and it is the one worth keeping if any are ever cut.
 *
 * The fixture writes to the LOCAL PumpIT stub and to Agora's own table, never
 * to the customer's instance, and only under branch 999 — the inactive branch
 * that exists for exactly this. tearDown() removes every row it wrote.
 */
class StockItemViewTest extends TestCase
{
    /** The E2E fixture branch: inactive, non-trading, and nobody's real site. */
    private const BRANCH = 999;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessLocalStub('This fixture writes stock master rows.');
        $this->cleanUp();
        $this->seedLegacy();
    }

    protected function tearDown(): void
    {
        if ($this->app !== null) {
            $this->cleanUp();
        }

        parent::tearDown();
    }

    public function test_a_legacy_item_with_no_override_comes_through_unchanged(): void
    {
        $row = $this->resolved('10');

        $this->assertSame('legacy', trim($row->Source));
        $this->assertSame('PIE CHICKEN AND MUSHROOM', trim((string) $row->StockItemDescription));
        $this->assertSame('29.9900', $row->SellingPrice);
        $this->assertSame('WINBRANCH', trim((string) $row->PosSystem));
    }

    public function test_a_legacy_item_reads_as_active_because_pumpit_cannot_say_otherwise(): void
    {
        $this->assertSame(1, (int) $this->resolved('10')->IsActive);
    }

    public function test_a_live_override_replaces_the_legacy_row_whole(): void
    {
        $this->override('10', [
            'StockItemDescription' => 'PIE CHICKEN AND MUSHROOM 200G',
            'SellingPrice' => 34.5000,
            'PriceType' => 'Set Price',
        ]);

        $row = $this->resolved('10');

        $this->assertSame('agora', trim($row->Source));
        $this->assertSame('PIE CHICKEN AND MUSHROOM 200G', trim((string) $row->StockItemDescription));
        $this->assertSame('34.5000', $row->SellingPrice);

        // Still one row, not two. A UNION that let both arms through would be
        // a silent doubling of the whole master.
        $this->assertSame(1, $this->countResolved('10'));
    }

    public function test_parking_an_override_puts_the_customers_row_back(): void
    {
        $this->override('10', ['StockItemDescription' => 'SOMETHING ELSE ENTIRELY']);
        $this->assertSame('agora', trim($this->resolved('10')->Source));

        $this->db()->table('agora.StockItem')
            ->where('BranchId', self::BRANCH)
            ->where('StockItemNo', '10')
            ->update(['IsParked' => 1]);

        $row = $this->resolved('10');
        $this->assertSame('legacy', trim($row->Source));
        $this->assertSame('PIE CHICKEN AND MUSHROOM', trim((string) $row->StockItemDescription));

        // The override row itself survives, with its reason — that is the
        // difference between parking and deleting.
        $this->assertSame(1, $this->db()->table('agora.StockItem')
            ->where('BranchId', self::BRANCH)->where('StockItemNo', '10')->count());
    }

    public function test_an_override_with_no_legacy_row_adds_an_item(): void
    {
        $this->override('77', [
            'StockItemDescription' => 'TEST-AGORA ONLY LINE',
            'POSCode' => 'TEST-77',
        ]);

        $row = $this->resolved('77');
        $this->assertSame('agora', trim($row->Source));
        $this->assertSame('TEST-AGORA ONLY LINE', trim((string) $row->StockItemDescription));
    }

    public function test_retiring_an_item_is_an_override_carrying_is_active_zero(): void
    {
        $this->override('10', ['IsActive' => 0]);

        $this->assertSame(0, (int) $this->resolved('10')->IsActive);
    }

    public function test_a_float_from_pumpit_arrives_as_the_decimal_it_was_always_meant_to_be(): void
    {
        // PumpIT stores Factor 2.6 as a FLOAT, which round-trips as
        // 2.6000000000000001 and renders in a grid exactly like that. The view
        // casts both arms to DECIMAL(18,4) — and it has to cast the LEGACY arm
        // too, because float wins type precedence in a UNION and would drag
        // the override side back with it.
        $this->assertSame('2.6000', $this->resolved('10')->Factor);
    }

    public function test_the_cost_join_uses_the_pos_system_and_so_returns_one_row_per_item(): void
    {
        // Two products, one code, two POS systems — the live shape at branch
        // 18, where code 999 is a roll-on under WINBRANCH and a prawn salad
        // under AURA.
        $this->costRow('SHARED', 'WINBRANCH', 'PIE CHICKEN AND MUSHROOM', 21.50);
        $this->costRow('SHARED', 'AURA', 'PRAWN SALAD', 68.00);

        $this->db()->table('PumpIT.dbo.STK_StockMaster')
            ->where('SSBranchId', self::BRANCH)
            ->where('StockItemNo', '10')
            ->update(['POSCode' => 'SHARED']);

        $rows = $this->db()->select("
            SELECT i.StockItemNo, p.PosDescription, p.CostPrice
            FROM [agora].[vw_StockItem] i
            LEFT JOIN [agora].[vw_StockItemPos] p
                   ON p.BranchId = i.BranchId
                  AND p.PosSystem = i.PosSystem
                  AND p.PosCode = i.POSCode
            WHERE i.BranchId = ? AND i.StockItemNo = '10'
        ", [self::BRANCH]);

        $this->assertCount(1, $rows, 'The three-part join must return exactly one cost row per item.');
        $this->assertSame('PIE CHICKEN AND MUSHROOM', trim((string) $rows[0]->PosDescription));
        $this->assertSame('21.5000', $rows[0]->CostPrice);
    }

    public function test_dropping_the_pos_system_from_the_cost_join_attaches_the_wrong_product(): void
    {
        $this->costRow('SHARED', 'WINBRANCH', 'PIE CHICKEN AND MUSHROOM', 21.50);
        $this->costRow('SHARED', 'AURA', 'PRAWN SALAD', 68.00);

        $this->db()->table('PumpIT.dbo.STK_StockMaster')
            ->where('SSBranchId', self::BRANCH)
            ->where('StockItemNo', '10')
            ->update(['POSCode' => 'SHARED']);

        $rows = $this->db()->select("
            SELECT p.PosDescription
            FROM [agora].[vw_StockItem] i
            LEFT JOIN [agora].[vw_StockItemPos] p
                   ON p.BranchId = i.BranchId
                  AND p.PosCode = i.POSCode
            WHERE i.BranchId = ? AND i.StockItemNo = '10'
        ", [self::BRANCH]);

        // This is the bug this view exists to prevent, asserted rather than
        // described: one item, two rows, and one of them is a prawn salad.
        $this->assertCount(2, $rows);
        $this->assertContains('PRAWN SALAD', array_map(
            fn ($r) => trim((string) $r->PosDescription), $rows
        ));
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

    private function countResolved(string $itemNo): int
    {
        return count($this->db()->select(
            'SELECT 1 AS x FROM [agora].[vw_StockItem] WHERE BranchId = ? AND StockItemNo = ?',
            [self::BRANCH, $itemNo]
        ));
    }

    /** @param  array<string, mixed>  $changes */
    private function override(string $itemNo, array $changes): void
    {
        $this->db()->table('agora.StockItem')->insert(array_merge([
            'BranchId' => self::BRANCH,
            'StockItemNo' => $itemNo,
            'StockItemDescription' => 'TEST-OVERRIDE',
            'AreaNo' => 1,
            'Location' => 'WINBRANCH',
            'POSCode' => 'TEST-1',
            'SellingPrice' => 1.0000,
            'PriceType' => 'Set Price',
            'Factor' => 0,
            'UOMCode' => 'Each',
            'IssueMultiple' => 1,
            'IssueMultiplePercentage' => 0,
            'QtyVarAllowance' => 0,
            'IsMonitoredItem' => 0,
            'IsDoCloseQtyCalc' => 1,
            'IsAllowNegativeQtyIssued' => 0,
            'IsAllowNegativeQtyClose' => 0,
            'IsStockItemPreProduction' => 0,
            'IsPreProductionItem' => 0,
            'PreProductionTypeNo' => 0,
            'Ratio' => 0,
            'ProduceLimitPercentage' => 0,
            'IsActive' => 1,
            'IsParked' => 0,
            'Reason' => 'TEST-fixture',
        ], $changes));
    }

    private function costRow(string $code, string $posSystem, string $description, float $cost): void
    {
        $this->db()->table('PumpIT.dbo.DBF_STDB')->insert([
            'SSBranchId' => self::BRANCH,
            'CODE' => $code,
            'LOCATION' => $posSystem,
            'DESC' => $description,
            'CAT' => 'TEST',
            'STDCOST' => $cost,
            'STDSELL' => $cost * 1.5,
            'QTY' => 3,
            'M_SOLD' => 0,
            'L_SOLD' => null,
            'PACKSIZE' => 1,
            'VATCODE' => 'A',
        ]);
    }

    private function seedLegacy(): void
    {
        $this->db()->table('PumpIT.dbo.STK_StockMaster')->insert([
            'SSBranchId' => self::BRANCH,
            'StockItemNo' => '10',
            'StockItemDescription' => 'PIE CHICKEN AND MUSHROOM',
            'AreaNo' => 1,
            'Location' => 'WINBRANCH',
            'POSCode' => '1200',
            'SellingPrice' => 29.99,
            'PriceType' => 'Factor',
            // 2.6 through a FLOAT column, which is the whole point of the
            // cast test above.
            'Factor' => 2.6,
            'UOMCode' => 'Each',
            'IssueMultiple' => 1,
            'IssueMultiplePercentage' => 0,
            'QtyVarAllowance' => 0.01,
            'isMonitoredItem' => 1,
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
        $this->db()->table('PumpIT.dbo.DBF_STDB')->where('SSBranchId', self::BRANCH)->delete();
    }

    private function db(): Connection
    {
        return DB::connection(config('agora.connections.app'));
    }
}
