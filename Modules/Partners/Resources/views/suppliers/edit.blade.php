@php
    $code = trim((string) $supplier->SupplierCode);
    $show = route('app.setup.suppliers.show', ['branch' => $supplier->BranchId, 'code' => $code]);
    $sites = 1 + $elsewhere->count();
    $differs = $elsewhere->filter(fn ($s) => $s->SupplierName !== $supplier->SupplierName)->count();
@endphp
<x-app-shell :title="'Edit '.($supplier->SupplierName ?? $code)">
    <x-page-head eyebrow="Setup · Trading rules · Suppliers" :title="'Edit '.($supplier->SupplierName ?? $code)"
                 :blurb="$code.' · '.$supplier->BranchName">
        <x-slot:info>
            <p>These changes are written to PumpIT's BRN_Suppliers, because the cashups and the till's paid-outs read it. Each value is recorded first, with your reason, so the change can be put back from the supplier's page.</p>
            <p>The name is the supplier's at every site: a new name is written at all {{ $sites }} of your sites carrying {{ $code }}. Contact, department, active, cash and stock are this site's only. The code and the POS account number are not changed here.</p>
        </x-slot:info>
        <x-slot:actions><a class="btn" href="{{ $show }}">Back to supplier</a></x-slot:actions>
    </x-page-head>
    @error('refusal')<x-notice tone="warn" title="Not saved">{{ $message }}</x-notice>@enderror
    @if ($errors->any() && ! $errors->has('refusal'))
        <x-notice tone="warn" title="Not saved"><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></x-notice>
    @endif

    <form method="POST" action="{{ route('app.setup.suppliers.update', ['branch' => $supplier->BranchId, 'code' => $code]) }}"
          data-confirm="Write this to PumpIT?" data-confirm-action="Save supplier"
          data-confirm-text="PumpIT's BRN_Suppliers is changed for {{ $code }} {{ $supplier->SupplierName }} at {{ $supplier->BranchName }}; a new name at {{ $sites }} site(s). Each change and your reason are recorded, and can be put back."
          data-diff="PumpIT BRN_Suppliers · {{ $code }} {{ $supplier->SupplierName }} · {{ $supplier->BranchName }} (a new name: {{ $sites }} site(s){{ $differs ? ', '.$differs.' named differently today' : '' }})"
          data-diff-note="Each change and your reason are recorded, and can be put back. Refused if somebody changes the supplier first.">
        @csrf @method('PUT')
        <input type="hidden" name="ExpectedSupplierName" value="{{ $supplier->SupplierName }}">
        <input type="hidden" name="ExpectedContactPerson" value="{{ $supplier->ContactPerson }}">
        <input type="hidden" name="ExpectedDepartment" value="{{ $supplier->Department }}">
        <input type="hidden" name="ExpectedIsActive" value="{{ $supplier->Status === 'Active' ? 1 : 0 }}">
        <input type="hidden" name="ExpectedIsCash" value="{{ (int) $supplier->IsCash }}">
        <input type="hidden" name="ExpectedIsStock" value="{{ (int) $supplier->IsStock }}">
        <x-card title="The supplier" :sub="$code.' · the name at every site, the rest at '.$supplier->BranchName">
            <div class="field-row">
                <x-field name="SupplierName" label="Name" :value="old('SupplierName', $supplier->SupplierName)" help="Up to 55 characters. Written at every site carrying the code."
                         data-diff-field="Name" :data-diff-old="(string) $supplier->SupplierName" />
                <x-field name="ContactPerson" label="Contact" :value="old('ContactPerson', $supplier->ContactPerson)" help="Up to 50 characters."
                         data-diff-field="Contact" :data-diff-old="(string) $supplier->ContactPerson" />
                <x-field name="Department" label="Department" :value="old('Department', $supplier->Department)" help="Up to 30 characters."
                         data-diff-field="Department" :data-diff-old="(string) $supplier->Department" />
            </div>
            <div class="field-row">
                <x-field name="IsActive" type="bool" label="Active" placeholder="Active — offered at this site"
                         data-diff-field="Active" :data-diff-old="$supplier->Status === 'Active' ? 1 : 0" data-diff-on="Active" data-diff-off="Inactive"
                         :value="(bool) old('IsActive', $supplier->Status === 'Active')" />
                <x-field name="IsCash" type="bool" label="Paid in cash" placeholder="Paid in cash at this site — offered on the cashup's Suppliers leg"
                         data-diff-field="Cash" :data-diff-old="(int) $supplier->IsCash" :value="(bool) old('IsCash', $supplier->IsCash)" />
                <x-field name="IsStock" type="bool" label="Stock supplier" placeholder="Supplies stock"
                         data-diff-field="Stock" :data-diff-old="(int) $supplier->IsStock" :value="(bool) old('IsStock', $supplier->IsStock)" />
            </div>
            <x-field name="Reason" label="Reason for the change" :value="old('Reason')" help="At least {{ (int) config('partners.reason_min', 10) }} characters. Kept with every value you change." />
            <div class="form-actions"><button class="btn-primary" type="submit">Save supplier</button></div>
        </x-card>
    </form>
</x-app-shell>
