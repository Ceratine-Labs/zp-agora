{{--
    What one proposal is actually made of.

    Two columns, side by side, because that is the question: these bank lines
    against those deposits. Where one side is empty the panel says so in words
    rather than showing an empty table — "no deposit behind this" IS the
    finding on a bank-only row, and an empty column reads like a loading state.
--}}
@php
    /*
     * Rows something else has reconciled since the preview are SEPARATED, not
     * merged and not hidden.
     *
     * Hidden (the behaviour until 9 Sep 2026) they looked like rows that never
     * existed, and the two prose blocks below then said "nothing carries this
     * reference" and "nothing was declared" about run #260's batch 904 — whose
     * 17 deposits and 4 bank lines had every one been stamped, as batches
     * 125384 and 125385, after the preview was taken.
     *
     * Merged they would be a different lie: usp_Recon_Commit's run-38 note
     * records that a widened drill also returns OTHER rows sharing the key and
     * the window which were never part of this proposal. So they are listed
     * apart, under what claimed them, exactly as the commit moves them into
     * @BankClaimed rather than counting them.
     */
    $bankOpen = $bank->filter(fn ($r) => ! isset($r->ReconState) || (int) $r->ReconState === 1);
    $bankGone = $bank->reject(fn ($r) => ! isset($r->ReconState) || (int) $r->ReconState === 1);
    $mopsOpen = $mops->filter(fn ($r) => ! isset($r->ReconBatchNoPumpIT) || (int) $r->ReconBatchNoPumpIT === 0);
    $mopsGone = $mops->reject(fn ($r) => ! isset($r->ReconBatchNoPumpIT) || (int) $r->ReconBatchNoPumpIT === 0);
@endphp

@if ($bankGone->isNotEmpty() || $mopsGone->isNotEmpty())
    <p class="drill-claimed-note">
        <strong>Something reconciled part of this since the preview was taken.</strong>
        @if ($bankGone->isNotEmpty())
            {{ $bankGone->count() }} bank {{ Str::plural('line', $bankGone->count()) }}
            ({{ \App\Support\Format::r($bankGone->sum('Amount')) }})
        @endif
        @if ($bankGone->isNotEmpty() && $mopsGone->isNotEmpty()) and @endif
        @if ($mopsGone->isNotEmpty())
            {{ $mopsGone->count() }} {{ Str::plural('deposit', $mopsGone->count()) }}
            ({{ \App\Support\Format::r($mopsGone->sum('Amount')) }})
        @endif
        carrying this reference {{ ($bankGone->count() + $mopsGone->count()) === 1 ? 'is' : 'are' }}
        no longer outstanding. They are listed below under the batch that claimed them.
        Execute acts only on what is still open, so this proposal will stamp
        {{ $bankOpen->count() }} bank {{ Str::plural('line', $bankOpen->count()) }}
        against {{ $mopsOpen->count() }} {{ Str::plural('deposit', $mopsOpen->count()) }}, not the
        figures the run recorded when it was previewed.
    </p>
@endif

