{{--
    The chain behind one shift.

    A shift row on its own cannot be judged: its variance came from the counts
    on either side of it, its amendment was decided by the whole item's running
    total, and whether it is blocked was decided by a DIFFERENT shift somewhere
    else in the chain. So the panel is the chain — every shift of this item in
    this window, in order, counted beside balanced, with the row the reader came
    from marked and the blocking shift findable.

    HTML rather than JSON. The formatting rules live in App\Support\Format, and
    rebuilding them in JavaScript is how the two drift apart.
--}}
@php($blocks = collect([
    $item?->BlockSoldMore, $item?->BlockCloseExceeds, $item?->BlockNetOver, $item?->BlockChainBroken,
    $item?->BlockIssueNowhere, $item?->BlockBigAmendment, $item?->BlockPctAmendment,
    $item?->BlockNegativeClose, $item?->BlockShortChain, $item?->BlockDormantMoved,
])->filter()->values())

<div class="drill-chain">
    <header>
        <h4>{{ $item->StockItemDescription ?? $line->StockItemNo }}</h4>
        <p class="muted">
            {{ $item->AreaDescription ?? 'area '.$line->AreaNo }}
            @if ($item?->POSCode) · POS <code>{{ $item->POSCode }}</code> @endif
            @if ($item?->StockLocation) · {{ $item->StockLocation }} @endif
            @if ($item?->UOMCode) · {{ $item->UOMCode }} @endif
        </p>
    </header>

    <x-statstrip :stats="[
        ['label' => 'Chain total (T)', 'value' => \App\Support\Format::n($item?->ChainNetVar, 3),
         'note' => \App\Support\Format::r($item?->ChainNetVarValue).' · fixed by the dates, unchanged by any amendment',
         'tone' => (float) ($item?->ChainNetVar ?? 0) > 0.005 ? 'warn' : 'neutral'],
        ['label' => 'Shifts', 'value' => \App\Support\Format::n($item?->ChainShifts),
         'note' => \App\Support\Format::n($item?->ActiveShifts).' active, '.\App\Support\Format::n($item?->DormantShifts).' dormant'],
        ['label' => 'Chain', 'value' => ($item?->ChainBlocked ? 'Reported' : 'Balanceable'),
         'note' => $item?->ChainBlocked ? 'blocked whole' : 'every over goes to zero',
         'tone' => $item?->ChainBlocked ? 'serious' : 'good'],
    ]" />

    @if ($blocks->isNotEmpty())
        <x-notice tone="stop" title="Why this chain is reported rather than balanced">
            <ul>
                @foreach ($blocks as $why)<li>{{ $why }}</li>@endforeach
            </ul>
            @if ($item?->BlockChainBroken)
                <p><strong>A broken chain is the one worth chasing.</strong> The whole method rests on a
                   shift's opening being the previous shift's closing. Where that is not true in the
                   source, moving a quantity along the chain is moving it along a path that does not
                   exist — so the chain is left exactly as counted and the break is reported instead.</p>
            @endif
        </x-notice>
    @endif

    <x-table :count="$shifts->count()" dense
             empty="This item has no other shift in the window.">
        <x-slot:head>
            <tr>
                <th class="l">Date</th><th class="num">Shift</th>
                <th class="num">Open</th><th class="num">Issued</th><th class="num">Close</th>
                <th class="num">POS</th><th class="num">Variance</th><th class="num">Cumulative</th>
                <th class="num grp">New open</th><th class="num">New close</th><th class="num">Amend</th>
                <th class="num">New variance</th><th class="l">Outcome</th>
            </tr>
        </x-slot:head>

        @foreach ($shifts as $shift)
            <tr @class(['is-matched' => (bool) $shift->IsClicked])>
                <td class="l">{{ \Illuminate\Support\Carbon::parse($shift->TransactionDate)->format('d M') }}</td>
                <td class="num">{{ $shift->ShiftNo }}</td>
                <td class="num">{{ \App\Support\Format::n($shift->QtyOpen, 3) }}</td>
                <td class="num">{{ (float) $shift->QtyIssued === 0.0 ? '—' : \App\Support\Format::n($shift->QtyIssued, 3) }}</td>
                <td class="num">{{ \App\Support\Format::n($shift->QtyClose, 3) }}</td>
                <td class="num">{{ (float) $shift->QtyPOS === 0.0 ? '—' : \App\Support\Format::n($shift->QtyPOS, 3) }}</td>
                <td class="num">{{ (float) $shift->QtyVar === 0.0 ? '—' : \App\Support\Format::n($shift->QtyVar, 3) }}</td>
                {{-- The curve the method is drawn on. The balanced answer is
                     its non-rising envelope, and seeing the running total is
                     what makes that legible without a chart. --}}
                <td class="num muted">{{ \App\Support\Format::n($shift->CumulativeVar, 3) }}</td>
                <td class="num grp">{{ \App\Support\Format::n($shift->QtyOpenNew, 3) }}</td>
                <td class="num">{{ \App\Support\Format::n($shift->QtyCloseNew, 3) }}</td>
                <td class="num">{{ abs((float) $shift->AmendClose) < 0.0005 ? '—' : \App\Support\Format::n($shift->AmendClose, 3) }}</td>
                <td class="num">{{ (float) $shift->QtyVarNew === 0.0 ? '—' : \App\Support\Format::n($shift->QtyVarNew, 3) }}</td>
                <td class="l muted" style="font-size:12px">
                    {{ $shift->Outcome }}
                    @if ($shift->IsDormant)<x-chip tone="neutral" :dot="false">dormant</x-chip>@endif
                    @if ($shift->AmendmentState === 'active')<x-chip tone="good" :dot="false">amended</x-chip>@endif
                </td>
            </tr>
        @endforeach
    </x-table>

    <p class="field-help">
        Only the closing count is amended. The opening follows automatically, because it <em>is</em> the
        previous closing — which is why a shift whose closing is pinned can still have its opening moved,
        and why both are written when this run is committed.
    </p>
</div>
