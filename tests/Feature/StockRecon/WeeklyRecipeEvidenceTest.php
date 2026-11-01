<?php

namespace Tests\Feature\StockRecon;

use App\Exceptions\AgoraProcException;
use App\Support\ProcedureService;
use Illuminate\Database\Connection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Real migration views and procedure; only their namespaces are remapped. */
class WeeklyRecipeEvidenceTest extends TestCase
{
    private const SCHEMA = 'TEST_WeeklyRecipe';

    private ?Connection $database = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->skipUnlessLocalStub();
        $this->assertSame(config('agora.connections.app'), config('database.default'));
        $this->database = DB::connection(config('agora.connections.app'));
        $this->database->beginTransaction();
        $this->database->unprepared('CREATE SCHEMA '.self::SCHEMA);
        // Source types were verified against sys.columns, 25 October 2026.
        // These tables are in Agora's disposable schema, never MIST_Import.
        $this->database->unprepared('CREATE TABLE '.self::SCHEMA.'.WINBRANCH_INVHISTF (
            MIST_BranchId int, [DATE] datetime, DRC char(1), [TYPE] char(1), REF char(8), CODE char(16),
            [DESC] char(50), QTY decimal(18,6), UNIT char(6), COST decimal(18,6), PRICE decimal(18,6),
            SALES decimal(18,6), TOTAL decimal(18,6), VAT decimal(18,6), VATCODE char(1),
            EOD_CNTR char(8), SYSTIME char(8), REPCODE char(6), PROMCODE char(10), TENDER char(1),
            ALTCODE varchar(16), ExportRegisterId bigint);
            CREATE TABLE '.self::SCHEMA.'.PILOT_PrepsheetHistory (
            MIST_BranchId int, PrepsheetHistoryId int PRIMARY KEY, StoreId int, TDATE datetime,
            PROD varchar(10), NAME varchar(30), DPT varchar(4), Updated varchar(100),
            OSTK numeric(8,2), MAKE numeric(8,2), TRANS numeric(8,2), ISSUE numeric(8,2),
            CSTK numeric(8,2), SALES numeric(8,2), LOSS numeric(10,2), COST numeric(8,2), IsHourlyPrep bit);
            CREATE TABLE '.self::SCHEMA.'.PILOT_MasterFile (
            MIST_BranchId int, masterID bigint PRIMARY KEY, Updated datetime, PROD varchar(10),
            NAME varchar(30), UNIT varchar(10), CAT char(2));
            CREATE TABLE '.self::SCHEMA.'.PILOT_Vzcat (
            MIST_BranchId int, CAT varchar(10), [DESC] varchar(20), CCNT varchar(20));
            CREATE TABLE '.self::SCHEMA.'.PILOT_PrepVariance (
            MIST_BranchId int, prepID bigint PRIMARY KEY, IDATE datetime, PROD varchar(10), LossValue numeric(19,4));');

