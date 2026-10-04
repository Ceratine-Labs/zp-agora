{{--
    One cashup's legs, as tabs — the mockup's "legs as tabs". One tab per leg
    the setting shows (cash.cashup.channels less cash.cashup.hidden_channels),
    each with its line count; the dead legs are hidden by that setting, and a
    line that ever arrives in one is still shown, under "Other legs".

    The lines are agora.usp_Cash_GetCashup's second result set, whole, so each
    table is the complete answer for its leg (feature-rules proposed §B).

    A reconciled line carries its recon batch. A line that counts for nothing
    in the total — a customer, supplier or person PumpIT's list does not have,
    which the legacy total silently drops — says so rather than vanishing.

    Each tab also says how its lines get there: "Add from PumpIT" for a leg
    Agora writes (the pool, or the card load for FNB), or why Agora does not
    write it (cash.cashup.held_channels).

    Props: $lines (Collection), $channels (array channel => label),
           $scope, $one (?object), $savable (array), $held (array)
--}}
@php
    $canSave = (bool) auth()->user()?->can('cash.cashup.save');
    $open = ($one?->Status ?? 'Open') !== 'Closed';
    $byChannel = $lines->groupBy('Channel');
    $other = $lines->reject(fn ($l) => array_key_exists($l->Channel, $channels));
    $items = collect($channels)->map(fn ($label, $key) => [
        'key' => $key,
        'label' => $label,
        'count' => ($byChannel[$key] ?? collect())->count(),
    ])->values();
    if ($other->isNotEmpty()) {
        $items->push(['key' => 'other', 'label' => 'Other legs', 'count' => $other->count()]);
    }
    $first = $items->first(fn ($i) => $i['count'] > 0)['key'] ?? $items->first()['key'] ?? null;
@endphp

<x-tabs :items="$items->all()" :active="$first" persist="cash-banking-legs" label="Legs of the cashup">
    @foreach ($items as $item)
        @php($rows = $item['key'] === 'other' ? $other : ($byChannel[$item['key']] ?? collect()))
        <x-tab-panel :key="$item['key']">
            @if (isset($held[$item['key']]))
                {{-- The fact stays on the tab; the reason is behind the button.
                     A <div>, because <details> cannot sit inside a <p>. --}}
                <div class="muted" style="margin:10px 12px">
                    Captured in PumpIT, not here.
                    <x-info label="Why this leg is captured in PumpIT"><p>{{ $held[$item['key']] }}</p></x-info>
                </div>
            @elseif ($canSave && $open && in_array($item['key'], $savable, true))
                <div class="form-actions" style="margin:10px 12px">
                    @if ($item['key'] === 'FNB')
                        <a class="btn-ghost" href="{{ route('app.cash.banking', $scope->query() + ['cards' => 1]) }}">Load card amounts from the terminal</a>
                    @else
                        <a class="btn-ghost" href="{{ route('app.cash.banking', $scope->query() + ['pool' => $item['key']]) }}">Add from {{ config('cash.cashup.pool_names.'.$item['key']) }}</a>
                    @endif
                </div>
            @endif
            <x-table :count="$rows->count()" procedure="agora.usp_Cash_GetCashup" dense
                     :empty="'No '.mb_strtolower($item['label']).' on this cashup.'">
                <x-slot:head>
                    <tr>
                        @if ($item['key'] === 'other')<th>Leg</th>@endif
                        <th>Reference</th>
                        <th>Who</th>
                        <th>Detail</th>
                        <th class="num">Amount</th>
                        <th>Recon</th>
                    </tr>
                </x-slot:head>
                @foreach ($rows as $l)
                    <tr>
                        @if ($item['key'] === 'other')<td>{{ $l->Channel }}</td>@endif
                        <td class="mono">{{ $l->LineRef }}</td>
                        <td>{{ $l->Counterparty ?? \App\Support\Format::NOTHING }}</td>
                        <td>
                            {{ $l->Detail ?? \App\Support\Format::NOTHING }}
                            @unless ($l->InActualTotal)
                                <br><span class="muted">not counted — not on PumpIT's list</span>
                            @endunless
                        </td>
                        <td class="num">
                            {{ \App\Support\Format::r($l->Amount) }}
                            @if ((float) ($l->AdjustedBy ?? 0) !== 0.0)
                                <br><span class="muted">adjusted by {{ \App\Support\Format::r($l->AdjustedBy) }}</span>
                            @endif
                        </td>
                        <td>
                            @if ($l->ReconBatchNo === null)
                                <span class="muted">{{ \App\Support\Format::NOTHING }}</span>
                            @elseif ($l->IsReconciled)
                                <x-chip tone="good">batch {{ $l->ReconBatchNo }}</x-chip>
                            @else
                                <x-chip tone="neutral">outstanding</x-chip>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </x-table>
        </x-tab-panel>
    @endforeach
</x-tabs>
