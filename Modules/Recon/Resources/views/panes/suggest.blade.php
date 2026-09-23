{{--
    The Suggestions tab: what the batch number could not pair, proposed by
    value.

    The step between the other two. Auto reconciliation settles what a batch
    number can reach; this proposes pairings for what is left — mostly FNB's
    'FN' lines, which carry no batch number at all — and the manual match is
    for whatever neither can reach.

    Everything is worked out in agora.usp_Recon_SuggestMatches, read live on
    every visit: the estate moves, and every accepted suggestion changes what
    is left. Accepting one posts it to the same route, the same procedure and
    the same ledger as a match made by hand, with its reading recorded on the
    run — so it is reversed by the same path and found by the same trace.

    STRONG means nothing else wants any of its rows. POSSIBLE means something
    does, or the batch number on the bank line disagrees; the row says which.
    Each tier has its own one-press "match all" (possible added on Ryan's ask,
    23 Sep 2026, looking at Ngwenya, where the till files deposits under a
    different merchant and batch numbering from the bank's, so every tie there
    is possible). They are two buttons, never one: the possible press is the
    weaker claim and its confirmation says so, and the run it writes records
    that the suggestion was a possible one and why.
--}}
@php
    $chosen = ($branchId ?? 0) > 0;
    $canMatch = (bool) auth()->user()?->can('recon.runs.execute');
    $live = $stampMode === 'live';
    $suggestions = collect($suggestions ?? []);
    $strong = $suggestions->where('Confidence', 'strong');
    $possible = $suggestions->where('Confidence', 'possible');
@endphp

@if (session('suggestMatched'))
    <x-notice tone="info" title="Matched" style="margin-bottom:16px">
        <p>{{ session('suggestMatched') }}
           @if (session('suggestRun'))<a href="{{ session('suggestRun') }}">Open the run</a> — it can be reversed from there.@endif</p>
    </x-notice>
@endif

@if (session('suggestRefusal'))
    <x-notice tone="stop" title="Nothing was matched" style="margin-bottom:16px">
        <p>{{ session('suggestRefusal') }}</p>
    </x-notice>
@endif

<x-card title="What to suggest for"
        sub="The site and the period. Both sides are read live, and nothing is written until a suggestion is accepted.">
    <form method="GET" action="{{ route('app.recon.suggest', $area['key']) }}">
        <div class="field-row">
            @if ($pinned)
                <div class="field">
                    <label>Site</label>
                    <p class="field-fixed">{{ $branches->firstWhere('BranchId', $branchId)?->Name ?? 'Not set' }}</p>
                    <p class="field-help">Your workspace is pinned to this site.</p>
                </div>
                <input type="hidden" name="branch_id" value="{{ $branchId }}">
            @else
                <x-field name="branch_id" label="Site"
                         :choices="['' => 'Choose a site'] + $branches->pluck('Name', 'BranchId')->all()"
                         :value="$branchId ?: ''"
                         help="Suggestions are always within one site." />
            @endif

            <x-field name="from" label="From" type="date" :value="$from"
                     help="Bank line dates. Deposits are read from a few days earlier, because a line settles takings from before it." />
            <x-field name="to" label="To" type="date" :value="$to" />
        </div>

        <div class="form-actions">
            <button type="submit" class="btn-primary">Suggest matches</button>
            <span class="field-help">Read-only. Takes a few seconds on a busy month.</span>
        </div>
    </form>
</x-card>

@if (! $chosen)
    <x-card title="Suggestions" sub="Pick a site above">
        <x-empty-state title="Choose a site first">
            <p>Suggestions pair one site's bank lines with that site's deposits. An empty list before a site
               is chosen would read as “nothing to suggest”, which is a different answer.</p>
        </x-empty-state>
    </x-card>
