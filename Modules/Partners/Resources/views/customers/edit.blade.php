@php
    $code = trim((string) $customer->CustomerCode);
    $show = route('app.setup.customers.show', ['branch' => $customer->BranchId, 'code' => $code]);
    $fields = [
        'CustomerName' => ['Name', $customer->CustomerName, 'Up to 100 characters.'],
        'ContactPerson' => ['Contact', $customer->ContactPerson, 'Up to 35 characters.'],
        'Department' => ['Department', $customer->Department, 'Up to 30 characters.'],
        'JobTitle' => ['Job title', $customer->JobTitle, 'Up to 50 characters.'],
        'Telephone' => ['Telephone', $customer->Telephone, 'Up to 25 characters.'],
        'Telephone2' => ['Telephone 2', $customer->Telephone2, 'Up to 25 characters.'],
    ];
@endphp
<x-app-shell :title="'Edit '.($customer->CustomerName ?? $code)">
    <x-page-head eyebrow="Setup · Trading rules · Account customers" :title="'Edit '.($customer->CustomerName ?? $code)"
                 :blurb="$code.' · '.$customer->BranchName">
        <x-slot:info>
            <p>These changes are written to PumpIT's BRN_Customers, the row the cashup's Debtors leg resolves an account sale through. Each value is recorded first, with your reason, so the change can be put back from the customer's page.</p>
            <p>The till account, the credit limit and the cell number are the till's and are not changed here; where the statement goes is changed on the debtor's statement.</p>
        </x-slot:info>
        <x-slot:actions><a class="btn" href="{{ $show }}">Back to customer</a></x-slot:actions>
    </x-page-head>
    @error('refusal')<x-notice tone="warn" title="Not saved">{{ $message }}</x-notice>@enderror
    @if ($errors->any() && ! $errors->has('refusal'))
        <x-notice tone="warn" title="Not saved"><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></x-notice>
    @endif

    <form method="POST" action="{{ route('app.setup.customers.update', ['branch' => $customer->BranchId, 'code' => $code]) }}"
          data-confirm="Write this to PumpIT?" data-confirm-action="Save customer"
          data-confirm-text="PumpIT's BRN_Customers is changed for {{ $code }} {{ $customer->CustomerName }} at {{ $customer->BranchName }}. Each change and your reason are recorded, and can be put back."
          data-diff="PumpIT BRN_Customers · {{ $code }} {{ $customer->CustomerName }} · {{ $customer->BranchName }}"
          data-diff-note="Each change and your reason are recorded, and can be put back. Refused if somebody changes the customer first.">
        @csrf @method('PUT')
        @foreach ($fields as $name => [$label, $current, $help])
            <input type="hidden" name="Expected{{ $name }}" value="{{ $current }}">
        @endforeach
        <x-card title="The customer" :sub="$code.' · till account '.($customer->AccountNo ?? 'none')">
            <div class="field-row">
                @foreach ($fields as $name => [$label, $current, $help])
                    <x-field :name="$name" :label="$label" :value="old($name, $current)" :help="$help"
                             :data-diff-field="$label" :data-diff-old="(string) $current" />
                @endforeach
            </div>
            <x-field name="Reason" label="Reason for the change" :value="old('Reason')" help="At least {{ (int) config('partners.reason_min', 10) }} characters. Kept with every value you change." />
            <div class="form-actions"><button class="btn-primary" type="submit">Save customer</button></div>
        </x-card>
    </form>
    @include('people::partials.confirm-diff')
</x-app-shell>
