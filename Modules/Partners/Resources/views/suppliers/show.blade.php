<x-app-shell :title="$supplier->SupplierName ?? $supplier->SupplierCode">
    <x-page-head eyebrow="Setup · Trading rules · Suppliers" :title="$supplier->SupplierName ?? $supplier->SupplierCode"
                 :blurb="$supplier->SupplierCode.' · '.$supplier->BranchName">
        <x-slot:actions>
            <a class="btn" href="{{ route('app.setup.suppliers.index', ['branches' => [$supplier->BranchId]]) }}">Back to suppliers</a>
        </x-slot:actions>
    </x-page-head>
    @include('partners::suppliers.detail')
</x-app-shell>
