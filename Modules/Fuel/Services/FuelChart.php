<?php

namespace Modules\Fuel\Services;

use App\Grid\GridQuery;
use App\Support\ProcedureService;
use Illuminate\Support\Collection;
use Modules\Fuel\Grids\FuelPriceGrid;

/** Presentation only: all ranges and prior prices are computed by the grid procedure. */
class FuelChart
{
    public function __construct(private ProcedureService $procedures) {}

    /** @return array{series: list<array<string, mixed>>, changes: Collection<int, object>} */
    public function build(FuelPriceGrid $definition, GridQuery $query, string $metric): array
    {
        $parameters = $query->onPage(1)->procedureParameters();
        $parameters['PageSize'] = 1;
        $sets = $this->procedures->callSets('usp_Fuel_GridPrices', $parameters + [
            'FiltersJson' => $query->filtersJson(), 'Dataset' => $definition->dataset(),
            'AllowedBranchIds' => FuelPriceGrid::allowed(), 'IncludeChart' => 1,
        ]);
        $rows = $sets[2] ?? collect();
        $dates = $rows->pluck('ChartDate')->unique()->sort()->values();
        $series = [];
        $field = match ($metric) {
            'cost' => 'Cost', 'margin' => 'Margin', default => 'Sell',
        };
        foreach ($rows->groupBy(fn (object $r) => $r->BranchId.'|'.$r->FuelTypeNo.'|'.$r->IsFleetCard) as $group) {
            $first = $group->first();
            $byDate = $group->keyBy('ChartDate');
            $name = $first->FuelTypeDescription.' ('.$first->FuelTypeNo.')';
            if ($definition->dataset() === 'observations') {
                $name .= (bool) $first->IsFleetCard ? ' · fleet' : ' · non-fleet';
            }
            foreach (['Low' => 'lowest', 'High' => 'highest'] as $suffix => $label) {
                $points = [];
                foreach ($dates as $date) {
                    $row = $byDate->get($date);
                    $points[] = ['label' => substr((string) $date, 0, 10), 'value' => $row?->{$field.$suffix}];
                }
                $series[] = ['name' => $name.' · '.$label, 'points' => $points];
            }
        }
        $changes = $rows->filter(fn (object $r) => $r->PreviousSellLow === null
            || $r->SellLow !== $r->PreviousSellLow || $r->SellHigh !== $r->PreviousSellHigh
            || (bool) $r->Overlap)->values();

        return ['series' => $series, 'changes' => $changes];
    }
}
