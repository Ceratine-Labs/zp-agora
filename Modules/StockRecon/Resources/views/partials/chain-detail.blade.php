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
            · item <code>{{ $line->StockItemNo }}</code>
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
        /* How many different people appear anywhere on this chain. One is a
           chain a single person is answerable for; six is a chain where a
           persistent short is about the item or the process, not a person. */
        ['label' => 'People on it', 'value' => \App\Support\Format::n($item?->DistinctEmployeeSets),
         'note' => (int) ($item?->DistinctEmployeeSets ?? 0) > 1
             ? 'a short spread across several is about the item, not a person'
             : 'one person across the whole chain'],
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

    {{-- `fit` for the same reason the proposals table has it: fourteen columns,
         eleven of them a figure under a heading twice its width, and one column
         carrying up to four names. --}}
    {{-- TWO HEADER ROWS, because the screen this replaces has two blocks.
         The legacy Stock Recon Balancing window sets Original Values beside
         Amended Values over the same column names, and the people who work
         this screen read it that way. Before this the two halves were told
         apart only by the word "New" on three of the headings and a `grp`
         class that had no stylesheet rule behind it at all — so the panel
         showed both answers and never said which was which.

         Safe here and NOT in the proposals table: table-tools.js reads
         `head.rows[0]` for its sort and filter row, so a spanning row above
         the real headings would break a table that opts into `tools`. This one
         does not. --}}
    <x-table :count="$shifts->count()" dense fit
             empty="This item has no other shift in the window.">
        <x-slot:head>
            <tr class="grp-head">
                <th class="l fit-min" colspan="3"></th>
                <th class="num grp" colspan="7">Original — as counted</th>
                <th class="num grp" colspan="5">Amended — as this run proposes</th>
                <th class="l"></th>
            </tr>
            <tr>
                <th class="l fit-min">Date</th><th class="num">Shift</th><th class="l">On shift</th>
                <th class="num grp">Open</th><th class="num">Issued</th><th class="num">Close</th>
                <th class="num">POS</th><th class="num">Variance</th><th class="num">Value</th>
                <th class="num">Cumulative</th>
                <th class="num grp">Open</th><th class="num">Close</th><th class="num">Amend</th>
                <th class="num">Variance</th><th class="num">Value</th>
                <th class="l">Outcome</th>
            </tr>
        </x-slot:head>

        @foreach ($shifts as $shift)
            <tr @class(['is-matched' => (bool) $shift->IsClicked])>
                <td class="l fit-min">{{ \Illuminate\Support\Carbon::parse($shift->TransactionDate)->format('d M') }}</td>
                <td class="num">{{ $shift->ShiftNo }}</td>
                {{-- Who was signed on to this counting area for this shift.
                     The method note listed this as the second thing the
                     algorithm could not see; the estate records it after all,
                     at exactly this grain. --}}
                <td class="l cell-name">
                    @if ($shift->EmployeeNames)
                        {{ $shift->EmployeeNames }}
                        @if ((int) $shift->EmployeeCount > 1)
                            <x-chip tone="warn" :dot="false" class="crew-count">{{ $shift->EmployeeCount }} on shift</x-chip>
                        @endif
                    @else
                        <span class="muted">—</span>
                    @endif
                </td>
                <td class="num grp">{{ \App\Support\Format::n($shift->QtyOpen, 3) }}</td>
                <td class="num">{{ (float) $shift->QtyIssued === 0.0 ? '—' : \App\Support\Format::n($shift->QtyIssued, 3) }}</td>
                <td class="num">{{ \App\Support\Format::n($shift->QtyClose, 3) }}</td>
                <td class="num">{{ (float) $shift->QtyPOS === 0.0 ? '—' : \App\Support\Format::n($shift->QtyPOS, 3) }}</td>
                <td class="num">{{ (float) $shift->QtyVar === 0.0 ? '—' : \App\Support\Format::n($shift->QtyVar, 3) }}</td>
                {{-- The rand behind the quantity. A variance is read as a pair
                     on the legacy screen and it should be read as a pair here:
                     0.1 kg of R240 cheese and 0.1 kg of sauce are the same
                     number and not the same finding. --}}
                <td class="num money">{{ (float) $shift->QtyVar === 0.0 ? '—' : \App\Support\Format::r($shift->VarValue) }}</td>
                {{-- The curve the method is drawn on. The balanced answer is
                     its non-rising envelope, and seeing the running total is
                     what makes that legible without a chart. --}}
                <td class="num muted">{{ \App\Support\Format::n($shift->CumulativeVar, 3) }}</td>
                <td class="num grp">{{ \App\Support\Format::n($shift->QtyOpenNew, 3) }}</td>
                <td class="num">{{ \App\Support\Format::n($shift->QtyCloseNew, 3) }}</td>
                <td class="num">{{ abs((float) $shift->AmendClose) < 0.0005 ? '—' : \App\Support\Format::n($shift->AmendClose, 3) }}</td>
                <td class="num">{{ (float) $shift->QtyVarNew === 0.0 ? '—' : \App\Support\Format::n($shift->QtyVarNew, 3) }}</td>
                <td class="num money">{{ (float) $shift->QtyVarNew === 0.0 ? '—' : \App\Support\Format::r($shift->VarValueNew) }}</td>
                <td class="l muted" style="font-size:12px">
                    {{ $shift->Outcome }}
                    @if ($shift->IsDormant)<x-chip tone="neutral" :dot="false">dormant</x-chip>@endif
                    @if ($shift->AmendmentState === 'active')<x-chip tone="good" :dot="false">amended</x-chip>@endif
                </td>
            </tr>
        @endforeach
    </x-table>

    <p class="field-help">
        <strong>Two people on a shift is not two people to charge.</strong> Where a shift is shared the
        panel names both and says so: the counts cannot tell you which of them the variance belongs to,
        and a screen that showed one name would be deciding that on your behalf.
    </p>
    <p class="field-help">
        Only the closing count is amended. The opening follows automatically, because it <em>is</em> the
        previous closing — which is why a shift whose closing is pinned can still have its opening moved,
        and why both are written when this run is committed.
    </p>
</div>
