{{--
    Who the cash is accounted to — the split by cashier a cash-bag match was
    confirmed with, read back off agora.ReconAllocation.

    A split that only existed at match time was not an accountability record
    (task 01a084fd-096f, 27 Sep 2026). usp_Recon_ManualMatch keeps it with the
    batch, one row per cashier line; agora.usp_Recon_GetAllocation reads it
    back with the batch's own figures, and this draws one table per batch —
    in practice one, since a match by hand is one batch.

    The columns are the match screen's, in its order: bags, banked, drop safe
    (with the "adjusted" chip where what was declared at the safe is not what
    was banked, Ryan's call of 9 Sep 2026), the share of the variance, what
    that leaves the cashier accountable for — then how the share was reached
    and the staff short it is due, in the words every recon screen uses
    (Modules\Recon\Support\StaffShort). SHARE IS BANK LESS BAGS: negative is
    money the bank did not receive.

    The totals row is the lines added up, and the line under it says whether
    that ties to the batch — bags, banked, variance and bank. The match refuses
    a split that does not, so "does not tie" means something changed a row
    since, and the page says so rather than presenting the sums as the batch.

    A reversed match keeps its split, marked: usp_Recon_Reverse stamps the
    lines and never deletes them, so the record of what was confirmed survives
    the undo.

    Expects: $allocation — ['lines' => Collection, 'batches' => Collection],
             as ReconService::allocation() returns it.
--}}
@php
    use App\Support\Format;
    use Illuminate\Support\Carbon;
    use Modules\Recon\Support\StaffShort;

    $cents = fn (mixed $v): int => (int) round((float) $v * 100);
@endphp

@foreach ($allocation['batches'] as $batch)
    @php
        $lines = $allocation['lines']->filter(fn (object $l) => (int) $l->BatchId === (int) $batch->BatchId)->values();
        $first = $lines->first();
        $reversed = $batch->State === 'reversed';
        $variance = (float) $batch->Variance;
    @endphp

    <x-card title="Who the cash is accounted to"
            :sub="'Batch '.$batch->BatchNo.' · the split by cashier the clerk confirmed'
                .($first?->ConfirmedByName ? ', by '.$first->ConfirmedByName : '')
                .($first?->ConfirmedAt ? ' '.Carbon::parse($first->ConfirmedAt)->diffForHumans() : '')
                .' · share is bank less bags'"
            flush style="margin-top:16px">

        @if ($reversed)
            <x-notice tone="warn" style="margin:12px 14px 0"
                      :title="'Reversed '.($batch->ReversedAt ? Carbon::parse($batch->ReversedAt)->diffForHumans() : '').' — the split is kept, marked'">
                <p>{{ rtrim((string) $batch->ReversalReason, '. ') }}. The bags went back to outstanding and no short is due from
                   this batch any more; the lines below are what was confirmed before it was undone.</p>
            </x-notice>
        @endif

        <x-table dense :count="$lines->count()" procedure="agora.usp_Recon_GetAllocation"
                 :id="'recon-alloc-'.$batch->BatchId">
            <x-slot:head>
                <tr>
                    <th>Cashier</th>
                    <th class="num">Bags</th>
                    <th class="num">Banked</th>
                    <th class="num">Drop safe</th>
                    <th class="num">Share of the variance</th>
                    <th class="num">Accountable</th>
                    <th>Basis</th>
                    <th>Staff short</th>
                </tr>
            </x-slot:head>

            @foreach ($lines as $line)
                @php([$tone, $words] = StaffShort::describe($line->ShortStatus, $line->Share, $line->StaffShortsNo))
                <tr @class(['muted' => $line->ReversedAt !== null])>
                    <td>
                        @if ($line->CashierCode === null)
                            <span class="muted">No cashier recorded</span>
                        @elseif ($line->CashierName === null)
                            <span class="mono">{{ $line->CashierCode }}</span>
                            <x-chip tone="warn" :dot="false"
                                    title="The drop safe names this code, and BRN_Employee has no one by it at this site">not on the employee list</x-chip>
                        @else
                            {{ $line->CashierName }}
                            <span class="mono muted" style="font-size:11px">{{ $line->CashierCode }}</span>
                        @endif
                    </td>
                    <td class="num">{{ Format::n($line->Bags) }}</td>
                    <td class="num">{{ Format::r($line->Banked) }}</td>
                    @if ($line->DropSafe === null)
                        <td class="num"><span class="muted">—</span></td>
                    @elseif ($cents($line->DropSafe) !== $cents($line->Banked))
                        <td class="num">
                            {{ Format::r($line->DropSafe) }}
                            <div><x-chip tone="warn" :dot="false"
                                    title="Declared {{ Format::r($line->DropSafe) }} at the drop safe; banked as {{ Format::r($line->Banked) }}">adjusted</x-chip></div>
                        </td>
                    @else
                        <td class="num">{{ Format::r($line->DropSafe) }}</td>
                    @endif
                    <td class="num">{{ Format::r($line->Share) }}</td>
                    <td class="num">{{ Format::r($line->Accountable) }}</td>
                    <td>
                        @if ($line->Basis === 'manual')
                            changed by hand
                            <br><span class="drill-line-id">derived {{ Format::r($line->DerivedShare) }}</span>
                        @else
                            <span class="muted">derived</span>
                        @endif
                    </td>
                    <td>
                        <x-chip :tone="$tone">{{ $words }}</x-chip>
                        @if ($line->ReversedAt !== null)
                            <br><x-chip tone="neutral" :dot="false">reversed</x-chip>
                            <span class="drill-line-id">{{ $line->ReversalReason }}</span>
                        @endif
                    </td>
                </tr>
            @endforeach

            {{-- The lines added up. Inside the body, as <x-data-grid> does it:
                 <x-table> owns the table's structure. --}}
            <tr style="font-weight:600;background:var(--surface-3)">
                <td>{{ Format::n($batch->Lines) }} {{ Str::plural('line', (int) $batch->Lines) }}</td>
                <td class="num">{{ Format::n($batch->Bags) }}</td>
                <td class="num">{{ Format::r($batch->Banked) }}</td>
                <td class="num">{{ $batch->DropSafe === null ? '—' : Format::r($batch->DropSafe) }}</td>
                <td class="num">{{ Format::r($batch->Share) }}</td>
                <td class="num">{{ Format::r($batch->Accountable) }}</td>
                <td></td>
                <td>@if ((float) $batch->ShortDue > 0){{ Format::r($batch->ShortDue) }} short @endif</td>
            </tr>
        </x-table>

        <p class="field-help" style="margin:10px 14px 14px">
            @if ((bool) $batch->Ties)
                <x-chip tone="good">Ties to batch {{ $batch->BatchNo }}</x-chip>
            @else
                <x-chip tone="crit">Does not tie to batch {{ $batch->BatchNo }}</x-chip>
            @endif
            The batch holds {{ Format::n($batch->MopsRowCount) }} {{ Str::plural('bag', (int) $batch->MopsRowCount) }}
            banked at {{ Format::r($batch->MopsTotal) }} against {{ Format::r($batch->BankTotal) }} at the bank:
            @if ($cents($variance) === 0)
                no variance, so nobody carries a share.
            @else
                a variance of {{ Format::r($variance) }}, bank less bags — the bank is
                {{ Format::r(abs($variance)) }} {{ $variance < 0 ? 'short of' : 'over' }} the bags.
            @endif
            @unless ((bool) $batch->Ties)
                <strong>The lines above add up to {{ Format::n($batch->Bags) }} bags, {{ Format::r($batch->Banked) }}
                banked and {{ Format::r($batch->Share) }} of variance, which is not the batch.</strong> The match
                refuses a split that does not add up, so a row has changed since it was confirmed.
            @endunless
            @if ($lines->contains(fn (object $l) => $l->ShortStatus === 'pending'))
                A short due has not been raised: staff shorts are raised by the Cash module, which is not built
                yet, and nothing here raises one.
            @endif
        </p>
    </x-card>
@endforeach
