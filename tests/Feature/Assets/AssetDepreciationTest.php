<?php

namespace Tests\Feature\Assets;

use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Straight-line monthly depreciation by group (T057), computed two ways.
 *
 * Way one is agora.fn_Assets_Depreciation, through the procedures that read
 * it: the register grid and agora.usp_Assets_Depreciate. Way two is written
 * out here in PHP from the conventions in Modules/Assets/Config/config.php,
 * independently of the SQL:
 *
 *   · the month of purchase is charged in full, the month of disposal not;
 *   · a month is charged at its month end;
 *   · accumulated(n) = round(cost × n ÷ life, 2), capped at the cost;
 *   · residual 0.
 *
 * THE ASSETS are the customer's own: the seven distinct rows in
 * PumpIT.dbo.ASSET_AssetRegister on 27 Sep 2026 (the pilot of 4 Jun 2026 —
 * not migrated, but their costs and purchase dates are real), under a
 * three-year group standing in for Office Equipment and a six-year group for
 * Bakery Equipment, which is what the provisional SARS mapping gives OE and BE.
 */
class AssetDepreciationTest extends TestCase
{
    use AssetsFixture;

    /** [legacy item code, group, cost, purchased] — ASSET_AssetRegister, live, 27 Sep 2026. */
    private const PILOT = [
        ['-8',      self::GROUP,     2030.00,  '2026-06-04'],
        ['-3',      self::GROUP,     12000.00, '2026-06-04'],
        ['OE001-3', self::GROUP,     3000.00,  '2026-06-01'],
        ['OE002-3', self::GROUP,     2000.00,  '2026-05-01'],
        ['OE003-3', self::GROUP_SIX, 4000.00,  '2026-05-22'],
        ['BE002-8', self::GROUP_SIX, 20000.00, '2026-05-05'],
        ['BE003-8', self::GROUP_SIX, 35000.00, '2025-04-01'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessLocalStub('This fixture writes assets.');
        $this->cleanUpAssets();
        $this->seedAssetMasters();
    }

    protected function tearDown(): void
    {
        if ($this->app !== null) {
            $this->cleanUpAssets();
        }

        parent::tearDown();
    }

    public function test_the_register_agrees_with_an_independent_straight_line_on_the_customers_own_assets(): void
    {
        $ids = [];
        foreach (self::PILOT as [$code, $group, $cost, $bought]) {
            $ids[$code] = $this->registerAsset([
                'AssetGroupCode' => $group,
                'AssetTypeCode' => $group === self::GROUP ? 'TSTA02' : 'TSTC01',
                'Make' => 'TEST-'.$code,
                'DatePurchased' => $bought,
                'Cost' => $cost,
            ]);
        }

        foreach (['2026-08-31', '2026-09-27', '2027-12-31', '2031-06-30'] as $asAt) {
            $rows = $this->procs()->callSets('agora.usp_Assets_GridRegister', [
                'BranchIds' => (string) self::BRANCH, 'PageSize' => 100,
                'RatesJson' => $this->ratesJson(), 'AsAt' => $asAt,
            ])[0]->keyBy('AssetId');

            foreach (self::PILOT as [$code, $group, $cost, $bought]) {
                $life = $group === self::GROUP ? 36 : 72;
                [$months, $accumulated, $charge] = $this->straightLine($cost, $bought, $life, $asAt);
                $row = $rows[$ids[$code]];

                $this->assertSame($months, (int) $row->MonthsCharged, "{$code} as at {$asAt}: months charged");
                $this->assertEqualsWithDelta($accumulated, (float) $row->Accumulated, 0.001, "{$code} as at {$asAt}: accumulated");
                $this->assertEqualsWithDelta($cost - $accumulated, (float) $row->Nbv, 0.001, "{$code} as at {$asAt}: NBV");
                $this->assertEqualsWithDelta($charge, (float) $row->ChargeForMonth, 0.001, "{$code} as at {$asAt}: this month's charge");
            }
        }
    }

    public function test_the_schedule_is_the_function_asked_at_every_month_end_and_adds_up_to_the_cost_exactly(): void
    {
        // R2,030.00 over 36 months is R56.3888…: the rounding has to land
        // somewhere, and it must not leave the asset a cent off nil.
        $printer = $this->registerAsset(['AssetTypeCode' => 'TSTA02', 'DatePurchased' => '2026-06-04', 'Cost' => 2030]);

        $schedule = $this->procs()->callSets('agora.usp_Assets_Depreciate', [
            'AssetBranchId' => self::BRANCH, 'AssetId' => $printer, 'RatesJson' => $this->ratesJson(),
        ])[2];

        $this->assertCount(36, $schedule);
        $this->assertSame('2026-06-30', substr((string) $schedule->first()->MonthEnd, 0, 10), 'The month of purchase is charged.');
        $this->assertSame('2029-05-31', substr((string) $schedule->last()->MonthEnd, 0, 10));
        $this->assertEqualsWithDelta(2030.00, (float) $schedule->sum('Charge'), 0.001, 'The charges add up to the cost, to the cent.');
        $this->assertEqualsWithDelta(0.0, (float) $schedule->last()->Nbv, 0.001);

        foreach ($schedule as $month) {
            [, $accumulated, $charge] = $this->straightLine(2030.00, '2026-06-04', 36, substr((string) $month->MonthEnd, 0, 10));
            $this->assertEqualsWithDelta($accumulated, (float) $month->Accumulated, 0.001, "Month {$month->MonthNo}");
            $this->assertEqualsWithDelta($charge, (float) $month->Charge, 0.001, "Month {$month->MonthNo}");
        }
    }

    public function test_a_disposal_stops_depreciation_before_its_month_and_takes_the_asset_off_the_register(): void
    {
        $fryer = $this->registerAsset(['AssetGroupCode' => self::GROUP_SIX, 'AssetTypeCode' => 'TSTC01',
            'DatePurchased' => '2025-04-01', 'Cost' => 35000]);

        $this->procs()->write('agora.usp_Assets_Move', [
            'BranchId' => self::BRANCH, 'AssetId' => $fryer, 'MovementType' => 'S', 'MovementDate' => '2026-03-10',
            'Proceeds' => 20000, 'Reason' => 'TEST-sold to the franchisee',
        ]);

        $row = $this->procs()->callSets('agora.usp_Assets_GridRegister', [
            'BranchIds' => (string) self::BRANCH, 'RatesJson' => $this->ratesJson(), 'AsAt' => '2026-09-27',
        ])[0]->firstWhere('AssetId', $fryer);

        // April 2025 to February 2026: 11 months. None in March, the month it went.
        $this->assertSame(11, (int) $row->MonthsCharged);
        $this->assertEqualsWithDelta(round(35000 * 11 / 72, 2), (float) $row->Accumulated, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $row->Nbv, 0.001, 'Off the register, so nothing is carried.');
        $this->assertEqualsWithDelta(35000 - round(35000 * 11 / 72, 2), (float) $row->NbvAtDisposal, 0.001);
        $this->assertEqualsWithDelta(20000 - (35000 - round(35000 * 11 / 72, 2)), (float) $row->ProfitOnDisposal, 0.001);
        $this->assertSame('Off the register', $row->Depreciation);
        $this->assertSame('Disposed of', $row->Status);
    }

    public function test_a_group_with_no_period_shows_no_nbv_rather_than_its_cost(): void
    {
        $land = $this->registerAsset(['AssetGroupCode' => self::GROUP_NO_RATE, 'AssetTypeCode' => 'TSTB01', 'Cost' => 500000]);

        $row = $this->procs()->callSets('agora.usp_Assets_GridRegister', [
            'BranchIds' => (string) self::BRANCH, 'RatesJson' => $this->ratesJson(),
        ])[0]->firstWhere('AssetId', $land);

        $this->assertNull($row->Nbv);
        $this->assertNull($row->Accumulated);
        $this->assertSame('No rate', $row->Depreciation);
    }

    public function test_the_register_footer_is_the_whole_filtered_set_not_the_page(): void
    {
        foreach (self::PILOT as [$code, $group, $cost, $bought]) {
            $this->registerAsset(['AssetGroupCode' => $group, 'AssetTypeCode' => $group === self::GROUP ? 'TSTA02' : 'TSTC01',
                'Make' => 'TEST-'.$code, 'DatePurchased' => $bought, 'Cost' => $cost]);
        }

        $sets = $this->procs()->callSets('agora.usp_Assets_GridRegister', [
            'BranchIds' => (string) self::BRANCH, 'PageSize' => 2, 'RatesJson' => $this->ratesJson(),
        ]);

        $this->assertCount(2, $sets[0]);
        $this->assertSame(7, (int) $sets[1]->first()->TotalRows);
        $this->assertEqualsWithDelta(78030.00, (float) $sets[2]->first()->Cost, 0.001, 'All seven, not the two on the page.');
    }

    /**
     * Way two: the convention, in PHP, from nothing but cost, purchase date,
     * life and the as-at date.
     *
     * @return array{0:int, 1:float, 2:float} months charged, accumulated, charge for the as-at month
     */
    private function straightLine(float $cost, string $bought, int $life, string $asAt): array
    {
        $at = Carbon::parse($asAt);
        $monthEnd = $at->isSameDay($at->copy()->endOfMonth()) ? $at->copy()->startOfDay() : $at->copy()->subMonthNoOverflow()->endOfMonth()->startOfDay();
        $purchased = Carbon::parse($bought)->startOfDay();

        $months = 0;
        $cursor = $purchased->copy()->endOfMonth()->startOfDay();
        while ($cursor->lessThanOrEqualTo($monthEnd) && $months < $life) {
            $months++;
            $cursor = $cursor->copy()->addDay()->endOfMonth()->startOfDay();
        }

        $accumulated = fn (int $n) => $n >= $life ? $cost : round($cost * $n / $life, 2);
        $lastCharged = $months === 0 ? null : $purchased->copy()->endOfMonth()->startOfDay()->addMonthsNoOverflow($months - 1)->endOfMonth()->startOfDay();
        $charge = $lastCharged !== null && $lastCharged->isSameDay($monthEnd)
            ? round($accumulated($months) - $accumulated($months - 1), 2)
            : 0.0;

        return [$months, $months === 0 ? 0.0 : $accumulated($months), $charge];
    }
}
