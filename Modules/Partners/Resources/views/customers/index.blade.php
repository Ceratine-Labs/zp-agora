<x-app-shell title="Account customers">
    <x-page-head eyebrow="Setup · Trading rules" title="Account customers" :blurb="$grid->definition->blurb()">
        <x-slot:info>
            <p>PumpIT's account customer list (BRN_Customers), by site. Its till account number (DebtorID) is the debtor account at the site's till, and the till keeps its own record of that account — the credit limit and the cell number are filled there and empty in PumpIT, so the list shows the till's.</p>
            <p>Where a debtor's statement goes is Agora's own address list, kept with the Debtors statements and changed on the debtor's statement. PumpIT keeps trading on these rows — the cashup's Debtors leg resolves an account sale through them — so Agora reads them and does not change them.</p>
        </x-slot:info>
        <x-slot:actions>
            @if (\Modules\Partners\Services\PartnerWriteService::may('customers', 'create'))
                <a class="btn-primary" href="{{ route('app.setup.customers.create') }}">New customer</a>
            @endif
            @if ($includesControls)
                <a class="btn" href="{{ route('app.setup.customers.index') }}">Hide payment-control accounts</a>
            @else
                <a class="btn" href="{{ route('app.setup.customers.index', ['controls' => 1]) }}">Show payment-control accounts</a>
            @endif
            @can('debtors.statements.view')<a class="btn" href="{{ route('app.debtors.index') }}">Debtors statements</a>@endcan
            @can('partners.suppliers.view')<a class="btn" href="{{ route('app.setup.suppliers.index') }}">Suppliers</a>@endcan
        </x-slot:actions>
    </x-page-head>
    @if (session('status'))<x-notice tone="good" title="Saved">{{ session('status') }}</x-notice>@endif
    <x-data-grid :grid="$grid" :branches="$branches" surface />
</x-app-shell>
