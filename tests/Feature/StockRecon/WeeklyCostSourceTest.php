<?php

namespace Tests\Feature\StockRecon;

use App\Exceptions\AgoraProcException;
use App\Support\ProcedureService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WeeklyCostSourceTest extends TestCase
{
    public function test_det_fallback_preserves_export_branch_period_and_category_boundaries(): void
    {
        $this->skipUnlessLocalStub();
        DB::beginTransaction();
        try {
            DB::unprepared('CREATE SCHEMA TEST_WeeklyCost');
            DB::unprepared('CREATE TABLE TEST_WeeklyCost.Sales (BranchId int,SalesDate datetime,POSCode nvarchar(80),MCODE nvarchar(80),TYPE char(1),DRCR char(1),DRC char(1),QTY decimal(19,4),SALES decimal(19,4),COST decimal(19,4),ExportRegisterId int,EOD_CNTR int)');
            DB::unprepared('CREATE TABLE TEST_WeeklyCost.Cost (BranchId int,POSCode nvarchar(80),PACKCODE nvarchar(80),Description nvarchar(80),CAT nvarchar(20),UnitCost decimal(19,4))');
            DB::unprepared('CREATE VIEW TEST_WeeklyCost.vw_WeeklySalesCost AS SELECT * FROM TEST_WeeklyCost.Sales');
            DB::unprepared('CREATE VIEW TEST_WeeklyCost.vw_WeeklyWinbranchCost AS SELECT * FROM TEST_WeeklyCost.Cost');
            DB::unprepared(str_replace('agora.', 'TEST_WeeklyCost.', file_get_contents(base_path('Modules/StockRecon/Database/Procedures/usp_StockRecon_WeeklyCosts.sql'))));
            DB::unprepared("INSERT TEST_WeeklyCost.Cost VALUES (970,'TEST-A','','TEST-A','SHOP',2),(971,'TEST-A','','TEST-Other','SHOP',99),
                (970,'TEST-B','','TEST-B','SHOP',3),(970,'TEST-C','','TEST-C','OTHER',4)");
            DB::unprepared("INSERT TEST_WeeklyCost.Sales VALUES
                (970,'2026-09-01','TEST-A','','I','D','D',10,100,50,1,1),
                (970,'2026-09-01','TEST-A','','I','D','D',10,100,20,2,1),
                (970,'2026-09-21 23:59:59','TEST-A','','I','D','D',10,100,40,3,2),
                (970,'2026-09-22','TEST-A','','I','D','D',10,100,900,4,3),
                (971,'2026-09-21','TEST-A','','I','D','D',10,100,900,3,2),
                (970,'2026-09-15','TEST-CONFLICT','TEST-B','I','D','D',10,100,20,5,4),
                (970,'2026-09-16','TEST-CONFLICT','TEST-C','I','D','D',10,100,40,6,5)");
            $rows = app(ProcedureService::class)->call('TEST_WeeklyCost.usp_StockRecon_WeeklyCosts', ['BranchId' => 970, 'FromDate' => '2026-09-01', 'ToDate' => '2026-09-21'])->keyBy('POSCode');
            $this->assertCount(2, $rows);
            $this->assertEquals(3, $rows['TEST-A']->UnitCost);
            $this->assertNull($rows['TEST-CONFLICT']->UnitCost);
            $this->assertStringContainsString('conflicting', $rows['TEST-CONFLICT']->CostBasis);
            DB::unprepared('DROP VIEW TEST_WeeklyCost.vw_WeeklySalesCost');
            try {
                app(ProcedureService::class)->call('TEST_WeeklyCost.usp_StockRecon_WeeklyCosts', ['BranchId' => 970, 'FromDate' => '2026-09-01', 'ToDate' => '2026-09-21']);
                $this->fail('An unavailable cost source must not return zero.');
            } catch (AgoraProcException $e) {
                $this->assertSame('WEEKLY_SOURCE_UNAVAILABLE', $e->code());
            }
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }
    }
}
