{{--
    The seven answers, in the order a reconciliation happens: what was
    proposed, what was allocated, what was touched, what was written — and
    then the estate as it stands right now, which is the check on all of it.

    Each card folds away once read and says plainly when it found nothing.
    An empty set is an answer here, not an absence: "no batch was ever
    allocated for this bag" is precisely what somebody opens this to learn.
--}}

<x-card title="Runs" sub="Previews that proposed something carrying this value" collapsible flush>
    <x-table :count="$sets['runs']->count()" empty="No run has ever proposed anything carrying this value.">
        <x-slot:head>
            <tr><th>Run</th><th>Area</th><th>Site</th><th>Period</th><th>Status</th>
                <th class="num">Proposals</th><th class="num">Stamped</th><th>Run by</th><th>When</th></tr>
        </x-slot:head>
        @foreach ($sets['runs'] as $row)
            <tr>
                <td><a href="{{ route('app.recon.run', $row->Id) }}">#{{ $row->Id }}</a>
                    @if ($row->Note)<br><span class="drill-line-id">{{ $row->Note }}</span>@endif</td>
                <td>{{ config("recon.areas.{$row->ReconArea}.label", $row->ReconArea) }}</td>
                <td>{{ $row->BranchName ?? $row->BranchId }}</td>
                <td class="mono">{{ substr((string) $row->FromDate, 0, 10) }} → {{ substr((string) $row->ToDate, 0, 10) }}</td>
                <td>
                    <x-chip :tone="$row->Status === 'committed' ? 'good' : ($row->Status === 'failed' ? 'crit' : 'neutral')">{{ $row->Status }}</x-chip>
                    @if ($row->FailureCode)<br><span class="drill-line-id">{{ $row->FailureCode }}: {{ $row->FailureMessage }}</span>@endif
                    @if ($row->ReversedAt)<br><span class="drill-line-id">reversed — {{ $row->ReversalReason }}</span>@endif
                </td>
                <td class="num">{{ \App\Support\Format::n($row->TotalRows) }}</td>
                <td class="num">{{ \App\Support\Format::n($row->CommittedRows) }}</td>
                <td>{{ $row->RunBy ?? '—' }}</td>
                <td class="muted">{{ $row->CreatedAt ? \Illuminate\Support\Carbon::parse($row->CreatedAt)->diffForHumans() : '—' }}</td>
            </tr>
        @endforeach
    </x-table>
</x-card>

<x-card title="Proposals" sub="The lines themselves, and what each was judged to be" collapsible flush>
    <x-table :count="$sets['proposals']->count()" empty="No proposal carries this value.">
        <x-slot:head>
            <tr><th>Run</th><th>Reference</th><th>Outcome</th><th class="num">Bank</th>
                <th class="num">Deposit</th><th class="num">Difference</th><th>State</th><th>Rule</th></tr>
        </x-slot:head>
        @foreach ($sets['proposals'] as $row)
            <tr>
                <td><a href="{{ route('app.recon.run', $row->RunId) }}">#{{ $row->RunId }}</a>
                    <br><span class="drill-line-id">{{ $row->BranchName ?? $row->BranchId }} · {{ $row->ReconArea }}</span></td>
                <td class="mono">{{ $row->KeyRef ?? '—' }}
                    @if ($row->MopsKeyRef)<span class="paired-ref">→ {{ $row->MopsKeyRef }}</span>@endif
                    @if ($row->BankNarrative)<br><span class="muted" style="font-size:11px">{{ Str::limit($row->BankNarrative, 60) }}</span>@endif</td>
                <td><x-chip :tone="$row->WouldReconcile ? 'good' : 'warn'">{{ $row->Outcome }}</x-chip>
                    @if ($row->NearRefNote)<br><span class="drill-line-id">{{ $row->NearRefNote }}</span>@endif</td>
                <td class="num">{{ \App\Support\Format::r($row->BankTotal) }}</td>
                <td class="num">{{ \App\Support\Format::r($row->MopsTotal) }}</td>
                <td class="num">{{ (float) $row->DiffAmount === 0.0 ? '—' : \App\Support\Format::r($row->DiffAmount) }}</td>
                <td>{{ $row->CommitState }}@if ($row->ReconBatchNo)<br><span class="drill-line-id">batch {{ $row->ReconBatchNo }}</span>@endif
                    @if ($row->BlockReason)<br><span class="drill-line-id">{{ $row->BlockReason }}</span>@endif</td>
                <td class="muted" style="font-size:11px">
                    @if ($row->UsedBankStart !== null)pos {{ $row->UsedBankStart }}, len {{ $row->UsedBankLen }}@else—@endif
                </td>
            </tr>
        @endforeach
    </x-table>
