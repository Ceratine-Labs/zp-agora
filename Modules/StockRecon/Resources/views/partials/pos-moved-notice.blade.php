{{--
    Amendments on this run that were worked out against a POS quantity PumpIT
    has since changed (Caltex Ulundi, 14 Nov 2026): a run committed before the
    till quantities came through balanced on a POS of 0, and its amendments
    still stand. From agora.usp_StockRecon_PosMovedCheck, one row per item, each
    linked to that item's chain reversal. It reports only: nothing is reversed
    or held back because of it.

    Props: $posMoved (Collection of rows from the procedure), $run
--}}
@if (isset($posMoved) && $posMoved->isNotEmpty())
    @php($items = $posMoved->count())
    <x-notice tone="warn" title="The POS has changed since this run — {{ $items }} {{ Str::plural('item', $items) }} {{ $items === 1 ? 'was' : 'were' }} balanced on old till quantities" style="margin-bottom:16px">
        <p>These items were amended before PumpIT had their till quantities, so the amendments were worked out on the old POS
           and are probably wrong now. Reverse each item's chain, then preview the period again: the new preview balances against
           the POS PumpIT holds today.</p>
        <details>
            <summary>Show the {{ $items }} {{ Str::plural('item', $items) }}</summary>
            <x-table layout-key="StockRecon.pos-moved.1" :count="$items" fit data-tt-page="25" id="pos-moved"
                     empty="No POS has changed.">
                <x-slot:head>
                    <tr>
                        <th class="l">Area</th><th class="l">Item</th><th class="l">Dates</th>
                        <th class="num">Shifts</th><th class="num">POS at the run</th><th class="num">POS now</th><th class="l"></th>
                    </tr>
                </x-slot:head>
                @foreach ($posMoved as $row)
                    @php($from = substr((string) $row->FromDate, 0, 10))
                    @php($to = substr((string) $row->ToDate, 0, 10))
                    <tr>
                        <td class="l">{{ $row->AreaDescription ?? $row->AreaNo }}</td>
                        <td class="l"><code>{{ $row->StockItemNo }}</code> {{ $row->StockItemDescription }}</td>
                        <td class="l">{{ $from === $to ? $from : $from.' – '.$to }}</td>
                        <td class="num">{{ $row->Shifts }}</td>
                        <td class="num">{{ \App\Support\Format::n($row->QtyPOSRun, 3) }}</td>
                        <td class="num">{{ \App\Support\Format::n($row->QtyPOSNow, 3) }}</td>
                        <td class="l"><a class="btn-ghost" href="{{ route('app.stockrecon.line', [$run, $row->RunLineId]) }}#reverse-chain">Reverse this item…</a></td>
                    </tr>
                @endforeach
            </x-table>
            <p class="muted">agora.usp_StockRecon_PosMovedCheck · standing amendments whose shift POS differs from PumpIT now.</p>
        </details>
    </x-notice>
@endif
