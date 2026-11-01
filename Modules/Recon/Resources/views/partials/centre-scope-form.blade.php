{{-- Read the selected period first; only the explicit auto action records a preview. --}}
@php($preview = $preview ?? false)
<form method="{{ $preview ? 'POST' : 'GET' }}" action="{{ $preview ? route('app.recon.preview') : route('app.recon.area', $area['key']) }}" data-loader="{{ $preview ? 'Preparing auto reconciliation…' : 'Reading both sides…' }}">
    @if ($preview)
        @csrf
        <input type="hidden" name="area" value="{{ $area['key'] }}">
        <input type="hidden" name="centre" value="1">
    @else
        <input type="hidden" name="tab" value="match">
    @endif

    <div class="field-row">
        {{-- The branches component lives on the result set, not in the
             chrome (feature-rules §3.3). In the branch workspace the site is
             already pinned, so it states it rather than offering a choice
             that does not exist. --}}
        @if ($pinned)
            <div class="field">
                <label>Site</label>
                <p class="field-fixed">{{ $branches->firstWhere('BranchId', $branchId)?->Name ?? 'Not set' }}</p>
            </div>
            <input type="hidden" name="branch_id" value="{{ $branchId }}">
        @else
            <x-field name="branch_id" label="Site"
                     :choices="$branches->pluck('Name', 'BranchId')->all()"
                     :value="old('branch_id', $branchId)"
                     info="Trading sites only — the administrative entities keep no banking." />
        @endif

        <x-field name="from" label="From" type="date" :value="old('from', $from)"
                 info="Bank line date and deposit date, inclusive." />

        <x-field name="to" label="To" type="date" :value="old('to', $to)" />

        {{-- What to call it. A clerk previews the same month several times
             while narrowing the dates, and "August ABSA, second attempt" is
             how they find the one they meant an hour later. --}}
        @if ($preview)
        <x-field name="note" label="Name this run" :value="old('note')"
                 info="Optional. Yours to find it by on the Runs tab." />
        @else
            <x-field name="state" label="Show"
                     :choices="['outstanding' => 'Still outstanding', 'reconciled' => 'Already reconciled', 'all' => 'Everything']"
                     :value="$state ?? 'outstanding'" />
        @endif
    </div>

    @if ($preview && $options)
        <details class="params-extra" @if (old('options')) open @endif>
            <summary>Rules and readings ({{ count($options) }})</summary>
            {{-- Why the readings exist is on the "What to reconcile" card's
                 info button; each reading's own meaning is on its label. --}}
            <div class="field-row" style="margin-top:12px">
                @foreach ($options as $name => $option)
                    <x-field :name="'options['.$name.']'"
                             :label="$option['label']"
                             :info="$option['help']"
                             :type="$option['type'] ?? 'text'"
                             :choices="$option['choices'] ?? null"
                             :min="$option['min'] ?? null"
                             :max="$option['max'] ?? null"
                             :value="old('options.'.$name, $option['default'])" />
                @endforeach
            </div>
        </details>
    @endif

    <div class="form-actions">
        <button type="submit" class="btn-primary">{{ $preview ? 'Run auto balancing' : 'Show both sides' }}</button>
        <span class="field-help">{{ $preview ? 'A preview — nothing in PumpIT changes until you execute.' : 'Read-only — no run is created.' }}</span>
    </div>
</form>
