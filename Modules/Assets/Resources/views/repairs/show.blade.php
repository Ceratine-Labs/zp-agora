{{--
    One repair job card (T057): the call, whether it is a comeback, and the
    purchase request that pays for it, line by line.

    A job card is never deleted. One raised in error is cancelled — and a
    cancelled card stops counting as a call, so it cannot turn the next genuine
    call on the asset into a comeback.
--}}
@php
    use App\Support\Format;
    use Illuminate\Support\Carbon;

    $date = fn ($d) => $d === null ? null : Carbon::parse((string) $d)->format('j M Y');
@endphp

<x-app-shell :title="$repair->JobCardNo">
    <x-page-head eyebrow="Asset repair"
                 :title="$repair->JobCardNo.' · '.$repair->AssetNo"
                 :blurb="($repair->AssetTypeDescription ?: 'Asset').' · '.trim($repair->Make.' '.($repair->Model ?? '')).' · called in '.$date($repair->CallDate).' at '.$repair->SiteName">
        <x-slot:actions>
            <a class="btn" href="{{ route('app.assets.repairs') }}">Back to job cards</a>
            <a class="btn" href="{{ route('app.assets.register.show', ['branch' => $repair->BranchId, 'asset' => $repair->AssetId]) }}">The asset</a>
            @if (! $repair->IsCancelled)
                @can('assets.repair.edit')
                    <button type="button" class="btn-primary" data-modal-open="edit-repair">Edit</button>
                    <button type="button" class="btn-ghost" data-modal-open="cancel-repair">Cancel</button>
                @endcan
            @endif
        </x-slot:actions>
    </x-page-head>

    @if (session('comeback'))
        <x-notice tone="warn" title="A comeback">{{ session('comeback') }}</x-notice>
    @elseif (session('status'))
        <x-notice tone="info" title="Done">{{ session('status') }}</x-notice>
    @endif

    @error('refusal')
        <x-notice tone="warn" title="That was refused">{{ $message }}</x-notice>
    @enderror

    @if ($errors->any() && ! $errors->has('refusal'))
        <x-notice tone="warn" title="That was not sent">
            <ul style="margin:0; padding-left:18px">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </x-notice>
    @endif

    @if ($repair->IsCancelled)
        <x-notice tone="info" title="Cancelled {{ $date($repair->CancelledAt) }}">
            {{ $repair->CancelledByName ?? 'Somebody' }}: {{ $repair->CancelReason }}. It no longer counts as a call.
        </x-notice>
    @elseif ($repair->IsComeback)
        <x-notice tone="warn" title="A comeback — {{ $repair->DaysSincePrevious }} days after the last call">
            Previously called in on {{ $date($repair->PrevCallDate) }}
            (<a href="{{ route('app.assets.repairs.show', ['branch' => $repair->BranchId, 'repair' => $repair->PrevRepairId]) }}">{{ $repair->PrevJobCardNo }}</a>)
            to {{ $repair->PrevSupplierName ?? $repair->PrevSupplierCode ?? 'an unrecorded supplier' }}; this call is within
            {{ $repair->WindowDays }} days, so it counts against that supplier.
        </x-notice>
    @endif

    <x-kpi-strip>
        <x-kpi label="Cost" :value="Format::r($repair->RepairCost)"
               :note="$repair->TransactionNo ? $repair->RequestState : 'No purchase request yet'"
               :tone="$repair->RepairCost === null ? 'warn' : 'neutral'" />
        <x-kpi label="Days out" :value="$repair->DaysOut === null ? Format::NOTHING : Format::n($repair->DaysOut)"
               :note="$repair->DateActualReturn ? 'Back '.$date($repair->DateActualReturn) : 'Not back yet'" />
        <x-kpi label="Since the last call" :value="$repair->DaysSincePrevious === null ? Format::NOTHING : Format::n($repair->DaysSincePrevious).' days'"
               :note="$repair->PrevJobCardNo ?? 'The first call on this asset'"
               :tone="$repair->IsComeback ? 'warn' : 'neutral'" />
    </x-kpi-strip>

    <x-card title="The call">
        <x-facts>
            <x-fact label="Problem" :value="$repair->Problem">As reported when it was called in.</x-fact>
            <x-fact label="Supplier" :value="$repair->SupplierName ?? $repair->SupplierCode">
                {{ $repair->SupplierCode ?? 'Not recorded yet.' }}{{ $repair->SupplierReference ? ' · their ref '.$repair->SupplierReference : '' }}
            </x-fact>
            <x-fact label="Site" :value="$repair->SiteName">Where the asset stood, and whose request pays.</x-fact>
            <x-fact label="Belongs to" :value="$repair->BranchName">The asset's own register.</x-fact>
            <x-fact label="Expected back" :value="$date($repair->DateExpectedReturn)">
                {{ $repair->DateExpectedReturn && $repair->DateActualReturn && $repair->DateActualReturn > $repair->DateExpectedReturn ? 'It came back late.' : 'As promised when it was called in.' }}
            </x-fact>
            <x-fact label="Raised" :value="$date($repair->CreatedAt)">{{ $repair->CreatedByName ?? 'Unknown' }}</x-fact>
        </x-facts>
    </x-card>

    <x-card title="The purchase request"
            :sub="$repair->TransactionNo ? $repair->TransactionNo.' at '.$repair->SiteName.' · '.$repair->RequestState.($repair->SharedBy > 1 ? ' · pays for '.$repair->SharedBy.' job cards' : '') : 'None attached yet'">
        {{-- How a shared request is counted is behind the card's info button;
             the share itself is in the sub. --}}
        @if ($repair->TransactionNo && $repair->SharedBy > 1)
            <x-slot:info>
                <p>One call-out, several assets. Each job card shows the request's full amount; totals count it
                   once.</p>
            </x-slot:info>
        @endif
        @if (! $repair->TransactionNo)
            <x-empty-state title="No purchase request yet"
                           text="The cost of a repair is its approved purchase request. Attach the request number when it is raised, and the cost appears here." />
        @else
            <x-table procedure="agora.usp_Assets_GetRepair" :count="$lines->count()" dense
                     empty="The request has no lines.">
                <x-slot:head>
                    <tr><th class="num">Line</th><th>Description</th><th>Justification</th><th class="num">Amount</th><th>Invoice</th></tr>
                </x-slot:head>
                @foreach ($lines as $l)
                    <tr>
                        <td class="num">{{ $l->ItemNo }}</td>
                        <td>{{ $l->Description }}</td>
                        <td>{{ $l->Justification }}</td>
                        <td class="num">{{ Format::r($l->PurchaseAmount) }}</td>
                        <td>{{ $l->InvoiceNo ?? Format::NOTHING }}</td>
                    </tr>
                @endforeach
            </x-table>
        @endif
    </x-card>

    @if (! $repair->IsCancelled)
        @can('assets.repair.edit')
            @include('assets::repairs.partials.form', [
                'modalId' => 'edit-repair',
                'modalTitle' => 'Change '.$repair->JobCardNo,
                'action' => route('app.assets.repairs.update', ['branch' => $repair->BranchId, 'repair' => $repair->RepairId]),
                'method' => 'PUT',
                'repair' => $repair,
                'assetKey' => null,
                'assets' => collect(),
            ])

            <x-modal id="cancel-repair" title="Cancel {{ $repair->JobCardNo }}">
                <form method="POST" action="{{ route('app.assets.repairs.cancel', ['branch' => $repair->BranchId, 'repair' => $repair->RepairId]) }}"
                      data-confirm="Cancel {{ $repair->JobCardNo }}?"
                      data-confirm-text="It stays on the record and stops counting as a call."
                      data-confirm-action="Cancel it" data-confirm-danger>
                    @csrf
                    <x-field name="reason" label="Why" help="Required. Kept on the card."
                             info="For a card raised in error — twice for one call, or on the wrong asset. A repair that did happen is never cancelled." />
                    <div class="btn-row">
                        <button type="submit" class="btn-primary">Cancel the job card</button>
                    </div>
                </form>
            </x-modal>
        @endcan
    @endif
</x-app-shell>
