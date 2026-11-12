<?php

namespace Tests\Feature\Fuel;

use App\Grid\GridQuery;
use App\Grid\GridRegistry;
use App\Support\BranchContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Models\Permission;
use Modules\Core\Models\User;
use Modules\Core\Models\UserPermission;
use Modules\Core\Services\NavigationModules;
use Modules\Core\Services\PermissionService;
use Modules\Fuel\Database\Seeders\FuelPermissionSeeder;
use Modules\Fuel\Grids\FuelHistoryGrid;
use Modules\Fuel\Services\FuelChart;
use Tests\TestCase;

class FuelScreenTest extends TestCase
{
    use FuelFixture;

    public function test_fuel_pages_keep_source_notes_and_chart_ranges(): void
    {
        $this->actingAs($this->reader(['fuel.prices.view']));
        $this->get('/app/fuel/matrix?branches[]=999&from=2026-08-01&to=2026-08-31')
            ->assertOk()->assertSee('Fuel matrix')->assertSee('10.1235')->assertSee('Overlapping DOE period.')
            ->assertSee('DOE periods · Selling price per litre');
        $this->get('/app/fuel/history?branches[]=999&from=2026-08-01&to=2026-08-31')
            ->assertOk()->assertSee('Fuel price history')->assertSee('Multiple observed prices on source date.')
            ->assertSee('Trading and source dates differ.')->assertSee('Cost missing.')
            ->assertSee('Price-change log')->assertSee('13.00 – 13.10');
        $definition = app(GridRegistry::class)->findOrFail('app.fuel.history');
        $this->assertInstanceOf(FuelHistoryGrid::class, $definition);
        $chart = app(FuelChart::class)->build($definition, new GridQuery(branchIds: [999], from: '2026-08-01', to: '2026-08-31', search: 'TEST-Fuel price'), 'sell');
        $this->assertCount(2, $chart['series']);
        $this->assertSame('13.000000', $chart['series'][0]['points'][2]['value']);
        $this->assertSame('13.100000', $chart['series'][1]['points'][2]['value']);
    }

    public function test_the_matrix_logs_pump_price_moves_and_summarises_each_grade(): void
    {
        $this->actingAs($this->reader(['fuel.prices.view']));
        $response = $this->get('/app/fuel/matrix?branches[]=999&from=2026-08-01&to=2026-08-31')->assertOk()
            ->assertSee('Pump prices by grade')->assertSee('Price-change log')
            ->assertSee('agora.usp_Fuel_GetPriceMoves')
            ->assertSee('Old and new both recorded this day')->assertSee('Cost not recorded')
            ->assertDontSee('Changes between successive DOE period starts');
        $pump = $response->viewData('pump');
        $moves = $pump['moves']->filter(fn (object $m) => (int) $m->FuelTypeNo === 99991)->values();
        $this->assertSame(['2026-08-05', '2026-08-13'], $moves->map(fn (object $m) => substr((string) $m->MoveDate, 0, 10))->all());
        $this->assertSame(['12.000000', '13.000000'], [$moves[0]->FromLow, $moves[0]->ToLow]);
        $this->assertSame(['13.000000', '13.100000'], [$moves[1]->FromLow, $moves[1]->ToLow]);

        // The log covers the selection's sites without waiting for one to be
        // picked, and never a site outside the reader's scope.
        $user = $this->reader(['fuel.prices.view']);
        DB::table('agora.UserBranch')->insert(['BranchId' => 999, 'UserId' => $user->Id]);
        $this->actingAs($user);
        $all = $this->get('/app/fuel/matrix?from=2026-08-01&to=2026-08-31')->assertOk()->viewData('pump');
        $this->assertSame([999], $all['summary']->pluck('BranchId')->map(fn (mixed $id) => (int) $id)->unique()->values()->all());
        $this->assertSame(0, $this->get('/app/fuel/matrix?from=2026-08-01&to=2026-08-31&branches[]=998')->assertOk()->viewData('pump')['moves']->count());
    }

