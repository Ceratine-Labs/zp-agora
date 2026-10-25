<?php

namespace Tests\Feature\StockRecon;

use App\Exceptions\AgoraProcException;
use App\Support\ProcedureService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Models\User;
use Modules\Core\Services\PermissionService;
use Modules\StockRecon\Models\StockReconRun;
use Modules\StockRecon\Services\StockReconService;
use Tests\Fixtures\GrantsAccess;
use Tests\TestCase;

class GlobalOverviewTest extends TestCase
{
    use GrantsAccess;

    private StockReconRun $run;

    private bool $started = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->skipUnlessLocalStub();
        DB::connection('agora')->beginTransaction();
        $this->started = true;
        $this->run = StockReconRun::create([
            'BranchId' => 999, 'GroupRef' => (string) Str::uuid(),
            'FromDate' => '2030-01-01', 'ToDate' => '2030-01-07', 'CompleteTo' => '2030-01-06',
            'ProcedureName' => 'TEST-global', 'Status' => 'previewed', 'Note' => 'TEST-global overview',
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->started) {
            DB::connection('agora')->rollBack();
            app(PermissionService::class)->forgetAll();
        }
        parent::tearDown();
    }

    public function test_candidates_need_a_link_and_comparable_units_and_are_ranked(): void
    {
        $this->chain('TG01', 'TEST Beef', 1, 4, 7, 'KG', 'BEEF');
        $this->chain('TG02', 'TEST Mince', 2, -4, 7, 'KG', 'MINCE');
        $this->chain('TG03', 'TEST Cut', 3, -4, 0, 'KG', 'BEEF');
        $this->chain('TG04', 'TEST Steak', 1, -4, 0, 'KG', 'STEAK');
        $this->chain('TG05', 'Unrelated', 9, -4, 0, 'KG', 'OTHER');
        $this->chain('TG06', 'Different unit', 8, -4, 7, 'EA', 'BEEF');
        $this->chain('TG07', 'TEST Missing master', 1, -4, 0, 'KG', 'BEEF');
        DB::connection('agora')->table('PumpIT.dbo.STK_StockMaster')
            ->where('SSBranchId', 999)->where('StockItemNo', 'TG07')->delete();
        $r = $this->overview();
        $this->assertCount(3, $r['pairs']);
        $this->assertSame([3, 2, 1], $r['pairs']->pluck('LinkStrength')->map(fn ($v) => (int) $v)->all());
        $this->assertSame(6, (int) $r['summary']->ArithmeticPairs);
        $this->assertSame(3, (int) $r['summary']->RejectedPairs);
        $this->assertSame(3, (int) $r['chains']->firstWhere('StockItemNo', 'TG01')->CandidateCount);
        $this->assertSame(2, (int) $r['groups']->firstWhere('PreProductionTypeNo', 7)->UnitKinds);
        $this->assertEquals(0, $r['chains']->firstWhere('StockItemNo', 'TG01')->GroupRawOffset);
    }

    public function test_raw_group_totals_do_not_multiply_by_ratio_and_balanced_is_default(): void
    {
        $this->chain('TG01', 'Beef', 1, 5, 7, 'KG', 'A', 0, 8);
        $this->chain('TG02', 'Mince', 2, -3, 7, 'KG', 'B', 0.5, -8);
        $r = $this->overview();
        $g = $r['groups']->firstWhere('PreProductionTypeNo', 7);
        $this->assertEquals(8, $g->GrossRawQuantity);
        $this->assertEquals(2, $g->NetRawQuantity);
        $this->assertEquals(6, $g->CancelledRawQuantity);
        $this->assertEquals(1, $g->UnsetRatios);
        $this->assertEquals(6, $r['chains']->first()->GroupRawOffset);
        $this->assertCount(0, $r['pairs']);
        $original = $this->overview('original');
        $this->assertCount(1, $original['pairs']);
        $this->assertEquals(0, $original['groups']->firstWhere('PreProductionTypeNo', 7)->NetRawQuantity);
    }

    public function test_master_rows_cannot_duplicate_chains_and_blank_metadata_never_links(): void
    {
        $this->chain('TG01', '', 1, 5, 0, '', '');
        $this->chain('TG02', '', 1, -5, 0, '', '');
        DB::connection('agora')->table('PumpIT.dbo.STK_StockMaster')->insert([
            'QtyVarAllowance' => 0, 'isMonitoredItem' => 0, 'SellingPrice' => 10,
            'SSBranchId' => 999, 'StockItemNo' => 'TG01', 'Location' => 'OTHER',
            'StockItemDescription' => 'Different label', 'AreaNo' => 10, 'UOMCode' => 'EA',
        ]);
        $r = $this->overview();
        $this->assertCount(2, $r['chains']);
        $this->assertCount(0, $r['pairs']);
        $this->assertEquals(1, $r['summary']->RejectedPairs);
        $this->assertEquals(2, $r['groups']->first()->UnknownUnits);
    }

