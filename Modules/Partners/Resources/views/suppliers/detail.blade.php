@php
    $yes = fn ($value) => $value ? 'Yes' : 'No';
    $code = trim((string) $supplier->SupplierCode);
    $show = route('app.setup.suppliers.show', ['branch' => $supplier->BranchId, 'code' => $code]);
    $series = ['APS' => 'Mostly stock suppliers.', 'APE' => 'Mostly expense creditors.'];
@endphp
<x-card :title="$supplier->SupplierName ?? $code" :sub="$code.' · '.$supplier->BranchName">
    <x-slot:actions>
        @if (request()->ajax())<a class="btn sm" href="{{ $show }}">Open</a>@endif
    </x-slot:actions>
    <x-facts>
        <x-fact label="Status" :value="$supplier->Status" :tone="$supplier->Status === 'Active' ? 'good' : 'warn'">
            {{ $supplier->Status === 'Active' ? 'Offered at this site.' : 'Switched off at this site in PumpIT.' }}
        </x-fact>
        <x-fact label="Series" :value="$supplier->CodeSeries">{{ $series[$supplier->CodeSeries] ?? 'Not one of the APS / APE series.' }}</x-fact>
        <x-fact label="POS account" :value="$supplier->AccountNo">SupplierID — what this site's till pays out against.</x-fact>
        <x-fact label="Stock supplier" :value="$yes($supplier->IsStock)" />
        <x-fact label="Paid in cash" :value="$yes($supplier->IsCash)">Offered on this site's cashup Suppliers leg.</x-fact>
        <x-fact label="Cash or credit" :value="$supplier->CashOrCredit" />
        <x-fact label="Department" :value="$supplier->Department" />
        <x-fact label="Contact" :value="$supplier->ContactPerson">{{ $supplier->JobTitle }}</x-fact>
        <x-fact label="Telephone" :value="$supplier->Telephone">{{ $supplier->Telephone2 }}</x-fact>
        <x-fact label="Cell" :value="$supplier->CellNo" />
        <x-fact label="Email" :value="$supplier->EmailAddress" />
        <x-fact label="Fax" :value="$supplier->FaxNo" />
        <x-fact label="Physical address" :value="$supplier->PhysicalAddress" />
        <x-fact label="Postal address" :value="$supplier->PostalAddress" />
        <x-fact label="POS system" :value="$supplier->PosSystemId" />
        <x-fact label="Loaded to accounts" :value="$yes($supplier->IsSdkLoaded)">{{ $supplier->SdkLoadedAt ? 'On '.\Carbon\Carbon::parse($supplier->SdkLoadedAt)->format('j M Y H:i').', PumpIT clock.' : '' }}</x-fact>
        <x-fact label="Created in PumpIT" :value="$supplier->CreateDateTime ? \Carbon\Carbon::parse($supplier->CreateDateTime)->format('j M Y') : null" />
        @if ($supplier->Comments)
            <x-fact label="Comments" :value="$supplier->Comments" />
        @endif
    </x-facts>
</x-card>

<x-card title="At the other sites" :sub="$elsewhere->count().' more of your sites carry '.$code">
    @if ($elsewhere->isEmpty())
        <x-empty-state text="No other site you can see carries this code." />
    @else
        <x-table layout-key="Partners.suppliers.elsewhere" procedure="agora.usp_Partners_GetSupplier" :count="$elsewhere->count()" tools>
            <x-slot:head><tr><th>Site</th><th>Name there</th><th>POS account</th><th>Stock</th><th>Cash</th><th>Status</th></tr></x-slot:head>
            @foreach ($elsewhere as $site)
                <tr>
                    <td><a href="{{ route('app.setup.suppliers.show', ['branch' => $site->BranchId, 'code' => $code]) }}">{{ $site->BranchName }}</a></td>
                    <td>{{ $site->SupplierName ?? '—' }}@if ($site->SupplierName !== $supplier->SupplierName) <x-chip tone="warn" :dot="false">differs</x-chip>@endif</td>
                    <td class="mono">{{ $site->AccountNo ?? '—' }}</td>
                    <td>{{ $yes($site->IsStock) }}</td>
                    <td>{{ $yes($site->IsCash) }}</td>
                    <td><x-chip :tone="$site->Status === 'Active' ? 'good' : 'warn'">{{ $site->Status }}</x-chip></td>
                </tr>
            @endforeach
        </x-table>
    @endif
</x-card>
