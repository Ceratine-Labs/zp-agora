<?php

namespace Tests\Feature\Operations;

use App\Support\ProcedureService;
use Illuminate\Database\Connection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Local-only fixtures, fully rolled back, including the temporary budget view.
 * Covers the consequential distinctions: POS system, missing versus zero, and
 * duplicate budgets versus conflicting budgets.
 */
class TradingChecksTest extends TestCase
{
    private const BRANCH = 997;

    private bool $transactionStarted = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->skipUnlessLocalStub();
        $this->db()->beginTransaction();
        $this->transactionStarted = true;
        $this->branch(self::BRANCH);
    }

    protected function tearDown(): void
    {
        if ($this->transactionStarted) {
            $this->db()->rollBack();
        }

        parent::tearDown();
    }

    public function test_critical_stock_preserves_pos_keys_missing_data_and_the_existing_pack_rule(): void
    {
        $this->critical('TEST-KVI-A', 'WINBRANCH', 0, 6);
        $this->critical('TEST-KVI-A', 'AURA', 20, 6);
        $this->critical('TEST-KVI-B', 'WINBRANCH', 4, 6);
        $this->critical('TEST-KVI-C', 'WINBRANCH', 1, 0);
        $this->critical('TEST-KVI-D', 'WINBRANCH', 8, 1, 1, false);
        $this->critical('TEST-KVI-E', 'WINBRANCH', 0, 1, 0);
        $this->db()->table('PumpIT.dbo.DBF_STDB')
            ->where('SSBranchId', self::BRANCH)->where('CODE', 'TEST-KVI-B')
            ->update(['STDCOST' => 12, 'STDSELL' => 10]);
        $this->db()->table('PumpIT.dbo.DBF_STDB')
            ->where('SSBranchId', self::BRANCH)->where('CODE', 'TEST-KVI-C')
            ->update(['STDCOST' => 0]);

        $rows = $this->rows()->where('MetricKey', 'critical-stock')
            ->keyBy(fn ($row) => $row->ItemCode.'/'.$row->Area);

        $this->assertCount(5, $rows, 'Off-list rows disappear; uncounted critical products remain.');
        $this->assertSame('crit', $rows['TEST-KVI-A/WINBRANCH']->Severity);
        $this->assertEquals(0, $rows['TEST-KVI-A/WINBRANCH']->Value);
        $this->assertSame('good', $rows['TEST-KVI-A/AURA']->Severity, 'Same code on another POS is a different item.');
        $this->assertEquals(20, $rows['TEST-KVI-A/AURA']->Value);
        $this->assertSame('warn', $rows['TEST-KVI-B/WINBRANCH']->Severity);
        $this->assertEquals(6, $rows['TEST-KVI-B/WINBRANCH']->SecondaryValue);
        $this->assertSame('warn', $rows['TEST-KVI-C/WINBRANCH']->Severity);
        $this->assertEquals(1, $rows['TEST-KVI-C/WINBRANCH']->SecondaryValue);
        $this->assertSame('neutral', $rows['TEST-KVI-D/WINBRANCH']->Severity);
        $this->assertNull($rows['TEST-KVI-D/WINBRANCH']->Value);
        $this->assertEquals(0, $rows['TEST-KVI-D/WINBRANCH']->SourceCount);
        $this->assertTrue($rows->every(fn ($row) => $row->SourceUpdatedAt === null));
        $this->assertStringContainsString('not historical stock', $rows->first()->Detail);

        $prices = $this->rows()->where('MetricKey', 'critical-pricing')->keyBy('ItemCode');
        $this->assertEqualsWithDelta(-20, (float) $prices['TEST-KVI-B']->Value, 0.001);
        $this->assertSame('crit', $prices['TEST-KVI-B']->Severity);
        $this->assertNull($prices['TEST-KVI-C']->Value, 'Missing cost is not 100% margin.');
        $this->assertSame('neutral', $prices['TEST-KVI-C']->Severity);
        $this->assertStringContainsString('both exclude VAT', $prices['TEST-KVI-B']->Detail);
    }

    public function test_selection_and_allowed_sites_are_independent_and_never_expand_empty_to_all(): void
    {
        $this->critical('TEST-KVI-A', 'WINBRANCH', 0, 6);

        $this->assertTrue($this->rows()->isNotEmpty());
        $this->assertCount(0, $this->rows(null));
        $this->assertCount(0, $this->rows(''));
        $this->assertCount(0, $this->rows('not-a-site'));
        $this->assertCount(0, $this->rows((string) self::BRANCH, '998'));
        $this->assertTrue($this->rows((string) self::BRANCH, (string) self::BRANCH)
            ->every(fn ($row) => (int) $row->BranchId === self::BRANCH));
    }

    public function test_daily_budgets_deduplicate_and_map_liquor_without_inventing_missing_targets(): void
    {
        $this->branch(9);
        $this->branch(15);
        $this->budgetView(false);

        $rows = $this->rows('9,15,997')->where('MetricKey', 'daily-budget')
            ->keyBy(fn ($row) => $row->BranchId.'/'.$row->ItemCode);

        $this->assertCount(12, $rows);
        $this->assertEqualsWithDelta(100, (float) $rows['997/SHOP']->Value, 0.0001, 'Two identical rows are one monthly budget.');
        $this->assertEquals(2, $rows['997/SHOP']->SourceCount);
        $this->assertEqualsWithDelta(10, (float) $rows['997/LUBES']->Value, 0.0001);
        $this->assertEqualsWithDelta(1000, (float) $rows['997/FUEL']->Value, 0.0001, 'Fuel GP is not added to litres.');
        $this->assertSame('litres', $rows['997/FUEL']->Unit);
        $this->assertEquals(0, $rows['997/QSR']->Value, 'An explicit zero budget remains zero.');
        $this->assertEquals(1, $rows['997/QSR']->SourceCount);
        $this->assertEqualsWithDelta(100, (float) $rows['9/SHOP']->Value, 0.0001);
        $this->assertEqualsWithDelta(200, (float) $rows['15/SHOP']->Value, 0.0001, 'Branch 9 liquor budget belongs to branch 15.');
        $this->assertNull($rows['9/FUEL']->Value, 'An absent budget stays unknown.');
        $this->assertEquals(0, $rows['9/FUEL']->SourceCount);
        $this->assertTrue($rows->every(fn ($row) => $row->Severity === 'neutral' && $row->SecondaryValue === null));
        $this->assertTrue($rows->every(fn ($row) => $row->SourceUpdatedAt === null));

        $onlyLiquor = $this->rows('9,15', '15')->where('MetricKey', 'daily-budget');
        $this->assertCount(4, $onlyLiquor);
        $this->assertTrue($onlyLiquor->every(fn ($row) => (int) $row->BranchId === 15));
        $this->assertEqualsWithDelta(200, (float) $onlyLiquor->firstWhere('ItemCode', 'SHOP')->Value, 0.0001);

        $this->budgetView(true);
        $conflict = $this->rows()->first(fn ($row) => $row->MetricKey === 'daily-budget' && $row->ItemCode === 'SHOP');
        $this->assertNotNull($conflict);
        $this->assertNull($conflict->Value);
        $this->assertStringContainsString('Conflicting', $conflict->Detail);
    }

    /** @return Collection<int, object> */
    private function rows(?string $branches = '997', ?string $allowed = null): Collection
    {
        return app(ProcedureService::class)->call('usp_Operations_TradingChecks', [
            'BranchIds' => $branches,
            'AllowedBranchIds' => $allowed,
            'TradeDate' => '2030-10-20',
        ]);
    }

    private function branch(int $id): void
    {
        if (! $this->db()->table('agora.Branch')->where('BranchId', $id)->exists()) {
            $this->db()->table('agora.Branch')->insert([
                'BranchId' => $id, 'Name' => 'TEST-WALL-TRADING-'.$id,
                'IsActive' => 1, 'IsTrading' => 1, 'SortOrder' => $id,
            ]);
        }
    }

    private function critical(string $code, string $system, float $qty, float $pack, int $active = 1, bool $pos = true): void
    {
        $this->db()->table('PumpIT.dbo.STK_StockMasterCritical')->insert([
            'SSBranchId' => self::BRANCH, 'Code' => $code, 'Location' => $system,
            'Desc' => 'TEST-Critical lube', 'CAT' => 'LUBES', 'IsActive' => $active,
        ]);

        if ($pos) {
            $this->db()->table('PumpIT.dbo.DBF_STDB')->insert([
                'SSBranchId' => self::BRANCH, 'CODE' => $code, 'LOCATION' => $system,
                'DESC' => 'TEST-Critical lube', 'CAT' => 'LUBES', 'STDCOST' => 10,
                'STDSELL' => 15, 'QTY' => $qty, 'M_SOLD' => 10,
                'L_SOLD' => '2030-10-19', 'PACKSIZE' => $pack, 'VATCODE' => 'A',
            ]);
        }
    }

    private function budgetView(bool $conflict): void
    {
        $second = $conflict ? 3200 : 3100;
        $this->db()->unprepared("
            CREATE OR ALTER VIEW agora.vw_OperationsBudget AS
            SELECT BranchId, CONVERT(int, 2031) AS BudgetYear,
                   CONVERT(date, '2030-10-01') AS BudgetMonth,
                   Category, SubCategory, TableKey, TableUnit, CONVERT(decimal(22,4), Value) AS Value
            FROM (VALUES
                (997, 'Retail', 'Shop', 'TEST-SHOP', 'TO_Excl', 3100),
                (997, 'Retail', 'Shop', 'TEST-SHOP', 'TO_Excl', {$second}),
                (997, 'Retail', 'Lubes', 'TEST-LUBES', 'TO_Excl', 310),
                (997, 'QSR', 'Steers', 'TEST-QSR', 'TO_Excl', 0),
                (997, 'Fuel', 'Diesel', 'TEST-FUEL', 'Volume', 31000),
                (997, 'Fuel', 'Diesel', 'TEST-FUEL-GP', 'GP_Excl', 6200),
                (997, 'Hospitality', 'Accomodation', 'TEST-ROOMS', 'TO_Excl', 6200),
                (9, 'Retail', 'Shop', 'TEST-SHOP9', 'TO_Excl', 3100),
                (9, 'Retail', 'Liquor', 'TEST-LIQUOR', 'TO_Excl', 6200)
            ) d(BranchId, Category, SubCategory, TableKey, TableUnit, Value)
        ");
    }

    private function db(): Connection
    {
        return DB::connection(config('agora.connections.app'));
    }
}
