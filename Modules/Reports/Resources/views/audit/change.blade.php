{{--
    One audit trail row, opened: the correction the way the customer report
    reads one (Ryan, 2 Oct 2026). What changed, why, who, when and whether it
    still stands; then the change in context — for stock recon every shift of
    the item in that run with the old figure struck through beside the new, for
    bank recon every row the same batch stamped.

    A fragment: row-detail.js fetches it under the row, and page.blade.php wraps
    it for a direct link. HTML, not JSON — numbers go through App\Support\Format.

    Props: $change (usp_Reports_GetAuditChange result set 1), $context (set 2).
--}}
@php
    use Illuminate\Support\Carbon;

    $c = $change;
    $when = fn ($t) => $t ? Carbon::parse($t)->format('j M Y, H:i') : null;
    $reversed = $c->State === 'reversed';
    $journal = $c->WriteMode === 'journal';
@endphp

<div class="audit-change">
    <x-facts>
        <x-fact label="What changed" :value="$c->Change">{{ $c->RowKey }} · PumpIT {{ $c->PumpItTable }}</x-fact>
        <x-fact label="Why" :value="$c->Reason ?: $c->Action">{{ $c->Module }} · {{ $c->Action }}</x-fact>
        <x-fact label="When" :value="$when($c->ChangedAt)">by {{ $c->ChangedByName ?? 'a user no longer on file' }} · {{ $c->Ref }} · SAST</x-fact>
        <x-fact label="Now" :value="$reversed ? 'Reversed' : ($journal ? 'Recorded only' : 'In place')"
                :tone="$reversed ? 'neutral' : ($journal ? 'warn' : 'good')">
            @if ($reversed)
                {{ $when($c->ReversedAt) }} by {{ $c->ReversedByName ?? 'a user no longer on file' }}@if ($c->ReversalReason): {{ $c->ReversalReason }}@endif.
                PumpIT holds the before figure again.
            @elseif ($journal)
                Recorded in Agora but never written to PumpIT: the run was committed in journal mode.
            @else
                PumpIT holds the after figure. Reversing the press puts the before figure back.
            @endif
        </x-fact>
    </x-facts>

    @if ($c->Ledger === 'StockReconAmendment' && $context->isNotEmpty())
        @php
            // The shift this row is about, with three either side: enough to see
            // what moved around it, few enough that it is never below the fold.
            $at = (int) ($context->search(fn ($s) => (bool) $s->IsThis) ?: 0);
            $shown = $context->values()->slice(max(0, $at - 3), 7);
        @endphp
        {{-- How to read the table sits behind the "i" (4 Oct 2026); the count
             stays on the line. A <div>, because a <details> cannot sit in a <p>. --}}
        <div class="audit-change-note">
            The commit wrote {{ $context->where('Written', true)->count() }} of this item's
            {{ $context->count() }} shifts in the run.
            <x-info label="How to read this table">
                <p>This shift, highlighted, with the shifts either side of it. A crossed-out figure is what PumpIT
                   held before the commit; the figure beside it is what Agora wrote.</p>
                <p>Variance is sold − (open + issued − counted): negative is short.</p>
            </x-info>
        </div>
        <x-table procedure="agora.usp_Reports_GetAuditChange" :count="$shown->count()" :total="$context->count()" dense fit>
            <x-slot:head>
                <tr>
                    <th class="fit-min">Date</th><th class="fit-min">Shift</th>
                    <th class="num">Open</th><th class="num">+ Issued</th><th class="num">− Sold</th>
                    <th class="num">= Expected</th><th class="num">Counted</th><th class="num">Variance</th>
                    <th class="fit-min">Written</th>
                </tr>
            </x-slot:head>
            @foreach ($shown as $s)
                <tr @class(['is-matched' => (bool) $s->IsThis])>
                    <td class="fit-min">{{ Carbon::parse($s->TransactionDate)->format('d M') }}</td>
                    <td class="fit-min">{{ $s->ShiftName }}</td>
                    <td class="num"><x-reports::before-after :before="$s->OpenBefore" :after="$s->OpenAfter" /></td>
                    <td class="num">{{ (float) $s->Issued === 0.0 ? '—' : \App\Support\Format::n($s->Issued, (float) $s->Issued == round((float) $s->Issued) ? 0 : 3) }}</td>
                    <td class="num">{{ (float) $s->Sold === 0.0 ? '—' : \App\Support\Format::n($s->Sold, (float) $s->Sold == round((float) $s->Sold) ? 0 : 3) }}</td>
                    <td class="num"><x-reports::before-after :before="$s->ExpectedBefore" :after="$s->ExpectedAfter" /></td>
                    <td class="num"><x-reports::before-after :before="$s->CloseBefore" :after="$s->CloseAfter" /></td>
                    <td class="num"><x-reports::before-after :before="$s->VarBefore" :after="$s->VarAfter" signed /></td>
                    <td class="fit-min">
                        @if ($s->Written)
                            <x-chip :tone="$s->WrittenState === 'reversed' ? 'neutral' : 'good'" :dot="false">{{ $s->WrittenState === 'reversed' ? 'reversed' : 'written' }}</x-chip>
                        @else
                            <span class="muted">—</span>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-table>
        @if ($c->RunId)
            @can('stockrecon.runs.view')
                <p class="audit-change-note"><a href="{{ route('app.stockrecon.run', $c->RunId) }}">Open stock recon run {{ $c->RunId }}</a> for all {{ $context->count() }} shifts and the rule behind each figure.</p>
            @endcan
        @endif
    @elseif ($c->Ledger === 'ReconStamp' && $context->isNotEmpty())
        <p class="audit-change-note">Every row this batch stamped: the bank side first, then the deposits it was matched to.</p>
        <x-table procedure="agora.usp_Reports_GetAuditChange" :count="$context->count()" dense>
            <x-slot:head>
                <tr><th>Row</th><th>Action</th><th>Before → after</th><th class="fit-min">Now</th></tr>
            </x-slot:head>
            @foreach ($context as $r)
                <tr @class(['is-matched' => (bool) $r->IsThis])>
                    <td>{{ $r->RowKey }}</td>
                    <td>{{ $r->Action }}</td>
                    <td>{{ $r->Change }}</td>
                    <td class="fit-min"><x-chip :tone="$r->State === 'reversed' ? 'neutral' : 'good'" :dot="false">{{ $r->State === 'reversed' ? 'reversed' : 'in place' }}</x-chip></td>
                </tr>
            @endforeach
        </x-table>
        @if ($c->RunId)
            @can('recon.runs.view')
                <p class="audit-change-note"><a href="{{ route('app.recon.run', $c->RunId) }}">Open recon run {{ $c->RunId }}</a></p>
            @endcan
        @endif
    @elseif ($c->Detail)
        <details class="audit-change-detail">
            <summary>What was written, field by field</summary>
            <pre>{{ json_encode(json_decode((string) $c->Detail), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
        </details>
    @endif
</div>
