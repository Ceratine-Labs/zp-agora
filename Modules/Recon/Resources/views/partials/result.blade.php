{{--
    A preview's answer.

    Four outcomes, not two. The live AUTO RECON screen has a matched list and
    an unmatched list, and a bank line with no deposit behind it lands in the
    matched one — so the two counts that matter most here are the ones it
    cannot show: "bank only", which is the defect, and "deposit only", which
    it never reports at all.
--}}
@php
    $tones = [
        'matched' => 'is-matched', 'mismatch' => 'is-warn',
        'bank-only' => 'is-serious', 'ambiguous' => 'is-crit', 'unparseable' => 'is-crit',
    ];
@endphp

{{-- What each outcome means, and what a reversal does, is behind the info
     button beside the title (4 Oct 2026); the strip keeps only figures. --}}
<x-card :title="'Run #'.$run->Id"
        :sub="$run->FromDate->toDateString().' to '.$run->ToDate->toDateString().' · previewed in '.$run->PreviewMs.' ms'"
        flush info-wide>
    <x-slot:info>
        <p><strong>Amount mismatch</strong>: both sides present, totals differ. <strong>Bank only</strong>: no
           deposit behind the bank lines. <strong>Deposit only</strong>: deposits with no bank line.
           <strong>Other</strong>: an unresolved rule or reference.</p>
        <p><strong>Bank less deposits</strong>: negative is money the bank did not receive. Click a row to see
           the bank lines and deposits behind it.</p>
        <p>An executed run can be reversed from this page: every row goes back to what it held before, using
           the prior state recorded per row.</p>
    </x-slot:info>
    <x-statstrip :stats="[
        ['label' => 'Would reconcile', 'value' => \App\Support\Format::n($run->MatchedRows),
         'note' => \App\Support\Format::r($run->MatchedTotal), 'tone' => 'good'],
        ['label' => 'Amount mismatch', 'value' => \App\Support\Format::n($run->MismatchRows), 'tone' => 'warn'],
        ['label' => 'Bank only', 'value' => \App\Support\Format::n($run->BankOnlyRows), 'tone' => 'serious'],
        ['label' => 'Deposit only', 'value' => \App\Support\Format::n($run->DepositOnlyRows), 'tone' => 'neutral'],
        ['label' => 'Other', 'value' => \App\Support\Format::n($run->OtherRows), 'tone' => 'neutral'],
        ['label' => 'Bank side', 'value' => \App\Support\Format::r($run->BankTotal)],
        ['label' => 'Deposit side', 'value' => \App\Support\Format::r($run->MopsTotal)],
    ]" />

    @php($paired = $run->lines->filter->hasNearReference())
    @if ($paired->isNotEmpty())
        <x-notice tone="warn" collapsible style="margin:12px 14px 0"
                  :title="$paired->count().' '.Str::plural('row', $paired->count()).' paired on an inferred reference'">
            <p>Each was an unpaired bank line and an unpaired deposit, because the two sides extract the same
               reference to a different length. Joined into one row and judged like any other:
               <strong>{{ $paired->where('WouldReconcile', true)->count() }}</strong> now
               {{ $paired->where('WouldReconcile', true)->count() === 1 ? 'matches' : 'match' }},
               <strong>{{ $paired->where('WouldReconcile', false)->count() }}</strong> came out as amount
               mismatches.</p>
            <ul>
                @foreach ($paired->take(12) as $row)
                    <li><code>{{ $row->KeyRef }}</code> → <code>{{ $row->pairedReference() }}</code>
                        — {{ $row->Outcome }}</li>
                @endforeach
                @if ($paired->count() > 12)<li>… and {{ $paired->count() - 12 }} more.</li>@endif
            </ul>
            <p><strong>The pairing is an inference, not the configured rule.</strong> If setting
               <em>Deposit-side positions</em> the other way on the preview form makes these match, that is the
               reading this site wants and its configuration should be corrected.</p>
        </x-notice>
    @endif

    @if ($run->BankOnlyRows > 0)
        {{-- The count is the finding and stays on screen; the reasoning behind
             it is the same paragraph every time and folds away once read. --}}
        <x-notice tone="stop" collapsible style="margin:12px 14px 0"
                  :title="$run->BankOnlyRows.' bank '.Str::plural('line', $run->BankOnlyRows).' with no deposit behind '.($run->BankOnlyRows === 1 ? 'it' : 'them')">
            <p>The live procedure treats these as matched: its amount comparison falls through to the matched
               branch when there is no deposit. Executed there, each is stamped reconciled against nothing and
               drops out of the outstanding list.</p>
        </x-notice>
    @endif

    <form method="POST" action="{{ route('app.recon.execute', $run) }}" id="execute-{{ $run->Id }}"
          data-loader="{{ $stampMode === 'live' ? 'Reconciling in PumpIT…' : 'Recording…' }}"
          {{-- Names the table so the confirmation can say out loud that a
               column filter is holding rows back. Silent when none is. --}}
          data-confirm-filtered="recon-lines-{{ $run->Id }}"
          data-confirm="{{ $stampMode === 'live' ? 'Reconcile the selected batches in PumpIT?' : 'Record the selected batches?' }}"
          data-confirm-text="{{ $stampMode === 'live'
              ? 'This stamps ReconState and ReconBatchNo on the bank lines and ReconBatchNoPumpIT on the deposits, in the customer\'s live database. Every row is re-checked first, and anything that has moved since the preview is skipped. It can be reversed from this page.'
              : 'Journal mode: the decision is recorded here and nothing in PumpIT changes.' }}"
          data-confirm-action="{{ $stampMode === 'live' ? 'Reconcile' : 'Record' }}"
          @if ($stampMode === 'live') data-confirm-danger @endif>
    @csrf
    {{-- Inside the recon centre: back to its Auto tab, not to the run page. --}}
    @include('recon::partials.centre-back')

    @isset($extract)
        {{-- The same bar the grid shell puts above its table, and the same
             drawer behind the button. This screen cannot BE an <x-data-grid> —
             the tick boxes decide what a commit stamps and the rows expand —
             but the answer to "get me this in Excel" should not depend on
             that. See Modules/Recon/Grids/ReconRunLineGrid. --}}
        <div class="dg-bar" style="margin:12px 14px 0">
            <span class="dg-shown">{{ \App\Support\Format::n($run->lines->count()) }}
                {{ Str::plural('proposal', $run->lines->count()) }}</span>
            <span class="dg-bar-gap"></span>

            {{-- The two SIDES, for the whole run: every bank line behind every
                 proposal, and every deposit. A cash-bag run's deposits link says
                 so (`area`), and its file comes with each bag's cashier and the
                 split line it was accounted on (ReconMopsSideGrid); every other
                 area's link is what it always was. Instant on a committed run, which
                 reads agora.ReconMatch; on a preview each proposal has to be
                 drilled, so the button says how long that is likely to take
                 rather than looking broken while it does it. --}}
            @php($drilled = $run->Status !== 'committed')
            @php($seconds = (int) ceil($run->lines->count() * 0.2))
            @php($sideNote = $drilled ? ' — about '.$seconds.' seconds, because each proposal is re-read' : '')
            @php($bagArea = $run->ReconArea === 'CashBags' ? ['area' => 'CashBags'] : [])
            <span class="dg-sides">
                <span class="dg-sides-label">All rows, both sides</span>

                <span class="dg-side-pair">
                    <span class="dg-side-name">Bank</span>
                    <a class="btn-ghost sm" data-extract
                       href="{{ route('app.grids.extract', ['grid' => 'app.recon.run:bank', 'run' => $run->Id, 'format' => 'xlsx']) }}"
                       title="Every bank line behind every proposal on this run{{ $sideNote }}">.xlsx</a>
                    <a class="btn-ghost sm" data-extract
                       href="{{ route('app.grids.extract', ['grid' => 'app.recon.run:bank', 'run' => $run->Id, 'format' => 'csv']) }}"
                       title="Every bank line behind every proposal on this run{{ $sideNote }}">.csv</a>
                </span>

                <span class="dg-side-pair">
                    <span class="dg-side-name">Deposits</span>
                    <a class="btn-ghost sm" data-extract
                       href="{{ route('app.grids.extract', ['grid' => 'app.recon.run:mops', 'run' => $run->Id] + $bagArea + ['format' => 'xlsx']) }}"
                       title="Every deposit behind every proposal on this run{{ $sideNote }}">.xlsx</a>
                    <a class="btn-ghost sm" data-extract
                       href="{{ route('app.grids.extract', ['grid' => 'app.recon.run:mops', 'run' => $run->Id] + $bagArea + ['format' => 'csv']) }}"
                       title="Every deposit behind every proposal on this run{{ $sideNote }}">.csv</a>
                </span>
            </span>

            <button type="button" class="btn-ghost" data-drawer-open="dg-recon-{{ $run->Id }}-extract">Extract</button>
        </div>
    @endisset

    {{-- Which pending proposals another run has already committed since this
         preview — agora.usp_Recon_RunFreshness, read on every open. Empty on
         anything that is not an open preview. --}}
    @php($claimed = collect($freshness['claimed'] ?? []))

    @if ($run->Status === 'previewed')
        @php($ready = $run->lines->where('WouldReconcile', true)->where('CommitState', 'pending')->count())

        @if ($claimed->isNotEmpty())
            @php($summary = $freshness['summary'])
            @php($newest = $claimed->sortByDesc('ClaimedAt')->first())
            {{-- Before the press, not after it. The commit re-checks every row
                 and skips anything that moved, so nothing is ever stamped twice
                 — but until now it said so only once the button had been
                 pressed, and a clerk resuming an old preview found out that
                 most of it was already done from the refusal list. --}}
            <x-notice tone="warn" style="margin:12px 14px 0"
                      :title="$claimed->count().' of '.(int) $summary->Pending.' '.Str::plural('proposal', (int) $summary->Pending).' here '.($claimed->count() === 1 ? 'has' : 'have').' been reconciled by another run since this preview'">
                <p>Worth {{ \App\Support\Format::r($summary->ClaimedTotal) }}. Their ticks are off, so
                   Reconcile now offers the {{ \App\Support\Format::n((int) $summary->Pending - $claimed->count()) }}
                   that nobody has touched. The newest was run
                   <a href="{{ route('app.recon.run', (int) $newest->ClaimedByRunId) }}">#{{ $newest->ClaimedByRunId }}</a>{{ $newest->ClaimedBy ? ', by '.$newest->ClaimedBy : '' }},
                   {{ \Illuminate\Support\Carbon::parse($newest->ClaimedAt)->diffForHumans() }}.</p>
                <p class="field-help">A line stamped outside Agora is still caught on Reconcile, which re-checks
                   every row. For a clean list, preview the period again.</p>
            </x-notice>
        @endif

        {{-- The press, above the rows it acts on. It used to sit in the footer
             under the table, which on a month of ABSA is four hundred rows
             further down than the reader's eye ever goes. The count follows
             the ticks and the column filters — see <x-action-bar>. --}}
        <x-action-bar :for="'recon-lines-'.$run->Id">
            <button type="submit" form="execute-{{ $run->Id }}" class="btn-primary"
                    data-count-verb="{{ $stampMode === 'live' ? 'Reconcile' : 'Record' }}"
                    data-count-noun="batch" data-count-plural="batches"
                    @disabled($ready === 0)>
                {{ $stampMode === 'live' ? 'Reconcile' : 'Record' }}
                {{-- What is ticked on arrival, which is what the count on the
                     button tracks from here on. --}}
                {{ $ready - $claimed->count() }} {{ Str::plural('batch', $ready - $claimed->count()) }}
            </button>

            <x-info label="What this press does">
                @if ($stampMode === 'live')
                    <p>Stamps <code>ReconState</code> and <code>ReconBatchNo</code> on the bank lines in PumpIT,
                       and <code>ReconBatchNoPumpIT</code> on the deposits — on the ticked rows only.</p>
                    <p>Every row is re-checked first: anything reconciled elsewhere since the preview, or whose
                       two sides no longer balance, is skipped and reported. Reversible from this page.</p>
                @else
                    <p><strong>Journal mode:</strong> recorded in Agora only; nothing in PumpIT changes.</p>
                @endif
            </x-info>
        </x-action-bar>
    @endif

    {{-- `tools` gives the head a sort control and a filter row, applied in the
         browser over the rows already here. This table cannot be an
         <x-data-grid> — the tick boxes decide what a commit stamps and the
         rows expand — but "show me only the bank-only ones" is a question
         about rows on the page and should not cost a round trip. A filtered
         row's tick box is disabled, so it leaves the submission and the count
         above says so. --}}
    <x-table :count="$run->lines->count()"
             :procedure="$run->ProcedureName"
             :id="'recon-lines-'.$run->Id"
             tools
             data-row-detail
             empty="The procedure ran and found nothing in this period. That is an answer, not a failure.">
        <x-slot:head>
            <tr>
                @if ($run->Status === 'previewed')
                    {{-- Only reconcilable rows get a box. A tick on anything
                         else would be a promise the commit has to break. --}}
                    <th class="pick"><input type="checkbox" data-check-all aria-label="Select every reconcilable batch"></th>
                @endif
                <th>Outcome</th>
                <th>{{ $area['key_label'] }}</th>
                @if ($area['key2_label'])<th>{{ $area['key2_label'] }}</th>@endif
                @if ($run->ReconArea === 'FNB' || $run->ReconArea === 'CashBags')<th>Population</th>@endif
                <th class="num">Bank lines</th>
                <th class="num">Bank</th>
                @if ($run->ReconArea === 'ABSA')<th class="num">CC</th><th class="num">DD</th>@endif
                <th class="num">Deposits</th>
                <th class="num">Deposit</th>
                {{-- Bank less deposits, so negative is money the bank did not
                     receive: the sign the cash-bag split and the match message
                     use (Ryan, 27 Sep 2026). DiffAmount is stored the other
                     way round; ReconRunLine::bankLessDeposits() turns it. --}}
                <th class="num" title="The bank side less the deposits: negative is money the bank did not receive">Bank less deposits</th>
                <th>Rule</th>
            </tr>
        </x-slot:head>

        @foreach ($run->lines as $line)
            {{-- Click, or Enter, opens the rows behind the total. Fetched on
                 demand: a busy branch-month is a few hundred proposals. --}}
            <tr class="{{ $tones[$line->outcomeKey()] ?? '' }}"
                data-detail-url="{{ route('app.recon.line', [$run, $line]) }}">
                @if ($run->Status === 'previewed')
                    <td class="pick">
                        @if ($line->WouldReconcile && $line->CommitState === 'pending')
                            {{-- Unticked, not removed, where another run has
                                 committed the same reference since: a matching
                                 key is a strong sign, not proof, and the commit
                                 re-check has the final word. --}}
                            <input type="checkbox" name="lines[]" value="{{ $line->Id }}" data-check
                                   aria-label="Reconcile {{ $line->KeyRef }}" @checked(! $claimed->has($line->Id))>
                        @endif
                    </td>
                @endif
                <td>
                    <x-chip :tone="$line->tone()">{{ $line->Outcome }}</x-chip>
                    @if ($claimed->has($line->Id))
                        @php($claim = $claimed->get($line->Id))
                        <br><span class="drill-line-id">reconciled by run #{{ $claim->ClaimedByRunId }}
                            (batch {{ $claim->ClaimedBatchNo }}) since this preview</span>
                    @endif
                    @if ($line->CommitState === 'committed')
                        <br><span class="drill-line-id">batch {{ $line->ReconBatchNo }}</span>
                    @elseif ($line->CommitState === 'blocked')
                        <br><span class="drill-line-id">{{ $line->BlockReason }}</span>
                    @endif
                </td>
                <td class="mono">
                    {{ $line->KeyRef ?? '—' }}
                    @if ($line->pairedReference())
                        {{-- Both references, because the row is about both. The
                             arrow says which way round: the bank reads the
                             first, the deposits are filed under the second. --}}
                        <span class="paired-ref" title="{{ $line->NearRefNote }}">→ {{ $line->pairedReference() }}</span>
                        <x-chip tone="warn" title="{{ $line->NearRefNote }}">paired</x-chip>
                    @endif
                    @if ($line->BankNarrative)
                        <br><span class="muted" style="font-size:11px">{{ Str::limit($line->BankNarrative, 60) }}</span>
                    @elseif ($line->DeviceRefs)
                        <br><span class="muted" style="font-size:11px">{{ Str::limit($line->DeviceRefs, 60) }}</span>
                    @elseif ($line->WindowFrom)
                        <br><span class="muted" style="font-size:11px">{{ $line->WindowFrom->format('d M H:i') }} → {{ $line->WindowTo?->format('d M H:i') }}</span>
                    @endif
                </td>
                @if ($area['key2_label'])<td class="mono">{{ $line->KeyRef2 ?? '—' }}</td>@endif
                @if ($run->ReconArea === 'FNB' || $run->ReconArea === 'CashBags')
                    <td class="muted">{{ $line->Population ?? '—' }}</td>
                @endif
                <td class="num">{{ $line->BankLines ?: '—' }}</td>
                <td class="num">{{ $line->BankLines ? \App\Support\Format::r($line->BankTotal) : '—' }}</td>
                @if ($run->ReconArea === 'ABSA')
                    {{-- The procedure returns 0 on a row with no bank side at
                         all; nought rand did not settle, nothing did. --}}
                    <td class="num muted">{{ $line->BankLines ? \App\Support\Format::r($line->BankCC) : '—' }}</td>
                    <td class="num muted">{{ $line->BankLines ? \App\Support\Format::r($line->BankDD) : '—' }}</td>
                @endif
                <td class="num">{{ $line->MopsTxns ?: '—' }}</td>
                <td class="num">{{ $line->MopsTxns ? \App\Support\Format::r($line->MopsTotal) : '—' }}</td>
                <td class="num">{{ (float) $line->DiffAmount === 0.0 ? '—' : \App\Support\Format::r($line->bankLessDeposits()) }}</td>
                <td class="muted" style="font-size:11px">
                    @if ($line->UsedBankStart !== null)
                        {{-- Finding 11 — branch 7 extracting from past the end of its own
                             narratives — would have been visible at a glance with this on screen. --}}
                        pos {{ $line->UsedBankStart }}, len {{ $line->UsedBankLen }}
                        @if ($line->mixedRules())
                            <x-chip tone="warn">{{ $line->RulesInGroup }} rules</x-chip>
                        @endif
                    @else
                        —
                    @endif
                </td>
            </tr>
        @endforeach
    </x-table>

    </form>

    @isset($extract)
        @include('grid._extract', [
            'grid' => $extract,
            'columns' => $extract->visibleColumns(),
            'definition' => $extract->definition,
            'slug' => 'recon-'.$run->Id,
        ])
    @endisset

    {{-- What is left down here is what is NOT a press on the rows above: a
         reversal, which needs a typed reason and must not be one scroll away
         from the button that made the run, and the two states that are prose
         rather than a control. --}}
    @unless ($run->Status === 'previewed')
    <footer class="run-actions">
        @if ($run->Status === 'committed')
            <form method="POST" action="{{ route('app.recon.reverse', $run) }}" class="reverse-form" data-loader="Reversing…"
                  data-confirm="Reverse run #{{ $run->Id }}?"
                  data-confirm-text="Every bank line and deposit row this run stamped goes back to what it held before. The batch numbers are not reused."
                  data-confirm-action="Reverse" data-confirm-danger>
                @csrf
                @include('recon::partials.centre-back')
                <input type="text" name="reason" required maxlength="300"
                       placeholder="Why is this being reversed?" aria-label="Reason for the reversal">
                <button type="submit" class="btn-ghost">Reverse</button>
            </form>
            <p class="field-help">
                Committed {{ $run->CommittedAt?->diffForHumans() }} —
                {{ $run->CommittedRows }} {{ Str::plural('batch', $run->CommittedRows) }},
                {{ \App\Support\Format::r($run->CommittedTotal) }}{{ $run->StampMode === 'journal' ? ', journal only' : '' }}.
            </p>

        @elseif ($run->Status === 'reversed')
            <p class="field-help">
                Reversed {{ $run->ReversedAt?->diffForHumans() }} — {{ $run->ReversalReason }}.
                The proposals are pending again.
            </p>

        @elseif ($run->Status === 'closed')
            <p class="field-help">
                Marked complete{{ $run->UpdatedAt ? ' '.$run->UpdatedAt->diffForHumans() : '' }} — nothing was
                stamped from this run. Reopen it to reconcile anything on it.
            </p>
        @endif
    </footer>
    @endunless
</x-card>
