{{--
    Tab one: what the balancing proposes, and the press that writes it.

    The tick boxes decide what a commit writes, so this cannot be an
    <x-data-grid> — and it is deliberately not paged either: a paged commit form
    is a form that writes rows nobody looked at. `tools` gives the head a sort
    control and an Excel-style filter row applied IN THE BROWSER over the rows
    already here, and a filtered row's tick box is disabled, so it leaves the
    submission and the count on the button says so.
--}}
@php
    $tones = [
        'balanced' => 'is-matched', 'short' => 'is-warn', 'blocked' => 'is-serious',
        'unrecorded' => 'is-crit', 'dormant' => '', 'clean' => '',
    ];
    $ready = $run->lines->where('WouldAmend', true)->where('CommitState', 'pending')->count();

    /*
     * Chains that carry nothing: flagged too short to balance AND with a nil
     * total. Not the same as "too short" on its own — a chain with one active
     * shift and a real variance is also flagged short, and that one is the
     * accountable case, the short nobody can balance away. Hiding on the flag
     * would take it with them. See StockReconRunLine::isEmptyChain().
     */
    $hideEmpty = $hideEmpty ?? true;
    $emptyLines = $run->lines->filter(fn ($l) => $l->isEmptyChain());
    $emptyChains = $emptyLines->unique(fn ($l) => $l->AreaNo.'|'.$l->StockItemNo)->count();
    $rows = $hideEmpty ? $run->lines->reject(fn ($l) => $l->isEmptyChain()) : $run->lines;
@endphp

