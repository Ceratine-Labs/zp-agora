{{--
    What one proposal is actually made of.

    Two columns, side by side, because that is the question: these bank lines
    against those deposits. Where one side is empty the panel says so in words
    rather than showing an empty table — "no deposit behind this" IS the
    finding on a bank-only row, and an empty column reads like a loading state.
--}}
<div class="drill">
    <div class="drill-side">
        <h4>Bank {{ Str::plural('line', $bank->count()) }}
            <span class="muted">{{ $bank->count() }} · {{ \App\Support\Format::r($bank->sum('Amount')) }}</span></h4>

        @if ($bank->isEmpty())
            <p class="drill-none">Nothing on the statement carries this reference in the period —
               the deposit was captured but the bank has not settled it, or it settled under a
               reference the configured extraction does not produce.</p>
        @else
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
                    @foreach ($bank as $row)
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
    </div>

    <div class="drill-side">
        @if ($line->pairedReference())
            <p class="drill-paired">Found under <code>{{ $line->pairedReference() }}</code>, not
               <code>{{ $line->KeyRef }}</code> — {{ $line->NearRefNote }}</p>
        @endif
        <h4>{{ Str::plural('Deposit', $mops->count()) }}
            <span class="muted">{{ $mops->count() }} · {{ \App\Support\Format::r($mops->sum('Amount')) }}</span></h4>

        @if ($mops->isEmpty())
            <p class="drill-none">
                <strong>Nothing was declared against this reference.</strong>
                The live procedure reaches its matched branch here — its comparison against a
                missing deposit is neither true nor false — and under Execute it would stamp the
                bank {{ Str::plural('line', $bank->count()) }} above reconciled against nothing.
            </p>
        @else
            <table class="dt dense">
                <thead>
                    <tr><th>Date</th><th>Reference</th><th class="num">Amount</th></tr>
                </thead>
                <tbody>
                    @foreach ($mops as $row)
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
        <span class="table-proc">agora.usp_Recon_DrillProposal</span>
    </footer>
</div>
