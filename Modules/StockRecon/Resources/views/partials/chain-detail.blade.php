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
@php($groups = \Modules\StockRecon\Services\ChainSummary::groupsOfShifts($shifts))
{{-- The pencil (Ryan, 16 Nov 2026): on an open run, for somebody who may
     amend, on every shift but the chain's last (its closing is the next
     count's opening, outside the run). --}}
@php($canAdjust = $run->isOpen() && (auth()->user()?->can('stockrecon.runs.execute') ?? false) && ! ($item?->BlockPossibleDuplicate))
{{-- UP TO WHERE ANOTHER RUN AMENDED (Ryan, 16 Nov 2026, run 80536: "show up
     to where a run has been amended"). Each shift another run already amended
     says which; a line under the last of them says this run proposes from
     there on. --}}
@php($elsewhere = collect($elsewhere ?? []))
@php($heldHere = $shifts->filter(fn ($s) => $elsewhere->has((int) $s->Id)))
@php($lastHeldId = $heldHere->isNotEmpty() ? (int) $heldHere->last()->Id : null)
@php($heldRuns = $heldHere->map(fn ($s) => $elsewhere->get((int) $s->Id))->unique()->values())
@php($panelCols = 19 + ($canAdjust ? 1 : 0))

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
@php($caseUrl = fn ($s) => route('app.stockrecon.overview', [$run, 'dup' => 'dup-'.$s->Id]).'#duplicates')
@php($twinOf = fn ($s) => $s->DupTwinDate ? \Illuminate\Support\Carbon::parse($s->DupTwinDate)->format('d M').' shift '.\Modules\StockRecon\Support\ShiftLabel::show($s->DupTwinShift) : 'a neighbouring shift')

<div class="drill-chain">
    {{-- Explain this chain (Ryan, 15 Nov 2026: "give a button to explain the
         run in the same way you did now"). Shown only with an Anthropic key.
         A button, not a form: this fragment sits inside the commit form. --}}
    @if (app(\Modules\StockRecon\Services\ChainExplainer::class)->available())
        <div class="chain-explain" data-chain-explain="{{ route('app.stockrecon.line.explain', [$run, $line]) }}">
            <button type="button" class="btn-ghost btn-row" data-chain-explain-go>Explain this chain</button>
            <div class="chain-explain-body" aria-live="polite" hidden></div>
        </div>
    @endif
    {{-- NOTHING ABOVE THE GRID (Ryan, 12 Nov 2026: "can remove this entire
         section ... no one reads it"). The panel opened on a heading, the
         balancing profile, four figures and up to five notices before the
         first shift; what each of them said is on the shift's own row, in the
         Outcome column and its chips. One thing stays, because it is a press
         and not a paragraph: a chain a possible duplicate holds back, and the
         release that lets it through. --}}
    @if ($item?->BlockPossibleDuplicate)
        <x-notice tone="warn" title="Held back: a possible duplicate issue">
            <p>Nothing on this chain is written until the possible duplicate is released, with a reason, by
               somebody who has checked the issue book.</p>
            @if ($unreleased->isNotEmpty())
                @include('stockrecon::partials.duplicate-release', [
                    'run' => $run,
                    'lines' => $unreleased->pluck('Id')->map(fn ($id) => (int) $id)->all(),
                    'label' => $unreleased->count() > 1 ? 'Release all '.$unreleased->count().'…' : 'Release…',
                    'summary' => ($item->StockItemDescription ?? $line->StockItemNo).': '
                        .$unreleased->map(fn ($s) => \Illuminate\Support\Carbon::parse($s->TransactionDate)->format('d M').' shift '.\Modules\StockRecon\Support\ShiftLabel::show($s->ShiftNo)
                            .', issue '.\App\Support\Format::qty($s->QtyIssued))->implode('; ').'.',
                ])
            @endif
            @if ($releasedSince->isNotEmpty())
                <p>{{ $releasedSince->count() }} released since this preview — preview again to let the chain commit.</p>
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
         does not.

         The Amended block carries the Original block's seven columns in the
         same order (Ryan, 16 Oct 2026), as the legacy screen does, so a figure
         is read straight across; Amend, which only the amended side has, sits
         after them. --}}
    @php($dash = fn ($v) => abs((float) $v) < 0.0005 ? '—' : \App\Support\Format::qty($v))
    {{-- An amended figure that is not the counted one is marked IN THE GRID
         (Ryan, 12 Nov 2026: "highlight difference between amended and original
         ... just in the grid"). The two blocks carry the same seven columns in
         the same order, and the reader was left to find the cells that moved
         by comparing them across the row. `title` carries the counted figure. --}}
    @php($moved = fn ($new, $was) => abs((float) $new - (float) $was) > 0.0005)
    @php($was = fn ($new, $old, bool $money = false) => abs((float) $new - (float) $old) > 0.0005
        ? 'Counted: '.($money ? \App\Support\Format::r($old) : \App\Support\Format::qty($old)) : null)
    <x-table layout-key="StockRecon.partials.chain-detail.1" :count="$shifts->count()" dense fit
             empty="This item has no other shift in the window.">
        <x-slot:head>
            <tr class="grp-head">
                <th class="l fit-min" colspan="3"></th>
                <th class="num grp" colspan="7">Original — as counted</th>
                <th class="num grp" colspan="8">Amended — as this run proposes</th>
                <th class="l"></th>
                @if ($canAdjust)<th class="fit-min"></th>@endif
            </tr>
            <tr>
                <th class="l fit-min">Date</th><th class="num">Shift</th><th class="l">On shift</th>
                <th class="num grp">Open</th><th class="num">Issued</th><th class="num">Close</th>
                <th class="num">POS</th><th class="num">Variance</th><th class="num">Value</th>
                <th class="num">Cumulative</th>
                <th class="num grp">Open</th><th class="num">Issued</th><th class="num">Close</th>
                <th class="num">POS</th><th class="num">Variance</th><th class="num">Value</th>
                <th class="num">Cumulative</th><th class="num">Amend</th>
                <th class="l">Outcome</th>
                @if ($canAdjust)<th class="fit-min"><span class="sr-only">Adjust</span></th>@endif
            </tr>
        </x-slot:head>

        @foreach ($shifts as $shift)
            {{-- The group it balances in, as on the proposals: rail and letter. --}}
            @php($group = $groups[(int) $shift->Id] ?? null)
            {{-- Till sales that never reached PumpIT (Ryan, 15 Nov 2026). --}}
            @php($posGap = \Modules\StockRecon\Services\StockReconService::posGap($posGaps ?? [], $shift->TransactionDate, $item?->AreaNo, $item?->StockItemNo, $shift->QtyPOS, $item?->StockLocation))
            {{-- The shift to look at, red (Ryan, 16 Nov 2026: "red row as in the
                 detail ... just bringing the users attention to it"): a missed
                 count Agora proposes to correct, or the shift that holds the
                 chain back. Attention only; it says nothing about the tick. --}}
            @php($lookHere = str_starts_with((string) $shift->Outcome, 'Missed count:')
                || ($item?->ChainBlocked && ! str_starts_with((string) $shift->Outcome, 'Held back: another shift')))
            <tr @class(['is-matched' => (bool) $shift->IsClicked && ! $lookHere, 'is-crit' => $lookHere, 'is-held-elsewhere' => $elsewhere->has((int) $shift->Id), 'bal-g'.($group['series'] ?? 0) => $group !== null])>
                <td class="l fit-min">{{ \Illuminate\Support\Carbon::parse($shift->TransactionDate)->format('d M') }}</td>
                <td class="num">{{ \Modules\StockRecon\Support\ShiftLabel::show($shift->ShiftNo) }}</td>
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
                    {{ \App\Support\Format::qty($shift->QtyOpen) }}
                    @if (abs((float) $shift->ChainBreak) > 0.0005)
                        <x-chip :tone="(float) $shift->ChainBreak < 0 ? 'crit' : 'warn'" :dot="false"
                                :title="'Previous closing '.\App\Support\Format::qty($shift->PrevClose)
                                    .($shift->BreakKept ? ' — small, the chain runs through it' : ' — the chain is split here')">
                            {{ (float) $shift->ChainBreak < 0 ? 'S10a' : 'S10b' }}
                            {{ \App\Support\Format::qty($shift->ChainBreak) }}{{ $shift->BreakKept ? ' kept' : '' }}
                        </x-chip>
                    @endif
                </td>
                <td class="num">{{ (float) $shift->QtyIssued === 0.0 ? '—' : \App\Support\Format::qty($shift->QtyIssued) }}</td>
                <td class="num">{{ \App\Support\Format::qty($shift->QtyClose) }}</td>
                <td @class(['num', 'pos-missing' => $posGap]) @if ($posGap) title="{{ $posGap }}" @endif>@if ($posGap)<span class="pos-missing-mark" aria-label="{{ $posGap }}">!</span>@endif{{ (float) $shift->QtyPOS === 0.0 ? '—' : \App\Support\Format::qty($shift->QtyPOS) }}</td>
                <td class="num">
                    @if ($group)
                        <span class="bal-tag" title="{{ \Modules\StockRecon\Services\ChainSummary::balanceNote($group) }}">{{ $group['letter'] }}</span>
                    @endif
                    {{ (float) $shift->QtyVar === 0.0 ? '—' : \App\Support\Format::qty($shift->QtyVar) }}
                </td>
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
                <td class="num muted">{{ abs((float) $shift->CumulativeVar) < 0.0005 ? '—' : \App\Support\Format::qty($shift->CumulativeVar) }}</td>
                <td @class(['num', 'grp', 'is-changed' => $moved($shift->QtyOpenNew, $shift->QtyOpen)]) title="{{ $was($shift->QtyOpenNew, $shift->QtyOpen) }}">{{ \App\Support\Format::qty($shift->QtyOpenNew) }}</td>
                <td @class(['num', 'is-changed' => $moved($shift->QtyIssuedAmended, $shift->QtyIssued)]) title="{{ $was($shift->QtyIssuedAmended, $shift->QtyIssued) }}">{{ $dash($shift->QtyIssuedAmended) }}</td>
                <td @class(['num', 'is-changed' => $moved($shift->QtyCloseNew, $shift->QtyClose)]) title="{{ $was($shift->QtyCloseNew, $shift->QtyClose) }}">{{ \App\Support\Format::qty($shift->QtyCloseNew) }}</td>
                <td @class(['num', 'pos-missing' => $posGap]) @if ($posGap) title="{{ $posGap }}" @endif>@if ($posGap)<span class="pos-missing-mark" aria-label="{{ $posGap }}">!</span>@endif{{ (float) $shift->QtyPOS === 0.0 ? '—' : \App\Support\Format::qty($shift->QtyPOS) }}</td>
                <td @class(['num', 'is-changed' => $moved($shift->QtyVarNew, $shift->QtyVar)]) title="{{ $was($shift->QtyVarNew, $shift->QtyVar) }}">{{ (float) $shift->QtyVarNew === 0.0 ? '—' : \App\Support\Format::qty($shift->QtyVarNew) }}</td>
                <td @class(['num', 'money', 'is-changed' => $moved($shift->QtyVarNew, $shift->QtyVar)]) title="{{ $was($shift->VarValueNew, $shift->VarValue, true) }}">{{ (float) $shift->QtyVarNew === 0.0 ? '—' : \App\Support\Format::r($shift->VarValueNew) }}</td>
                <td @class(['num', 'muted', 'is-changed' => $moved($shift->CumulativeVarNew, $shift->CumulativeVar)]) title="{{ $was($shift->CumulativeVarNew, $shift->CumulativeVar) }}">{{ $dash($shift->CumulativeVarNew) }}</td>
                <td class="num">{{ $dash($shift->AmendClose) }}</td>
                <td class="l muted" style="font-size:12px">
                    {{ $shift->Outcome }}
                    @if ($elsewhere->has((int) $shift->Id))
                        <a href="{{ route('app.stockrecon.run', $elsewhere->get((int) $shift->Id)) }}" class="held-elsewhere-link"><x-chip class="held-elsewhere" :dot="false">Amended on run #{{ $elsewhere->get((int) $shift->Id) }}</x-chip></a>
                    @endif
                    @if ((float) $shift->CurrentGoodWaste > 0 || (float) $shift->CurrentBadWaste > 0 || (float) $shift->PreviewAppliedGoodWaste > 0)
                        <div>Current captured waste: good {{ \App\Support\Format::qty($shift->CurrentGoodWaste) }}, bad {{ \App\Support\Format::qty($shift->CurrentBadWaste) }} · {{ $shift->CurrentWasteStatus }}.</div>
                        @if ($shift->CurrentWasteStatus === 'Authorized')
                            <div>Authorized in PumpIT{{ $shift->CurrentWasteAuthorisedBy ? ' by '.$shift->CurrentWasteAuthorisedBy : '' }}{{ $shift->CurrentWasteAuthorisedAt ? ' · '.\Illuminate\Support\Carbon::parse($shift->CurrentWasteAuthorisedAt)->format('Y-m-d H:i') : '' }}.</div>
                        @endif
                        <div>This preview: authorized {{ \App\Support\Format::qty($shift->PreviewAuthorizedGoodWaste) }}, applied {{ \App\Support\Format::qty($shift->PreviewAppliedGoodWaste) }}. Refresh the preview after an authorization changes.</div>
                        @can('reports.waste.view')
                            <a href="{{ route('app.reports.show', ['report' => 'waste', 'from' => $run->FromDate->format('Y-m-d'), 'to' => $run->ToDate->format('Y-m-d'), 'branches' => [$run->BranchId]]) }}">Review waste authorization</a>
                        @endcan
                    @endif
                    @if ($shift->IsDormant)<x-chip tone="neutral" :dot="false">dormant</x-chip>@endif
                    @if ($shift->AmendmentState === 'active')<x-chip tone="good" :dot="false">amended</x-chip>@endif
                    @if ($shift->AuditCode)
                        @include('stockrecon::partials.code', ['code' => $shift->AuditCode,
                            'title' => 'PumpIT holds '.\App\Support\Format::qty($shift->QtyOpenNow).' / '.\App\Support\Format::qty($shift->QtyCloseNow)])
                    @endif
                    {{-- A clear duplicate: the issue it takes out, or why it
                         does not — and its case on the run's panel. --}}
                    @if ($shift->DupTier === 'D1')
                        <x-chip :tone="$shift->QtyIssuedNew !== null ? 'good' : 'warn'" :dot="false"
                                :title="'Clear duplicate issue: the identical issue on '.$twinOf($shift).' arrived. '.($shift->DupReason ?? '')">
                            D1 {{ $shift->QtyIssuedNew !== null
                                ? 'issue '.\App\Support\Format::qty($shift->QtyIssued).' → '.\App\Support\Format::qty($shift->QtyIssuedNew)
                                : 'not removed' }}
                        </x-chip>
                        <a href="{{ $caseUrl($shift) }}">case</a>
                    @endif
                    {{-- An over balanced by capturing its missing issue (13 Oct 2026). --}}
                    @if ($shift->QtyIssuedNew !== null && $shift->DupTier !== 'D1')
                        <x-chip tone="good" :dot="false"
                                title="Agora proposes this issue adjustment; check the commit state to see whether it has been written. It is reversible from the run.">
                            @if ((float) $shift->QtyIssuedNew < (float) $shift->QtyIssued)
                                {{ $shift->AmendmentState === 'active' ? ($run->StampMode === 'live' ? 'Agora reduced issue' : 'Agora recorded issue reduction') : 'Agora proposes reducing issue' }}
                            @else
                                {{ $shift->AmendmentState === 'active' ? ($run->StampMode === 'live' ? 'Agora added missing issue' : 'Agora recorded missing issue') : 'Agora proposes missing issue' }}
                            @endif
                            {{ \App\Support\Format::qty($shift->QtyIssued) }} → {{ \App\Support\Format::qty($shift->QtyIssuedNew) }}
                        </x-chip>
                    @endif
                    {{-- A possible duplicate: its twin, why it is not clear, and
                         the release it has now — or the press that makes one. --}}
                    @if ($shift->DupTier === 'D2')
                        @php($twin = $shift->DupTwinDate ? \Illuminate\Support\Carbon::parse($shift->DupTwinDate)->format('d M').' shift '.\Modules\StockRecon\Support\ShiftLabel::show($shift->DupTwinShift) : 'not found')
                        @php($what = ($item->StockItemDescription ?? $line->StockItemNo).', '
                            .\Illuminate\Support\Carbon::parse($shift->TransactionDate)->format('d M').' shift '.\Modules\StockRecon\Support\ShiftLabel::show($shift->ShiftNo)
                            .', issue '.\App\Support\Format::qty($shift->QtyIssued).' — the same issue on '.$twin.'.')
                        <x-chip :tone="$shift->ReleaseId ? 'neutral' : 'serious'" :dot="false"
                                :title="'Possible duplicate issue: the same issue on '.$twin.'. '.($shift->DupReason ?? '')">
                            D2{{ $shift->ReleaseId ? ' released' : '' }}
                        </x-chip>
                        @if ($shift->ReleaseId)
                            <br>Released by {{ $shift->ReleasedBy ?? 'somebody' }},
                            {{ \App\Support\BusinessDate::show($shift->ReleasedAt, 'd M H:i') }}: {{ $shift->ReleaseReason }}
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
                {{-- The pencil (Ryan, 16 Nov 2026): this shift's closing, by hand,
                     with a reason. Not on the chain's last shift. --}}
                @if ($canAdjust)
                    <td class="fit-min">
                        @if (! $loop->last && in_array($shift->CommitState ?? 'pending', ['pending', 'skipped'], true))
                            <button type="button" class="btn-ghost btn-row adjust-pencil"
                                    data-modal-open="adjust-close"
                                    data-modal-url="{{ route('app.stockrecon.line.adjust.form', [$run, $shift->Id]) }}"
                                    title="Adjust this shift's closing count, with a reason"
                                    aria-label="Adjust the closing for {{ \Illuminate\Support\Carbon::parse($shift->TransactionDate)->format('d M') }} shift {{ \Modules\StockRecon\Support\ShiftLabel::show($shift->ShiftNo) }}">
                                <svg width="13" height="13" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M11.5 2.5l2 2L5 13H3v-2l8.5-8.5z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg>
                            </button>
                        @endif
                    </td>
                @endif
            </tr>
            @if ($lastHeldId !== null && (int) $shift->Id === $lastHeldId)
                <tr class="amend-boundary">
                    <td colspan="{{ $panelCols }}">
                        Amended up to {{ \Illuminate\Support\Carbon::parse($shift->TransactionDate)->format('d M') }} shift {{ \Modules\StockRecon\Support\ShiftLabel::show($shift->ShiftNo) }}
                        on run{{ $heldRuns->count() > 1 ? 's' : '' }}
                        @foreach ($heldRuns as $held)<a href="{{ route('app.stockrecon.run', $held) }}">#{{ $held }}</a>{{ $loop->last ? '' : ', ' }}@endforeach
                        · {{ $loop->last ? 'to the end of this chain' : 'this run proposes from here on' }}
                    </td>
                </tr>
            @endif
        @endforeach
    </x-table>

    {{-- Reverse this item (12 Nov 2026): every amendment this committed run
         still holds for the item in this area goes back to its recorded prior,
         and the rest of the run stays posted. One press under the grid; the
         confirmation names each PumpIT row and column it writes, old → new
         (confirm-diff.js). The fetched fragment sits inside the commit form,
         where a form of its own cannot live, so there it opens the chain page,
         which carries the form. --}}
    @php($reversible = $reversible ?? collect())
    @if ($run->isCommitted() && $reversible->isNotEmpty())
        @can('stockrecon.runs.reverse')
            @if ($fragment ?? false)
                <a class="btn-ghost" href="{{ route('app.stockrecon.line', [$run, $line]) }}#reverse-chain">Reverse this item…</a>
            @else
                {{-- Old is what the commit left, new the prior that goes back,
                     per column the reversal writes (the model's
                     reversalWrites()). One-line php directives only: this
                     file uses them, and Blade cannot mix them with a block. --}}
                @php($itemName = $item?->StockItemDescription ?? $line->StockItemNo)
                @php($live = $reversible->where('StampMode', 'live'))
                @php($journal = $reversible->count() - $live->count())
                @php($changes = $live->flatMap(fn ($a) => collect($a->reversalWrites())->map(fn ($p, $column) => [
                    'field' => $a->TransactionDate->format('d M').' shift '.\Modules\StockRecon\Support\ShiftLabel::show($a->ShiftNo).' · '.$column,
                    'old' => \App\Support\Format::qty($p[0]), 'new' => \App\Support\Format::qty($p[1])])->values()))
                @php($table = 'STK_StockReconLine · '.$itemName.', item '.$line->StockItemNo.', area '.$line->AreaNo.', site '.$run->BranchId)
                @php($note = ($journal > 0 ? $journal.' journal '.Str::plural('amendment', $journal).' reversed in Agora only. ' : '')
                    .'Every other item on run #'.$run->Id.' stays posted.')
                @php($confirmText = $live->isEmpty() ? 'Journal mode: nothing in PumpIT changes. '.$note
                    : $table."\n".$changes->map(fn ($c) => $c['field'].': '.$c['old'].' → '.$c['new'])->whenEmpty(fn ($c) => $c->push('Nothing will change.'))->implode("\n")."\n\n".$note)
                <form id="reverse-chain" method="POST" action="{{ route('app.stockrecon.lines.reverse', [$run, $line]) }}"
                      class="reverse-form" style="margin-top:8px"
                      data-loader="Reversing…"
                      data-confirm="Reverse {{ $itemName }} on run #{{ $run->Id }}?"
                      @if ($live->isNotEmpty()) data-diff="{{ $table }}" data-diff-note="{{ $note }}" @endif
                      data-confirm-text="{{ $confirmText }}"
                      data-confirm-action="Reverse" data-confirm-danger>
                    @csrf
                    @foreach ($changes as $change)
                        <input type="hidden" data-diff-field="{{ $change['field'] }}" data-diff-old="{{ $change['old'] }}" value="{{ $change['new'] }}">
                    @endforeach
                    <input type="text" name="reason" required maxlength="300"
                           placeholder="Why is this item being reversed?" aria-label="Reason for reversing this item">
                    <button type="submit" class="btn-ghost">Reverse this item</button>
                </form>
            @endif
        @endcan
    @endif

    {{-- Why the Cumulative column steps on a row whose Variance reads nothing.
         It runs on the balancing basis and ends on the chain total in the card
         above (Ryan, 6 Oct 2026: "balance to 0 but … show a movement of 1 in
         cumulative"); until then it ran over the counted variance and this line
         had to do the sum for the reader. The terms come from
         usp_StockRecon_DrillChain, which also says whether they add up: a run
         made before the method settled on 30 Sep 2026 was totalled another way,
         and gets a plain sentence instead of a sum that does not hold. --}}
    @php($signed = fn ($v) => ((float) $v > 0.0005 ? '+' : '').\App\Support\Format::qty($v))
    @php($openings = (int) ($item?->OpeningsCount ?? 0))
    @php($dups = (int) ($item?->DuplicatesCount ?? 0))
    @if ($item && ! $item->TotalReconciles)
        <p class="muted">
            This run's chain total was worked out by an earlier version of the balancing, so the Cumulative
            column does not end on it — preview again to see how the total is made up.
        </p>
    @elseif ($item && ($openings > 0 || $dups > 0))
        <p class="muted">
            Cumulative ends on the chain total, {{ \App\Support\Format::qty($item->ChainNetVar) }}. Besides each
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
