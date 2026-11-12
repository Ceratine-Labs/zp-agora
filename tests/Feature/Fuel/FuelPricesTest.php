<?php

namespace Tests\Feature\Fuel;

use App\Exceptions\AgoraProcException;
use App\Support\ProcedureService;
use Illuminate\Support\Collection;
use Tests\TestCase;

/** Local-only source fixtures: every insert is rolled back, never a customer write. */
class FuelPricesTest extends TestCase
{
    use FuelFixture;

    public function test_literal_periods_keep_overlap_and_decimal_precision(): void
    {
        $sets = $this->prices(['DateFrom' => '2026-08-07', 'DateTo' => '2026-08-07']);
        $this->assertSame(2, (int) $sets[1]->first()->TotalRows);
        $this->assertSame(['2026-08-01', '2026-08-05'], $sets[0]->map(fn (object $r) => substr($r->StartDate, 0, 10))->all());
        $this->assertSame('10.123456', $sets[0][0]->CostPrice);
        $this->assertSame('.100001', $sets[0][0]->FleetMargin);
    }

    public function test_observations_keep_both_dates_prices_and_missing_margin(): void
    {
        $sets = $this->prices(['Dataset' => 'observations']);
        $this->assertSame(4, (int) $sets[1]->first()->TotalRows);
        $this->assertCount(4, $sets[0]->pluck('RowKey')->unique());
        $moved = $sets[0]->first(fn (object $r) => substr($r->TradingDate, 0, 10) === '2026-08-04' && $r->SellingPrice === '13.000000');
        $this->assertNotNull($moved);
        $this->assertStringStartsWith('2026-08-05', $moved->SourceDate);
        $missing = $sets[0]->firstWhere('SellingPrice', '13.100000');
        $this->assertNull($missing->CostPrice);
        $this->assertNull($missing->Margin);
        $this->assertNull($missing->SellingPriceMatrix);
    }

    public function test_branch_scope_is_intersected_and_rejected_selection_does_not_expand(): void
    {
        $sets = $this->prices(['BranchIds' => '998,999', 'AllowedBranchIds' => '999']);
        $this->assertSame([999], $sets[0]->pluck('BranchId')->map(fn (mixed $id) => (int) $id)->unique()->all());
        foreach (['998', '-1', 'invalid', ''] as $selection) {
            $this->assertSame(0, (int) $this->prices(['BranchIds' => $selection, 'AllowedBranchIds' => '999'])[1]->first()->TotalRows);
        }
    }

    public function test_typed_filters_sort_paging_and_details_match_the_same_source(): void
    {
        $sets = $this->prices(['SortColumn' => 'SellingPrice', 'SortAsc' => 0, 'PageSize' => 1]);
        $this->assertSame('13.000000', $sets[0][0]->SellingPrice);
        $this->assertSame(2, (int) $sets[1][0]->TotalRows);
        $this->assertSame('12.000000', $this->prices(['SortColumn' => 'SellingPrice', 'SortAsc' => 0, 'PageSize' => 1, 'Page' => 2])[0][0]->SellingPrice);
        $this->assertSame(1, (int) $this->prices(['RowKey' => $sets[0][0]->RowKey])[1][0]->TotalRows);
        foreach ([
            ['column' => 'SellingPrice', 'type' => 'number', 'op' => 'gt', 'value' => '12.5'],
            ['column' => 'StartDate', 'type' => 'date', 'op' => 'eq', 'value' => '2026-08-05'],
        ] as $filter) {
            $filtered = $this->prices(['FiltersJson' => json_encode([$filter])]);
            $this->assertSame(1, (int) $filtered[1][0]->TotalRows);
            $this->assertSame('13.000000', $filtered[0][0]->SellingPrice);
        }
        $this->assertSame(2, (int) $this->prices(['FiltersJson' => json_encode([
            ['column' => 'FuelTypeNo', 'type' => 'set', 'in' => ['99991']],
        ])])[1][0]->TotalRows);
        $this->assertSame(0, (int) $this->prices(['FiltersJson' => json_encode([
            ['column' => 'FuelTypeDescription', 'type' => 'text', 'op' => 'contains', 'value' => 'not-present'],
        ])])[1][0]->TotalRows);
        $this->assertSame(0, (int) $this->prices(['FiltersJson' => json_encode([
            ['column' => 'SellingPrice', 'type' => 'number', 'op' => 'eq', 'value' => 'not-a-number'],
        ])])[1][0]->TotalRows);
    }

    public function test_invalid_range_is_refused(): void
    {
        $this->expectException(AgoraProcException::class);
        $this->prices(['DateFrom' => '2026-08-31', 'DateTo' => '2026-08-01']);
    }

    /** @param array<string, mixed> $parameters
     * @return array<int, Collection<int, object>>
     */
    private function prices(array $parameters = []): array
    {
        return app(ProcedureService::class)->callSets('usp_Fuel_GridPrices', $parameters + [
            'BranchIds' => '999', 'DateFrom' => '2026-08-01', 'DateTo' => '2026-08-31',
            'Search' => 'TEST-Fuel price',
        ]);
    }
}
