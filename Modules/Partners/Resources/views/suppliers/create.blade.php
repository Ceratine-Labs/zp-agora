@php
    $sites = (int) ($next['APS']->Sites ?? 0);
    $proposal = fn (string $series) => $next[$series]->NextCode ?? 'none free';
@endphp
<x-app-shell title="New supplier">
    <x-page-head eyebrow="Setup · Trading rules · Suppliers" title="New supplier"
                 :blurb="'Added to PumpIT\'s supplier list at all '.$sites.' sites carrying it.'">
        <x-slot:info>
            <p>A code is the same supplier at every site, so a new supplier is inserted into PumpIT's BRN_Suppliers at every site that carries the list, in one go or not at all. The code is the next free one in its series, taken when you save — the one shown here is what it would be now.</p>
            <p>The site's POS account number is left blank, as it is on most rows: it is linked when the supplier is set up on that site's till. The cash flag is the same at every site to begin with; change a site afterwards from its page. A new supplier is not taken away again — switch it off instead.</p>
        </x-slot:info>
        <x-slot:actions><a class="btn" href="{{ route('app.setup.suppliers.index') }}">Back to suppliers</a></x-slot:actions>
    </x-page-head>
    @error('refusal')<x-notice tone="warn" title="Not added">{{ $message }}</x-notice>@enderror
    @if ($errors->any() && ! $errors->has('refusal'))
        <x-notice tone="warn" title="Not added"><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></x-notice>
    @endif

    <form method="POST" action="{{ route('app.setup.suppliers.store') }}"
          data-confirm="Add this supplier to PumpIT?" data-confirm-action="Add supplier"
          data-confirm-text="PumpIT's BRN_Suppliers gets a new code at {{ $sites }} sites (APS now {{ $proposal('APS') }}, APE now {{ $proposal('APE') }}). It cannot be taken away again, only switched off."
          data-diff="PumpIT BRN_Suppliers · INSERT at {{ $sites }} sites · next code APS {{ $proposal('APS') }} / APE {{ $proposal('APE') }}"
          data-diff-note="Recorded with your reason. It cannot be taken away again, only switched off.">
        @csrf
        <x-card title="The supplier">
            <div class="field-row">
                <x-field name="Series" label="Series" :choices="['APS' => 'APS — stock supplier (next '.$proposal('APS').')', 'APE' => 'APE — expense creditor (next '.$proposal('APE').')']"
                         :value="old('Series', 'APS')" data-diff-field="Series" data-diff-old="" />
                <x-field name="SupplierName" label="Name" :value="old('SupplierName')" help="Up to 55 characters." data-diff-field="Name" data-diff-old="" />
                <x-field name="ContactPerson" label="Contact" :value="old('ContactPerson')" data-diff-field="Contact" data-diff-old="" />
                <x-field name="Department" label="Department" :value="old('Department')" data-diff-field="Department" data-diff-old="" />
            </div>
            <div class="field-row">
                <x-field name="IsStock" type="bool" label="Stock supplier" placeholder="Supplies stock" :value="(bool) old('IsStock', true)"
                         data-diff-field="Stock" data-diff-old="" />
                <x-field name="IsCash" type="bool" label="Paid in cash" placeholder="Paid in cash at every site to begin with" :value="(bool) old('IsCash', false)"
                         data-diff-field="Cash" data-diff-old="" />
            </div>
            <x-field name="Reason" label="Reason" :value="old('Reason')" help="At least {{ (int) config('partners.reason_min', 10) }} characters. Kept with the new rows." />
            <div class="form-actions"><button class="btn-primary" type="submit">Add supplier</button></div>
        </x-card>
    </form>
    @include('people::partials.confirm-diff')
</x-app-shell>
