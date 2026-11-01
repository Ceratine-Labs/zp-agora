<?php

namespace Tests\Feature\StockRecon;

use App\Exceptions\AgoraProcException;
use App\Support\ProcedureService;
use Illuminate\Database\Connection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Executes the actual adapter views and SQL in a disposable local schema. */
class WeeklyReceiptsTest extends TestCase
{
    private const SCHEMA = 'TEST_WeeklyReceipts';

    private ?Connection $database = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->skipUnlessLocalStub();
        $this->assertSame(config('agora.connections.app'), config('database.default'));
        $this->database = DB::connection(config('agora.connections.app'));
        $this->database->beginTransaction();
        $this->database->unprepared('CREATE SCHEMA '.self::SCHEMA);
        $this->database->unprepared('CREATE TABLE '.self::SCHEMA.'.POSSystems (POSSystemId int PRIMARY KEY, POSSystemName nvarchar(30));
            CREATE TABLE '.self::SCHEMA.'.MIST_Trans_Supplier (
            MIST_BranchId int, POSSystemId int, TransactionDate datetime, EODDate datetime,
            TransactionNumber nvarchar(20), TransactionType nvarchar(1),
            BusinessPartnerCode char(16), BusinessPartnerName char(50), DocumentNumber char(50),
            ItemCode char(16), ItemDescription char(50), ItemUOM char(6), PackSize decimal(18,6),
            Qty decimal(18,6), UnitCostPriceExcl decimal(18,6), LineTotalAmountExcl decimal(18,6),
            LineTotalAmountVat decimal(18,6), LineTotalAmountIncl decimal(18,6));
            CREATE TABLE '.self::SCHEMA.'.PILOT_Purchases (
            MIST_BranchId int, purchaseID int, D datetime, PROD varchar(10), ITEM varchar(30),
            UNIT varchar(10), QTY numeric(8,2), COST numeric(8,2), VAT numeric(8,2),
            SUPP varchar(10), SUPPLIER varchar(30), SREF varchar(20), PREF varchar(20), CAT char(2));');
        $this->database->table(self::SCHEMA.'.POSSystems')->insert([
            ['POSSystemId' => 1, 'POSSystemName' => 'Aura'],
            ['POSSystemId' => 2, 'POSSystemName' => 'Arch'],
            ['POSSystemId' => 5, 'POSSystemName' => 'Winbranch'],
            ['POSSystemId' => 7, 'POSSystemName' => 'Arch Liquor'],
        ]);
        $queries = $this->database->pretend(function (): void {
            $migration = require base_path('Modules/StockRecon/Database/Migrations/v1__14pzc_stockrecon_weekly_receipts.php');
            $migration->up();
        });
        $this->assertCount(3, $queries);
        $namespaces = array_map(fn (string $name): string => '['.str_replace(']', ']]', $name).'].dbo.', [
            config('agora.source_databases.erp'), config('agora.source_databases.pos_landing'), config('agora.source_databases.pos_reference'),
        ]);
        $namespaces[] = '['.config('agora.schema').'].';
        $namespaces[] = 'agora.';
        foreach ($queries as $query) {
            $this->database->unprepared(str_replace($namespaces, self::SCHEMA.'.', $query['query']));
        }
    }

    protected function tearDown(): void
    {
        if ($this->database !== null) {
            $this->database->rollBack();
        }
        parent::tearDown();
    }

