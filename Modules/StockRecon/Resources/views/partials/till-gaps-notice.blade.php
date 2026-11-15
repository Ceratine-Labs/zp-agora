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
    @php
        /* A whole till missing for a day (Kind 'feed', Ryan 15 Nov 2026,
           Pongola run 80516) leads the headline: every item that till sells
           reads short that day, so it is the bigger news than one item. */
        $feeds = $tillGaps->where('Kind', 'feed');
        $lines = $tillGaps->where('Kind', '!=', 'feed');
        $feedText = $feeds->groupBy('Location')->map(fn ($g, $loc) => 'the '.$loc.' till on '
            .$g->pluck('TransactionDate')->map(fn ($d) => \Illuminate\Support\Carbon::parse($d)->format('j M'))->unique()->implode(', '))
            ->implode('; ');
        $days = $lines->pluck('TransactionDate')->map(fn ($d) => substr((string) $d, 0, 10))->unique()->count();
        $items = $lines->map(fn ($r) => $r->AreaNo.'|'.$r->StockItemNo)->unique()->count();
        $title = collect([
            $feeds->isNotEmpty() ? 'no sales at all from '.$feedText : null,
            $items > 0 ? $items.' '.Str::plural('item', $items).' on '.$days.' '.Str::plural('day', $days) : null,
        ])->filter()->implode(' · ');
    @endphp
    {{-- One thin line, the explanation and the lines folded behind Details
         (Ryan, 15 Nov 2026: "reduce the size of the warning"). --}}
    <x-notice tone="warn" icon collapsible title="Till quantities are missing in PumpIT: {{ $title }}. These shifts cannot balance yet" style="margin-bottom:12px">
        @if ($feeds->isNotEmpty())
            <p><strong>A whole till's day never reached PumpIT.</strong> Every item that till sells reads short on that day,
               so the shorts and any issue reductions proposed there are not real. Load the day's computer quantities in
               PumpIT, then preview this period again. Do not amend those days until then.</p>
        @endif
        @if ($lines->isNotEmpty())
            <p>What the tills sold does not match the POS quantity on these recon lines, so these items cannot balance
               however they are amended. Update the computer quantities in PumpIT, then preview this period again.
               A recon quantity of 0 means the whole day is missing; one below the till means a shift is missing its quantity.</p>
        @endif
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
                        @if ($gap->Kind === 'feed')
                            <td class="l"><strong>{{ $gap->StockItemDescription }}</strong></td>
                            <td class="num">{{ \App\Support\Format::n($gap->QtyRecon, 3) }}</td>
                            <td class="num">—</td>
                            <td class="num">Whole day</td>
                        @else
                            <td class="l"><code>{{ $gap->StockItemNo }}</code> {{ $gap->StockItemDescription }}</td>
                            <td class="num">{{ \App\Support\Format::n($gap->QtyRecon, 3) }}</td>
                            <td class="num">{{ \App\Support\Format::n($gap->QtyTill, 3) }}</td>
                            <td class="num">{{ \App\Support\Format::n($gap->QtyMissing, 3) }}</td>
                        @endif
                    </tr>
                @endforeach
            </x-table>
            <p class="muted">agora.usp_StockRecon_TillQtyCheck · day level, the way head office's own check reads it.</p>
    </x-notice>
@endif
