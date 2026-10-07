{{--
    Tab one: what the balancing proposes, and the press that writes it.

    The tick boxes decide what a commit writes, so this cannot be an
    <x-data-grid>. `tools` gives the head a sort control and an Excel-style
    filter row applied IN THE BROWSER over the rows already here, and a filtered
    row's tick box is disabled, so it leaves the submission and the count on the
    button says so.

    IT IS NOW PAGED, and the note that stood here said it must never be: "a
    paged commit form is a form that writes rows nobody looked at". That is true
    of SERVER paging, where page two is a second request and the rows on it are
    not in the document at all. This is browser paging over the set that already
    arrived complete: every row is in the form, a tick on page seven is still
    ticked, still counted on the button and still in the POST, and the only
    thing a page does is decide what is on screen. The customer asked for it
    (22 Sep 2026) because a branch-month is thousands of shifts and the browser
    was laying out every one of them.

    The outcome column is a PILL STRIP rather than a tick list — same customer,
    same day. The pills set the same `column.chosen` the tick list set, so
    scoping to a state disables the rows outside it exactly as any other filter
    does, and the Amend button's count follows, with the amber note in the
    action bar saying how many rows a scope is holding back. It opened scoped
    to Balanced until 7 Oct 2026; it opens on All now, and pills are several
    at once (Ryan: "default the pills to all, make them multiple selectable").
--}}
{{-- The loader stays up until this table is laid out (Ryan, 7 Oct 2026:
     "show the loader until the grid has been initialized when running a stock
     recon preview"). A branch-week is hundreds of chains and the browser spends
     seconds parsing, grouping and paging them; without this the cart came down
     on arrival and the page sat frozen and half drawn. The shell renders the
     overlay already showing, with these words — a request attribute, read
     after this pane has rendered, because the slot renders before the shell —
     and loader.js takes it down when table-tools marks the table ready. --}}
@php
    // Here, inside this block, and not as a one-line directive above it:
    // Blade pairs the first opening php directive in a file with the first
    // closing one, so a one-liner above a block swallows the block.
    request()->attributes->set('loader_hold', 'Laying out the chains…');

    $tones = [
        'balanced' => 'is-matched', 'duplicate' => 'is-matched', 'short' => 'is-warn',
        'possible' => 'is-serious', 'blocked' => 'is-serious',
        'restored' => 'is-warn', 'unrecorded' => 'is-crit', 'dormant' => '', 'clean' => '',
    ];

    /*
     * The STATE a row is in, which is not the same as its outcome sentence.
     * The procedure writes some twenty sentences; they collapse onto the nine
     * states StockReconRunLine::outcomeKey() already names and tone() already
     * colours, and nine is a strip of pills somebody reads at a glance where
     * twenty is another list. The label is what the pill says, what the column sorts on
     * and what the filter matches — all three, because `data-sort` is the one
     * value table-tools reads for a cell.
     */
    $states = [
        'balanced' => 'Balanced',
        /* A duplicate issue is its own state (5 Oct 2026). A clear one the
           commit takes out OPENS IN THE PRESS beside Balanced: its chain is
           written with it or not at all (usp_StockRecon_Commit skips the rest
           as "write that shift with it"), and it used to sit in Dormant, out
           of the press, whenever the shift sold nothing. */
        'duplicate' => 'Duplicate issue removed',
        'short' => 'Short',
        'possible' => 'Possible duplicate',
        'blocked' => 'Blocked',
        'restored' => 'Hand amendment reversed',
        'unrecorded' => 'Unrecorded issue',
        'dormant' => 'Dormant',
        'clean' => 'No change',
    ];
    // Worst-last, with the default first: the strip opens on the pill it is
    // scoped to, and the states it is hiding read left to right after it.
    $stateOrder = implode('|', $states);
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

    /*
     * Balanceable, but nothing to write: the balanced answer is what PumpIT
     * already holds — head office balanced it by hand. Said on the strip, or
     * "45 balanceable" beside "0 to amend" reads as a press that does not
     * work (Ryan, 7 Oct 2026). Reset to original takes the hand balancing off.
     */
    $toBalance = $run->lines
        ->filter(fn ($l) => $l->WouldAmend && ! $l->ChainBlocked)
        ->unique(fn ($l) => $l->AreaNo.'|'.$l->StockItemNo)
        ->count();

    $alreadyBalanced = $run->lines
        ->groupBy(fn ($l) => $l->AreaNo.'|'.$l->StockItemNo)
        ->filter(fn ($chain) => ! $chain->first()->ChainBlocked
            && ! $chain->contains(fn ($l) => $l->WouldAmend)
            && $chain->contains(fn ($l) => abs((float) $l->QtyCloseNew - (float) $l->QtyClose) > 0.0005
                || abs((float) $l->QtyOpenNew - (float) $l->QtyOpen) > 0.0005))
        ->count();
@endphp

<x-card :title="'Proposals'"
        :sub="\App\Support\Format::n($run->TotalRows).' shifts across '.\App\Support\Format::n($run->ChainCount).' item chains · previewed in '.\App\Support\Format::n($run->PreviewMs).' ms'"
        flush>

    <x-statstrip :stats="[
        ['label' => 'Shifts to amend', 'value' => \App\Support\Format::n($run->AmendedRows),
         'note' => \App\Support\Format::n($run->UnitsAmended, 3).' units moved', 'tone' => 'good'],
        /* Chains with something to WRITE, not chains nothing holds back: a
           chain short with no over, or one head office already balanced, is
           not held back and has nothing to balance — and counting those as
           balanceable is what read as a press that does not work (Ryan,
           7 Oct 2026, twice). */
        ['label' => 'Chains to balance', 'value' => \App\Support\Format::n($toBalance),
         'note' => \App\Support\Format::n($run->BalanceableChains).' not held back'
             .($alreadyBalanced > 0 ? ' · '.\App\Support\Format::n($alreadyBalanced).' already balanced in PumpIT' : ''),
         'tone' => 'good'],
        ['label' => 'Chains reported', 'value' => \App\Support\Format::n($run->BlockedChains), 'tone' => 'serious'],
        ['label' => 'Unrecorded issue', 'value' => \App\Support\Format::r($run->NetOverValue),
         'note' => \App\Support\Format::n($run->NetOverUnits, 3).' units', 'tone' => 'warn'],
        ['label' => 'Shifts over', 'value' => \App\Support\Format::n($run->OverRowsBefore).' → '.\App\Support\Format::n($run->OverRowsAfter),
         'tone' => 'neutral'],
        ['label' => 'Shifts short', 'value' => \App\Support\Format::n($run->ShortRowsBefore).' → '.\App\Support\Format::n($run->ShortRowsAfter),
         'note' => \App\Support\Format::r($run->ShortValueAfter).' after', 'tone' => 'neutral'],
        ['label' => 'Dormant shifts', 'value' => \App\Support\Format::n($run->DormantRows)],
        /* Kept breaks are the ones of KeepBreaksUpTo or fewer that the chain
           runs through (ZRP, 4 Oct 2026); the rest split their chain. */
        ['label' => 'Openings repaired', 'value' => \App\Support\Format::n($run->RepairedOpenings),
         'note' => $run->KeptBreaks > 0 ? \App\Support\Format::n($run->KeptBreaks).' small, chain kept' : null,
         'tone' => $run->RepairedOpenings > 0 ? 'warn' : 'neutral'],
        ['label' => 'Hand balancing breaches', 'value' => \App\Support\Format::n($run->AuditRows),
         'note' => $run->AuditRows > 0 ? 'see Exceptions' : null, 'tone' => $run->AuditRows > 0 ? 'serious' : 'neutral'],
    ]" />

    @if ($run->HeldBackRows > 0)
        {{-- Rule 10 of the client's pack. A data problem, not a finding, and
             the pack says to report it first. --}}
        <x-notice tone="stop" collapsible style="margin:12px 14px 0"
                  :title="\App\Support\Format::n($run->HeldBackRows).' shift lines held back from '.$run->CompleteTo?->copy()->addDay()->format('d M').' — the recon is not finished that day'">
            <p>A shift is missing or short of lines, closings are not captured, or POS is not posted. That day
               and every day after it are left out. Finish it at the site and preview again.</p>
        </x-notice>
    @endif

    @if ($run->BlockedChains > 0)
        {{-- The count is the finding and stays on screen; the reasoning behind
             it is the same paragraph every time and folds away once read. --}}
        <x-notice tone="warn" collapsible style="margin:12px 14px 0"
                  :title="$run->BlockedChains.' '.Str::plural('chain', $run->BlockedChains).' reported rather than balanced'">
            <p>If any shift trips a test, none of the chain is amended: no till sales linked, an issue that went
               nowhere, an amendment over a cap, too few active shifts, a possible duplicate issue, or a write the
               client's weekly audit would flag. Open a row to see which. A chain that totals nil is never held
               back by the first four: it balances, and the finding stays on the exceptions.</p>
            <p>A missing issue does not hold a chain back. The chain balances around it, and the over that is
               left stays on the shift that carries it, marked <strong>Unrecorded issue</strong>, to capture.</p>
        </x-notice>
    @endif

    {{-- Reset to original (Ryan, 7 Oct 2026: "update the amended with the
         original column values"). Its own form, because a form cannot sit
         inside the commit's; its button is in the action bar below. --}}
    @can('stockrecon.runs.execute')
        @if ($run->isOpen())
            <form method="POST" action="{{ route('app.stockrecon.reset', $run) }}" id="reset-{{ $run->Id }}"
                  data-loader="Putting the original counts back, then balancing again…"
                  data-confirm="{{ $stampMode === 'live' ? 'Reset these shifts to the original counts in PumpIT?' : 'Record a reset to the original counts?' }}"
                  data-confirm-text="{{ $stampMode === 'live'
                      ? 'Every shift on this run whose '.(config('stockrecon.reset_issues') ? 'open, issue or close' : 'open or close').' differs from the staff\'s original is set back to it in STK_StockReconLine, with the closing of the shift just before the week — head office\'s hand balancing comes off. A chain held by a possible duplicate is left alone. Each row\'s prior is recorded, so reversing this run puts it all back. The same window is then previewed again, to balance from.'
                      : 'Journal mode: the reset is recorded in Agora with each prior, and nothing in PumpIT changes.' }}"
                  data-confirm-action="Reset"
                  @if ($stampMode === 'live') data-confirm-danger @endif>
                @csrf
            </form>
        @endif
    @endcan

    <form method="POST" action="{{ route('app.stockrecon.commit', $run) }}" id="commit-{{ $run->Id }}"
          data-loader="{{ $stampMode === 'live' ? 'Amending in PumpIT…' : 'Recording the amendments…' }}"
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

                <x-info label="What this press does">
                    <p>A ticked shift on any page is in the press. Only the outcome pills and the column filters
                       take rows out of it.</p>
                    @if ($stampMode === 'live')
                        <p>Writes <code>QtyOpen</code> and <code>QtyClose</code> in PumpIT on the ticked rows only,
                           re-checking each first. Reversible from this page.</p>
                    @else
                        <p><strong>Journal mode:</strong> recorded in Agora only; nothing in PumpIT changes.</p>
                    @endif
                    <p><strong>Reset to original</strong> sets every shift on this run back to the staff's original
                       {{ config('stockrecon.reset_issues') ? 'open, issue and close' : 'open and close' }}, and the closing just
                       before the week, then previews again — so a chain head office balanced by hand is
                       balanced here. Reverse this run to undo it.</p>
                </x-info>

                @can('stockrecon.runs.execute')
                    <button type="submit" form="reset-{{ $run->Id }}" class="btn-ghost">Reset to original</button>
                @endcan
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
                    with no movement hidden —
                    <a href="{{ route('app.stockrecon.run', [$run, 'empty' => 'show']) }}">show them</a>.
                @else
                    with no movement —
                    <a href="{{ route('app.stockrecon.run', $run) }}">hide them</a>.
                @endif
            </p>
        @endif

        {{-- `data-tt-page`: browser paging over rows that are all already here.
             See the head of this file for why that is allowed on a form that
             stamps, and the head of table-tools.js for the line between a page
             and a filter. A Blade comment cannot go INSIDE a component tag —
             the tag compiler reads the attribute list with a regex — which is
             why this one is out here. --}}
        <x-table :count="$rows->count()"
                 :procedure="$run->ProcedureName"
                 :id="'stockrecon-lines-'.$run->Id"
                 tools fit
                 data-tt-page="50"
                 data-tt-groups
                 data-loader-hold
                 data-tt-group-noun="chain"
                 data-tt-row-noun="shift"
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
                    {{-- Filtered by the pill strip above the table, not by a
                         tick list in the filter row. It OPENS ON ALL (Ryan,
                         7 Oct 2026: "default the pills to all"); until then it
                         opened scoped to Balanced and a duplicate taken out.
                         Pills are several at once. The order is declared so a
                         run with no blocked chains still shows "Blocked 0" in
                         the same place rather than reshuffling the strip. --}}
                    <th data-tt-pills
                        data-tt-pill-order="{{ $stateOrder }}">Outcome</th>
                    <th>Item</th>
                    {{-- The item's chain total, and a second pill set in the
                         same row as Outcome: Positive, Short, Nil (Ryan, 7 Oct
                         2026: "show only positive chains or short chains").
                         The cell sorts and filters on the state, and shows the
                         figure. --}}
                    <th data-tt-pills data-tt-pills-join data-tt-pill-order="Positive|Short|Nil">Chain</th>
                    {{-- `fit-min`: a date is short and must never wrap. Under
                         `fit` the surplus goes to the word columns, and without
                         this the browser squeezed "01 Sep" onto two lines and
                         the date set the row height. --}}
                    <th class="fit-min">Date</th>
                    <th class="num">Shift</th>
                    <th class="num">Open</th>
                    {{-- How far the opening missed the previous closing. A
                         small one keeps the chain going but is still a figure
                         somebody has to explain (ZRP, 4 Oct 2026). --}}
                    <th class="num">Break</th>
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

            {{-- ONE ROW PER CHAIN, its shifts beneath it (Ryan, 7 Oct 2026:
                 "collapse them into a single row, can expand to detail and
                 expand again"). Collapsed on arrival. The chain row is not a
                 data row: the filters, the pills and the press act on the
                 shifts, and a chain shows while any of its shifts does
                 (table-tools `data-tt-groups`). Its figures: the first opening,
                 the last closing, and the sums between. --}}
            @foreach (\Modules\StockRecon\Services\ChainSummary::of($rows) as $chain)
                @php($state = $chain->state())
                @php($first = $chain->first())
                <tr class="tt-group" data-tt-group-head="{{ $chain->key }}" tabindex="0" aria-expanded="false">
                    @if ($run->isOpen())
                        <td class="pick">
                            @if ($chain->writable() > 0)
                                <input type="checkbox" data-tt-group-check
                                       aria-label="Every amendable shift of {{ $first->StockItemNo }}">
                            @endif
                        </td>
                    @endif
                    <td data-sort="{{ $state['label'] }}">
                        <x-chip :tone="$state['tone']" :title="$state['title']">{{ $state['label'] }}</x-chip>
                        {{-- A segment per shift, in order, in that shift's outcome
                             colour — the chain at a glance while it is shut. --}}
                        <span class="chain-bar" aria-hidden="true">
                            @foreach ($chain->segments() as $tone)<i class="tone-{{ $tone }}"></i>@endforeach
                        </span>
                    </td>
                    <td class="cell-name">
                        <span class="tt-caret" aria-hidden="true"></span>{{ $first->itemLabel() }}
                        <br><span class="muted mono" style="font-size:11px">{{ $first->StockItemNo }} · {{ $first->areaLabel() }} · {{ $chain->count() }} {{ Str::plural('shift', $chain->count()) }}</span>
                    </td>
                    <td class="num" data-sort="{{ (float) $first->ChainNetVar }}">
                        {{ $first->chainState() === 'Nil' ? '—' : ($first->chainState() === 'Positive' ? '+' : '').\App\Support\Format::n($first->ChainNetVar, 3) }}
                    </td>
                    <td class="fit-min" data-sort="{{ $chain->from()->toDateString() }}">
                        {{ $chain->from()->format('d M') }}{{ $chain->to()->ne($chain->from()) ? ' – '.$chain->to()->format('d M') : '' }}
                    </td>
                    <td class="num">{{ $chain->count() }}</td>
                    <td class="num">{{ \App\Support\Format::n($first->QtyOpen, 3) }}</td>
                    <td class="num">{{ abs($chain->breaks()) > 0.0005 ? \App\Support\Format::n($chain->breaks(), 3) : '—' }}</td>
                    <td class="num">{{ abs($chain->issued()) < 0.0005 ? '—' : \App\Support\Format::n($chain->issued(), 3) }}</td>
                    <td class="num">{{ \App\Support\Format::n($chain->last()->QtyClose, 3) }}</td>
                    <td class="num">{{ abs($chain->pos()) < 0.0005 ? '—' : \App\Support\Format::n($chain->pos(), 3) }}</td>
                    <td class="num">{{ abs($chain->variance()) < 0.0005 ? '—' : \App\Support\Format::n($chain->variance(), 3) }}</td>
                    <td class="num grp">{{ \App\Support\Format::n($first->QtyOpenNew, 3) }}</td>
                    <td class="num">{{ \App\Support\Format::n($chain->last()->QtyCloseNew, 3) }}</td>
                    <td class="num" title="Units the balancing moves on this chain, either way">{{ $chain->moved() < 0.0005 ? '—' : \App\Support\Format::n($chain->moved(), 3) }}</td>
                    <td class="num">{{ abs($chain->varianceNew()) < 0.0005 ? '—' : \App\Support\Format::n($chain->varianceNew(), 3) }}</td>
                    @if ($run->isOpen())
                        <td class="fit-min"></td>
                    @endif
                </tr>
            {{-- Which shifts balance against which (ZRP, 7 Oct 2026): a rail
                 down the left in the group's colour and its letter beside the
                 variance, the sentence on hover. ChainSummary::balanceGroups()
                 reads it off the amendments. --}}
            @php($groups = $chain->balanceGroups())
            @foreach ($chain->lines as $line)
                @php($group = $groups[$line->Id] ?? null)
                {{-- Click, or Enter, opens the whole chain behind the row.
                     Fetched on demand: a branch-month is thousands of shifts. --}}
                <tr class="{{ $tones[$line->outcomeKey()] ?? '' }}{{ $group ? ' bal-g'.$group['series'] : '' }}"
                    data-tt-group="{{ $chain->key }}"
                    data-detail-url="{{ route('app.stockrecon.line', [$run, $line]) }}">
                    @if ($run->isOpen())
                        <td class="pick">
                            @if ($line->isWritable())
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
                    {{-- `data-sort` is the STATE, not the rendered text: the
                         cell also carries the exception code and the commit
                         result, and a filter that matched "Balanced A3 amended"
                         would match nothing anybody would type. `data-tone`
                         hands the pill the same colour the chip has. --}}
                    <td data-sort="{{ $states[$line->outcomeKey()] ?? $line->outcomeKey() }}"
                        data-tone="{{ $line->tone() }}">
                        <x-chip :tone="$line->tone()" :title="$line->Outcome">{{ $line->outcomeLabel() }}</x-chip>
                        @if ($line->ExceptionCode)
                            <br>@include('stockrecon::partials.code', ['code' => $line->ExceptionCode])
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
                    <td class="num" data-sort="{{ $line->chainState() }}"
                        data-tone="{{ ['Positive' => 'crit', 'Short' => 'warn'][$line->chainState()] ?? 'neutral' }}"
                        title="Chain total {{ \App\Support\Format::n($line->ChainNetVar, 3) }}">
                        {{ $line->chainState() === 'Nil' ? '—' : ($line->chainState() === 'Positive' ? '+' : '').\App\Support\Format::n($line->ChainNetVar, 3) }}
                    </td>
                    <td class="fit-min">{{ $line->TransactionDate->format('d M') }}</td>
                    <td class="num">{{ $line->ShiftNo }}</td>
                    <td class="num">{{ \App\Support\Format::n($line->QtyOpen, 3) }}</td>
                    <td class="num" @if (abs((float) $line->ChainBreak) > 0.0005) title="Previous closing {{ \App\Support\Format::n($line->PrevClose, 3) }}{{ $line->BreakKept ? ' — kept in the chain' : ' — the chain is split here' }}" @endif>
                        {{ abs((float) $line->ChainBreak) > 0.0005 ? \App\Support\Format::n($line->ChainBreak, 3) : '—' }}
                    </td>
                    <td class="num">{{ (float) $line->QtyIssued === 0.0 ? '—' : \App\Support\Format::n($line->QtyIssued, 3) }}</td>
                    <td class="num">{{ \App\Support\Format::n($line->QtyClose, 3) }}</td>
                    <td class="num">{{ (float) $line->QtyPOS === 0.0 ? '—' : \App\Support\Format::n($line->QtyPOS, 3) }}</td>
                    {{-- `data-sort` so the letter beside the figure never reaches
                         the sort or the filter. --}}
                    <td class="num" @if ($group) data-sort="{{ (float) $line->QtyVar === 0.0 ? '—' : \App\Support\Format::n($line->QtyVar, 3) }}" @endif>
                        @if ($group)
                            <span class="bal-tag" title="{{ \Modules\StockRecon\Services\ChainSummary::balanceNote($group) }}">{{ $group['letter'] }}</span>
                        @endif
                        {{ (float) $line->QtyVar === 0.0 ? '—' : \App\Support\Format::n($line->QtyVar, 3) }}
                    </td>
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
                            @if ($line->isWritable())
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
                  data-loader="Reversing…"
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
            </p>
        </footer>
    @elseif ($run->Status === 'reversed')
        <footer class="run-actions">
            <p class="field-help">
                Reversed {{ $run->ReversedAt?->diffForHumans() }} — {{ $run->ReversalReason }}.
                The proposals are pending again.
            </p>
        </footer>
    @endif
</x-card>
