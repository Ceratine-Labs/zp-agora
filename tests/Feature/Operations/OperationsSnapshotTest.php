<?php

namespace Tests\Feature\Operations;

use App\Support\ProcedureService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Reports\Services\WasteAuthorizationService;
use Tests\TestCase;

class OperationsSnapshotTest extends TestCase
{
    private const BRANCH = 987;

    private const DAY = '2030-02-01';

    private bool $transactionStarted = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->skipUnlessLocalStub();
        DB::beginTransaction();
        $this->transactionStarted = true;
        DB::table('PumpIT.dbo.SS_Branch')->insert([
            'SSBranchId' => self::BRANCH, 'BranchName' => 'TEST Operations Wall',
            'IsActive' => 1,
        ]);
        DB::table('agora.Branch')->insert([
            'BranchId' => self::BRANCH, 'Name' => 'TEST Operations Wall',
            'IsTrading' => 1, 'IsActive' => 1,
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->transactionStarted) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    public function test_scope_is_explicit_and_permission_fenced(): void
    {
        $this->assertCount(0, $this->snapshot(''));
        $this->assertCount(0, $this->snapshot('invalid'));
        $this->assertCount(0, $this->snapshot((string) self::BRANCH, '13'));
        $this->assertCount(0, $this->snapshot((string) self::BRANCH, 'invalid'));
        $this->assertSame([self::BRANCH], $this->snapshot()->pluck('BranchId')->map(fn ($id) => (int) $id)->unique()->all());

        $sites = app(ProcedureService::class)->call('usp_Operations_Sites', [
            'AllowedBranchIds' => (string) self::BRANCH,
            'BranchId' => self::BRANCH,
        ]);
        $this->assertCount(1, $sites);
        $this->assertSame(self::BRANCH, (int) $sites->first()->BranchId);
    }

    public function test_absent_feeds_are_unknown_instead_of_healthy_zeroes(): void
    {
        $rows = $this->snapshot();
        $this->assertEqualsCanonicalizing(['fuel', 'meters', 'shop', 'qsr', 'cash', 'stock', 'loads'], $rows->pluck('SourceKey')->unique()->values()->all());

        foreach ($rows as $row) {
            $this->assertSame('neutral', $row->Severity, $row->MetricKey);
            $this->assertNull($row->Value, $row->MetricKey);
            $this->assertSame(0, (int) $row->SourceCount, $row->MetricKey);
        }
    }

    public function test_administrative_inactive_and_deleted_entities_are_excluded(): void
    {
        foreach ([
            ['IsTrading' => 0, 'IsActive' => 1, 'DeletedAt' => null],
            ['IsTrading' => 1, 'IsActive' => 0, 'DeletedAt' => null],
            ['IsTrading' => 1, 'IsActive' => 1, 'DeletedAt' => self::DAY],
        ] as $attributes) {
            DB::table('agora.Branch')->where('BranchId', self::BRANCH)->update($attributes);
            $this->assertCount(0, $this->snapshot());
            $this->assertCount(0, app(ProcedureService::class)->call('usp_Operations_Sites', [
                'AllowedBranchIds' => (string) self::BRANCH, 'BranchId' => self::BRANCH,
            ]));
        }
    }

    public function test_fuel_gaps_and_same_weekday_sales_use_complete_observations(): void
    {
        DB::table('PumpIT.dbo.BRN_FuelType')->insert([
            'SSBranchId' => self::BRANCH, 'FuelTypeNo' => 1,
            'FuelTypeDescription' => 'TEST Diesel', 'IsFleetCard' => 0,
        ]);
        for ($days = 0; $days < 7; $days++) {
            DB::table('PumpIT.dbo.BRN_FuelInput')->insert([
                'SSBranchId' => self::BRANCH, 'FuelTypeNo' => 1,
                'FuelDate' => Carbon::parse(self::DAY)->subDays($days)->toDateString(),
                'FuelVolume' => 1000, 'FuelDip' => $days === 0 ? 8800 : 10000,
                'FuelDelivery' => 0, 'Imported' => 1,
            ]);
        }
        foreach ([0, 7, 14, 21, 28] as $days) {
            DB::table('PumpIT.dbo.BRN_DayEnd')->insert([
                'SSBranchId' => self::BRANCH,
                'DayEndDate' => Carbon::parse(self::DAY)->subDays($days)->toDateString(),
                'DayEndTypeNo' => 1, 'DayEndNo' => "TEST{$days}", 'Imported' => 1,
                'QShopTO' => $days === 0 ? 800 : 1000,
                'FastFoodTO' => $days === 0 ? 100 : 200,
            ]);
        }
        DB::table('PumpIT.dbo.BRN_DayEnd')->insert([
            'SSBranchId' => self::BRANCH, 'DayEndDate' => self::DAY,
            'DayEndTypeNo' => 2, 'DayEndNo' => 'TEST-extra', 'Imported' => 1,
            'QShopTO' => 50, 'FastFoodTO' => 120,
        ]);

        $rows = $this->snapshot();
        $tank = $rows->firstWhere('MetricKey', 'tank-variance');
        $this->assertEqualsWithDelta(-200, (float) $tank->Value, 0.001);
        $this->assertSame('crit', $tank->Severity);
        $cover = $rows->firstWhere('MetricKey', 'tank-cover');
        $this->assertEqualsWithDelta(8.8, (float) $cover->Value, 0.001);
        $this->assertSame('good', $cover->Severity);
        $shop = $rows->firstWhere('SourceKey', 'shop');
        $this->assertEqualsWithDelta(850, (float) $shop->Value, 0.001);
        $this->assertEqualsWithDelta(1000, (float) $shop->SecondaryValue, 0.001);
        $this->assertSame('warn', $shop->Severity);
        $this->assertSame('good', $rows->firstWhere('SourceKey', 'qsr')->Severity);

        DB::table('PumpIT.dbo.BRN_FuelInput')->where('SSBranchId', self::BRANCH)
            ->where('FuelDate', Carbon::parse(self::DAY)->subDay()->toDateString())->delete();
        DB::table('PumpIT.dbo.BRN_DayEnd')->where('SSBranchId', self::BRANCH)
            ->where('DayEndDate', Carbon::parse(self::DAY)->subDays(28)->toDateString())->delete();
        $rows = $this->snapshot();
        $this->assertNull($rows->firstWhere('MetricKey', 'tank-variance')->Value);
        $this->assertSame('neutral', $rows->firstWhere('MetricKey', 'tank-variance')->Severity);
        $this->assertNull($rows->firstWhere('MetricKey', 'tank-cover')->Value);
        $this->assertSame('neutral', $rows->firstWhere('SourceKey', 'shop')->Severity);
    }

    public function test_top_ten_stock_codes_aggregate_short_lines_after_waste_and_match_the_variance_report(): void
    {
        DB::table('PumpIT.dbo.STK_Area')->insert([
            'SSBranchId' => self::BRANCH, 'AreaNo' => 1, 'AreaDescription' => 'TEST Kitchen',
            'DayShift' => 1, 'AfternoonShift' => 0, 'NightShift' => 1,
            'ShowReport' => 1, 'IsCaptureWaste' => 1,
        ]);
        for ($number = 1; $number <= 12; $number++) {
            $item = sprintf('K%04d', $number);
            DB::table('PumpIT.dbo.STK_StockMaster')->insert([
                'SSBranchId' => self::BRANCH, 'StockItemNo' => $item,
                'StockItemDescription' => "TEST Item {$number}", 'Location' => 'TEST',
                'UOMCode' => 'EA', 'AreaNo' => 1, 'QtyVarAllowance' => 0,
            ]);
            $this->stockLine($item, 1, 1, 0, $number * 10);
        }
        $this->stockLine('K0012', 2, 1, 0, 120);
        $this->stockLine('K0012', 3, 0, 1, 120);
        DB::table('agora.StockReconMeter')->insert([
            'BranchId' => self::BRANCH, 'AreaNo' => 1, 'StockItemNo' => 'TMTR',
            'Label' => 'TEST cumulative water', 'Unit' => 'L', 'IsActive' => 1,
        ]);
        $this->stockLine('TMTR', 1, 1000, 0, 100);
        DB::table('PumpIT.dbo.STK_StockWasteLine')->insert([
            'SSBranchId' => self::BRANCH, 'TransactionDate' => self::DAY, 'ShiftNo' => 1,
            'AreaNo' => 1, 'StockItemNo' => 'K0012', 'QtyGoodWaste' => 0.5, 'QtyBadWaste' => 0,
        ]);

        $rows = $this->snapshot();
        $top = $rows->where('MetricKey', 'stock-loss-item')->sortBy('SecondaryValue')->values();
        $this->assertCount(10, $top);
        $this->assertSame('K0012', $top[0]->ItemCode);
        $this->assertSame('TEST', $top[0]->StockLocation);
        $this->assertEqualsWithDelta(-240, (float) $top[0]->Value, 0.001, 'Unverified waste is not deducted.');
        $this->assertSame(2, (int) $top[0]->SourceCount, 'Both shortage shifts belong to one item; the over is separate.');
        $this->assertFalse($top->contains('ItemCode', 'K0001'));
        $this->assertFalse($top->contains('ItemCode', 'K0002'));
        $this->assertFalse($top->contains('ItemCode', 'TMTR'));

        app(WasteAuthorizationService::class)->decide([
            'branch_id' => self::BRANCH, 'date' => self::DAY, 'shift' => 1, 'area' => 1,
            'item' => 'K0012', 'captured_good' => 0.5, 'captured_at' => null,
            'decision' => 'authorize', 'authorized_good' => 0.5,
        ], 123);
        $rows = $this->snapshot();
        $this->assertEqualsWithDelta(-180, (float) $rows->where('MetricKey', 'stock-loss-item')
            ->firstWhere('ItemCode', 'K0012')->Value, 0.001, 'The wall applies authorized good waste.');

        $sets = app(ProcedureService::class)->callSets('usp_StockRecon_GridVariance', [
            'BranchIds' => (string) self::BRANCH, 'DateFrom' => self::DAY,
            'DateTo' => self::DAY, 'PageSize' => 100,
        ]);
        $lines = $sets[0];
        $summary = $rows->firstWhere('MetricKey', 'stock-summary');
        $this->assertEqualsWithDelta($lines->where('ValueVar', '<', 0)->sum('ValueVar'), (float) $summary->Value, 0.001);
        $this->assertEqualsWithDelta($lines->where('ValueVar', '>', 0)->sum('ValueVar'), (float) $summary->SecondaryValue, 0.001);
        $this->assertSame(14, (int) $summary->SourceCount);
    }

    /** @return Collection<int, object> */
    private function snapshot(?string $branches = null, ?string $allowed = null): Collection
    {
        return app(ProcedureService::class)->call('usp_Operations_Snapshot', [
            'BranchIds' => $branches ?? (string) self::BRANCH,
            'AllowedBranchIds' => $allowed, 'TradeDate' => self::DAY,
        ]);
    }

    private function stockLine(string $item, int $shift, int $open, int $close, int $price): void
    {
        DB::table('PumpIT.dbo.STK_StockReconLine')->insert([
            'SSBranchId' => self::BRANCH, 'TransactionDate' => self::DAY, 'ShiftNo' => $shift,
            'AreaNo' => 1, 'StockItemNo' => $item, 'SellPrice' => $price,
            'QtyOpen_Original' => $open, 'QtyIssued_Original' => 0,
            'QtyClose_Original' => $close, 'QtyComputer_Original' => 0,
            'QtyOpen' => $open, 'QtyIssued' => 0, 'QtyClose' => $close, 'QtyComputer' => 0,
        ]);
    }
}