</x-card>

<x-card title="Batches" sub="Batch numbers Agora allocated, from the customer's own counter" collapsible flush>
    <x-table :count="$sets['batches']->count()" empty="No batch was ever allocated for this value.">
        <x-slot:head>
            <tr><th>Batch</th><th>Run</th><th>Site</th><th>Reference</th>
                <th class="num">Bank lines</th><th class="num">Bank</th>
                <th class="num">Deposits</th><th class="num">Deposit</th><th>State</th></tr>
        </x-slot:head>
        @foreach ($sets['batches'] as $row)
            <tr>
                <td class="mono">{{ $row->BatchNo }}</td>
                <td><a href="{{ route('app.recon.run', $row->RunId) }}">#{{ $row->RunId }}</a></td>
                <td>{{ $row->BranchName ?? $row->BranchId }}</td>
                <td class="mono">{{ $row->KeyRef ?? '—' }}</td>
                <td class="num">{{ \App\Support\Format::n($row->BankLineCount) }}</td>
                <td class="num">{{ \App\Support\Format::r($row->BankTotal) }}</td>
                <td class="num">{{ \App\Support\Format::n($row->MopsRowCount) }}</td>
                <td class="num">{{ \App\Support\Format::r($row->MopsTotal) }}</td>
                <td><x-chip :tone="$row->State === 'reversed' ? 'warn' : 'good'">{{ $row->State }}</x-chip>
                    @if ($row->ReversalReason)<br><span class="drill-line-id">{{ $row->ReversalReason }}</span>@endif</td>
            </tr>
        @endforeach
    </x-table>
</x-card>

<x-card title="Rows touched"
        sub="One row per side, with what it held BEFORE Agora wrote to it — the record the executable never kept"
        collapsible flush>
    <x-table :count="$sets['matches']->count()" empty="Agora has never written to a row carrying this value.">
        <x-slot:head>
            <tr><th>Batch</th><th>Side</th><th>Source</th><th>Key</th><th>Date</th>
                <th class="num">Amount</th><th>Was</th></tr>
        </x-slot:head>
        @foreach ($sets['matches'] as $row)
            <tr>
                <td class="mono">{{ $row->BatchNo }}</td>
                <td>{{ $row->Side }}</td>
                <td class="mono" style="font-size:11px">{{ $row->SourceTable }}</td>
                <td class="mono" style="font-size:11px">{{ $row->SourceId ?? Str::limit((string) $row->SourceKeyJson, 40) }}</td>
                <td class="mono">{{ substr((string) $row->SourceDate, 0, 10) }}</td>
                <td class="num">{{ \App\Support\Format::r($row->Amount) }}</td>
                <td class="muted" style="font-size:11px">
                    state {{ $row->PriorReconState ?? '—' }}, batch {{ $row->PriorBatchNo ?? '—' }}
                </td>
            </tr>
        @endforeach
    </x-table>
</x-card>

