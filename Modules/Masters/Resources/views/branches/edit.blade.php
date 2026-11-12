@php
    $choices = fn (string $kind) => ['' => '—'] + collect($groupings->get($kind, []))
        ->mapWithKeys(fn ($g) => [$g->Id => $g->Name.($g->IsActive ? '' : ' (switched off)')])->all();
    $ticked = array_map('intval', old('ProfitCentreIds', $profitCentres->pluck('ProfitCentreId')->all()));
@endphp
<x-app-shell :title="'Edit '.$branch->Name">
    <x-page-head eyebrow="Setup · The business · Branches" :title="'Edit '.$branch->Name" :blurb="'Branch '.$branch->BranchId.' — the id is PumpIT\'s and does not change.'">
        <x-slot:actions><a class="btn" href="{{ route('app.setup.branches.show', ['branch' => $branch->BranchId]) }}">Back to branch</a></x-slot:actions>
    </x-page-head>
    @error('refusal')<x-notice tone="warn" title="Branch not saved">{{ $message }}</x-notice>@enderror
    @if ($errors->any() && ! $errors->has('refusal'))
        <x-notice tone="warn" title="Branch not saved"><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></x-notice>
    @endif
    <form method="POST" action="{{ route('app.setup.branches.update', ['branch' => $branch->BranchId]) }}"
          data-confirm="Save this branch?" data-confirm-text="Each change and your reason are recorded in the branch's history." data-confirm-action="Save branch">
        @csrf @method('PUT')
        <input type="hidden" name="ExpectedVersion" value="{{ old('ExpectedVersion', $branch->Version) }}">
        <x-card title="The branch">
            <div class="field-row">
                <x-field name="Name" label="Name" :value="old('Name', $branch->Name)" :help="'In PumpIT: '.($branch->LegacyName ?? '—')" />
                <x-field name="Status" label="Status" :choices="array_combine($statuses, $statuses)" :value="old('Status', $branch->Status)"
                         help="Mothballed and non-trading branches leave the trading lists and the site picker." />
                <x-field name="POSType" label="POS type" :choices="array_combine($posTypes, $posTypes)" :value="old('POSType', $branch->POSType)"
                         help="NAMOS where day-end comes from NAMOS (Total Hluhluwe)." />
            </div>
            <div class="field-row">
                <x-field name="RegionId" label="Region" :choices="$choices('Region')" :value="old('RegionId', $branch->RegionId)" />
                <x-field name="BrandId" label="Brand" :choices="$choices('Brand')" :value="old('BrandId', $branch->BrandId)" />
                <x-field name="FormatId" label="Format" :choices="$choices('Format')" :value="old('FormatId', $branch->FormatId)" />
            </div>
            <div class="field-row">
                <x-field name="IsAdminEntity" type="bool" label="Administrative entity" placeholder="A branch for accounting only — never trades"
                         :value="(bool) old('IsAdminEntity', $branch->IsAdminEntity)" help="An administrative entity must be Non-trading." />
                <x-field name="IsActive" type="bool" label="Active" placeholder="Active" :value="(bool) old('IsActive', $branch->IsActive)" />
            </div>
        </x-card>
        <x-card title="Profit centres" sub="What this site runs">
            <div class="role-list">
                @foreach ($allProfitCentres as $centre)
                    <label class="role-row">
                        <input type="checkbox" name="ProfitCentreIds[]" value="{{ $centre->ProfitCentreId }}" @checked(in_array((int) $centre->ProfitCentreId, $ticked, true))>
                        <span class="role-name">{{ $centre->Name }}</span>
                    </label>
                @endforeach
            </div>
        </x-card>
        <x-card title="Why">
            <x-field name="Reason" label="Reason for the change" :value="old('Reason')" help="At least 10 characters. It is kept with every field you change." />
        </x-card>
        <div class="form-actions"><button class="btn-primary" type="submit">Save branch</button></div>
    </form>
</x-app-shell>
