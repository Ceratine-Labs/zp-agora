{{--
    Raising a job card, or updating one.

    The purchase request number comes first, because it is where the cost
    comes from (Ryan, 27 Sep 2026) and a card with a request can take its
    supplier from it. agora.usp_Assets_SaveRepair looks the request up at the
    site the asset stands at, refuses a declined one, and refuses a supplier
    that is not the request's.

    Props: $modalId, $modalTitle, $action, $method (POST|PUT), $repair
           (object|null), and EITHER $assetKey OR $assets (the picker).
--}}
@php
    $editing = $repair !== null;
    $d = fn ($v) => $v === null ? null : substr((string) $v, 0, 10);
@endphp

<x-modal :id="$modalId" :title="$modalTitle" wide>
    <form method="POST" action="{{ $action }}">
        @csrf
        @if ($method !== 'POST')
            @method($method)
        @endif

        @if (! $editing)
            @if (! empty($assetKey))
                <input type="hidden" name="asset" value="{{ $assetKey }}">
            @else
                <x-params legend="Which asset">
                    <x-param name="asset" label="Asset" searchable
                             :choices="['' => 'Choose the asset'] + $assets->pluck('Label', 'PickKey')->all()"
                             :value="old('asset')"
                             help="Assets still on a register at the sites you can see." />
                </x-params>
            @endif
        @endif

        <div class="field-row">
            <x-field name="CallDate" label="Called in" type="date"
                     :value="old('CallDate', $editing ? $d($repair->CallDate) : now()->toDateString())"
                     help="The day the repair was called in. The comeback rule measures between these." />
            <x-field name="TransactionNo" label="Purchase request" :value="old('TransactionNo', $repair?->TransactionNo)"
                     placeholder="CR000550"
                     help="The request that pays for it, at the site the asset stands at. Can be added later." />
        </div>

        <div class="field-row">
            <x-field name="SupplierCode" label="Supplier code" :value="old('SupplierCode', $repair?->SupplierCode)"
                     placeholder="APE-0211"
                     help="Leave it empty and the purchase request's supplier is used." />
            <x-field name="SupplierReference" label="Supplier's reference" :value="old('SupplierReference', $repair?->SupplierReference)" />
        </div>

        <x-field name="Problem" label="What is wrong" :value="old('Problem', $repair?->Problem)" />

        <div class="field-row">
            <x-field name="DateExpectedReturn" label="Expected back" type="date"
                     :value="old('DateExpectedReturn', $editing ? $d($repair->DateExpectedReturn) : null)"
                     help="Only when it leaves the site, or a date was promised." />
            <x-field name="DateActualReturn" label="Back / done" type="date"
                     :value="old('DateActualReturn', $editing ? $d($repair->DateActualReturn) : null)"
                     help="When it came back or the repair was finished." />
        </div>

        <div class="btn-row">
            <button type="submit" class="btn-primary">{{ $editing ? 'Save the job card' : 'Raise it' }}</button>
        </div>
    </form>
</x-modal>
