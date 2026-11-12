@use('App\Support\Format')
@if (session('batchDone'))
    <x-notice tone="info" :title="session('batchDone')" />
@endif
<p>Find one batch by the number shown on the recon lines. Review its recorded rows before confirming the reversal.</p>
<form method="GET" action="{{ route('app.recon.batch', $area['key']) }}">
    <div class="field-row">
    @if ($pinned)
        <input type="hidden" name="branch_id" value="{{ $branchId }}">
    @else
        <div class="field"><label for="batch-site">Site</label>
            <select id="batch-site" name="branch_id" required data-select>
                <option value="">Choose a site</option>
                @foreach ($branches as $branch)
                    <option value="{{ $branch->BranchId }}" @selected((int) $branch->BranchId === $branchId)>{{ $branch->Name }}</option>
                @endforeach
            </select>
        </div>
    @endif
    <div class="field"><label for="batch-no">Recon batch number</label>
        <input id="batch-no" type="number" min="1" name="batch_no" value="{{ $batchNo ?: '' }}" required>
    </div>
    </div>
    <div class="form-actions"><button class="btn-primary" type="submit">Preview batch</button></div>
</form>
@if ($refusal)
    <x-notice tone="stop" :title="$refusal" />
@endif
@if ($batch)
    <h2>Batch {{ $batch->BatchNo }} · {{ $branches->firstWhere('BranchId', $branchId)?->Name ?? 'Site '.$branchId }}</h2>
    <p>Run <a href="{{ route('app.recon.run', $batch->RunId) }}">#{{ $batch->RunId }}</a> · {{ $batch->State }} · {{ $batch->StampMode }}.
       {{ $batch->BankLineCount }} bank lines ({{ Format::r($batch->BankTotal) }}), {{ $batch->MopsRowCount }} deposit rows ({{ Format::r($batch->MopsTotal) }}).</p>
    <x-table layout-key="Recon.panes.batch.1" procedure="agora.usp_Recon_GetBatch" :count="$lines->count()">
        <x-slot:head><tr><th>Side</th><th>Reference / ID</th><th>Date</th><th>Recorded amount</th><th>Amount change to undo</th></tr></x-slot:head>
        @foreach ($lines as $line)
            <tr><td>{{ $line->Side }}</td><td>{{ $line->SourceKey ?? $line->SourceId }}@if ($line->SourceKey && $line->SourceId) · #{{ $line->SourceId }}@endif</td><td>{{ $line->SourceDate }}</td>
                <td>{{ Format::r($line->Amount) }}</td><td>{{ $line->Adjustment === null ? '—' : Format::r($line->Adjustment) }}</td></tr>
        @endforeach
    </x-table>
    @foreach ($allocations as $allocation)
        @if ($allocation->StaffShortsNo)
            <p>Staff short {{ $allocation->StaffShortsNo }} · {{ $allocation->CashierName ?? $allocation->CashierCode }} · {{ $allocation->ShortStatus }}.
               Pending shorts are declined with the reversal. Approved or posted shorts must be resolved in Cash first.</p>
        @endif
    @endforeach
    @if ($batch->State === 'committed')
        <form method="POST" action="{{ URL::temporarySignedRoute('app.recon.batch.reverse', now()->addMinutes(30), [$area['key'], 'branch_id' => $branchId, 'batch_id' => $batch->Id, 'batch_no' => $batch->BatchNo]) }}"
              data-confirm="Unreconcile batch {{ $batch->BatchNo }}?"
              data-confirm-text="Only this batch will be reversed. {{ $batch->StampMode === 'live' ? 'Its recorded changes will be restored in PumpIT, including any recorded deposit adjustments.' : 'This is a journal batch; PumpIT will not change.' }}" data-confirm-action="Unreconcile this batch" data-confirm-danger>
            @csrf
            <div class="field"><label for="batch-reason">Reason</label><input id="batch-reason" name="reason" maxlength="300" required></div>
            <button type="submit" class="btn-primary">Unreconcile this batch</button>
        </form>
    @endif
@endif
