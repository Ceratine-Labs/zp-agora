{{--
    Asset repair (T057): job cards by asset and supplier, with the comeback
    rule — a second call on the same asset within 30 days of the one before.

    The tally above the grid is the forensic question in one table: per
    supplier, the calls they took, the comebacks on their work and what their
    requests cost. It covers the sites and dates the grid is showing, so the
    two agree. "Comebacks only" is the Comeback column's tick list.
--}}
@php
    use App\Support\Format;
@endphp
<x-app-shell title="Asset repair">
    <x-page-head eyebrow="People and assets" title="Asset repair"
                 blurb="Job cards by asset and supplier. A comeback is a second call on the same asset within 30 days of the one before; it counts against the supplier whose repair did not hold.">
        <x-slot:actions>
            <a class="btn" href="{{ route('app.assets.register') }}">Register</a>
            <a class="btn" href="{{ route('app.assets.movement') }}">Movements</a>
            @if ($maySave)
                <button type="button" class="btn-primary" data-modal-open="log-repair">Raise a job card</button>
            @endif
        </x-slot:actions>
    </x-page-head>

    @if (session('status'))
        <x-notice tone="info" title="Done">{{ session('status') }}</x-notice>
    @endif

    @error('refusal')
        <x-notice tone="warn" title="That job card was refused">{{ $message }}</x-notice>
    @enderror

    @if ($errors->any() && ! $errors->has('refusal'))
        <x-notice tone="warn" title="That job card was not sent">
            <ul style="margin:0; padding-left:18px">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </x-notice>
    @endif

    <x-card title="Comebacks by supplier"
            sub="The calls each supplier took, and the comebacks on their work, for the sites and dates below. Worst rate first."
            collapsible remember="assets.repairs.tally">
        <x-table procedure="agora.usp_Assets_Comebacks" :count="$tally['suppliers']->count()" dense tools
                 empty="No job cards for these sites and dates.">
            <x-slot:head>
                <tr>
                    <th>Supplier</th>
                    <th class="num">Calls</th>
                    <th class="num">Comebacks</th>
                    <th class="num">Rate</th>
                    <th class="num">Cost</th>
                    <th>Last call</th>
                </tr>
            </x-slot:head>
            @foreach ($tally['suppliers'] as $s)
                <tr>
                    <td>{{ $s->SupplierName ?? $s->SupplierCode }} <span class="mono muted">{{ $s->SupplierCode }}</span></td>
                    <td class="num">{{ Format::n($s->Calls) }}</td>
                    <td class="num">{{ Format::n($s->Comebacks) }}</td>
                    <td class="num">{{ Format::pct($s->ComebackPct) }}</td>
                    <td class="num">{{ Format::r($s->RepairCost) }}</td>
                    <td class="mono">{{ $s->LastCall ? substr((string) $s->LastCall, 0, 10) : \App\Support\Format::NOTHING }}</td>
                </tr>
            @endforeach
        </x-table>
    </x-card>

    <x-data-grid :grid="$grid" :branches="$gridBranches" surface />

    @if ($maySave)
        @include('assets::repairs.partials.form', [
            'modalId' => 'log-repair',
            'modalTitle' => 'Raise a job card',
            'action' => route('app.assets.repairs.store'),
            'method' => 'POST',
            'repair' => null,
            'assetKey' => null,
        ])
    @endif
</x-app-shell>