    public function test_chart_includes_filtered_population_beyond_first_grid_page_and_preserves_nulls(): void
    {
        $this->actingAs($this->reader(['fuel.prices.view']));
        DB::table('PumpIT.dbo.BRN_FuelPrice')->insert([
            'SSBranchId' => 999, 'FuelTypeNo' => 99991, 'FuelTypeDescription' => 'TEST-Fuel price',
            'Date' => '2026-08-14', 'S_Date' => '2026-08-14', 'SellingPrice' => '13.100000',
            'CostPrice' => null, 'Margin' => null, 'IsFleetCard' => false,
        ]);
        $chart = app(FuelChart::class)->build(new FuelHistoryGrid, new GridQuery(branchIds: [999], from: '2026-08-01', to: '2026-08-31', search: 'TEST-Fuel price', pageSize: 1), 'margin');
        $this->assertCount(4, $chart['series'][0]['points']);
        $this->assertNull($chart['series'][0]['points'][3]['value']);
        $this->assertNull($chart['series'][1]['points'][3]['value']);
    }

    public function test_description_changes_do_not_split_or_overwrite_the_chart_grain(): void
    {
        $this->actingAs($this->reader(['fuel.prices.view']));
        DB::table('PumpIT.dbo.BRN_FuelPrice')->insert([
            'SSBranchId' => 999, 'FuelTypeNo' => 99991, 'FuelTypeDescription' => 'TEST-Another source label',
            'Date' => '2026-08-13', 'S_Date' => '2026-08-13', 'SellingPrice' => '13.200000',
            'CostPrice' => null, 'Margin' => null, 'IsFleetCard' => false,
        ]);
        $chart = app(FuelChart::class)->build(new FuelHistoryGrid, new GridQuery(branchIds: [999], from: '2026-08-01', to: '2026-08-31'), 'sell');
        $this->assertCount(2, $chart['series']);
        $this->assertCount(3, $chart['series'][0]['points']);
        $this->assertSame('13.000000', $chart['series'][0]['points'][2]['value']);
        $this->assertSame('13.200000', $chart['series'][1]['points'][2]['value']);
        $change = $chart['changes']->last();
        $this->assertSame('3', $change->Observations);
        $this->assertSame('Grade 99991 (multiple labels)', $change->FuelTypeDescription);
    }

    public function test_fuel_and_report_permissions_are_independent_and_extract_is_guarded(): void
    {
        $this->actingAs($this->reader(['reports.catalogue.view']));
        foreach (['/app/fuel/matrix', '/app/fuel/history', '/app/grids/app.fuel.history/extract', '/app/reports/fuel-history'] as $path) {
            $this->get($path)->assertForbidden();
        }
        $this->actingAs($this->reader(['fuel.prices.view']));
        $this->get('/app/reports/fuel-history')->assertForbidden();
        $this->actingAs($this->reader(['reports.fuel-history.view']));
        $this->get('/app/reports/fuel-history?branches[]=999&from=2026-08-01&to=2026-08-31')->assertOk()->assertSee('13.10');
        $this->get('/app/fuel/history')->assertForbidden();
    }