    public function test_source_dates_branch_and_extended_window_preserve_signed_units_without_source_writes(): void
    {
        $this->supplier('PRIOR', ['EODDate' => '2030-12-30', 'TransactionDate' => '2030-12-01', 'Qty' => 2.345678]);
        $this->supplier('FIRST', ['PackSize' => 12]);
        $this->supplier('CREDIT', ['EODDate' => '2031-01-12 23:59:59', 'TransactionType' => 'C', 'POSSystemId' => 7]);
        $this->supplier('LATE', ['EODDate' => '2031-01-15 23:59:59']);
        $this->supplier('BEFORE', ['EODDate' => '2030-12-29']);
        $this->supplier('AFTER', ['EODDate' => '2031-01-16']);
        $this->supplier('OTHER', ['MIST_BranchId' => 998]);
        $this->supplier('OTHER-FEED', ['POSSystemId' => 5]);
        $before = $this->db()->table(self::SCHEMA.'.MIST_Trans_Supplier')->get()->all();
        $rows = $this->evidence()->keyBy('Reference');
        $this->assertCount(4, $rows);
        $this->assertEqualsWithDelta(2.3457, $rows['PRIOR']->Quantity, 1e-8);
        $this->assertSame('Previous week', $this->detail($rows['PRIOR'])['PeriodPosition']);
        $this->assertSame('After week', $this->detail($rows['LATE'])['PeriodPosition']);
        $this->assertSame('Selected week', $this->detail($rows['FIRST'])['PeriodPosition']);
        $this->assertEquals(3, $rows['FIRST']->Quantity);
        $this->assertEquals(12, $this->detail($rows['FIRST'])['PackSize']);
        $this->assertSame('KG', $this->detail($rows['FIRST'])['Unit']);
        $this->assertSame('Credit', $rows['CREDIT']->EvidenceType);
        $this->assertEquals(-3, $rows['CREDIT']->Quantity);
        $this->assertEquals(-34.5, $rows['CREDIT']->Value);
        $this->assertSame('WINBRANCH', $rows['CREDIT']->SourceSystem);
        $this->assertEquals($before, $this->db()->table(self::SCHEMA.'.MIST_Trans_Supplier')->get()->all());
    }

    public function test_aura_keeps_transaction_date_and_branch_scoped_invoice_and_cost_exceptions(): void
    {
        $this->supplier('HISTORY', ['POSSystemId' => 1, 'TransactionDate' => '2030-12-15', 'UnitCostPriceExcl' => 5]);
        $this->supplier('NO-PRICE', ['POSSystemId' => 1, 'TransactionDate' => '2030-12-29', 'UnitCostPriceExcl' => 0]);
        $this->supplier('PRICE', ['POSSystemId' => 1, 'EODDate' => '2031-02-01']);
        $this->supplier('DUP', ['POSSystemId' => 1, 'TransactionNumber' => '2', 'TransactionDate' => '2031-01-07']);
        $this->supplier('DUP', ['POSSystemId' => 1, 'TransactionNumber' => '3', 'TransactionDate' => '2031-01-08']);
        $this->supplier('PRICE', ['MIST_BranchId' => 998, 'POSSystemId' => 1, 'UnitCostPriceExcl' => 1]);
        $rows = $this->evidence();
        $this->assertCount(3, $rows);
        $price = $rows->firstWhere('Reference', 'PRICE');
        $this->assertNotNull($price);
        $this->assertSame('AURA', $price->SourceSystem);
        $this->assertSame('2031-01-06', $price->EvidenceDate);
        $this->assertEquals(5, $this->detail($price)['PreviousUnitCost']);
        $this->assertSame('Unit cost moved more than twenty five percent', $this->detail($price)['ExceptionFlag']);
        foreach ($rows->where('Reference', 'DUP') as $row) {
            $this->assertSame('Invoice reference used more than once', $this->detail($row)['ExceptionFlag']);
        }
    }

    public function test_pilot_source_credits_and_unusable_source_rows_remain_visible_without_false_totals(): void
    {
        $this->pilot(1);
        $this->pilot(2, ['QTY' => -2, 'COST' => -20, 'VAT' => -3]);
        $this->pilot(3, ['CAT' => 'zc']);
        $this->pilot(4, ['MIST_BranchId' => 998]);
        $this->pilot(5, ['D' => '2031-01-16']);
        $this->supplier('UNKNOWN', ['TransactionType' => 'X']);
        $this->supplier('MISSING-EOD', ['EODDate' => null]);
        $rows = $this->evidence();
        $this->assertCount(5, $rows);
        $pilot = $rows->where('SourceSystem', 'PILOT');
        $this->assertCount(3, $pilot);
        $credit = $pilot->firstWhere('EvidenceType', 'Credit');
        $this->assertNotNull($credit);
        $this->assertEquals(-2, $credit->Quantity);
        $this->assertEquals(-23, $credit->Value);
        $this->assertSame('EACH', $this->detail($credit)['Unit']);
        $this->assertEquals(2, $this->detail($credit)['SourceId']);
        $exceptions = $rows->where('EvidenceType', 'SourceException');
        $this->assertCount(3, $exceptions);
        foreach ($exceptions as $row) {
            $this->assertNull($row->Quantity);
            $this->assertNull($row->Value);
            $this->assertNotEmpty($this->detail($row)['ExceptionFlag']);
            $this->assertNotNull($this->detail($row)['SourceQuantity']);
        }
    }

