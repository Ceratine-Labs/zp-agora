<x-app-shell :title="$label.' — run #'.$run->Id" wide>
    <x-page-head :eyebrow="'Stock recon · run #'.$run->Id"
                 :title="$label"
                 :blurb="$run->FromDate->format('j M Y').' to '.$run->ToDate->format('j M Y').' · site '.$run->BranchId">
        <x-slot:actions>
            <a class="btn-ghost" href="{{ route('app.stockrecon.overview', $run) }}">Back to overview</a>
        </x-slot:actions>
    </x-page-head>

    <x-card :title="$label" flush>
        <p class="muted" style="margin:12px 14px">
            {{ \App\Support\Format::n($records->count()) }} {{ Str::plural($chainMetric ? 'chain' : 'shift', $records->count()) }}.
            @if ($metric === 'unrecorded')
                Positive chain totals make up the run's unrecorded issue figure, including chains held back or already captured. Values are rounded for display; the run total is rounded after adding them.
            @elseif ($metric === 'amended')
                The preview's count includes count movements and duplicate issues removed; missing issues captured on their own are counted separately by the preview.
            @elseif ($chainMetric)
                One row per item and counting area. Open a chain to see its shifts.
            @else
                Open a shift to see its whole chain.
            @endif
        </p>
        @if ($recordedCount !== null && $recordedCount !== $records->count())
            <x-notice tone="warn" title="Saved total differs from the available details" style="margin:12px 14px">
                <p>The preview recorded {{ \App\Support\Format::n($recordedCount) }}, but its saved lines identify
                   {{ \App\Support\Format::n($records->count()) }}. Preview again for a current result.</p>
            </x-notice>
        @endif
        <x-table :count="$records->count()" tools fit data-tt-page="50" :id="'proposal-metric-'.$metric"
                 empty="No {{ $chainMetric ? 'chains' : 'shifts' }} contribute to this figure.">
            <x-slot:head>
                <tr>
                    <th class="l">Area</th><th class="l">Item</th>
                    @if (! $chainMetric)<th class="l">Date</th><th class="num">Shift</th>@endif
                    @if ($chainMetric)
                        <th class="num">Chain total</th>
                        @if ($metric === 'unrecorded')<th class="num">Selling price</th><th class="num">Value</th>@endif
                    @else
                        <th class="num">Variance before</th><th class="num">Variance after</th><th class="l">Outcome</th>
                    @endif
                    <th class="l">Details</th>
                </tr>
            </x-slot:head>
            @foreach ($records as $line)
                <tr>
                    <td class="l">{{ $line->areaLabel() }}</td>
                    <td class="l">{{ $line->itemLabel() }} <span class="muted">{{ $line->StockItemNo }}</span></td>
                    @if (! $chainMetric)
                        <td class="l">{{ $line->TransactionDate->format('d M Y') }}</td><td class="num" data-sort="{{ $line->ShiftNo }}">{{ \Modules\StockRecon\Support\ShiftLabel::show($line->ShiftNo) }}</td>
                        <td class="num" data-sort="{{ \App\Support\Format::n($line->QtyVar, 3) }}">{{ \App\Support\Format::qty($line->QtyVar) }}</td>
                        <td class="num" data-sort="{{ \App\Support\Format::n($line->QtyVarNew, 3) }}">{{ \App\Support\Format::qty($line->QtyVarNew) }}</td>
                        <td class="l">{{ $line->Outcome }}@if ($line->AuditCode) · {{ $line->AuditCode }} @endif</td>
                    @else
                        <td class="num" data-sort="{{ \App\Support\Format::n($line->metricChainTotal, 3) }}">{{ \App\Support\Format::qty($line->metricChainTotal) }}</td>
                        @if ($metric === 'unrecorded')
                            <td class="num">{{ \App\Support\Format::r($line->metricSellPrice) }}</td>
                            <td class="num">{{ \App\Support\Format::r($line->metricSellPrice === null ? null : (float) $line->metricChainTotal * (float) $line->metricSellPrice) }}</td>
                        @endif
                    @endif
                    <td class="l"><a href="{{ route('app.stockrecon.line', [$run, $line]) }}">Open chain</a></td>
                </tr>
            @endforeach
        </x-table>
    </x-card>
</x-app-shell>
