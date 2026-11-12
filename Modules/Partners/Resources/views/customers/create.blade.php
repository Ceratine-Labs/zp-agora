@php
    $choices = ['' => 'Choose a site'] + $branches->mapWithKeys(fn ($b) => [$b->BranchId => $b->Name])->all();
    $site = $branches->firstWhere('BranchId', $branch);
@endphp
<x-app-shell title="New account customer">
    <x-page-head eyebrow="Setup · Trading rules · Account customers" title="New account customer"
                 blurb="Added to PumpIT's customer list at one site.">
        <x-slot:info>
            <p>A customer is inserted into PumpIT's BRN_Customers at the site you choose, with the next code in that site's series (its three letters and five digits), taken when you save. The till account number is the debtor account at that site's till; another customer on the same account is refused.</p>
            <p>The credit limit and the cell number are kept on the till. A new customer is not taken away again.</p>
        </x-slot:info>
        <x-slot:actions><a class="btn" href="{{ route('app.setup.customers.index') }}">Back to account customers</a></x-slot:actions>
    </x-page-head>
    @error('refusal')<x-notice tone="warn" title="Not added">{{ $message }}</x-notice>@enderror
    @if ($errors->any() && ! $errors->has('refusal'))
        <x-notice tone="warn" title="Not added"><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></x-notice>
    @endif

    <form method="GET" action="{{ route('app.setup.customers.create') }}" class="field-row">
        <x-field name="branch" label="Site" :choices="$choices" :value="$branch ?: ''" help="The code follows this site's own." />
        <div class="form-actions"><button class="btn" type="submit">Choose site</button></div>
    </form>

    @if ($site)
        <form method="POST" action="{{ route('app.setup.customers.store') }}"
              data-confirm="Add this customer to PumpIT?" data-confirm-action="Add customer"
              data-confirm-text="PumpIT's BRN_Customers gets a new row at {{ $site->Name }}, code {{ $nextCode ?? 'none free' }} if nobody takes it first. It cannot be taken away again."
              data-diff="PumpIT BRN_Customers · INSERT at {{ $site->Name }} · code {{ $nextCode ?? 'none free' }}"
              data-diff-note="Recorded with your reason. It cannot be taken away again.">
            @csrf
            <input type="hidden" name="BranchId" value="{{ $site->BranchId }}">
            <x-card title="The customer" :sub="$site->Name.' · next code '.($nextCode ?? 'none — add the site\'s first customer in PumpIT')">
                <div class="field-row">
                    <x-field name="CustomerName" label="Name" :value="old('CustomerName')" help="Up to 100 characters." data-diff-field="Name" data-diff-old="" />
                    <x-field name="AccountNo" label="Till account" :value="old('AccountNo')" help="The debtor account number at this site's till." data-diff-field="Till account" data-diff-old="" />
                    <x-field name="ContactPerson" label="Contact" :value="old('ContactPerson')" data-diff-field="Contact" data-diff-old="" />
                </div>
                <div class="field-row">
                    <x-field name="Department" label="Department" :value="old('Department')" data-diff-field="Department" data-diff-old="" />
                    <x-field name="JobTitle" label="Job title" :value="old('JobTitle')" data-diff-field="Job title" data-diff-old="" />
                    <x-field name="Telephone" label="Telephone" :value="old('Telephone')" data-diff-field="Telephone" data-diff-old="" />
                    <x-field name="Telephone2" label="Telephone 2" :value="old('Telephone2')" data-diff-field="Telephone 2" data-diff-old="" />
                </div>
                <x-field name="Reason" label="Reason" :value="old('Reason')" help="At least {{ (int) config('partners.reason_min', 10) }} characters. Kept with the new row." />
                <div class="form-actions"><button class="btn-primary" type="submit" @disabled($nextCode === null)>Add customer</button></div>
            </x-card>
        </form>
        @include('people::partials.confirm-diff')
    @endif
</x-app-shell>