<div class="drill">
    <div class="drill-side">
        <h4>Bank {{ Str::plural('line', $bankOpen->count()) }}
            <span class="muted">{{ $bankOpen->count() }} · {{ \App\Support\Format::r($bankOpen->sum('Amount')) }}</span>
            @if ($bank->isNotEmpty())
                {{-- This proposal's own rows, not the run's. Scoped by `line`,
                     so it costs one drill and comes back immediately. --}}
                <span class="drill-export">
                    <a href="{{ route('app.grids.extract', ['grid' => 'app.recon.run:bank', 'line' => $line->Id, 'format' => 'xlsx']) }}"
                       data-extract title="This proposal's bank lines as .xlsx">.xlsx</a>
                    <a href="{{ route('app.grids.extract', ['grid' => 'app.recon.run:bank', 'line' => $line->Id, 'format' => 'csv']) }}"
                       data-extract title="This proposal's bank lines as .csv">.csv</a>
                </span>
            @endif
        </h4>

        @if ($bankOpen->isEmpty() && $bankGone->isNotEmpty())
            <p class="drill-none">Every bank line carrying this reference has already been
               reconciled — see the list below. The statement did settle this; something got to it
               before this preview was executed.</p>
        @elseif ($bank->isEmpty() && $line->isCommitted())
            <p class="drill-none">This batch was stamped, but no bank lines were recorded against it
               in <code>agora.ReconMatch</code>. That should not happen — the commit writes both
               sides together — so it is worth reporting rather than reading as "nothing settled".</p>
        @elseif ($bank->isEmpty())
            <p class="drill-none">Nothing on the statement carries this reference in the period —
               the deposit was captured but the bank has not settled it, or it settled under a
               reference the configured extraction does not produce.</p>
        @elseif ($bankOpen->isNotEmpty())
            <table class="dt dense">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Narrative</th>
                        @if ($run->ReconArea === 'ABSA')<th>Leg</th>@endif
                        <th class="num">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($bankOpen as $row)
                        <tr>
                            <td class="mono">{{ \Illuminate\Support\Carbon::parse($row->LineDate)->format('d M Y') }}</td>
                            <td class="mono drill-narrative" title="{{ $row->Description }}">
                                {{-- The extracted reference is marked inside the narrative, so
                                     an extraction pointing at the wrong characters is visible
                                     rather than inferred. Finding 11 — branch 7 reading from
                                     past the end of its own 32-character narratives — is this,
                                     in one glance. --}}
                                @php($d = (string) $row->Description)
                                @php($start = (int) $row->UsedBankStart)
                                @php($len = (int) $row->UsedBankLen)
                                @if ($start > 0 && $len > 0 && $start <= strlen($d))
                                    <span class="muted">{{ Str::substr($d, 0, $start - 1) }}</span><mark>{{ Str::substr($d, $start - 1, $len) }}</mark><span class="muted">{{ Str::substr($d, $start - 1 + $len) }}</span>
                                @else
                                    <span class="muted">{{ $d }}</span>
                                @endif
                                <br>
                                <span class="drill-line-id">line {{ $row->BankStatementLineID }}</span>
                            </td>
                            @if ($run->ReconArea === 'ABSA')<td>{{ $row->Leg ?: '—' }}</td>@endif
                            <td class="num">{{ \App\Support\Format::r($row->Amount) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        @if ($bankGone->isNotEmpty())
            <table class="dt dense drill-gone">
                <thead>
                    <tr><th>Date</th><th>Narrative</th><th>Claimed by</th><th class="num">Amount</th></tr>
                </thead>
                <tbody>
                    @foreach ($bankGone as $row)
                        <tr>
                            <td class="mono">{{ \Illuminate\Support\Carbon::parse($row->LineDate)->format('d M Y') }}</td>
                            <td class="mono drill-narrative" title="{{ $row->Description }}">{{ $row->Description }}<br>
                                <span class="drill-line-id">line {{ $row->BankStatementLineID }}</span></td>
                            <td class="mono">{{ $row->ReconBatchNo ? 'batch '.$row->ReconBatchNo : 'reconciled' }}</td>
                            <td class="num">{{ \App\Support\Format::r($row->Amount) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div class="drill-side">
        @if ($line->pairedReference())
            <p class="drill-paired">Found under <code>{{ $line->pairedReference() }}</code>, not
               <code>{{ $line->KeyRef }}</code> — {{ $line->NearRefNote }}</p>
        @endif
        <h4>{{ Str::plural('Deposit', $mopsOpen->count()) }}
            <span class="muted">{{ $mopsOpen->count() }} · {{ \App\Support\Format::r($mopsOpen->sum('Amount')) }}</span>
            @if ($mops->isNotEmpty())
                <span class="drill-export">
                    <a href="{{ route('app.grids.extract', ['grid' => 'app.recon.run:mops', 'line' => $line->Id, 'format' => 'xlsx']) }}"
                       data-extract title="This proposal's deposits as .xlsx">.xlsx</a>
                    <a href="{{ route('app.grids.extract', ['grid' => 'app.recon.run:mops', 'line' => $line->Id, 'format' => 'csv']) }}"
                       data-extract title="This proposal's deposits as .csv">.csv</a>
                </span>
            @endif
        </h4>

        @if ($mopsOpen->isEmpty() && $mopsGone->isNotEmpty())
            <p class="drill-none">Every deposit carrying this reference has already been
               reconciled — see the list below. They were declared; they are simply not outstanding
               any more.</p>
        @elseif ($mops->isEmpty() && $line->isCommitted())
            <p class="drill-none">This batch was stamped, but no deposit rows were recorded against
               it in <code>agora.ReconMatch</code>. That should not happen — the commit writes both
               sides together — so it is worth reporting rather than reading as "nothing was
               declared".</p>
        @elseif ($mops->isEmpty())
            <p class="drill-none">
                <strong>Nothing was declared against this reference.</strong>
                The live procedure reaches its matched branch here — its comparison against a
                missing deposit is neither true nor false — and under Execute it would stamp the
                bank {{ Str::plural('line', $bank->count()) }} above reconciled against nothing.
            </p>
        @elseif ($mopsOpen->isNotEmpty())
            <table class="dt dense">
                <thead>
                    <tr><th>Date</th><th>Reference</th><th class="num">Amount</th></tr>
                </thead>
                <tbody>
                    @foreach ($mopsOpen as $row)
                        <tr>
                            <td class="mono">{{ $row->SourceDate ? \Illuminate\Support\Carbon::parse($row->SourceDate)->format('d M Y') : '—' }}</td>
                            <td class="mono">{{ $row->SourceRef ?: '—' }}
                                @if ($row->Detail)<br><span class="drill-line-id">{{ $row->Detail }}</span>@endif
                            </td>
                            <td class="num">{{ \App\Support\Format::r($row->Amount) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        @if ($mopsGone->isNotEmpty())
            <table class="dt dense drill-gone">
                <thead>
                    <tr><th>Date</th><th>Reference</th><th>Claimed by</th><th class="num">Amount</th></tr>
                </thead>
                <tbody>
                    @foreach ($mopsGone as $row)
                        <tr>
                            <td class="mono">{{ $row->SourceDate ? \Illuminate\Support\Carbon::parse($row->SourceDate)->format('d M Y') : '—' }}</td>
                            <td class="mono">{{ $row->SourceRef ?: '—' }}
                                @if ($row->Detail)<br><span class="drill-line-id">{{ $row->Detail }}</span>@endif
                            </td>
                            <td class="mono">batch {{ $row->ReconBatchNoPumpIT }}</td>
                            <td class="num">{{ \App\Support\Format::r($row->Amount) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <footer class="drill-foot">
        <span>{{ $line->Outcome }}</span>
        @if ($line->UsedBankStart !== null)
            <span>·</span>
            <span>rule {{ $line->UsedProcessOrder }}, characters {{ $line->UsedBankStart }}–{{ $line->UsedBankStart + $line->UsedBankLen - 1 }} of the narrative</span>
        @endif
        @if ($line->mixedRules())
            <span>·</span>
            <span class="drill-warn">{{ $line->RulesInGroup }} different rules resolved inside this group —
                  the lines added together may not carry the same kind of reference</span>
        @endif
        <span>·</span>
        {{-- feature-rules §3.4: name the source, and the source is not the
             same one on a stamped row. A committed line is read back out of
             agora.ReconMatch — what the commit RECORDED it touched — because
             the drill only ever returns what is still outstanding, and
             committing is precisely what makes it not. --}}
        <span class="table-proc">{{ $line->isCommitted() ? 'agora.ReconMatch' : 'agora.usp_Recon_DrillProposal' }}</span>
    </footer>
</div>
