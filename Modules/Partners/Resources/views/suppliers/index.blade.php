<x-app-shell title="Suppliers">
    <x-page-head eyebrow="Setup · Trading rules" title="Suppliers" :blurb="$grid->definition->blurb()">
        <x-slot:info>
            <p>PumpIT's supplier list (BRN_Suppliers). It is one group-wide list repeated at every site: a code is the same supplier everywhere, while the POS account number and the cash, stock and active flags are each site's own — so a row here is a supplier at a site.</p>
            <p>PumpIT keeps trading on these rows — the cashup's Suppliers and Expenses legs and the till's paid-outs resolve a supplier through them — so Agora reads them and does not change them. Codes are APS-#### (mostly stock suppliers) or APE-#### (mostly expense creditors).</p>
        </x-slot:info>
        <x-slot:actions>
            @can('partners.customers.view')<a class="btn" href="{{ route('app.setup.customers.index') }}">Account customers</a>@endcan
        </x-slot:actions>
    </x-page-head>
    <x-data-grid :grid="$grid" :branches="$branches" surface />
</x-app-shell>