<x-card :title="'Proposals'"
        :sub="\App\Support\Format::n($run->TotalRows).' shifts across '.\App\Support\Format::n($run->ChainCount).' item chains · previewed in '.\App\Support\Format::n($run->PreviewMs).' ms'"
        flush>

    <x-statstrip :stats="[
        ['label' => 'Shifts to amend', 'value' => \App\Support\Format::n($run->AmendedRows),
         'note' => \App\Support\Format::n($run->UnitsAmended, 3).' units moved', 'tone' => 'good'],
        ['label' => 'Chains balanceable', 'value' => \App\Support\Format::n($run->BalanceableChains),
         'note' => 'of '.\App\Support\Format::n($run->ChainCount), 'tone' => 'good'],
        ['label' => 'Chains reported', 'value' => \App\Support\Format::n($run->BlockedChains),
         'note' => 'blocked whole, never half balanced', 'tone' => 'serious'],
        ['label' => 'Unrecorded issue', 'value' => \App\Support\Format::r($run->NetOverValue),
         'note' => \App\Support\Format::n($run->NetOverUnits, 3).' units no amendment can fix', 'tone' => 'warn'],
        ['label' => 'Shifts over', 'value' => \App\Support\Format::n($run->OverRowsBefore).' → '.\App\Support\Format::n($run->OverRowsAfter),
         'note' => 'an over is not credible: it is corrected', 'tone' => 'neutral'],
        ['label' => 'Shifts short', 'value' => \App\Support\Format::n($run->ShortRowsBefore).' → '.\App\Support\Format::n($run->ShortRowsAfter),
         'note' => \App\Support\Format::r($run->ShortValueAfter).' after — the floor, not a target', 'tone' => 'neutral'],
        ['label' => 'Dormant shifts', 'value' => \App\Support\Format::n($run->DormantRows),
         'note' => 'never charged, and they move as a block'],
    ]" />

    @if ($run->BlockedChains > 0)
        {{-- The count is the finding and stays on screen; the reasoning behind
             it is the same paragraph every time and folds away once read. --}}
        <x-notice tone="warn" collapsible style="margin:12px 14px 0"
                  :title="$run->BlockedChains.' '.Str::plural('chain', $run->BlockedChains).' reported rather than balanced'">
            <p>A chain is blocked <strong>whole</strong>: if any one of its shifts trips a test, none of it
               is amended. Half balancing around a fault is how a data problem gets dressed up as a
               performance figure.</p>
            <p>The reasons, worst first: the window ends <em>over</em> (nothing can fix that); a shift sold
               or closed holding more than it ever received; <strong>the chain is already broken in the
               source</strong> — an opening that is not the previous closing, which makes the whole method
               meaningless for that item; an amendment larger than a cap allows; or too few active shifts
               to move anything between.</p>
            <p>Open a row to see which shift did it and what the rest of the chain looks like.</p>
        </x-notice>
    @endif

    <form method="POST" action="{{ route('app.stockrecon.commit', $run) }}" id="commit-{{ $run->Id }}"
          {{-- Names the table so the confirmation can say out loud that a
               column filter is holding rows back. Silent when none is. --}}
          data-confirm-filtered="stockrecon-lines-{{ $run->Id }}"
          data-confirm="{{ $stampMode === 'live' ? 'Amend the ticked shifts in PumpIT?' : 'Record the ticked amendments?' }}"
          data-confirm-text="{{ $stampMode === 'live'
              ? 'This writes QtyOpen and QtyClose back to STK_StockReconLine in the customer\'s live database. Every shift is re-checked first and anything that has moved since the preview is skipped. Each row\'s prior pair is recorded, so it can be reversed exactly from this page.'
              : 'Journal mode: every amendment is recorded in Agora with the shift\'s prior counts beside the new ones, and nothing in PumpIT changes. The extract from this run is then the worklist.' }}"
          data-confirm-action="{{ $stampMode === 'live' ? 'Amend' : 'Record' }}"
          @if ($stampMode === 'live') data-confirm-danger @endif>
        @csrf

        @isset($extract)
            {{-- The same bar the grid shell puts above its table, and the same
                 drawer behind the button. This screen cannot BE an
                 <x-data-grid>, but the answer to "get me this in Excel" should
                 not depend on that — and in journal mode the extract IS the
                 deliverable. --}}
            <div class="dg-bar" style="margin:12px 14px 0">
                <span class="dg-shown">{{ \App\Support\Format::n($rows->count()) }}
                    {{ Str::plural('shift', $rows->count()) }}</span>
                <span class="dg-bar-gap"></span>
                <button type="button" class="btn-ghost" data-drawer-open="dg-stockrecon-{{ $run->Id }}-extract">Extract</button>
            </div>
        @endisset

        @if ($run->isOpen())
            {{-- The press, ABOVE the rows it acts on. It used to sit in a
                 footer under the table on every reconcile screen, which on a
                 branch-month is thousands of rows further down than the
                 reader's eye ever goes. The count follows the ticks and the
                 column filters — see <x-action-bar>. --}}
            <x-action-bar :for="'stockrecon-lines-'.$run->Id">
                <button type="submit" form="commit-{{ $run->Id }}" class="btn-primary"
                        data-count-verb="{{ $stampMode === 'live' ? 'Amend' : 'Record' }}"
                        data-count-noun="shift" data-count-plural="shifts"
                        @disabled($ready === 0)>
                    {{ $stampMode === 'live' ? 'Amend' : 'Record' }}
                    {{ $ready }} {{ Str::plural('shift', $ready) }}
                </button>

                <x-slot:note>
                    @if ($stampMode === 'live')
                        Writes <code>QtyOpen</code> and <code>QtyClose</code> on the recon line in PumpIT.
                        It acts on the ticked rows and no others. Every one is re-checked first: a shift
                        counted again since the preview is skipped and reported. Each row's prior pair is
                        stored, so this is reversible from this page — the legacy procedure can undo
                        nothing.
                    @else
                        <strong>Journal mode.</strong> Every amendment is recorded in Agora with the
                        shift's prior counts beside the new ones, and nothing in PumpIT changes.
                    @endif
                </x-slot:note>
            </x-action-bar>
        @endif

        @if ($emptyChains > 0)
            {{-- Counted out loud, with the way back. A screen that silently
                 decides what you are not allowed to see is worse than a long
                 one — and on a branch-month these are most of the length. --}}
            <p class="field-help" style="margin:10px 14px 0">
                {{ \App\Support\Format::n($emptyChains) }}
                {{ Str::plural('chain', $emptyChains) }}
                ({{ \App\Support\Format::n($emptyLines->count()) }}
                {{ Str::plural('shift', $emptyLines->count()) }})
                @if ($hideEmpty)
                    carried no movement and no variance and {{ $emptyChains === 1 ? 'is' : 'are' }} hidden —
                    <a href="{{ route('app.stockrecon.run', [$run, 'empty' => 'show']) }}">show them</a>.
                @else
                    carried no movement and no variance —
                    <a href="{{ route('app.stockrecon.run', $run) }}">hide them</a>.
                @endif
                A chain with one active shift and a real short stays on the list either way: that is
                the case nobody can balance, not an empty one.
            </p>
        @endif

        <x-table :count="$rows->count()"
                 :procedure="$run->ProcedureName"
                 :id="'stockrecon-lines-'.$run->Id"
                 tools fit
                 data-row-detail
                 empty="The procedure ran and found no shifts in this period. That is an answer, not a failure.">
            <x-slot:head>
                <tr>
                    @if ($run->isOpen())
                        {{-- Only a row the commit could honour gets a box. A
                             tick on anything else would be a promise the commit
                             has to break — and it is what makes the header box
                             mean "everything amendable" rather than
                             "everything". --}}
                        <th class="pick"><input type="checkbox" data-check-all aria-label="Select every amendable shift"></th>
                    @endif
                    <th>Outcome</th>
                    <th>Item</th>
                    {{-- `fit-min`: a date is short and must never wrap. Under
                         `fit` the surplus goes to the word columns, and without
                         this the browser squeezed "01 Sep" onto two lines and
                         the date set the row height. --}}
                    <th class="fit-min">Date</th>
                    <th class="num">Shift</th>
                    <th class="num">Open</th>
                    <th class="num">Issued</th>
                    <th class="num">Close</th>
                    <th class="num">POS</th>
                    <th class="num">Variance</th>
                    {{-- The balanced half, named rather than grouped.

                         NOT because `tools` forbids it — an earlier note here
                         said a second header row would give it two of
                         everything, and that is wrong: TableTools reads
                         `head.rows[LAST]` for its columns and only the sticky
                         height reads row 0, so a spanning row is available
                         here exactly as it is on the chain panel. The reason
                         is narrower: this table is sorted and filtered per
                         column, and two sortable columns both labelled "Open"
                         a few pixels apart is worse than one longer word. --}}
                    <th class="num grp">New open</th>
                    <th class="num">New close</th>
                    <th class="num">Amend</th>
                    <th class="num">New variance</th>
                    {{-- The per-row press. `data-no-tools` keeps the cell in
                         the filter row so the columns still line up, without
                         offering to sort or filter a column of buttons.
                         Deliberately unlabelled: the numeric "Amend" column is
                         three cells to the left and two headings reading
                         "Amend" would be worse than none. --}}
                    @if ($run->isOpen())
                        <th class="fit-min" data-no-tools></th>
                    @endif
                </tr>
            </x-slot:head>

            @foreach ($rows as $line)
                {{-- Click, or Enter, opens the whole chain behind the row.
                     Fetched on demand: a branch-month is thousands of shifts. --}}
                <tr class="{{ $tones[$line->outcomeKey()] ?? '' }}"
                    data-detail-url="{{ route('app.stockrecon.line', [$run, $line]) }}">
                    @if ($run->isOpen())
                        <td class="pick">
                            @if ($line->WouldAmend && $line->CommitState === 'pending')
                                <input type="checkbox" name="lines[]" value="{{ $line->Id }}" data-check
                                       aria-label="Amend {{ $line->StockItemNo }} on {{ $line->TransactionDate->toDateString() }} shift {{ $line->ShiftNo }}"
                                       @checked($line->Selected)>
                            @endif
                        </td>
                    @endif
                    {{-- The LABEL in the pill, the whole sentence on hover.
                         The procedure writes "Label: explanation" so the
                         customer can read the column in SSMS; a pill cannot
                         wrap without ceasing to look like one, and the longest
                         outcome is 67 characters. --}}
                    <td>
                        <x-chip :tone="$line->tone()" :title="$line->Outcome">{{ $line->outcomeLabel() }}</x-chip>
                        @if ($line->ExceptionCode)
                            <br><span class="drill-line-id">{{ $line->ExceptionCode }}</span>
                        @endif
                        @if ($line->CommitState === 'skipped')
                            <br><span class="drill-line-id">{{ $line->BlockReason }}</span>
                        @elseif ($line->CommitState === 'committed')
                            <br><span class="drill-line-id">amended</span>
                        @endif
                    </td>
                    {{-- The name leads and the number follows. It showed the
                         number over "area 1", which is two ids and no answer —
                         Ryan on the live screen, 9 Sep 2026. A run made before
                         v1__14a has no stored label and falls back to the id it
                         always had.

                         WHO WAS ON THE SHIFT IS NOT ON THIS TABLE, deliberately
                         (Ryan, same afternoon). It cost 469px — a third of the
                         table — to carry a crew list down a screen somebody
                         scrolls hundreds of rows of, on a table whose question
                         is "what will the commit write". It is on the CHAIN
                         panel behind the row, which is where a variance is
                         actually judged and where the count of distinct crews
                         already lives, and it stays in the extract, which is
                         the worklist an admin works line by line. --}}
                    <td class="cell-name">
                        {{ $line->itemLabel() }}
                        <br><span class="muted mono" style="font-size:11px">{{ $line->StockItemNo }} · {{ $line->areaLabel() }}</span>
                    </td>
                    <td class="fit-min">{{ $line->TransactionDate->format('d M') }}</td>
                    <td class="num">{{ $line->ShiftNo }}</td>
                    <td class="num">{{ \App\Support\Format::n($line->QtyOpen, 3) }}</td>
                    <td class="num">{{ (float) $line->QtyIssued === 0.0 ? '—' : \App\Support\Format::n($line->QtyIssued, 3) }}</td>
                    <td class="num">{{ \App\Support\Format::n($line->QtyClose, 3) }}</td>
                    <td class="num">{{ (float) $line->QtyPOS === 0.0 ? '—' : \App\Support\Format::n($line->QtyPOS, 3) }}</td>
                    <td class="num">{{ (float) $line->QtyVar === 0.0 ? '—' : \App\Support\Format::n($line->QtyVar, 3) }}</td>
                    <td class="num grp">{{ \App\Support\Format::n($line->QtyOpenNew, 3) }}</td>
                    <td class="num">{{ \App\Support\Format::n($line->QtyCloseNew, 3) }}</td>
                    <td class="num">{{ abs($line->amendment()) < 0.0005 ? '—' : \App\Support\Format::n($line->AmendClose, 3) }}</td>
                    <td class="num">{{ (float) $line->QtyVarNew === 0.0 ? '—' : \App\Support\Format::n($line->QtyVarNew, 3) }}</td>
                    @if ($run->isOpen())
                        {{-- AMEND THIS ONE SHIFT, without touching the ticks.

                             Every amendable row arrives ticked, so writing a
                             single shift used to mean unticking everything
                             else first. The button posts `only` with this
                             row's id and the commit narrows to it.

                             Same predicate as the tick box beside it and as
                             StockReconService::select(): a press the commit
                             would have to refuse is not offered. And it
                             carries its OWN confirmation, because "amend 24
                             shifts" is the wrong sentence to read immediately
                             before writing one. --}}
                        <td class="fit-min">
                            @if ($line->WouldAmend && ! $line->ChainBlocked && $line->CommitState === 'pending')
                                <button type="submit" name="only" value="{{ $line->Id }}"
                                        class="btn-ghost btn-row"
                                        data-confirm-single
                                        data-confirm="{{ $stampMode === 'live' ? 'Amend this one shift in PumpIT?' : 'Record this one amendment?' }}"
                                        data-confirm-text="{{ $line->StockItemDescription ?? $line->StockItemNo }} · {{ $line->TransactionDate->format('d M Y') }} shift {{ $line->ShiftNo }} · closing {{ \App\Support\Format::n($line->QtyClose, 3) }} becomes {{ \App\Support\Format::n($line->QtyCloseNew, 3) }}.{{ $stampMode === 'live' ? ' Nothing else on this run is written, and it is reversible from this page.' : ' Journal mode: nothing in PumpIT changes.' }}"
                                        data-confirm-action="{{ $stampMode === 'live' ? 'Amend' : 'Record' }}"
                                        @if ($stampMode === 'live') data-confirm-danger @endif
                                        aria-label="Amend {{ $line->StockItemNo }} on {{ $line->TransactionDate->toDateString() }} shift {{ $line->ShiftNo }}, this shift only">
                                    {{ $stampMode === 'live' ? 'Amend' : 'Record' }}
                                </button>
                            @endif
                        </td>
                    @endif
                </tr>
            @endforeach
        </x-table>
    </form>

    @isset($extract)
        @include('grid._extract', [
            'grid' => $extract,
            'columns' => $extract->visibleColumns(),
            'definition' => $extract->definition,
            'slug' => 'stockrecon-'.$run->Id,
        ])
    @endisset

    {{-- What is left down here is what is NOT a press on the rows above: the
         reversal, which needs a typed reason, and the two states that are prose
         rather than a control. --}}
    @if ($run->isCommitted())
        <footer class="run-actions">
            <form method="POST" action="{{ route('app.stockrecon.reverse', $run) }}" class="reverse-form"
                  data-confirm="Reverse run #{{ $run->Id }}?"
                  data-confirm-text="Every shift this run amended goes back to the counts it held before — the prior pair recorded per row, not the original counts, so an earlier hand amendment is not thrown away."
                  data-confirm-action="Reverse" data-confirm-danger>
                @csrf
                <input type="text" name="reason" required maxlength="300"
                       placeholder="Why is this being reversed?" aria-label="Reason for the reversal">
                <button type="submit" class="btn-ghost">Reverse</button>
            </form>
            <p class="field-help">
                Committed {{ $run->CommittedAt?->diffForHumans() }} —
                {{ \App\Support\Format::n($run->CommittedRows) }} {{ Str::plural('shift', $run->CommittedRows) }},
                {{ \App\Support\Format::n($run->CommittedUnits, 3) }} units{{ $run->StampMode === 'journal' ? ', recorded in Agora only' : ', written to PumpIT' }}.
                A reversal puts back exactly what was written, using the prior counts recorded per row.
                The legacy procedure has no reversal at all.
            </p>
        </footer>
    @elseif ($run->Status === 'reversed')
        <footer class="run-actions">
            <p class="field-help">
                Reversed {{ $run->ReversedAt?->diffForHumans() }} — {{ $run->ReversalReason }}.
                The proposals are pending again and can be committed once whatever caused it is dealt with.
            </p>
        </footer>
    @endif
</x-card>
