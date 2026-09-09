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
                <span class="dg-shown">{{ \App\Support\Format::n($run->lines->count()) }}
                    {{ Str::plural('shift', $run->lines->count()) }}</span>
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

        <x-table :count="$run->lines->count()"
                 :procedure="$run->ProcedureName"
                 :id="'stockrecon-lines-'.$run->Id"
                 tools
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
                    <th>On shift</th>
                    <th>Date</th>
                    <th class="num">Shift</th>
                    <th class="num">Open</th>
                    <th class="num">Issued</th>
                    <th class="num">Close</th>
                    <th class="num">POS</th>
                    <th class="num">Variance</th>
                    {{-- The balanced half. Named rather than grouped under a
                         spanning row: `tools` builds its sort control and its
                         filter row from the header cells, and a second header
                         row would give it two of everything. Two columns
                         called "Open" a few pixels apart is worse than a
                         longer word. --}}
                    <th class="num grp">New open</th>
                    <th class="num">New close</th>
                    <th class="num">Amend</th>
                    <th class="num">New variance</th>
                </tr>
            </x-slot:head>

            @foreach ($run->lines as $line)
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
                    <td>
                        <x-chip :tone="$line->tone()">{{ $line->Outcome }}</x-chip>
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
                         always had. --}}
                    <td class="cell-name">
                        {{ $line->itemLabel() }}
                        <br><span class="muted mono" style="font-size:11px">{{ $line->StockItemNo }} · {{ $line->areaLabel() }}</span>
                    </td>
                    <td class="cell-name">
                        @if ($line->EmployeeNames)
                            {{ $line->EmployeeNames }}
                            @if ($line->sharedShift())
                                {{-- Both names, and the fact that it was
                                     shared. A short on a shift two people
                                     worked cannot be put on either of them. --}}
                                <br><x-chip tone="warn" :dot="false">{{ $line->EmployeeCount }} on shift</x-chip>
                            @endif
                        @else
                            <span class="muted">—</span>
                        @endif
                    </td>
                    <td>{{ $line->TransactionDate->format('d M') }}</td>
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
