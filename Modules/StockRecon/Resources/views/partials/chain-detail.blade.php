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
@php
    $verdict = $item?->ChainBlocked
        ? ['label' => 'Held back', 'tone' => 'serious', 'hint' => 'see why below', 'note' => '']
        : \Modules\StockRecon\Services\ChainSummary::verdict(
            (float) ($item?->ChainNetVar ?? 0),
            $shifts->filter(fn ($s) => (bool) $s->WouldAmend)->count(),
            $shifts->contains(fn ($s) => abs((float) $s->QtyCloseNew - (float) $s->QtyClose) > 0.0005
                || abs((float) $s->QtyOpenNew - (float) $s->QtyOpen) > 0.0005),
        );
@endphp
@php($blocks = collect([
    $item?->BlockPossibleDuplicate,
    $item?->BlockPackOver, $item?->BlockNoPosLink,
    $item?->BlockSoldMore, $item?->BlockCloseExceeds, $item?->BlockNetOver,
    $item?->BlockIssueNowhere, $item?->BlockAuditConflict, $item?->BlockBigAmendment, $item?->BlockPctAmendment,
    $item?->BlockNegativeClose, $item?->BlockShortChain, $item?->BlockDormantMoved,
])->filter()->values())

{{-- The possible duplicates on this chain (PM decision 01a10b21): those with
     no release standing NOW, which the panel offers to release, and those
     released since this run was made, which only a new preview lets through. --}}
@php($possible = $shifts->filter(fn ($s) => $s->DupTier === 'D2'))
@php($unreleased = $possible->filter(fn ($s) => $s->ReleaseId === null))
@php($releasedSince = $possible->filter(fn ($s) => $s->ReleaseId !== null && (int) $s->ReleaseId !== (int) $s->DupReleaseId))

{{-- The clear duplicates on this chain (D1). The procedure has returned them
     since 1 Oct 2026 and the panel never said so: a removed one read as an
     ordinary balanced or dormant shift. Each links to its case on the run's
     duplicate-issues panel, which says where it stands. --}}
@php($clearDups = $shifts->filter(fn ($s) => $s->DupTier === 'D1'))
@php($caseUrl = fn ($s) => route('app.stockrecon.run', [$run, 'dup' => 'dup-'.$s->Id]).'#duplicates')
@php($twinOf = fn ($s) => $s->DupTwinDate ? \Illuminate\Support\Carbon::parse($s->DupTwinDate)->format('d M').' shift '.$s->DupTwinShift : 'a neighbouring shift')

