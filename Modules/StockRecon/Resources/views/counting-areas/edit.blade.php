<x-app-shell :title="'Classify '.$area->AreaDescription">
    <x-page-head eyebrow="Control · Stock · Counting areas" :title="'Classify '.$area->AreaDescription">
        <x-slot:actions><a class="btn" href="{{ route('app.stockrecon.countingareas.show', ['branch' => $area->BranchId, 'area' => $area->AreaNo]) }}">Back to area</a></x-slot:actions>
    </x-page-head>
    @include('stockrecon::counting-areas.detail')
    @error('refusal')<x-notice tone="warn" title="Confirmation refused">{{ $message }}</x-notice>@enderror
    <x-card title="Confirm prepared-food classification" :sub="$area->BranchName">
        <p>Prepared-food areas are protected from clear duplicate-issue removal. Choose No only after checking this area. Changes apply to future previews; existing previews keep the classification used when they were made.</p>
        @can('stockrecon.countingareas.save')
        <form method="POST" action="{{ route('app.stockrecon.countingareas.update', ['branch' => $area->BranchId, 'area' => $area->AreaNo]) }}"
              data-confirm="Confirm this counting area's classification?" data-confirm-text="Your choice and reason will be recorded in the area's history." data-confirm-action="Confirm classification">
            @csrf @method('PUT')
            <input type="hidden" name="ExpectedVersion" value="{{ old('ExpectedVersion', $area->VersionToken) }}">
            <input type="hidden" name="ExpectedFood" value="{{ old('ExpectedFood', (int) $area->IsPreparedFood) }}">
            <x-field name="IsPreparedFood" label="Prepared food" :choices="[1 => 'Yes — prepared food', 0 => 'No — not prepared food']" :value="old('IsPreparedFood', (int) $area->IsPreparedFood)" />
            <x-field name="reason" label="Reason for this confirmation" :value="old('reason')" help="At least 10 characters. Describe what you checked." />
            <x-field name="confirm" type="bool" label="Explicit confirmation" placeholder="I have checked this area's prepared-food classification" :value="false" />
            <div class="form-actions"><button type="submit" class="btn primary">Confirm classification</button></div>
        </form>
        @else
            <p>You can review this area. Confirming a classification requires the counting-area save permission.</p>
        @endcan
    </x-card>
</x-app-shell>
