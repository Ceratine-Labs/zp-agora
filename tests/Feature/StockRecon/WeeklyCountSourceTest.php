<?php

namespace Tests\Feature\StockRecon;

use App\Support\ProcedureService;
use Illuminate\Database\Connection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Exact source-procedure SQL over disposable local views, never legacy writes. */
class WeeklyCountSourceTest extends TestCase
{
    private const SCHEMA = 'TEST_WeeklyCounts';

    private ?Connection $db = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->skipUnlessLocalStub();
        foreach (config('agora.connections') as $name) {
            $this->assertContains(config("database.connections.{$name}.host"), ['127.0.0.1', 'localhost', '::1']);
        }
        $this->db = DB::connection(config('agora.connections.app'));
        $this->db->beginTransaction();
        $this->db->unprepared('CREATE SCHEMA '.self::SCHEMA);
        $tables = [
            'WinbranchCount' => 'BranchId int,CountDate date,POSCode nvarchar(80),TYPE varchar(3),REF varchar(20),DETAILS varchar(40),QTY decimal(19,4),QTYREC decimal(19,4)',
            'WinbranchLastCount' => 'BranchId int,POSCode nvarchar(80),LOCATION varchar(20),LastCountDate date',
            'PilotCount' => 'BranchId int,PrepsheetHistoryId int,StoreId int,CountDate date,POSCode nvarchar(80),OSTK decimal(19,4),TRANS decimal(19,4),MAKE decimal(19,4),ISSUE decimal(19,4),SALES decimal(19,4),CSTK decimal(19,4),COST decimal(19,4),CapturedAt datetime',
            'AuraCount' => 'BranchId int,CountDate date,POSCode nvarchar(80),LocationId int,Entered bit,Prepped decimal(19,4),Unprepped decimal(19,4),BulkUnitPrice decimal(19,4)',
            'AuraPurchase' => 'BranchId int,MovementDate date,POSCode nvarchar(80),LocationId int,BULKPCH decimal(19,4),QUANTITY decimal(19,4),UNIT_PRICE decimal(19,4),PRICEPCH decimal(19,4)',
            'AuraSale' => 'BranchId int,MovementDate date,CODE nvarchar(80),QUANTITY decimal(19,4),PREVOID bit',
            'AuraPrice' => 'BranchId int,CODE nvarchar(80),RECIPE nvarchar(80),LocationId int',
            'AuraRecipe' => 'BranchId int,RECIPE nvarchar(80),POSCode nvarchar(80),QUANTITY decimal(19,4)',
        ];
        foreach ($tables as $name => $columns) {
            $this->db->unprepared('CREATE TABLE '.self::SCHEMA.'.'.$name.' ('.$columns.')');
            $this->db->unprepared('CREATE VIEW '.self::SCHEMA.'.vw_Weekly'.$name.' AS SELECT * FROM '.self::SCHEMA.'.'.$name);
        }
        $sql = file_get_contents(base_path('Modules/StockRecon/Database/Procedures/usp_StockRecon_WeeklyCounts.sql'));
        $this->db->unprepared(str_replace('agora.', self::SCHEMA.'.', $sql));
    }

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            $this->db->rollBack();
        }
        parent::tearDown();
    }

    public function test_winbranch_proves_zero_only_from_in_week_count_facts_or_latest_date_and_keeps_branch_scope(): void
    {
        $this->db->unprepared('INSERT INTO '.self::SCHEMA.".WinbranchCount VALUES
            (970,'2026-09-20','ZERO','S','S/TAKE','TEST-zero',10,10),
            (970,'2026-09-13','OLD','S','S/TAKE','TEST-previous',8,10),
            (970,'2026-09-20','NOTCOUNT','S','OTHER','TEST-other',1,10),
            (971,'2026-09-20','OTHERBRANCH','S','S/TAKE','TEST-hidden',1,10);
            INSERT INTO ".self::SCHEMA.".WinbranchLastCount VALUES
            (970,'ZERO','WINBRANCH','2026-09-20'),(970,'NIL','WINBRANCH','2026-09-18'),
            (970,'LATER','WINBRANCH','2026-09-21'),(970,'NEVER','WINBRANCH',NULL);");
        $sets = $this->read();
        $rows = $sets[0]->keyBy('POSCode');
        $this->assertCount(3, $rows);
        $this->assertEquals(0, $rows['ZERO']->VarianceQty);
        $this->assertEquals(0, $rows['NIL']->VarianceQty);
        $this->assertNull($rows['NIL']->CountedQty);
        $this->assertEquals(-2, $rows['OLD']->VarianceQty);
        $this->assertFalse($rows->has('LATER'));
        $this->assertFalse($rows->has('OTHERBRANCH'));
        $this->assertSame('2026-09-21', $sets[2]->keyBy('POSCode')['LATER']->LastCountDate);
    }

    public function test_aura_preserves_location_cycles_and_unknown_prior_count_and_pilot_matches_actual_report_arithmetic(): void
    {
        $this->db->unprepared('INSERT INTO '.self::SCHEMA.".AuraCount VALUES
            (970,'2026-09-13','A',1,1,100,0,5),(970,'2026-09-20','A',1,1,80,0,5),
            (970,'2026-09-13','A',2,1,50,0,5),(970,'2026-09-20','A',2,1,60,0,5),
            (970,'2026-09-18','NOPRIOR',1,1,3,0,5),(970,'2026-09-18','NOTENTERED',1,0,3,0,5);
            INSERT INTO ".self::SCHEMA.".AuraPurchase VALUES(970,'2026-09-17','A',1,20,0,8,160),(970,'2026-09-21','A',1,20,0,99,1980),(971,'2026-09-17','A',1,20,0,99,1980);
            INSERT INTO ".self::SCHEMA.".AuraSale VALUES(970,'2026-09-18','P',10,0),(970,'2026-09-18','P',99,1);
            INSERT INTO ".self::SCHEMA.".AuraPrice VALUES(970,'P','R',1);
            INSERT INTO ".self::SCHEMA.".AuraRecipe VALUES(970,'R','A',2);
            INSERT INTO ".self::SCHEMA.".PilotCount VALUES
            (970,1,1,'2026-09-20','PIL',100,2,5,3,10,90,2,'2026-09-21'),
            (970,2,1,'2026-09-20','-1',100,2,5,3,10,90,2,'2026-09-21'),
            (970,3,1,'2026-09-20','UNKNOWN',100,2,5,3,10,NULL,2,'2026-09-21');");
        $rows = $this->read()[0];
        $aura = $rows->filter(fn ($row) => $row->SourceSystem === 'AURA' && $row->POSCode === 'A' && $row->CountDate === '2026-09-20')->values();
        $this->assertCount(2, $aura);
        $this->assertEquals(8, $aura[0]->UnitCost);
        $this->assertEquals(5, json_decode($aura[0]->DetailJson)->SnapshotUnitCost);
        $this->assertNull($rows->firstWhere('POSCode', 'NOPRIOR')->UnitCost);
        $this->assertEquals(-10, $aura->sum('VarianceQty'));
        $this->assertEquals(100, $aura[0]->ExpectedQty);
        $this->assertEquals(20, json_decode($aura[0]->DetailJson)->ReceivedQty);
        $this->assertEquals(20, json_decode($aura[0]->DetailJson)->RecipeConsumptionQty);
        $this->assertNull($rows->firstWhere('POSCode', 'NOPRIOR')->VarianceQty);
        $this->assertNull($rows->firstWhere('POSCode', 'UNKNOWN')->VarianceQty);
        $this->assertEquals(-4, $rows->firstWhere('POSCode', 'PIL')->VarianceQty);
        $this->assertEquals(94, $rows->firstWhere('POSCode', 'PIL')->ExpectedQty);
        $this->assertNull($rows->firstWhere('POSCode', '-1'));
        $this->assertNull($rows->firstWhere('POSCode', 'NOTENTERED'));
    }

    public function test_missing_source_family_is_unavailable_and_other_readers_remain_available(): void
    {
        $this->db->unprepared('DROP VIEW '.self::SCHEMA.'.vw_WeeklyAuraRecipe');
        $sets = $this->read();
        $this->assertSame('Unavailable', $sets[1]->firstWhere('SourceSystem', 'AURA')->State);
        $this->assertSame('Available', $sets[1]->firstWhere('SourceSystem', 'PILOT')->State);
        $this->assertSame('Available', $sets[1]->firstWhere('SourceName', 'WINBRANCH_INVHISTF')->State);
        $this->assertCount(0, $sets[0]);
    }

    public function test_last_count_date_never_fabricates_zero_when_variance_source_is_unavailable(): void
    {
        $this->db->unprepared('DROP VIEW '.self::SCHEMA.'.vw_WeeklyWinbranchCount');
        $this->db->unprepared('INSERT INTO '.self::SCHEMA.".WinbranchLastCount VALUES (970,'MISSING','WINBRANCH','2026-09-18')");
        $sets = $this->read();
        $this->assertCount(0, $sets[0]);
        $this->assertSame('Unavailable', $sets[1]->firstWhere('SourceName', 'WINBRANCH_INVHISTF')->State);
        $this->assertCount(1, $sets[2]);
    }

    /** @return array<int, Collection<int, object>> */
    private function read(): array
    {
        return app(ProcedureService::class)->callSets(self::SCHEMA.'.usp_StockRecon_WeeklyCounts', ['BranchId' => 970, 'FromDate' => '2026-09-14', 'ToDate' => '2026-09-20']);
    }
}
