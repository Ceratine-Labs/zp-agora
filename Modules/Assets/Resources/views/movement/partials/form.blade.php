{{--
    Recording a movement. One form for all six types; which fields matter
    depends on the type, and agora.usp_Assets_Move is what refuses the wrong
    combination — a transfer with no destination, proceeds on a write-off, a
    return from repair for an asset that never went.

    Props: $modalId, $movementTypes, $branches, and EITHER $assetKey +
           $assetLabel (from an asset's page) OR $assets (the picker).
--}}
@php
    $typeChoices = ['' => 'What happened'];
    foreach ($movementTypes as $t) {
        if ($t->Kind !== null) {
            $typeChoices[$t->MovementType] = $t->MovementTypeDescription.' — '.$t->WhatItDoes;
        }
    }
    $branchChoices = ['' => 'Where it stands now'] + $branches->pluck('Name', 'BranchId')->all();
@endphp

<x-modal :id="$modalId" title="Record a movement" wide>
    <form method="POST" action="{{ route('app.assets.movement.store') }}">
        @csrf

        @if (! empty($assetKey))
            <input type="hidden" name="asset" value="{{ $assetKey }}">
            <p class="field-help" style="margin-top:0"><strong>{{ $assetLabel }}</strong></p>
        @else
            <x-params legend="Which asset">
                <x-param name="asset" label="Asset" searchable
                         :choices="['' => 'Choose the asset'] + $assets->pluck('Label', 'PickKey')->all()"
                         :value="old('asset')" />
            </x-params>
        @endif

        <div class="field-row">
            <x-field name="MovementType" label="Movement" :choices="$typeChoices" :value="old('MovementType')" />
            <x-field name="MovementDate" label="Date" type="date" :value="old('MovementDate', now()->toDateString())"
                     info="When it happened. Movements are kept in date order." />
        </div>

        <div class="field-row">
            <x-field name="ToBranchId" label="To site" :choices="$branchChoices" :value="old('ToBranchId')"
                     info="For a transfer, where it went. For a return from repair, where it came back to, if not where it left from." />
            <x-field name="Proceeds" label="Proceeds (excl VAT)" type="number" step="0.01" min="0" :value="old('Proceeds')"
                     info="Sold or traded in: what it fetched. Nothing on a write-off." />
        </div>

        <x-field name="reason" label="Reason" :value="old('reason')"
                 info="Required when it leaves the register — sold, traded in or written off." />

        <div class="btn-row">
            <button type="submit" class="btn-primary">Record it</button>
        </div>
    </form>
</x-modal>
