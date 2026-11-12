@php
    use App\Support\Format;
    $periods = $dataset === 'periods';
    $title = $periods ? 'Fuel matrix' : 'Fuel price history';
    $source = $periods ? 'DOE periods' : 'POS observations';
    $metricLabel = ['sell' => 'Selling price', 'cost' => 'Cost price', 'margin' => 'Margin'][$metric];
@endphp
<x-app-shell :title="$title">
    <x-page-head eyebrow="Fuel" :title="$title"
        :blurb="$periods ? 'Cost, selling price, margin and fleet margin for each source period.' : 'Every recorded fuel price, including conflicting prices and differences between trading and source dates.'">
        <x-slot:info>
            <p>DOE periods and POS observations are separate records. Agora retains overlaps and different prices without choosing a daily winner.</p>
            <p>Missing costs and margins remain blank. A POS price is never filled from a DOE period. Open a row for its source fields and full decimal precision.</p>
        </x-slot:info>
    </x-page-head>

    <div class="fuel-matrix">
        <x-data-grid :grid="$grid" :branches="$branches" surface />
    </div>

    <x-card :title="$source.' · price and margin history'"
        :sub="$periods ? 'Period starts from the DOE matrix; overlapping periods remain in the grid.' : 'Source dates within the selected trading-date records; lowest and highest recorded values are separate lines.'">
        <div class="fuel-metric-controls" role="group" aria-label="Chart measure">
            @foreach (['sell' => 'Selling price', 'cost' => 'Cost price', 'margin' => 'Margin'] as $key => $label)
                <a @class(['btn', 'is-active' => $metric === $key]) aria-current="{{ $metric === $key ? 'true' : 'false' }}"
                   href="{{ url()->current().'?'.http_build_query(array_replace(request()->query(), ['metric' => $key])) }}">{{ $label }}</a>
            @endforeach
        </div>
        @if ($chartMessage)
            <x-empty-state :text="$chartMessage" />
        @else
            <x-chart type="line" :series="$series" :title="$source.' · '.$metricLabel.' per litre'"
                :options="['format' => ['n', 4], 'label' => $source.' '.$metricLabel.' per litre', 'apex' => ['markers' => ['size' => 3], 'stroke' => ['curve' => 'stepline']]]" />
            <p class="fuel-source-note">Values come from {{ $periods ? 'BRN_MonthlyDOEFuelPricingMatrix' : 'BRN_FuelPrice' }}.
                Lowest and highest coincide when there is one recorded value. Missing values remain gaps; lines do not establish an effective-price policy.</p>
        @endif
    </x-card>

    @if ($periods)
        @include('fuel::pump-prices')
    @elseif (! $chartMessage)
        <x-card title="Price-change log" :sub="$periods ? 'Changes between successive DOE period starts in this selection.' : 'Changes in the observed selling-price range by source date. This does not establish the order of prices within a day.'" collapsible open remember="fuel.changes">
            <x-table :layout-key="'fuel.'.$dataset.'.changes'" procedure="agora.usp_Fuel_GridPrices" :count="$changes->count()" dense empty="No price observations in this selection.">
                <x-slot:head><tr><th>{{ $periods ? 'Period start' : 'Source date' }}</th><th>Grade</th><th>Fleet</th><th class="num">Previous range</th><th class="num">Recorded range / ℓ</th><th>Source notes</th></tr></x-slot:head>
                @foreach ($changes as $change)
                    <tr>
                        <td>{{ substr($change->ChartDate, 0, 10) }}</td>
                        <td>{{ $change->FuelTypeDescription }} ({{ $change->FuelTypeNo }})</td>
                        <td>{{ $periods ? '—' : ($change->IsFleetCard ? 'Yes' : 'No') }}</td>
                        <td class="num">@if ($change->PreviousSellLow === null) First in selection @else {{ Format::n($change->PreviousSellLow, 2) }}@if ($change->PreviousSellLow !== $change->PreviousSellHigh) – {{ Format::n($change->PreviousSellHigh, 2) }}@endif @endif</td>
                        <td class="num">{{ Format::n($change->SellLow, 2) }}@if ($change->SellLow !== $change->SellHigh) – {{ Format::n($change->SellHigh, 2) }}@endif</td>
                        <td>
                            @if ($change->SellLow !== $change->SellHigh) Multiple prices recorded. @endif
                            @if ($change->Overlap) Overlapping DOE period. @endif
                            @if ($change->MissingCost) {{ Format::n($change->MissingCost) }} records without cost. @endif
                            @if ($change->MissingMargin) {{ Format::n($change->MissingMargin) }} records without margin. @endif
                        </td>
                    </tr>
                @endforeach
            </x-table>
        </x-card>
    @endif
</x-app-shell>