    public function test_scope_and_detail_reject_other_sites_and_branch_workspace_ignores_selector(): void
    {
        $user = $this->reader(['fuel.prices.view', 'reports.fuel-history.view']);
        DB::table('agora.UserBranch')->insert(['BranchId' => 999, 'UserId' => $user->Id]);
        $this->actingAs($user);
        $url = '/app/fuel/matrix?from=2026-08-01&to=2026-08-31';
        $this->get($url.'&branches[]=998')->assertOk()->assertDontSee('99.00');
        $this->get('/app/reports/fuel-history?from=2026-08-01&to=2026-08-31&branches[]=998')->assertOk()->assertDontSee('TEST-Forbidden POS')->assertDontSee('99.12')->assertDontSee('13.10');
        $this->get('/app/reports/fuel-history?from=2026-08-01&to=2026-08-31&branches[]=999')->assertOk()->assertSee('13.10')->assertDontSee('99.12');
        $deniedExtract = $this->get('/app/grids/app.fuel.history/extract?from=2026-08-01&to=2026-08-31&branches[]=998')->assertOk()->streamedContent();
        $this->assertStringNotContainsString('99.123456', $deniedExtract);
        $this->assertStringNotContainsString('13.1', $deniedExtract);
        $response = $this->get($url.'&branches[]=999')->assertOk();
        $grid = $response->viewData('grid');
        $detail = $grid->definition->detailUrl($grid->rows->first());
        $this->assertSame('expand', $grid->definition->detailMode());
        $this->assertSame($detail, $grid->definition->rowActions($grid->rows->first())[0]['url']);
        $this->get($detail)->assertOk()->assertSee('Fuel source detail')->assertSee('10.123456');
        $this->get($detail, ['X-Requested-With' => 'XMLHttpRequest'])->assertOk()->assertSee('10.123456')->assertDontSee('<main', false);
        app(BranchContext::class)->setAllowed([998]);
        $key = DB::selectOne("SELECT TOP(1) CONVERT(varchar(64),HASHBYTES('SHA2_256',CONCAT(SSBranchId,'|',FuelTypeNo,'|',CONVERT(varchar(23),StartDate,121),'|',CONVERT(varchar(23),EndDate,121))),2) AS RowKey FROM PumpIT.dbo.BRN_MonthlyDOEFuelPricingMatrix WHERE SSBranchId=998 AND FuelTypeNo=99991")->RowKey;
        $this->get('/app/fuel/periods/'.$key.'?from=2026-08-05&to=2026-08-05')->assertNotFound();
        $user->update(['Workspace' => 'branch', 'HomeBranchId' => 999]);
        $this->get($url.'&branches[]=998&ws=ho')->assertOk()->assertSee('12.00')->assertDontSee('99.00');
        $this->get('/app/reports/fuel-history?from=2026-08-01&to=2026-08-31&branches[]=998&ws=ho')->assertOk()->assertSee('13.10')->assertDontSee('99.12')->assertDontSee('TEST-Forbidden POS');
        $pinnedExtract = $this->get('/app/grids/app.fuel.history/extract?from=2026-08-01&to=2026-08-31&branches[]=998&ws=ho')->assertOk()->streamedContent();
        $this->assertStringContainsString('13.1', $pinnedExtract);
        $this->assertStringNotContainsString('99.123456', $pinnedExtract);
    }

    public function test_extract_keeps_filter_and_full_decimal_value_and_navigation_groups_under_fuel(): void
    {
        $this->actingAs($this->reader(['fuel.prices.view']));
        $response = $this->get('/app/grids/app.fuel.matrix/extract?branches[]=999&from=2026-08-01&to=2026-08-31&f[SellingPrice][q]=12&f[SellingPrice][op]=eq&columns=FuelTypeNo,CostPrice,SellingPrice');
        $response->assertOk();
        $csv = $response->streamedContent();
        $this->assertStringContainsString('10.123456', $csv);
        $this->assertStringNotContainsString('11.123456', $csv);
        $this->assertSame('fuel', app(NavigationModules::class)->forRoute('app.fuel.matrix')['key']);
        $this->assertSame('fuel', app(NavigationModules::class)->forRoute('app.reports.show', ['report' => 'fuel-history'])['key']);
    }

    /** @param list<string> $codes */
    private function reader(array $codes): User
    {
        (new FuelPermissionSeeder)->run();
        $user = User::query()->acrossBranches()->create([
            'BranchId' => 2, 'EmailAddress' => 'TEST-fuel-'.Str::random(12).'@agora.invalid',
            'UserName' => 'TEST-Fuel reader', 'PasswordHash' => Str::random(32),
            'IsActive' => true, 'IsLocked' => false, 'MustChangePassword' => false, 'Workspace' => 'ho',
        ]);
        foreach (Permission::query()->acrossBranches()->whereIn('Code', $codes)->get() as $permission) {
            UserPermission::query()->acrossBranches()->create(['BranchId' => 2, 'UserId' => $user->Id, 'PermissionId' => $permission->Id]);
        }
        app(PermissionService::class)->forget($user);

        return $user;
    }
}
