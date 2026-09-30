{{--
    Registering an asset, or changing one. The same fields both ways; what
    differs is whether the site is asked for (a registration, in head office)
    and whether a reason is (every change).

    The type picker sends "GROUP|TYPE" as one value, because a type code is
    only unique within its group — CE28 exists twice on the customer's list —
    and SaveAssetRequest splits it. Every rule (the type exists, the owner is
    on the list, the serial is not already on a register, the supplier is set
    up at the site) is agora.usp_Assets_SaveAsset's.

    Props: $modalId, $modalTitle, $action, $method (POST|PUT), $asset (object|null),
           $masters, $branches, $pinned (int|null — registration only)
--}}
@php
    $editing = $asset !== null;
    $old = fn (string $key, mixed $current = null) => old($key, $current);

    $typeChoices = ['' => 'Choose the type'];
    foreach ($masters['types'] as $t) {
        $typeChoices[$t->AssetGroupCode.'|'.$t->AssetTypeCode] = $t->AssetGroupCode.' · '.$t->AssetTypeCode.' '.$t->AssetTypeDescription;
    }

    $ownerChoices = ['' => 'The site itself'] + $masters['owners']->pluck('AssetOwner', 'AssetOwner')->all();
    $conditionChoices = $masters['conditions']->pluck('Label', 'Condition')->all();
    $branchChoices = $branches->pluck('Name', 'BranchId')->all();
@endphp

<x-modal :id="$modalId" :title="$modalTitle" wide>
    <form method="POST" action="{{ $action }}">
        @csrf
        @if ($method !== 'POST')
            @method($method)
        @endif

        @unless ($editing)
            @if ($pinned)
                <input type="hidden" name="BranchId" value="{{ $pinned }}">
            @else
                <div class="field-row">
                    <x-field name="BranchId" label="Belongs to"
                             :choices="['' => 'Choose the site'] + $branchChoices"
                             :value="$old('BranchId')"
                             help="The site whose register it goes on. It keeps this site even when it is moved." />
                    <x-field name="LocatedBranchId" label="Standing at"
                             :choices="['' => 'The same site'] + $branchChoices"
                             :value="$old('LocatedBranchId')"
                             help="Only when it is already somewhere else — on loan, say. After this, only a movement changes it." />
                </div>
            @endif
        @endunless

        <x-params legend="What it is">
            <x-param name="AssetType" label="Type" searchable
                     :choices="$typeChoices"
                     :value="$old('AssetType', $editing ? $asset->AssetGroupCode.'|'.$asset->AssetTypeCode : null)"
                     help="The customer's own list of groups and types. A new type is added in PumpIT." />
        </x-params>

        <div class="field-row">
            <x-field name="Make" label="Make" :value="$old('Make', $asset?->Make)" />
            <x-field name="Model" label="Model" :value="$old('Model', $asset?->Model)" />
            <x-field name="SerialNo" label="Serial no" :value="$old('SerialNo', $asset?->SerialNo)"
                     help="The same make and serial cannot be on two registers — move the asset instead." />
        </div>

        <x-field name="Description" label="Description" :value="$old('Description', $asset?->Description)"
                 help="Optional. Where it is, what it is for — 'walk-in cold room, butchery'." />

        <div class="field-row">
            <x-field name="DatePurchased" label="Bought" type="date"
                     :value="$old('DatePurchased', $editing ? substr((string) $asset->DatePurchased, 0, 10) : null)"
                     help="Depreciation starts in this month." />
            <x-field name="DateAllocated" label="Allocated to the site" type="date"
                     :value="$old('DateAllocated', $editing && $asset->DateAllocated ? substr((string) $asset->DateAllocated, 0, 10) : null)" />
            <x-field name="Cost" label="Cost (excl VAT)" type="number" step="0.01" min="0"
                     :value="$old('Cost', $asset?->Cost)" />
        </div>

        <div class="field-row">
            <x-field name="Condition" label="Condition" :choices="$conditionChoices"
                     :value="$old('Condition', $asset?->ConditionCode ?? 'GOOD')" />
            <x-field name="AssetOwner" label="Owner" :choices="$ownerChoices"
                     :value="$old('AssetOwner', $asset?->AssetOwner)"
                     help="Only when somebody else owns it — a brewery's fridge, an oil company's pump." />
            <x-field name="SupplierCode" label="Supplier code" :value="$old('SupplierCode', $asset?->SupplierCode)"
                     placeholder="APE-0211"
                     help="Who it was bought from, as the site's supplier list has them." />
        </div>

        @if ($editing)
            <x-field name="reason" label="Why is it changing" :value="old('reason')"
                     help="Required. Kept against the change — a cost or a purchase date moved without a reason is what the old register was full of." />
        @endif

        <div class="btn-row">
            <button type="submit" class="btn-primary">{{ $editing ? 'Save the asset' : 'Register it' }}</button>
        </div>
    </form>
</x-modal>