    public function test_invalid_week_and_missing_sources_are_explicit(): void
    {
        foreach ([['BranchId' => 0], ['FromDate' => '2031-01-13'], ['ToDate' => '2031-01-13']] as $params) {
            try {
                $this->evidence($params);
                $this->fail('Invalid scope must be refused.');
            } catch (AgoraProcException $e) {
                $this->assertSame('BAD_WEEK', $e->code());
            }
        }
        $this->db()->unprepared('DROP VIEW '.self::SCHEMA.'.vw_StockWeeklyPilotPurchase');
        try {
            $this->evidence();
            $this->fail('Missing receipts must not look like a clean week.');
        } catch (AgoraProcException $e) {
            $this->assertSame('WEEKLY_SOURCE_UNAVAILABLE', $e->code());
        }
    }

    /** @param array<string, mixed> $overrides */
    private function supplier(string $reference, array $overrides = []): void
    {
        $this->db()->table(self::SCHEMA.'.MIST_Trans_Supplier')->insert(array_merge([
            'MIST_BranchId' => 999, 'POSSystemId' => 2, 'TransactionDate' => '2031-01-06', 'EODDate' => '2031-01-06',
            'TransactionNumber' => $reference, 'TransactionType' => 'I', 'BusinessPartnerCode' => 'TEST',
            'BusinessPartnerName' => 'TEST-Supplier', 'DocumentNumber' => $reference, 'ItemCode' => ' T-ITEM ',
            'ItemDescription' => 'TEST-Stock', 'ItemUOM' => 'KG', 'PackSize' => 1, 'Qty' => 3,
            'UnitCostPriceExcl' => 10, 'LineTotalAmountExcl' => 30, 'LineTotalAmountVat' => 4.5, 'LineTotalAmountIncl' => 34.5,
        ], $overrides));
    }

    /** @param array<string, mixed> $overrides */
    private function pilot(int $id, array $overrides = []): void
    {
        $this->db()->table(self::SCHEMA.'.PILOT_Purchases')->insert(array_merge([
            'MIST_BranchId' => 999, 'purchaseID' => $id, 'D' => '2031-01-06', 'PROD' => 'T-PILOT',
            'ITEM' => 'TEST-Stock', 'UNIT' => 'EACH', 'QTY' => 2, 'COST' => 20, 'VAT' => 3,
            'SUPP' => 'TEST', 'SUPPLIER' => 'TEST-Supplier', 'SREF' => 'TEST-'.$id, 'PREF' => 'PREF-'.$id, 'CAT' => '01',
        ], $overrides));
    }

    /** @param array<string, mixed> $params
     * @return Collection<int, object>
     */
    private function evidence(array $params = []): Collection
    {
        $sets = (new ProcedureService(config('agora.connections.app')))->callSets(self::SCHEMA.'.usp_StockRecon_WeeklyReceipts',
            array_merge(['BranchId' => 999, 'FromDate' => '2031-01-06', 'ToDate' => '2031-01-12'], $params));
        $this->assertCount(1, $sets);

        return $sets[0];
    }

    /** @return array<string, mixed> */
    private function detail(object $row): array
    {
        return json_decode($row->DetailJson, true, 512, JSON_THROW_ON_ERROR);
    }

    private function db(): Connection
    {
        $this->assertNotNull($this->database);

        return $this->database;
    }
}