    public function test_production_is_scoped_to_site_item_pos_and_complete_period_without_changing_counts(): void
    {
        $this->chain('TG01', 'Beef', 1, 4, 7);
        $db = DB::connection('agora');
        foreach ([[999, 'TG01', 'TEST', '2030-01-06', 1], [998, 'TG01', 'TEST', '2030-01-06', 2],
            [999, 'TG02', 'TEST', '2030-01-06', 3], [999, 'TG01', 'OTHER', '2030-01-06', 4],
            [999, 'TG01', 'TEST', '2030-01-07', 5]] as [$branch, $item, $pos, $date, $ref]) {
            $db->table('PumpIT.dbo.DBF_P3TRANS_STKRECON_PREPRODUCTION')->insert([
                'SSBranchId' => $branch, 'StockItemno' => $item, 'LOCATION' => $pos, 'DATE' => $date,
                'ShiftNo' => 1, 'basecode' => 'TEST-base', 'TransactionTrackingNumber' => $ref, 'TotalQuantity' => 12,
            ]);
        }
        $before = $db->table('agora.StockReconRunLine')->where('RunId', $this->run->Id)->get()->toJson();
        $r = $this->overview();
        $this->assertCount(1, $r['production']);
        $this->assertCount(0, app(StockReconService::class)->globalOverview($this->run, 'balanced', false)['production']);
        $this->assertEquals(1, $r['production']->first()->TrackingNumber);
        $this->assertEquals(4, $r['chains']->first()->Residual, 'Production is context, not subtracted again.');
        $this->assertSame($before, $db->table('agora.StockReconRunLine')->where('RunId', $this->run->Id)->get()->toJson());
    }

    public function test_a_run_cannot_be_read_under_another_branch(): void
    {
        $this->expectException(AgoraProcException::class);
        app(ProcedureService::class)->callSets('usp_StockRecon_PreviewGlobal', ['BranchId' => 998, 'RunId' => $this->run->Id]);
    }

    public function test_the_page_has_the_caveat_hierarchy_and_candidates_without_write_controls(): void
    {
        $this->chain('TG01', 'TEST Beef', 1, 4, 7);
        $this->chain('TG02', 'TEST Mince', 1, -4, 7);
        $user = User::query()->acrossBranches()->create([
            'BranchId' => 2, 'EmailAddress' => 'TEST-global-'.Str::uuid().'@agora.invalid',
            'UserName' => 'TEST-global', 'IsActive' => true, 'IsLocked' => false,
            'Workspace' => 'ho', 'PasswordHash' => 'TEST-only',
        ]);
        $this->grantProfile($user, 'auditor');
        $this->actingAs($user)->get(route('app.stockrecon.global', $this->run))
            ->assertOk()->assertSee('Raw quantities')->assertSee('TEST Beef')->assertSee('Linked candidates')
            ->assertSee('Production group 7')->assertSee('ratios are still being prepared')
            ->assertDontSee('data-confirm-action="Amend"', false);
        $csv = $this->get(route('app.stockrecon.global', [$this->run, 'basis' => 'original', 'export' => 'pairs']))
            ->assertOk()->assertDownload('stock-recon-'.$this->run->Id.'-original-pairs.csv')->streamedContent();
        $this->assertStringContainsString('ratios not applied', $csv);
        $this->assertStringContainsString('TEST Beef', $csv);
        $this->getJson(route('app.stockrecon.global', [$this->run, 'basis' => 'invalid']))->assertUnprocessable();
    }

    public function test_the_route_refuses_a_person_without_view_permission(): void
    {
        $user = User::query()->acrossBranches()->create([
            'BranchId' => 2, 'EmailAddress' => 'TEST-global-denied-'.Str::uuid().'@agora.invalid',
            'UserName' => 'TEST-global-denied', 'IsActive' => true, 'IsLocked' => false,
            'Workspace' => 'ho', 'PasswordHash' => 'TEST-only',
        ]);
        $this->actingAs($user)->get(route('app.stockrecon.global', $this->run))->assertForbidden();
    }

    /** @return array<string, mixed> */
    private function overview(string $basis = 'balanced'): array
    {
        return app(StockReconService::class)->globalOverview($this->run, $basis);
    }

    private function chain(string $item, string $name, int $area, float $variance, int $group = 0,
        string $unit = 'KG', string $pos = 'TEST', float $ratio = 1, ?float $original = null): void
    {
        $db = DB::connection('agora');
        $db->table('PumpIT.dbo.STK_StockMaster')->insert([
            'QtyVarAllowance' => 0, 'isMonitoredItem' => 0, 'SellingPrice' => 10,
            'SSBranchId' => 999, 'StockItemNo' => $item, 'StockItemDescription' => $name,
            'Location' => 'TEST', 'AreaNo' => $area, 'UOMCode' => $unit, 'POSCode' => $pos,
            'PreProductionTypeNo' => $group, 'Ratio' => $ratio,
        ]);
        $db->table('agora.StockReconRunLine')->insert([
            'BranchId' => 999, 'RunId' => $this->run->Id, 'LineNo' => $db->table('agora.StockReconRunLine')->where('RunId', $this->run->Id)->count() + 1,
            'AreaNo' => $area, 'StockItemNo' => $item, 'TransactionDate' => '2030-01-01', 'ShiftNo' => 1,
            'ItemDescription' => $name, 'POSCode' => $pos, 'StockLocation' => 'TEST',
            'QtyVar' => $original ?? $variance, 'QtyVarNew' => $variance, 'Outcome' => 'TEST-global',
        ]);
    }
}
