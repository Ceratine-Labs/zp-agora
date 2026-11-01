<?php

namespace Tests\Feature\StockRecon;

use App\Grid\GridService;
use App\Support\BranchContext;
use App\Support\ProcedureService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Connection;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ViewErrorBag;
use Modules\Core\Http\Middleware\AuthorizeAccessGrid;
use Modules\Core\Models\User;
use Modules\Core\Services\MenuAccessService;
use Modules\StockRecon\Grids\WaterMeterGrid;
use Modules\StockRecon\Http\Controllers\WaterMeterController;
use Modules\StockRecon\Http\Middleware\WaterMeterAccess;
use Modules\StockRecon\Models\StockReconRun;
use Modules\StockRecon\Services\StockReconService;
use Modules\StockRecon\Services\WaterMeterService;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** Local-only source stubs and Agora metadata; every write rolls back. */
class WaterMeterTest extends TestCase
{
    private const BRANCH = 999;

    private const AREA = 985;

    private const ITEM = 'TWMTR';

    private Connection $db;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (config('database.connections') as $name => $connection) {
            if (($connection['driver'] ?? null) !== 'sqlsrv') {
                continue;
            }
            foreach ([$connection['host'] ?? null, $connection['read']['host'] ?? null, $connection['write']['host'] ?? null] as $host) {
                foreach ((array) $host as $value) {
                    if (! in_array($value, ['127.0.0.1', 'localhost', '::1'], true)) {
                        $this->markTestSkipped("Water-meter fixtures require every SQL connection to be loopback: {$name}.");
                    }
                }
            }
        }
        $this->skipUnlessLocalStub();
        $this->db = DB::connection(config('agora.connections.app'));
        $this->db->beginTransaction();
        app(BranchContext::class)->setWorkspace('ho')->set(null)->setAllowed([self::BRANCH]);
        $this->db->table('PumpIT.dbo.STK_Area')->insert([
            'SSBranchId' => self::BRANCH, 'AreaNo' => self::AREA, 'AreaDescription' => 'TEST-Meter area',
            'AreaGroup' => 'Food Items', 'DayShift' => 1, 'AfternoonShift' => 1, 'NightShift' => 1,
            'ShowReport' => 1, 'IsCaptureWaste' => 0,
        ]);
        $this->db->table('agora.StockReconMeter')->insert([
            'BranchId' => self::BRANCH, 'AreaNo' => self::AREA, 'StockItemNo' => self::ITEM,
            'Label' => 'TEST-Cumulative litres', 'Unit' => 'L', 'IsActive' => 1,
        ]);
        $this->db->table('PumpIT.dbo.STK_StockMaster')->insert([
            'SSBranchId' => self::BRANCH, 'StockItemNo' => self::ITEM, 'AreaNo' => self::AREA,
            'StockItemDescription' => 'TEST-Renamed product', 'Location' => 'TEST', 'UOMCode' => 'L',
            'SellingPrice' => 2, 'POSCode' => 'TESTMETER', 'QtyVarAllowance' => 0, 'isMonitoredItem' => 0,
        ]);
        for ($index = 0; $index < 6; $index++) {
            $this->insertReading($index, 1000 + 100 * $index, $index === 1 ? 90 : 100);
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->transactionLevel() > 0) {
            $this->db->rollBack();
        }
        parent::tearDown();
    }

    public function test_comparison_uses_previous_captured_close_before_period_and_never_rewrites_readings(): void
    {
        $before = $this->sourceRows();
        $sets = $this->report(['DateFrom' => '2031-01-02']);
        $rows = $sets[0];
        $this->assertCount(3, $rows);
        $this->assertEquals(1200, $rows[0]->PreviousReading);
        $this->assertSame('2031-01-01', $rows[0]->PreviousDate);
        $this->assertSame('valid', $rows[0]->StatusCode);
        $this->assertEquals(100, $rows[0]->MeterLitres);
        $this->assertEquals(1300, $rows[0]->CurrentReading);
        $this->assertEquals(0, $rows[0]->RawOpening, 'Opening stock is not the cumulative baseline.');
        $this->assertEquals(7, $rows[0]->LiveClosing, 'Working amendments remain separately visible.');
        $this->assertEquals(300, $sets[2][0]->ComparableMeterLitres);
        $this->assertEquals(300, $sets[2][0]->ComparablePOSLitres);
        $this->assertEquals(0, $sets[2][0]->DifferenceLitres);
        $this->assertFalse(property_exists($rows[0], 'ValueVar'));
        $this->assertEquals($before, $this->sourceRows());
    }

    public function test_first_reading_requires_baseline_and_only_comparable_intervals_contribute_to_totals(): void
    {
        $sets = $this->report();
        $this->assertSame('missing_baseline', $sets[0][0]->StatusCode);
        $this->assertNull($sets[0][0]->MeterLitres);
        $this->assertNull($sets[0][0]->DifferenceLitres);
        $this->assertEquals(5, $sets[2][0]->ComparableIntervals);
        $this->assertEquals(1, $sets[2][0]->InvalidIntervals);
        $this->assertEquals(500, $sets[2][0]->ComparableMeterLitres);
        $this->assertEquals(490, $sets[2][0]->ComparablePOSLitres);
        $this->assertEquals(10, $sets[2][0]->DifferenceLitres);
    }

    public function test_negative_reset_and_backward_readings_are_not_normalised_or_valued(): void
    {
        $this->changeReading(1, ['QtyClose_Original' => 0]);
        $this->changeReading(2, ['QtyClose_Original' => -120.5]);
        $this->changeReading(4, ['QtyClose_Original' => 1200]);
        $rows = $this->report()[0];
        $this->assertSame(['missing_baseline', 'reset', 'negative_reading', 'invalid_baseline', 'backward', 'valid'],
            $rows->pluck('StatusCode')->all());
        $this->assertEquals(-120.5, $rows[2]->CurrentReading);
        $this->assertEquals(-120.5, $rows[3]->PreviousReading);
        foreach ($rows->take(5) as $row) {
            $this->assertNull($row->MeterLitres);
            $this->assertNull($row->DifferenceLitres);
        }
        $onlyInvalid = $this->report(['DateTo' => '2031-01-01']);
        $this->assertEquals(0, $onlyInvalid[2][0]->ComparableIntervals);
        $this->assertNull($onlyInvalid[2][0]->ComparableMeterLitres);
        $this->assertNull($onlyInvalid[2][0]->ComparablePOSLitres);
        $this->assertNull($onlyInvalid[2][0]->DifferenceLitres);
    }

    public function test_missing_shift_and_unknown_schedule_do_not_compare_a_multi_shift_delta_to_one_pos_shift(): void
    {
        $this->db->table('PumpIT.dbo.STK_StockReconLine')->where('SSBranchId', self::BRANCH)
            ->where('AreaNo', self::AREA)->where('StockItemNo', self::ITEM)
            ->where('TransactionDate', '2031-01-01')->where('ShiftNo', 3)->delete();
        $rows = $this->report(['DateFrom' => '2031-01-02'])[0];
        $this->assertSame('reading_gap', $rows[0]->StatusCode);
        $this->assertNull($rows[0]->MeterLitres);
        $this->db->table('PumpIT.dbo.STK_Area')->where('SSBranchId', self::BRANCH)
            ->where('AreaNo', self::AREA)->update(['DayShift' => 0, 'AfternoonShift' => 0, 'NightShift' => 0]);
        $rows = $this->report(['DateFrom' => '2031-01-02'])[0];
        $this->assertSame(['unknown_schedule', 'unknown_schedule', 'unknown_schedule'], $rows->pluck('StatusCode')->all());
        $this->assertNull($rows[2]->DifferenceLitres);
    }

    public function test_duplicate_shift_readings_and_their_next_baseline_are_invalid(): void
    {
        $copy = (array) $this->sourceRows()[1];
        $copy['TransactionDate'] = '2031-01-01 01:00:00';
        $copy['QtyClose_Original'] = 1150;
        $this->db->table('PumpIT.dbo.STK_StockReconLine')->insert($copy);
        $rows = $this->report()[0];
        $this->assertEquals(7, $rows->count());
        $this->assertCount(2, $rows->where('StatusCode', 'duplicate'));
        $next = $rows->first(fn ($row) => $row->TransactionDate === '2031-01-01' && (int) $row->ShiftNo === 3);
        $this->assertSame('invalid_baseline', $next->StatusCode);
        $this->assertNull($next->MeterLitres);
    }

    public function test_negative_pos_filtering_pagination_and_totals_share_the_same_comparable_rows(): void
    {
        $this->changeReading(1, ['QtyComputer_Original' => -1]);
        $sets = $this->report(['PageSize' => 1, 'FiltersJson' => json_encode([
            ['column' => 'Status', 'type' => 'set', 'in' => ['Comparable interval']],
        ])]);
        $this->assertCount(1, $sets[0]);
        $this->assertEquals(4, $sets[1][0]->TotalRows);
        $this->assertEquals(400, $sets[2][0]->ComparableMeterLitres);
        $this->assertSame('negative_pos', $this->report()[0][1]->StatusCode);
        $filtered = $this->report(['FiltersJson' => json_encode([
            ['column' => 'TransactionDate', 'type' => 'date', 'op' => 'eq', 'value' => '2031-01-02'],
            ['column' => 'DifferenceLitres', 'type' => 'number', 'op' => 'eq', 'value' => 0],
        ])]);
        $this->assertEquals(3, $filtered[1][0]->TotalRows);
        $this->assertEquals(300, $filtered[2][0]->ComparablePOSLitres);
        foreach ([['LiveClosing', 'number', 'eq', 7, 6], ['PreviousReading', 'number', 'gt', 1200, 2],
            ['PreviousDate', 'date', 'eq', '2031-01-01', 3], ['StockItemDescription', 'text', 'eq', 'TEST-Renamed product', 6]] as [$column, $type, $op, $value, $count]) {
            $this->assertEquals($count, $this->report(['FiltersJson' => json_encode([
                ['column' => $column, 'type' => $type, 'op' => $op, 'value' => $value],
            ])])[1][0]->TotalRows);
        }

    }

    public function test_scope_is_intersected_and_denied_or_malformed_scope_never_becomes_unrestricted(): void
    {
        $this->db->table('agora.StockReconMeter')->insert([
            'BranchId' => 998, 'AreaNo' => self::AREA, 'StockItemNo' => self::ITEM,
            'Label' => 'TEST-Other branch meter', 'Unit' => 'L', 'IsActive' => 1,
        ]);
        $other = (array) $this->sourceRows()[0];
        $other['SSBranchId'] = 998;
        $this->db->table('PumpIT.dbo.STK_StockReconLine')->insert($other);
        foreach ([['AllowedBranchIds' => '-1'], ['AllowedBranchIds' => 'garbage'], ['BranchIds' => 'garbage'],
            ['AllowedBranchIds' => '998'], ['BranchIds' => '998']] as $scope) {
            $this->assertCount(0, $this->report($scope)[0]);
        }
        $this->assertCount(7, $this->report(['AllowedBranchIds' => null, 'BranchIds' => '999,998'])[0]);
        $this->assertCount(6, $this->report(['BranchIds' => '999,998'])[0]);
        $context = app(BranchContext::class);
        $service = app(WaterMeterService::class);
        $context->setAllowed([])->setWorkspace('ho');
        $this->assertNull($service->allowedBranchIds());
        $context->setWorkspace('branch');
        foreach ([null, 0, -1] as $pin) {
            $context->set($pin);
            $this->assertSame('-1', $service->allowedBranchIds());
            $this->assertCount(0, $service->sites());
        }
        $context->setAllowed([999])->set(998);
        $this->assertSame('-1', $service->allowedBranchIds());
        $context->set(999);
        $this->assertSame('999', $service->allowedBranchIds());
        $this->assertSame([999], $service->sites()->map(fn ($site) => (int) $site->BranchId)->all());
    }

    public function test_positive_meters_never_amend_or_restore_counts_and_physical_stock_is_unchanged(): void
    {
        $meterSource = $this->sourceRows();
        $this->db->table('PumpIT.dbo.STK_StockReconLine')->where('SSBranchId', self::BRANCH)
            ->where('AreaNo', self::AREA)->where('StockItemNo', self::ITEM)->delete();
        foreach ([[10, 8, 2], [8, 6, 2], [6, 4, 2], [4, 3, 1], [3, 2, 1], [2, 1, 1]] as $i => [$open, $close, $pos]) {
            $this->insertReading($i, $close, $pos, 'TPHYS', $open);
        }
        $ordinary = $this->preview();
        foreach ($meterSource as $row) {
            $this->db->table('PumpIT.dbo.STK_StockReconLine')->insert((array) $row);
        }
        $withMeters = $this->preview();
        $this->assertEquals(6, $withMeters->ExcludedMeterRows);
        $this->assertCount(6, $withMeters->lines);
        $this->assertCount(0, $withMeters->lines->where('StockItemNo', self::ITEM));
        $fields = ['StockItemNo', 'QtyOpenNew', 'QtyCloseNew', 'QtyVarNew', 'WouldAmend', 'RestoresHand', 'Outcome'];
        $this->assertEquals($ordinary->lines->map->only($fields)->all(), $withMeters->lines->map->only($fields)->all());
        $this->assertEquals($ordinary->HeldBackRows, $withMeters->HeldBackRows);
        $this->assertEquals($ordinary->ShortValueAfter, $withMeters->ShortValueAfter);
        $this->assertEquals($meterSource, $this->sourceRows(), 'Positive originals and working hand values are untouched.');
        $html = view('stockrecon::panes.overview', [
            'run' => $withMeters,
            'duplicates' => ['cases' => collect(), 'totals' => (object) []],
        ])->render();
        $this->assertStringContainsString('Cumulative water meters are separate', $html);
        $this->assertStringContainsString('No stock amendment or hand-count restoration', $html);
        $this->db->table('PumpIT.dbo.STK_StockReconLine')->where('SSBranchId', self::BRANCH)
            ->where('AreaNo', self::AREA)->where('StockItemNo', 'TPHYS')->delete();
        $meterOnly = $this->preview();
        $this->assertCount(0, $meterOnly->lines);
        $this->assertEquals(0, $meterOnly->AmendedRows);
        $this->assertEquals(6, $meterOnly->ExcludedMeterRows);
    }

    public function test_physical_variance_excludes_mapped_readings_with_visible_count_and_retains_ordinary_items(): void
    {
        $this->insertReading(0, 8, 0, 'TPHYS', 10);
        $sets = app(ProcedureService::class)->callSets('usp_StockRecon_GridVariance', [
            'BranchIds' => '999', 'AllowedBranchIds' => '999', 'DateFrom' => '2031-01-01', 'DateTo' => '2031-01-02',
        ]);
        $this->assertCount(1, $sets[0]);
        $this->assertSame('TPHYS', $sets[0][0]->StockItemNo);
        $this->assertEquals(-2, $sets[2][0]->QtyVarOriginal);
        $this->assertEquals(-4, $sets[2][0]->ValueVarOriginal);
        $this->assertEquals(6, $sets[2][0]->ExcludedMeterRows);
        $this->db->table('agora.StockReconMeter')->where('BranchId', 999)->where('StockItemNo', self::ITEM)->update(['IsActive' => 0]);
        $this->assertCount(0, $this->report()[0], 'Only explicit active mappings identify meters, never descriptions.');
    }

    public function test_meter_extract_requires_the_variance_permission(): void
    {
        Gate::shouldReceive('authorize')->once()->with('stockrecon.variance.view')
            ->andThrow(new AuthorizationException);
        $this->expectException(AuthorizationException::class);
        app(AuthorizeAccessGrid::class)
            ->handle($this->extractRequest(), fn () => response('must not render'));
    }

    public function test_meter_extract_retains_parent_menu_access_and_same_guard_allows_granted_users(): void
    {
        Gate::shouldReceive('authorize')->twice()->with('stockrecon.variance.view')->andReturnNull();
        $access = \Mockery::mock(MenuAccessService::class);
        $access->shouldReceive('canReachRoute')->twice()->withArgs(fn ($user, $route) => $route === 'app.stockrecon.variance')
            ->andReturn(false, true);
        $this->app->instance(MenuAccessService::class, $access);
        $guard = app(AuthorizeAccessGrid::class);
        try {
            $guard->handle($this->extractRequest(), fn () => response('must not render'));
            $this->fail('An unticked parent menu must block the meter extract.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertSame('allowed', $guard->handle($this->extractRequest(), fn () => response('allowed'))->getContent());
        $this->assertContains(WaterMeterAccess::class,
            app('router')->getRoutes()->getByName('app.stockrecon.water')->gatherMiddleware());
    }

    private function extractRequest(): Request
    {
        $request = Request::create('/app/grids/app.stockrecon.water/extract');
        $route = new Route('GET', 'app/grids/{grid}/extract', []);
        $route->bind($request);
        $request->setRouteResolver(fn () => $route);
        $request->setUserResolver(fn () => new User);

        return $request;
    }

    public function test_saved_pre_mapping_preview_cannot_commit_a_meter_even_in_live_mode(): void
    {
        $this->assertStaleRunRefused('usp_StockRecon_Commit');
    }

    public function test_saved_pre_mapping_preview_cannot_reset_meter_history_even_in_live_mode(): void
    {
        $this->assertStaleRunRefused('usp_StockRecon_ResetToOriginal');
    }

    private function assertStaleRunRefused(string $procedure): void
    {
        // Reproduce a preview made before the key was classified as a meter.
        $mapping = $this->db->table('agora.StockReconMeter')->where('BranchId', self::BRANCH)->where('StockItemNo', self::ITEM);
        $mapping->update(['IsActive' => 0]);
        $run = $this->preview();
        $this->assertCount(6, $run->lines);
        $mapping->update(['IsActive' => 1]);
        // A selected old proposal remains a real candidate; the mapping, not
        // absence of a proposal or a changed source snapshot, must stop it.
        $this->db->table('agora.StockReconRunLine')->where('BranchId', self::BRANCH)->where('RunId', $run->Id)
            ->update(['Selected' => 1, 'WouldAmend' => 1, 'ChainBlocked' => 0, 'RestoresHand' => 1,
                'CommitState' => 'pending', 'QtyOpenNew' => 0, 'QtyCloseNew' => 1000, 'CountsMove' => 1]);
        // THROW + XACT_ABORT rolls back this PDO fixture transaction at the
        // batch boundary. Capture evidence inside CATCH, then explicitly roll
        // back; checking after the refusal would only see the vanished fixture.
        $this->assertContains($procedure, ['usp_StockRecon_Commit', 'usp_StockRecon_ResetToOriginal']);
        $evidence = $this->db->selectOne("DECLARE @Before nvarchar(max) = (
                SELECT TransactionDate, ShiftNo, QtyOpen, QtyClose, QtyIssued, QtyComputer,
                    QtyOpen_Original, QtyClose_Original, QtyIssued_Original, QtyComputer_Original
                FROM PumpIT.dbo.STK_StockReconLine WHERE SSBranchId = ? AND AreaNo = ? AND StockItemNo = ?
                ORDER BY TransactionDate, ShiftNo FOR JSON PATH);
            BEGIN TRY
                EXEC agora.{$procedure} @RunId = ?, @BranchId = ?, @StampMode = 'live';
            END TRY
            BEGIN CATCH
                DECLARE @Message nvarchar(2048) = ERROR_MESSAGE();
                DECLARE @After nvarchar(max) = (
                    SELECT TransactionDate, ShiftNo, QtyOpen, QtyClose, QtyIssued, QtyComputer,
                        QtyOpen_Original, QtyClose_Original, QtyIssued_Original, QtyComputer_Original
                    FROM PumpIT.dbo.STK_StockReconLine WHERE SSBranchId = ? AND AreaNo = ? AND StockItemNo = ?
                    ORDER BY TransactionDate, ShiftNo FOR JSON PATH);
                DECLARE @Amendments int = (SELECT COUNT(*) FROM agora.StockReconAmendment WHERE BranchId = ? AND RunId = ?);
                DECLARE @RunStatus nvarchar(20) = (SELECT Status FROM agora.StockReconRun WHERE BranchId = ? AND Id = ?);
                DECLARE @Pending int = (SELECT COUNT(*) FROM agora.StockReconRunLine WHERE BranchId = ? AND RunId = ? AND CommitState = 'pending');
                IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
                SELECT @Message AS Message, CASE WHEN @Before = @After THEN 1 ELSE 0 END AS SourceUnchanged,
                    @Amendments AS Amendments, @RunStatus AS RunStatus, @Pending AS Pending;
            END CATCH", [self::BRANCH, self::AREA, self::ITEM, $run->Id, self::BRANCH,
            self::BRANCH, self::AREA, self::ITEM, self::BRANCH, $run->Id,
            self::BRANCH, $run->Id, self::BRANCH, $run->Id]);
        $this->assertNotNull($evidence);
        $this->assertStringStartsWith('AGORA:CUMULATIVE_METER:', $evidence->Message);
        $this->assertEquals(1, $evidence->SourceUnchanged);
        $this->assertEquals(0, $evidence->Amendments);
        $this->assertSame('previewed', $evidence->RunStatus);
        $this->assertEquals(6, $evidence->Pending);
    }

    public function test_controller_renders_readonly_report_and_grid_with_scope_preserved(): void
    {
        view()->share('errors', new ViewErrorBag);
        $request = Request::create(route('app.stockrecon.water'), 'GET', ['from' => '2031-01-01', 'to' => '2031-01-02']);
        $view = app(WaterMeterController::class)->index($request, app(GridService::class), app(WaterMeterGrid::class),
            app(WaterMeterService::class), app(BranchContext::class));
        $html = $view->render();
        $this->assertStringContainsString('Water meter reconciliation', $html);
        $this->assertStringContainsString('Missing baseline', $html);
        $this->assertStringContainsString('TEST-Cumulative litres', $html);
        $this->assertStringContainsString('not a loss value', $html);
        $this->assertStringContainsString('Source corrections require separate approval', $html);
        $this->assertStringNotContainsString('type="submit">Amend', $html);
    }

    /** @param array<string, mixed> $parameters
     * @return array<int, Collection<int, object>>
     */
    private function report(array $parameters = []): array
    {
        return app(ProcedureService::class)->callSets('usp_StockRecon_GridWaterMeters', array_merge([
            'BranchIds' => '999', 'AllowedBranchIds' => '999', 'DateFrom' => '2031-01-01', 'DateTo' => '2031-01-02',
        ], $parameters));
    }

    private function preview(): StockReconRun
    {
        return app(StockReconService::class)->preview(self::BRANCH, self::AREA,
            Carbon::parse('2031-01-01'), Carbon::parse('2031-01-02'), [], note: 'TEST-Water meter exclusion')->load('lines');
    }

    /** @return Collection<int, \stdClass> */
    private function sourceRows(): Collection
    {
        return $this->db->table('PumpIT.dbo.STK_StockReconLine')->where('SSBranchId', self::BRANCH)
            ->where('AreaNo', self::AREA)->where('StockItemNo', self::ITEM)->orderBy('TransactionDate')->orderBy('ShiftNo')->get();
    }

    /** @param array<string, float|int|null> $values */
    private function changeReading(int $index, array $values): void
    {
        $this->db->table('PumpIT.dbo.STK_StockReconLine')->where('SSBranchId', self::BRANCH)
            ->where('AreaNo', self::AREA)->where('StockItemNo', self::ITEM)
            ->where('TransactionDate', $index < 3 ? '2031-01-01' : '2031-01-02')
            ->where('ShiftNo', $index % 3 + 1)->update($values);
    }

    private function insertReading(int $index, float $close, float $pos, string $item = self::ITEM, float $open = 0): void
    {
        $this->db->table('PumpIT.dbo.STK_StockReconLine')->insert([
            'SSBranchId' => self::BRANCH, 'AreaNo' => self::AREA, 'StockItemNo' => $item,
            'TransactionDate' => $index < 3 ? '2031-01-01' : '2031-01-02', 'ShiftNo' => $index % 3 + 1,
            'SellPrice' => 2, 'QtyOpen' => $item === self::ITEM ? 5 : $open, 'QtyClose' => $item === self::ITEM ? 7 : $close,
            'QtyOpen_Original' => $open, 'QtyClose_Original' => $close,
            'QtyIssued' => 0, 'QtyIssued_Original' => 0, 'QtyComputer' => $pos, 'QtyComputer_Original' => $pos,
        ]);
    }
}
