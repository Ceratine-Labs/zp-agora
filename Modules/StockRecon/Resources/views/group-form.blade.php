<x-app-shell title="Every-site stock preview">
    <x-page-head eyebrow="Control · stock" title="Every-site stock preview">
        <x-slot:actions><a class="btn-ghost" href="{{ route('app.stockrecon.index') }}">Stock recon centre</a></x-slot:actions>
    </x-page-head>
    @can('stockrecon.runs.create')
    <x-card title="Preview selected sites" sub="One week, every counting area. Review and commit each site's proposals from its own run.">
        <form method="POST" action="{{ route('app.stockrecon.groups.start') }}">
            @csrf
            <div class="field-row">
                <div class="field">
                    <label for="stock-group-sites">Sites</label>
                    <select id="stock-group-sites" name="branches[]" multiple data-select required>
                        @foreach ($sites as $site)
                            <option value="{{ $site->BranchId }}" @selected(in_array($site->BranchId, old('branches', $sites->pluck('BranchId')->all())))>{{ $site->Name }}</option>
                        @endforeach
                    </select>
                    @error('branches')<p class="field-error">{{ $message }}</p>@enderror
                </div>
                <x-field name="from" label="From" type="date" :value="old('from', $from)" :max="$today" />
                <x-field name="note" label="Name this group" :value="old('note')" />
            </div>
            <div class="form-actions"><button class="btn-primary" type="submit">Preview selected sites</button><span class="field-help">Previews only. Nothing in PumpIT changes.</span></div>
        </form>
    </x-card>
    @endcan
    @if ($recent->isNotEmpty())
        <x-card title="Your recent groups">
            @foreach ($recent as $recentGroup)
                <p><a href="{{ route('app.stockrecon.group', $recentGroup->GroupRef) }}">{{ $recentGroup->Note ?: $recentGroup->FromDate.' to '.$recentGroup->ToDate }}</a> · {{ $recentGroup->SiteCount }} sites</p>
            @endforeach
        </x-card>
    @endif
</x-app-shell>