        $queries = $this->database->pretend(function (): void {
            $migration = require base_path('Modules/StockRecon/Database/Migrations/v1__14pzd_stockrecon_weekly_recipe.php');
            $migration->up();
        });
        $this->assertCount(6, $queries);
        $landing = str_replace(']', ']]', config('agora.source_databases.pos_landing'));
        $schema = str_replace(']', ']]', config('agora.schema'));
        foreach ($queries as $query) {
            $sql = str_replace(
                ["[{$landing}].dbo.", "[{$schema}].", 'agora.'],
                ['['.self::SCHEMA.'].', '['.self::SCHEMA.'].', '['.self::SCHEMA.'].'],
                $query['query'],
            );
            $this->database->unprepared($sql);
        }
    }

    protected function tearDown(): void
    {
        if ($this->database !== null) {
            $this->database->rollBack();
        }
        parent::tearDown();
    }

    public function test_winbranch_keeps_signed_source_events_units_and_unknown_value_basis(): void
    {
        $this->raw(['REF' => 'FIRST', 'QTY' => 2.345678]);
        $this->raw(['REF' => 'LAST', 'DATE' => '2031-01-12 23:59:59', 'QTY' => -1.25]);
        $this->raw(['REF' => 'BEFORE', 'DATE' => '2031-01-05 23:59:59']);
        $this->raw(['REF' => 'AFTER', 'DATE' => '2031-01-13']);
        $this->raw(['REF' => 'OTHER', 'MIST_BranchId' => 998]);
        $this->raw(['REF' => 'CREDIT', 'DRC' => 'C']);
        $this->raw(['REF' => 'SALE', 'TYPE' => 's']);
        $this->raw(['REF' => 'EXCLUDE', 'MIST_BranchId' => 11]);
        $this->raw(['REF' => 'EXCLUDE2', 'MIST_BranchId' => 12]);
        $before = $this->db()->table(self::SCHEMA.'.WINBRANCH_INVHISTF')->get()->all();

        $rows = $this->evidence();
        $this->assertCount(2, $rows);
        $this->assertEqualsWithDelta(2.3457, $rows[0]->Quantity, 1e-8);
        $this->assertEqualsWithDelta(-1.25, $rows[1]->Quantity, 1e-8);
        foreach ($rows as $row) {
            $this->assertSame('WINBRANCH', $row->SourceSystem);
            $this->assertSame('T-RAW', $row->POSCode);
            $this->assertSame('RawConsumption', $row->EvidenceType);
            $this->assertNull($row->Value);
            $detail = $this->detail($row);
            $this->assertSame(1237, $detail['SourceReportId']);
            $this->assertSame('KG', $detail['Unit']);
            $this->assertEquals(13.37, $detail['SourceCost']);
            $this->assertNull($detail['ActualConsumptionQuantity']);
            $this->assertStringContainsString('Unknown COST basis', $detail['ValueBasis']);
            $this->assertSame(999, $detail['SourceKey']['BranchId']);
            $this->assertCount(16, $detail['SourceKey']);
            $this->assertStringStartsWith(' T-RAW ', $detail['SourceKey']['SourceCode']);
        }
        $this->assertEqualsWithDelta(2.345678, $this->detail($rows[0])['TheoreticalConsumptionQuantity'], 1e-8);
        $this->assertCount(0, $this->evidence(['BranchId' => 11]));
        $this->assertCount(0, $this->evidence(['BranchId' => 12]));
        $this->assertEquals($before, $this->db()->table(self::SCHEMA.'.WINBRANCH_INVHISTF')->get()->all());
    }

    public function test_pilot_uses_theoretical_quantity_and_preserves_actual_and_cost_facts_without_join_fanout(): void
    {
        $this->prep(9001, ['Updated' => 'source capture text', 'LOSS' => 0.25]);
        $this->prep(9002, ['MIST_BranchId' => 998]);
        $this->prep(9003, ['TDATE' => '2031-01-13']);
        foreach ([[1, 999, '2031-01-01', 'EACH'], [2, 999, '2031-01-12', 'PACK'],
            [3, 999, '2031-01-12', 'KG'], [4, 999, '2031-01-13', 'FUTURE'],
            [5, 998, '2031-01-12', 'WRONG']] as [$id, $branch, $date, $unit]) {
            $this->db()->table(self::SCHEMA.'.PILOT_MasterFile')->insert([
                'MIST_BranchId' => $branch, 'masterID' => $id, 'Updated' => $date,
                'PROD' => 'T-PILOT', 'NAME' => 'TEST-Pilot master', 'UNIT' => $unit, 'CAT' => '01',
            ]);
        }
        $this->db()->table(self::SCHEMA.'.PILOT_Vzcat')->insert([
            ['MIST_BranchId' => 999, 'CAT' => '01', 'DESC' => 'TEST-A', 'CCNT' => 'KITCHEN'],
            ['MIST_BranchId' => 999, 'CAT' => '01', 'DESC' => 'TEST-Z', 'CCNT' => 'OTHER'],
            ['MIST_BranchId' => 998, 'CAT' => '01', 'DESC' => 'CROSS-BRANCH', 'CCNT' => 'WRONG'],
        ]);
        $this->db()->table(self::SCHEMA.'.PILOT_PrepVariance')->insert([
            ['MIST_BranchId' => 999, 'prepID' => 1, 'IDATE' => '2031-01-06 09:00', 'PROD' => 'T-PILOT', 'LossValue' => 2],
            ['MIST_BranchId' => 999, 'prepID' => 2, 'IDATE' => '2031-01-06 18:00', 'PROD' => 'T-PILOT', 'LossValue' => 3],
            ['MIST_BranchId' => 998, 'prepID' => 3, 'IDATE' => '2031-01-06', 'PROD' => 'T-PILOT', 'LossValue' => 100],
            ['MIST_BranchId' => 999, 'prepID' => 4, 'IDATE' => '2031-01-07', 'PROD' => 'T-PILOT', 'LossValue' => 100],
        ]);
        $before = $this->db()->table(self::SCHEMA.'.PILOT_PrepsheetHistory')->get()->all();

        $row = $this->evidence()->sole();
        $this->assertSame('PILOT', $row->SourceSystem);
        $this->assertSame('RecipeComparison', $row->EvidenceType);
        $this->assertSame('PILOT:9001', $row->Reference);
        $this->assertEquals(8.5, $row->Quantity);
        $this->assertEquals(19.13, $row->Value);
        $detail = $this->detail($row);
        $this->assertSame(1317, $detail['SourceReportId']);
        $this->assertSame('KG', $detail['Unit']);
        $this->assertSame(3, $detail['UnitMasterId']);
        $this->assertSame('TEST-A', $detail['Category']);
        $this->assertSame('KITCHEN', $detail['CostCentre']);
        $this->assertSame('source capture text', $detail['CountCapturedAt']);
        $this->assertEquals(10, $detail['ActualConsumptionQuantity']);
        $this->assertEquals(8.5, $detail['TheoreticalConsumptionQuantity']);
        $this->assertEquals(1.5, $detail['ConsumptionVarianceQuantity']);
        $this->assertEquals(22.5, $detail['ActualConsumptionValue']);
        $this->assertEquals(3.38, $detail['ConsumptionVarianceValue']);
        $this->assertEquals(17.65, $detail['VariancePercentOfTheoretical']);
        $this->assertEquals(0.25, $detail['RecordedWasteQuantity']);
        $this->assertEquals(5, $detail['RecordedWasteValue']);
        $this->assertSame(2, $detail['WasteSourceRows']);
        $this->assertStringContainsString('source cost basis', $detail['ValueBasis']);
        $this->assertEquals($before, $this->db()->table(self::SCHEMA.'.PILOT_PrepsheetHistory')->get()->all());
    }

    public function test_missing_facts_stay_unknown_and_known_zero_theoretical_is_retained(): void
    {
        $this->prep(9001, ['PROD' => 'T-MISSING', 'OSTK' => null, 'COST' => null]);
        $this->prep(9002, ['PROD' => 'T-ZERO', 'SALES' => 0]);
        $this->prep(9003, ['PROD' => 'T-QUIET', 'SALES' => 0, 'MAKE' => 0, 'ISSUE' => 0, 'TRANS' => 0, 'CSTK' => 10]);
        $rows = $this->evidence()->keyBy('POSCode');
        $this->assertCount(2, $rows);
        $this->assertNull($rows['T-MISSING']->Value);
        $detail = $this->detail($rows['T-MISSING']);
        $this->assertNull($detail['ActualConsumptionQuantity']);
        $this->assertNull($detail['ConsumptionVarianceQuantity']);
        $this->assertNull($detail['Unit']);
        $this->assertNull($detail['UnitMasterId']);
        $this->assertNull($detail['RecordedWasteValue']);
        $this->assertSame(0, $detail['WasteSourceRows']);
        $this->assertEquals(0, $rows['T-ZERO']->Quantity);
        $this->assertEquals(0, $rows['T-ZERO']->Value);
        $this->assertNull($this->detail($rows['T-ZERO'])['VariancePercentOfTheoretical']);
        $this->assertCount(0, $this->evidence(['BranchId' => 997]));
    }

    public function test_reversed_date_range_is_refused(): void
    {
        $this->expectException(AgoraProcException::class);
        $this->expectExceptionMessage('Choose a branch and a valid date range');
        $this->evidence(['FromDate' => '2031-01-13']);
    }

    public function test_missing_source_view_is_explicitly_unavailable(): void
    {
        $this->db()->unprepared('DROP VIEW '.self::SCHEMA.'.vw_WeeklyRecipePilotMaster;');
        try {
            $this->evidence();
            $this->fail('A missing source must not look like a zero-consumption week.');
        } catch (AgoraProcException $e) {
            $this->assertSame('WEEKLY_SOURCE_UNAVAILABLE', $e->code());
        }
    }

    /** @param array<string, mixed> $overrides */
    private function raw(array $overrides = []): void
    {
        $this->db()->table(self::SCHEMA.'.WINBRANCH_INVHISTF')->insert(array_merge([
            'MIST_BranchId' => 999, 'DATE' => '2031-01-06', 'DRC' => 'D', 'TYPE' => 'u',
            'REF' => 'TEST', 'CODE' => ' T-RAW ', 'DESC' => 'TEST-Raw stock', 'QTY' => 1,
            'UNIT' => 'KG', 'COST' => 13.37, 'PRICE' => 5, 'SALES' => 4, 'TOTAL' => 4.6,
            'VAT' => 0.6, 'VATCODE' => '1', 'EOD_CNTR' => 'EOD1', 'SYSTIME' => '09:00',
            'REPCODE' => 'TEST', 'PROMCODE' => '', 'TENDER' => 'C', 'ALTCODE' => 'ALT', 'ExportRegisterId' => 123,
        ], $overrides));
    }

    /** @param array<string, mixed> $overrides */
    private function prep(int $id, array $overrides = []): void
    {
        $this->db()->table(self::SCHEMA.'.PILOT_PrepsheetHistory')->insert(array_merge([
            'MIST_BranchId' => 999, 'PrepsheetHistoryId' => $id, 'StoreId' => 1, 'TDATE' => '2031-01-06',
            'PROD' => 'T-PILOT', 'NAME' => 'TEST-Pilot stock', 'DPT' => 'TST', 'Updated' => '2031-01-06',
            'OSTK' => 10, 'MAKE' => 5, 'TRANS' => 2, 'ISSUE' => 3, 'CSTK' => 4,
            'SALES' => 8.5, 'LOSS' => 0, 'COST' => 2.25, 'IsHourlyPrep' => 0,
        ], $overrides));
    }

    /** @param array<string, mixed> $params
     * @return Collection<int, object>
     */
    private function evidence(array $params = []): Collection
    {
        $sets = (new ProcedureService(config('agora.connections.app')))->callSets(
            self::SCHEMA.'.usp_StockRecon_WeeklyRecipeEvidence',
            array_merge(['BranchId' => 999, 'FromDate' => '2031-01-06', 'ToDate' => '2031-01-12'], $params),
        );
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
