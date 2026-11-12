<x-app-shell title="Stock recon balancing configuration">
    <x-page-head eyebrow="Control · Stock · Counting areas" title="Stock recon balancing configuration">
        <x-slot:actions><a class="btn" href="{{ route('app.stockrecon.countingareas.show', ['branch' => $area->BranchId, 'area' => $area->AreaNo]) }}">Back to area</a></x-slot:actions>
    </x-page-head>
    @if (session('status'))<x-notice tone="good" title="Saved">{{ session('status') }}</x-notice>@endif
    @if ($errors->any())<x-notice tone="warn" title="Could not save">{{ $errors->first() }}</x-notice>@endif
    <x-card title="Profiles" sub="The generic profile supplies the run defaults. Item mappings use their selected custom profile for balancing.">
        <p>Profiles are shared across sites. An item without a mapping uses the generic profile. A changed profile or mapping requires a new preview before committing.</p>
        @foreach ($profiles as $profile)
            <details>
                <summary>{{ $profile->Name }}{{ $profile->IsDefault ? ' · Generic' : '' }}</summary>
                @if (auth()->user()->can('stockrecon.profiles.save'))
                <form method="post" action="{{ route('app.stockrecon.countingareas.profiles.update', ['branch' => $area->BranchId, 'area' => $area->AreaNo, 'profile' => $profile->Id]) }}">
                    @csrf @method('PUT')
                    <input type="hidden" name="revision" value="{{ $profile->Revision }}">
                    <x-field name="name" label="Profile name" :value="$profile->Name" />
                    <x-field name="MaxAmendment" label="Largest correction in units" type="number" min="1" max="999999" step="0.0001" :value="$profile->MaxAmendment" />
                    <x-field name="MaxAmendmentPct" label="Largest correction as share of stock" type="number" min="0.01" max="1" step="0.0001" :value="$profile->MaxAmendmentPct" />
                    <x-field name="MinShiftsInChain" label="Minimum active shifts" type="number" min="2" max="30" step="1" :value="$profile->MinShiftsInChain" />
                    <x-field name="KeepBreaksUpTo" label="Keep breaks up to units" type="number" min="0" max="99" step="0.0001" :value="$profile->KeepBreaksUpTo" />
                    @if ($profile->IsDefault)
                        <input type="hidden" name="HoldBackIncomplete" value="0">
                        <label><input type="checkbox" name="HoldBackIncomplete" value="1" @checked($profile->HoldBackIncomplete)> Hold back unfinished days by default</label>
                        <p>This setting applies to the whole preview period, rather than to individual items.</p>
                    @endif
                    <button class="btn primary" type="submit">Save profile</button>
                </form>
                @else
                    <p>Correction limit {{ $profile->MaxAmendment }}, share {{ $profile->MaxAmendmentPct }}, minimum shifts {{ $profile->MinShiftsInChain }}, keep breaks up to {{ $profile->KeepBreaksUpTo }}.</p>
                @endif
            </details>
        @endforeach
        @can('stockrecon.profiles.save')
        <details><summary>Create a custom profile</summary>
            <form method="post" action="{{ route('app.stockrecon.countingareas.profiles.store', ['branch' => $area->BranchId, 'area' => $area->AreaNo]) }}">
                @csrf
                <x-field name="name" label="Profile name" />
                <x-field name="MaxAmendment" label="Largest correction in units" type="number" min="1" max="999999" step="0.0001" value="9999" />
                <x-field name="MaxAmendmentPct" label="Largest correction as share of stock" type="number" min="0.01" max="1" step="0.0001" value="1" />
                <x-field name="MinShiftsInChain" label="Minimum active shifts" type="number" min="2" max="30" step="1" value="2" />
                <x-field name="KeepBreaksUpTo" label="Keep breaks up to units" type="number" min="0" max="99" step="0.0001" value="3" />
                <button class="btn primary" type="submit">Create profile</button>
            </form>
        </details>
        @endcan
    </x-card>
    <x-card title="Item mappings" :sub="$area->BranchName.' · '.$area->AreaDescription">
        @can('stockrecon.profiles.save')
        @if ($profiles->contains(fn ($profile) => ! $profile->IsDefault))
        <form method="post" action="{{ route('app.stockrecon.countingareas.profiles.map', ['branch' => $area->BranchId, 'area' => $area->AreaNo]) }}">
            @csrf
            <x-field name="item" label="Item number" />
            <label>Custom profile <select name="profile" required>@foreach ($profiles as $profile) @if (! $profile->IsDefault)<option value="{{ $profile->Id }}">{{ $profile->Name }}</option>@endif @endforeach</select></label>
            <button class="btn primary" type="submit">Map item</button>
        </form>
        @else <p>Create a custom profile before mapping an item.</p> @endif
        @endcan
        @if ($mappings->isEmpty())<p>No custom mappings. Items use the generic profile.</p>@else
        <x-table layout-key="StockRecon.counting-areas.profiles" tools>
            <thead><tr><th>Item</th><th>Profile</th><th></th></tr></thead><tbody>
            @foreach ($mappings as $mapping)
            <tr><td>{{ $mapping->StockItemNo }}</td><td>{{ $mapping->ProfileName }}</td><td>
                @can('stockrecon.profiles.save')
                <form method="post" action="{{ route('app.stockrecon.countingareas.profiles.unmap', ['branch' => $area->BranchId, 'area' => $area->AreaNo]) }}">@csrf @method('DELETE')<input type="hidden" name="item" value="{{ $mapping->StockItemNo }}"><button class="btn sm" type="submit">Use generic</button></form>
                @endcan
            </td></tr>
            @endforeach</tbody>
        </x-table>
        @endif
    </x-card>
</x-app-shell>
