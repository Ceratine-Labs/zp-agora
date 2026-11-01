<x-app-shell title="Build weekly stock investigation">
    <x-page-head eyebrow="Control · Stock" title="Build weekly stock investigation">
        <x-slot:actions><a class="btn-ghost" href="{{ route('app.stockrecon.weekly') }}">Weekly investigations</a></x-slot:actions>
    </x-page-head>
    <x-card title="Capture one site's week" sub="Save the source evidence and open investigation cases for a completed Monday to Sunday week.">
        <p>The snapshot preserves the figures read now. Building again creates a separate revision; existing findings stay with their original evidence. Incomplete days are held at the first incomplete day.</p>
        <form method="POST" action="{{ route('app.stockrecon.weekly.store') }}">
            @csrf
            @error('refusal')<x-notice tone="stop">{{ $message }}</x-notice>@enderror
            <div class="field-row">
                <div class="field"><label for="weekly-site">Site</label><select name="branch_id" id="weekly-site" data-select required>
                    <option value="">Choose a site</option>
                    @foreach ($sites as $site)<option value="{{ $site->BranchId }}" @selected((string) old('branch_id') === (string) $site->BranchId)>{{ $site->Name }}</option>@endforeach
                </select>@error('branch_id')<p class="field-error">{{ $message }}</p>@enderror</div>
                <x-field name="week_start" label="Monday" type="date" :value="old('week_start', $week)" required />
            </div>
            <div class="field-row">
                <x-field name="period_from" label="Control period from" type="date" :value="old('period_from')" help="Leave blank to use the selected week." />
                <x-field name="period_to" label="Control period through" type="date" :value="old('period_to')" help="Includes this date. The control period must contain the selected week." />
                <x-field name="reporting_as_of" label="Count recency as at" type="date" :value="old('reporting_as_of', \App\Support\BusinessDate::today()->toDateString())" />
            </div>
            <div class="form-actions"><button class="btn-primary" type="submit">Build investigation</button></div>
        </form>
    </x-card>
</x-app-shell>