<div class="drill-chain">
    <header>
        <div class="card-t">
            <h4>{{ $item->StockItemDescription ?? $line->StockItemNo }}</h4>
            {{-- The reading notes, behind a button (ZRP, 4 Oct 2026). --}}
            <x-info label="How to read this chain" class="info-wide">
                <p>Balancing amends the closing count; the opening follows, because it <em>is</em> the previous
                   closing. Where the counted opening missed the previous closing, the opening is repaired: a
                   break of 3 or fewer is balanced straight through, a bigger one splits the chain and the
                   closing before it stands.</p>
                <p>Where two people were on a shift both are named: the counts cannot say which of them a
                   variance belongs to. A red chip beside the outcome is hand balancing already in PumpIT that
                   the rules do not allow; the commit puts it back.</p>
            </x-info>
        </div>
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
         'note' => \App\Support\Format::r($item?->ChainNetVarValue),
         'tone' => (float) ($item?->ChainNetVar ?? 0) > 0.005 ? 'warn' : 'neutral'],
        ['label' => 'Shifts', 'value' => \App\Support\Format::n($item?->ChainShifts),
         'note' => \App\Support\Format::n($item?->ActiveShifts).' active, '.\App\Support\Format::n($item?->DormantShifts).' dormant'],
        /* What the chain comes to, in the chain row's words (ChainSummary::
           verdict). It said Balanceable for any chain nothing held back,
           including one short 2 with no over anywhere, which has nothing to
           balance (Ryan, 7 Oct 2026). No double quotes in this comment: it
           sits inside the attribute, and one would end it. */
        ['label' => 'Chain', 'value' => $verdict['label'], 'note' => $verdict['hint'], 'tone' => $verdict['tone']],
        /* How many different people appear anywhere on this chain. One is a
           chain a single person is answerable for; six is a chain where a
           persistent short is about the item or the process, not a person. */
        ['label' => 'People on it', 'value' => \App\Support\Format::n($item?->DistinctEmployeeSets)],
    ]" />

    {{-- A real short with nothing to set it against says why, in a line. --}}
    @if (! $item?->ChainBlocked && str_starts_with($verdict['label'], 'Short '))
        <p class="muted" style="margin:8px 0 0">{{ $verdict['note'] }}</p>
    @endif

    @if ($blocks->isNotEmpty())
        <x-notice tone="stop" title="Why this chain is reported rather than balanced">
            <ul>
                @foreach ($blocks as $why)<li>{{ $why }}</li>@endforeach
            </ul>
            @if ($item?->BlockNoPosLink)
                <p><strong>Not a loss:</strong> the selling PLU or recipe is not reducing this item. Link it and
                   re-run; nobody is charged.</p>
            @endif
        </x-notice>
    @endif

    @if ($item?->BlockPossibleDuplicate)
        <x-notice tone="warn" title="Held back: a possible duplicate issue">
            <p>The same issue was keyed on a neighbouring shift and this item is short by about that much, but
               the count cannot say which issue was real. Nothing on this chain is written to PumpIT — not the
               balancing, and not a hand amendment put back — because that could return a short somebody hid by
               moving counts to staff pay.</p>
            <p><strong>Two ways out.</strong> The site corrects the issue in PumpIT against the issue book, the
               storeroom count or the delivery note, and the next preview no longer finds it. Or somebody who has
               checked releases it here with a reason, and the next preview lets the chain commit.</p>
            @if ($unreleased->isNotEmpty())
                @include('stockrecon::partials.duplicate-release', [
                    'run' => $run,
                    'lines' => $unreleased->pluck('Id')->map(fn ($id) => (int) $id)->all(),
                    'label' => $unreleased->count() > 1 ? 'Release all '.$unreleased->count().'…' : 'Release…',
                    'summary' => ($item->StockItemDescription ?? $line->StockItemNo).': '
                        .$unreleased->map(fn ($s) => \Illuminate\Support\Carbon::parse($s->TransactionDate)->format('d M').' shift '.$s->ShiftNo
                            .', issue '.\App\Support\Format::n($s->QtyIssued, 3))->implode('; ').'.',
                ])
            @endif
            @if ($releasedSince->isNotEmpty())
                <p>{{ $releasedSince->count() }} released since this preview — preview again to let the chain commit.</p>
            @endif
        </x-notice>
    @endif

    @if ($clearDups->isNotEmpty())
        <x-notice tone="info"
                  :title="$clearDups->count() === 1 ? 'A duplicate issue on this chain' : $clearDups->count().' duplicate issues on this chain'">
            @foreach ($clearDups as $dup)
                <p>{{ \Illuminate\Support\Carbon::parse($dup->TransactionDate)->format('d M') }} shift {{ $dup->ShiftNo }}:
                   the issue of {{ \App\Support\Format::n($dup->QtyIssued, 3) }} was keyed twice — the identical issue on
                   {{ $twinOf($dup) }} arrived.
                   @if ($dup->QtyIssuedNew !== null)
                       It is taken out of the balancing and set to 0 by the commit, and the chain is written with it.
                   @else
                       Not taken out: {{ (\Illuminate\Support\Str::after((string) ($dup->DupReason ?: 'Not removed: the run did not take it out'), 'Not removed: ')) }}.
                   @endif
                   <a href="{{ $caseUrl($dup) }}">See the case</a>.</p>
            @endforeach
        </x-notice>
    @endif

    @if ((int) ($item?->RepairedOpenings ?? 0) > 0)
        <x-notice tone="warn" collapsible
                  :title="$item->RepairedOpenings.' '.Str::plural('opening', (int) $item->RepairedOpenings).' not equal to the previous closing'
                      .((int) ($item->KeptBreaks ?? 0) > 0 ? ' — '.$item->KeptBreaks.' small, chain kept' : '')">
            <p>The opening is amended to the previous closing. A break of 3 or fewer is balanced through; a
               bigger one splits the chain and the closing before it stands. An opening <em>below</em> the
               previous closing (S10a) stays on the exceptions report either way.</p>
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
                <td class="num grp">
                    {{ \App\Support\Format::n($shift->QtyOpen, 3) }}
                    @if (abs((float) $shift->ChainBreak) > 0.0005)
                        <x-chip :tone="(float) $shift->ChainBreak < 0 ? 'crit' : 'warn'" :dot="false"
                                :title="'Previous closing '.\App\Support\Format::n($shift->PrevClose, 3)
                                    .($shift->BreakKept ? ' — small, the chain runs through it' : ' — the chain is split here')">
                            {{ (float) $shift->ChainBreak < 0 ? 'S10a' : 'S10b' }}
                            {{ \App\Support\Format::n($shift->ChainBreak, 3) }}{{ $shift->BreakKept ? ' kept' : '' }}
                        </x-chip>
                    @endif
                </td>
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
                     what makes that legible without a chart. A zero is a dash,
                     as in every other figure column: a chain where every shift
                     balanced read 0.000 all the way down, which looked like a
                     column that was not working (Ryan, 6 Oct 2026). --}}
                <td class="num muted">{{ abs((float) $shift->CumulativeVar) < 0.0005 ? '—' : \App\Support\Format::n($shift->CumulativeVar, 3) }}</td>
                <td class="num grp">{{ \App\Support\Format::n($shift->QtyOpenNew, 3) }}</td>
                <td class="num">{{ \App\Support\Format::n($shift->QtyCloseNew, 3) }}</td>
                <td class="num">{{ abs((float) $shift->AmendClose) < 0.0005 ? '—' : \App\Support\Format::n($shift->AmendClose, 3) }}</td>
                <td class="num">{{ (float) $shift->QtyVarNew === 0.0 ? '—' : \App\Support\Format::n($shift->QtyVarNew, 3) }}</td>
                <td class="num money">{{ (float) $shift->QtyVarNew === 0.0 ? '—' : \App\Support\Format::r($shift->VarValueNew) }}</td>
                <td class="l muted" style="font-size:12px">
                    {{ $shift->Outcome }}
                    @if ($shift->IsDormant)<x-chip tone="neutral" :dot="false">dormant</x-chip>@endif
                    @if ($shift->AmendmentState === 'active')<x-chip tone="good" :dot="false">amended</x-chip>@endif
                    @if ($shift->AuditCode)
                        @include('stockrecon::partials.code', ['code' => $shift->AuditCode,
                            'title' => 'PumpIT holds '.\App\Support\Format::n($shift->QtyOpenNow, 3).' / '.\App\Support\Format::n($shift->QtyCloseNow, 3)])
                    @endif
                    {{-- A clear duplicate: the issue it takes out, or why it
                         does not — and its case on the run's panel. --}}
                    @if ($shift->DupTier === 'D1')
                        <x-chip :tone="$shift->QtyIssuedNew !== null ? 'good' : 'warn'" :dot="false"
                                :title="'Clear duplicate issue: the identical issue on '.$twinOf($shift).' arrived. '.($shift->DupReason ?? '')">
                            D1 {{ $shift->QtyIssuedNew !== null
                                ? 'issue '.\App\Support\Format::n($shift->QtyIssued, 3).' → '.\App\Support\Format::n($shift->QtyIssuedNew, 3)
                                : 'not removed' }}
                        </x-chip>
                        <a href="{{ $caseUrl($shift) }}">case</a>
                    @endif
                    {{-- A possible duplicate: its twin, why it is not clear, and
                         the release it has now — or the press that makes one. --}}
                    @if ($shift->DupTier === 'D2')
                        @php($twin = $shift->DupTwinDate ? \Illuminate\Support\Carbon::parse($shift->DupTwinDate)->format('d M').' shift '.$shift->DupTwinShift : 'not found')
                        @php($what = ($item->StockItemDescription ?? $line->StockItemNo).', '
                            .\Illuminate\Support\Carbon::parse($shift->TransactionDate)->format('d M').' shift '.$shift->ShiftNo
                            .', issue '.\App\Support\Format::n($shift->QtyIssued, 3).' — the same issue on '.$twin.'.')
                        <x-chip :tone="$shift->ReleaseId ? 'neutral' : 'serious'" :dot="false"
                                :title="'Possible duplicate issue: the same issue on '.$twin.'. '.($shift->DupReason ?? '')">
                            D2{{ $shift->ReleaseId ? ' released' : '' }}
                        </x-chip>
                        @if ($shift->ReleaseId)
                            <br>Released by {{ $shift->ReleasedBy ?? 'somebody' }},
                            {{ \Illuminate\Support\Carbon::parse($shift->ReleasedAt)->format('d M H:i') }}: {{ $shift->ReleaseReason }}
                            @if ((int) $shift->ReleaseId !== (int) $shift->DupReleaseId) — since this preview; preview again. @endif
                            @include('stockrecon::partials.duplicate-release', [
                                'run' => $run, 'withdraw' => (int) $shift->ReleaseId, 'summary' => $what,
                            ])
                        @else
                            @if ($shift->DupReleaseId)<br>Its release was withdrawn after this preview. @endif
                            @include('stockrecon::partials.duplicate-release', [
                                'run' => $run, 'lines' => [(int) $shift->Id], 'summary' => $what,
                            ])
                        @endif
                    @endif
                </td>
            </tr>
        @endforeach
    </x-table>

    {{-- Why the Cumulative column steps on a row whose Variance reads nothing.
         It runs on the balancing basis and ends on the chain total in the card
         above (Ryan, 6 Oct 2026: "balance to 0 but … show a movement of 1 in
         cumulative"); until then it ran over the counted variance and this line
         had to do the sum for the reader. The terms come from
         usp_StockRecon_DrillChain, which also says whether they add up: a run
         made before the method settled on 30 Sep 2026 was totalled another way,
         and gets a plain sentence instead of a sum that does not hold. --}}
    @php($signed = fn ($v) => ((float) $v > 0.0005 ? '+' : '').\App\Support\Format::n($v, 3))
    @php($openings = (int) ($item?->OpeningsCount ?? 0))
    @php($dups = (int) ($item?->DuplicatesCount ?? 0))
    @if ($item && ! $item->TotalReconciles)
        <p class="muted">
            This run's chain total was worked out by an earlier version of the balancing, so the Cumulative
            column does not end on it — preview again to see how the total is made up.
        </p>
    @elseif ($item && ($openings > 0 || $dups > 0))
        <p class="muted">
            Cumulative ends on the chain total, {{ \App\Support\Format::n($item->ChainNetVar, 3) }}. Besides each
            shift's variance it steps
            @if ($openings > 0)
                {{ $signed($item->OpeningsMoved) }} where {{ $openings === 1 ? '1 opening was' : $openings.' openings were' }}
                set to the previous closing{{ $dups > 0 ? ' and' : '.' }}
            @endif
            @if ($dups > 0)
                {{ $signed($item->DuplicatesTaken) }} where
                {{ $dups === 1 ? '1 clear duplicate issue was' : $dups.' clear duplicate issues were' }} taken out.
            @endif
        </p>
    @endif

</div>