@else
    @if (($summary->BatchBankRows ?? 0) > 0)
        <x-notice tone="info" style="margin-bottom:16px"
                  :title="\App\Support\Format::n($summary->BatchBankRows).' '.Str::plural('bank line', (int) $summary->BatchBankRows).' settle by batch number and are left out here'">
            <p>{{ \App\Support\Format::r($summary->BatchBankTotal) }} on the statement matches its deposits on the
               batch number and the amount. Reconcile those on
               <a href="{{ route('app.recon.area', [$area['key'], 'branch_id' => $branchId, 'from' => $from, 'to' => $to]) }}">Auto reconciliation</a>
               — the suggestions below are only for what the batch number cannot pair.</p>
        </x-notice>
    @elseif (($summary->BatchPassRan ?? 1) == 0)
        <x-notice tone="info" style="margin-bottom:16px" title="This site has no FNB extraction rule">
            <p>Nothing here can settle by batch number, so every outstanding line is considered below.</p>
        </x-notice>
    @endif

    <x-card :title="'Suggested matches — '.$area['label']"
            sub="Exact to the cent. Every deposit is dated on or up to four days before its bank line. Click a row to see the lines and deposits behind it."
            flush>
        <x-statstrip :stats="[
            ['label' => 'Outstanding on the statement', 'value' => \App\Support\Format::n($summary->BankRows ?? 0),
             'note' => \App\Support\Format::r($summary->BankTotal ?? 0)],
            ['label' => 'Strong', 'value' => \App\Support\Format::n($summary->StrongSuggestions ?? 0),
             'note' => \App\Support\Format::n($summary->StrongBankRows ?? 0).' lines · '.\App\Support\Format::r($summary->StrongBankTotal ?? 0),
             'tone' => ($summary->StrongSuggestions ?? 0) > 0 ? 'good' : 'neutral'],
            ['label' => 'Possible', 'value' => \App\Support\Format::n($summary->PossibleSuggestions ?? 0),
             'note' => \App\Support\Format::n($summary->PossibleBankRows ?? 0).' lines · '.\App\Support\Format::r($summary->PossibleBankTotal ?? 0),
             'tone' => ($summary->PossibleSuggestions ?? 0) > 0 ? 'warn' : 'neutral'],
            ['label' => 'Left for manual match', 'value' => \App\Support\Format::n($summary->LeftBankRows ?? 0),
             'note' => \App\Support\Format::r($summary->LeftBankTotal ?? 0)],
        ]" />

        @if ($canMatch && $suggestions->isNotEmpty())
            {{-- One press per tier, each posting its suggestions ONE AT A TIME
                 from the browser — each is its own match, re-read and
                 re-checked by the procedure, and a refusal is that row's
                 answer rather than a dead page. Sequential for the same reason
                 the every-site runner is: this is the customer's live
                 database. No suggestion shares a row with another, so the two
                 presses can never claim the same row twice. --}}
            <x-action-bar data-suggest-bulk
                          data-suggest-live="{{ $live ? '1' : '0' }}">
                @if ($strong->isNotEmpty())
                    <button type="button" class="btn-primary" data-suggest-run="strong">
                        Match all {{ \App\Support\Format::n($strong->count()) }} strong
                    </button>
                @endif
                @if ($possible->isNotEmpty())
                    <button type="button" class="btn" data-suggest-run="possible">
                        Match all {{ \App\Support\Format::n($possible->count()) }} possible
                    </button>
                @endif
                <span class="field-help" data-suggest-status role="status"></span>

                <x-slot:note>
                    @if ($live)
                        Each suggestion is matched on its own, exactly as if it had been accepted by hand: its
                        rows are re-read first, anything reconciled since this list was drawn is refused rather
                        than stamped over, and each gets its own batch number and its own run, reversible from
                        that run. A column filter narrows what is matched. The possible press asks separately,
                        and each run it writes says the suggestion was a possible one and why.
                    @else
                        <strong>Journal mode.</strong> Matches are recorded here and nothing in PumpIT changes.
                    @endif
                </x-slot:note>
            </x-action-bar>
        @endif

        <x-table dense tools :count="$suggestions->count()" procedure="agora.usp_Recon_SuggestMatches"
                 id="recon-suggest" data-row-detail data-tt-page="50"
                 empty="Nothing to suggest. Every outstanding line either settles by batch number, or has no set of deposits that adds up to it to the cent.">
            <x-slot:head>
                <tr>
                    <th data-tt-pills="Confidence" data-tt-pill-order="Strong|Possible">Confidence</th>
                    <th class="fit-min">Bank date</th>
                    <th>Device or merchant</th>
                    <th class="num">Lines</th>
                    <th class="num">Bank</th>
                    <th class="fit-min">Takings</th>
                    <th>Batch</th>
                    <th class="num">Deposits</th>
                    <th class="num">Days</th>
                    <th>Reading</th>
                    @if ($canMatch)<th data-no-tools></th>@endif
                </tr>
            </x-slot:head>

            @foreach ($suggestions as $s)
                @php
                    $isStrong = $s->Confidence === 'strong';
                    $takings = substr((string) $s->MopsFrom, 0, 10) === substr((string) $s->MopsTo, 0, 10)
                        ? substr((string) $s->MopsFrom, 0, 10)
                        : substr((string) $s->MopsFrom, 0, 10).' – '.substr((string) $s->MopsTo, 5, 5);
                @endphp
                <tr data-detail-template="suggest-{{ $s->SuggestionNo }}" data-suggestion="{{ $s->SuggestionNo }}">
                    <td data-tone="{{ $isStrong ? 'good' : 'warn' }}">
                        <x-chip :tone="$isStrong ? 'good' : 'warn'">{{ $isStrong ? 'Strong' : 'Possible' }}</x-chip>
                    </td>
                    <td class="mono">{{ substr((string) $s->BankFrom, 0, 10) }}</td>
                    <td class="mono" style="font-size:11px">{{ Str::limit((string) $s->Devices, 40) }}</td>
                    <td class="num">{{ \App\Support\Format::n($s->BankLines) }}</td>
                    <td class="num">{{ \App\Support\Format::r($s->BankTotal) }}</td>
                    <td class="mono">{{ $takings }}</td>
                    <td class="mono" style="font-size:11px">{{ Str::limit((string) $s->Batches, 30) }}</td>
                    <td class="num">{{ \App\Support\Format::n($s->MopsRows) }}</td>
                    <td class="num">{{ $s->LagDays }}</td>
                    <td style="font-size:12px">
                        {{ $s->Basis }}
                        @if ($s->Caution)
                            <br><span class="muted">{{ $s->Caution }}</span>
                        @endif
                    </td>
                    @if ($canMatch)
                        <td data-suggest-state>
                            <form method="POST" action="{{ route('app.recon.match.save', $area['key']) }}"
                                  data-suggest-kind="{{ $isStrong ? 'strong' : 'possible' }}"
                                  data-amount="{{ $s->BankTotal }}"
                                  @if (! $isStrong) data-caution="{{ $s->Caution }}" @endif
                                  data-confirm="{{ $live ? 'Match this suggestion in PumpIT?' : 'Record this match?' }}"
                                  data-confirm-text="{{ $live
                                      ? $s->BankLines.' bank '.Str::plural('line', (int) $s->BankLines).' and '.$s->MopsRows.' '.Str::plural('deposit', (int) $s->MopsRows).', '.\App\Support\Format::r($s->BankTotal).' each side. Every row is re-read first, and it can be reversed from the run it creates.'
                                      : 'Journal mode: the match is recorded here and nothing in PumpIT changes.' }}"
                                  data-confirm-action="Match"
                                  @if ($live) data-confirm-danger @endif>
                                @csrf
                                <input type="hidden" name="branch_id" value="{{ $branchId }}">
                                {{-- The window the procedure needs to find every deposit
                                     in this suggestion — not the page's period, which
                                     may start after the takings did. --}}
                                <input type="hidden" name="from" value="{{ substr((string) $s->MatchFrom, 0, 10) }}">
                                <input type="hidden" name="to" value="{{ substr((string) $s->MatchTo, 0, 10) }}">
                                <input type="hidden" name="period_from" value="{{ $from }}">
                                <input type="hidden" name="period_to" value="{{ $to }}">
                                <input type="hidden" name="back" value="suggest">
                                {{-- A possible one says so on the run it writes, with the
                                     reason it was only possible — the ledger has to be able
                                     to tell the weaker claim from the strong one afterwards. --}}
                                <input type="hidden" name="basis"
                                       value="{{ $isStrong ? $s->Basis : $s->Basis.' — possible: '.$s->Caution }}">
                                @foreach ($s->bank as $line)
                                    <input type="hidden" name="bank[]" value="{{ $line->BankStatementLineID }}">
                                @endforeach
                                @foreach ($s->mops as $row)
                                    <input type="hidden" name="mops[]" value="{{ json_encode([
                                        'id' => null,
                                        'key' => $row->SourceKey,
                                        'dt' => substr((string) $row->SourceDate, 0, 10),
                                        'amt' => (float) $row->Amount,
                                    ]) }}">
                                @endforeach
                                <button type="submit" class="btn sm">Match</button>
                            </form>
                        </td>
                    @endif
                </tr>
            @endforeach
        </x-table>

        {{-- The rows behind each suggestion. They arrived with the page — one
             procedure call returns every suggestion and every member — so a
             row opens onto its template rather than asking the server again. --}}
        @foreach ($suggestions as $s)
            <template id="suggest-{{ $s->SuggestionNo }}">
                <div class="twopane" style="padding:8px 4px">
                    <div class="twopane-side">
                        <div class="twopane-head">
                            <h3>Bank statement</h3>
                            <span class="muted">{{ $s->BankLines }} · {{ \App\Support\Format::r($s->BankTotal) }}</span>
                        </div>
                        <table class="dt dense">
                            <thead><tr><th>Date</th><th>Narrative</th><th class="num">Amount</th></tr></thead>
                            <tbody>
                                @foreach ($s->bank as $line)
                                    <tr>
                                        <td class="mono">{{ substr((string) $line->SourceDate, 0, 10) }}</td>
                                        <td class="mono" style="font-size:11px">{{ $line->Description }}</td>
                                        <td class="num">{{ \App\Support\Format::r($line->Amount) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="twopane-side">
                        <div class="twopane-head">
                            <h3>Deposits</h3>
                            <span class="muted">{{ $s->MopsRows }} · {{ \App\Support\Format::r($s->MopsTotal) }}</span>
                        </div>
                        <table class="dt dense">
                            <thead><tr><th>Date</th><th>{{ $area['key_label'] }}</th><th>{{ $area['key2_label'] ?? 'Second' }}</th><th class="num">Amount</th></tr></thead>
                            <tbody>
                                @foreach ($s->mops as $row)
                                    <tr>
                                        <td class="mono">{{ substr((string) $row->SourceDate, 0, 10) }}</td>
                                        <td class="mono">{{ $row->SourceKey }}</td>
                                        <td class="mono" style="font-size:11px">{{ $row->Merchant ?? '—' }}</td>
                                        <td class="num">{{ \App\Support\Format::r($row->Amount) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </template>
        @endforeach
    </x-card>
@endif
