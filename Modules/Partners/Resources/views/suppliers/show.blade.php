<x-app-shell :title="$supplier->SupplierName ?? $supplier->SupplierCode">
    <x-page-head eyebrow="Setup · Trading rules · Suppliers" :title="$supplier->SupplierName ?? $supplier->SupplierCode"
                 :blurb="$supplier->SupplierCode.' · '.$supplier->BranchName">
        <x-slot:actions>
            <a class="btn" href="{{ route('app.setup.suppliers.index', ['branches' => [$supplier->BranchId]]) }}">Back to suppliers</a>
            @if (\Modules\Partners\Services\PartnerWriteService::may('suppliers', 'edit'))
                <a class="btn-primary" href="{{ route('app.setup.suppliers.edit', ['branch' => $supplier->BranchId, 'code' => trim((string) $supplier->SupplierCode)]) }}">Edit supplier</a>
            @endif
        </x-slot:actions>
    </x-page-head>
    @if (session('status'))<x-notice tone="good" title="Saved">{{ session('status') }}</x-notice>@endif
    @error('refusal')<x-notice tone="warn" title="Not put back">{{ $message }}</x-notice>@enderror
    @include('partners::suppliers.detail')
    @include('partners::partials.changes', [
        'canReverse' => \Modules\Partners\Services\PartnerWriteService::may('suppliers', 'edit'),
        'reverseRoute' => 'app.setup.suppliers.changes.reverse',
        'routeParams' => ['branch' => $supplier->BranchId, 'code' => trim((string) $supplier->SupplierCode)],
    ])
</x-app-shell>
