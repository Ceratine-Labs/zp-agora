{{--
    Till quantities PumpIT has not rolled into this run's recon lines (Chantal
    Peens, 13 Nov 2026, "COMPUTER QTY"): per day, area and item, the recon
    lines' POS quantity against what the tills sold, from
    agora.usp_StockRecon_TillQtyCheck. Those shifts cannot balance however they
    are amended, so the run says so before anyone tries. It reports only:
    nothing is held back or written because of it.

    Props: $tillGaps (Collection of rows from the procedure)
--}}
@if (isset($tillGaps) && $tillGaps->isNotEmpty())
    @php($days = $tillGaps->pluck('TransactionDate')->map(fn ($d) => substr((string) $d, 0, 10))->unique()->count())
    @php($items = $tillGaps->map(fn ($r) => $r->AreaNo.'|'.$r->StockItemNo)->unique()->count())
    {{-- One thin line, the explanation and the lines folded behind Details
         (Ryan, 15 Nov 2026: "reduce the size of the warning"). --}}
    <x-notice tone="warn" icon collapsible title="Till quantities are missing in PumpIT: {{ $items }} {{ Str::plural('item', $items) }} on {{ $days }} {{ Str::plural('day', $days) }} cannot balance yet" style="margin-bottom:12px">
        <p>What the tills sold does not match the POS quantity on these recon lines, so these items cannot balance
           however they are amended. Update the computer quantities in PumpIT, then preview this period again.
           A recon quantity of 0 means the whole day is missing; one below the till means a shift is missing its quantity.</p>
        <x-table layout-key="StockRecon.till-gaps.1" :count="$tillGaps->count()" fit data-tt-page="25" id="till-gaps"
                     empty="Nothing is missing.">
                <x-slot:head>
                    <tr>
                        <th class="l">Date</th><th class="l">Area</th><th class="l">Item</th>
                        <th class="num">Recon POS</th><th class="num">Till sold</th><th class="num">Missing</th>
                    </tr>
                </x-slot:head>
                @foreach ($tillGaps as $gap)
                    <tr>
                        <td class="l">{{ substr((string) $gap->TransactionDate, 0, 10) }}</td>
                        <td class="l">{{ $gap->AreaDescription ?? $gap->AreaNo }}</td>
                        <td class="l"><code>{{ $gap->StockItemNo }}</code> {{ $gap->StockItemDescription }}</td>
                        <td class="num">{{ \App\Support\Format::n($gap->QtyRecon, 3) }}</td>
                        <td class="num">{{ \App\Support\Format::n($gap->QtyTill, 3) }}</td>
                        <td class="num">{{ \App\Support\Format::n($gap->QtyMissing, 3) }}</td>
                    </tr>
                @endforeach
            </x-table>
            <p class="muted">agora.usp_StockRecon_TillQtyCheck · day level, the way head office's own check reads it.</p>
    </x-notice>
@endif
