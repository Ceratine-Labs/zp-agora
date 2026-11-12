@php
    use App\Support\Format;
@endphp
<div class="fuel-source-detail">
    <h3>{{ $row->BranchName }} · {{ $row->FuelTypeDescription }} ({{ $row->FuelTypeNo }})</h3>
    <p>{{ $dataset === 'periods' ? 'DOE period · BRN_MonthlyDOEFuelPricingMatrix' : 'POS observation · BRN_FuelPrice' }}</p>
    @if ($row->SourceNote)<x-notice tone="warn" title="Source notes">{{ $row->SourceNote }}</x-notice>@endif
    <x-facts>
        @foreach (['StartDate' => 'Period from', 'EndDate' => 'Period through', 'TradingDate' => 'Trading date', 'SourceDate' => 'Source date'] as $field => $label)
            @if ($row->{$field} !== null)<x-fact :label="$label" :value="$row->{$field}" />@endif
        @endforeach
        @foreach (['CostPrice' => 'Cost / ℓ', 'SellingPrice' => 'Sell / ℓ', 'Margin' => 'Margin / ℓ'] as $field => $label)
            <x-fact :label="$label" :value="Format::n($row->{$field}, 6)" />
        @endforeach
        @if ($dataset === 'periods')
            <x-fact label="Fleet margin / ℓ" :value="Format::n($row->FleetMargin, 6)" />
        @else
            <x-fact label="Fleet card" :value="$row->IsFleetCard ? 'Yes' : 'No'" />
            <x-fact label="POS type" :value="$row->POSType" /><x-fact label="POS identifier" :value="$row->POSIdentifier" />
            <x-fact label="EOD" :value="$row->EodCounter" />
            <x-fact label="Handling fee" :value="Format::n($row->HandlingFee, 6)" />
            <x-fact label="Source matrix price / ℓ" :value="Format::n($row->SellingPriceMatrix, 6)" />
            <x-fact label="Source sales variance" :value="Format::n($row->SalesVariance, 6)" />
        @endif
    </x-facts>
</div>