<x-card title="Stamps" sub="What Agora asked the customer's estate to become, and whether it was applied" collapsible flush>
    <x-table :count="$sets['stamps']->count()" empty="Nothing was ever stamped for this value.">
        <x-slot:head>
            <tr><th>Batch</th><th>Target</th><th>Key</th><th>Columns</th><th>State</th>
                <th class="num">Rows</th><th>When</th></tr>
        </x-slot:head>
        @foreach ($sets['stamps'] as $row)
            <tr>
                <td class="mono">{{ $row->BatchNo }}</td>
                <td class="mono" style="font-size:11px">{{ $row->TargetDatabase }}.{{ $row->TargetTable }}</td>
                <td class="mono" style="font-size:11px">{{ Str::limit((string) $row->TargetKeyJson, 40) }}</td>
                <td class="mono" style="font-size:11px">{{ $row->SetColumns }}</td>
                <td><x-chip :tone="$row->State === 'applied' ? 'good' : ($row->State === 'failed' ? 'crit' : 'neutral')">{{ $row->State }}</x-chip>
                    @if ($row->FailureMessage)<br><span class="drill-line-id">{{ $row->FailureMessage }}</span>@endif</td>
                <td class="num">{{ $row->RowsAffected ?? '—' }}</td>
                <td class="muted">{{ $row->AppliedAt ? \Illuminate\Support\Carbon::parse($row->AppliedAt)->diffForHumans() : '—' }}</td>
            </tr>
        @endforeach
    </x-table>
</x-card>

<x-card title="Bank statement"
        sub="The legacy rows as they stand right now — the check on everything above"
        collapsible flush>
    <x-table :count="$sets['bank']->count()" empty="Nothing on the statement carries this value inside the window.">
        <x-slot:head>
            <tr><th>Line</th><th>Site</th><th>Date</th><th>Narrative</th>
                <th class="num">Amount</th><th>Channel</th><th>Recon state</th></tr>
        </x-slot:head>
        @foreach ($sets['bank'] as $row)
            <tr>
                <td class="mono">{{ $row->BankStatementLineID }}</td>
                <td>{{ $row->BranchName ?? $row->BranchId }}</td>
                <td class="mono">{{ substr((string) $row->LineDate, 0, 10) }}</td>
                <td class="mono" style="font-size:11px">{{ $row->Description }}</td>
                <td class="num">{{ \App\Support\Format::r($row->Amount) }}</td>
                <td>{{ $row->Type }}</td>
                <td>
                    {{-- 1 is outstanding, 2 is reconciled. IDState 1 means the
                         line was never classified at all — 75,305 rows on the
                         live system — and it is shown rather than hidden. --}}
                    <x-chip :tone="(int) $row->ReconState === 2 ? 'good' : 'warn'">
                        {{ (int) $row->ReconState === 2 ? 'reconciled' : 'outstanding' }}
                    </x-chip>
                    @if ($row->ReconBatchNo)<br><span class="drill-line-id">batch {{ $row->ReconBatchNo }}</span>@endif
                    @if ((int) $row->IDState === 1)<br><span class="drill-line-id">never classified</span>@endif
                </td>
            </tr>
        @endforeach
    </x-table>
</x-card>

<x-card title="Deposits" sub="All five BRN_DailyBanking families, asked the same question" collapsible flush>
    <x-table :count="$sets['deposits']->count()" empty="No deposit carries this value inside the window.">
        <x-slot:head>
            <tr><th>Area</th><th>Site</th><th>Date</th><th>Reference</th>
                <th>Second reference</th><th class="num">Amount</th><th>Reconciled in PumpIT</th></tr>
        </x-slot:head>
        @foreach ($sets['deposits'] as $row)
            <tr>
                <td>{{ config("recon.areas.{$row->Area}.label", $row->Area) }}</td>
                <td>{{ $row->BranchId }}</td>
                <td class="mono">{{ substr((string) $row->TxDate, 0, 10) }}</td>
                <td class="mono">{{ $row->Reference ?? '—' }}</td>
                <td class="mono">{{ $row->Reference2 ?? '—' }}</td>
                <td class="num">{{ \App\Support\Format::r($row->Amount) }}</td>
                <td>
                    <x-chip :tone="(int) $row->ReconBatchNoPumpIT > 0 ? 'good' : 'warn'">
                        {{ (int) $row->ReconBatchNoPumpIT > 0 ? 'batch '.$row->ReconBatchNoPumpIT : 'not reconciled' }}
                    </x-chip>
                </td>
            </tr>
        @endforeach
    </x-table>
</x-card>
