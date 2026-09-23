{{--
    The centre's one form: which site, which period, which readings.

    It POSTs the preview, as the Auto tab always has — auto balancing IS a
    recorded run, so it is reproducible and reversible — with `centre=1`, which
    sends the answer back to the centre with its run instead of to the run
    page. Shown in full before a scope is chosen and folded into
    <x-scope-line> after.

    Expects the area frame: $area, $branches, $branchId, $pinned, $from, $to,
    $options.
--}}
<form method="POST" action="{{ route('app.recon.preview') }}" data-loader="Balancing {{ $area['label'] }}…">
    @csrf
    <input type="hidden" name="area" value="{{ $area['key'] }}">
    <input type="hidden" name="centre" value="1">

    <div class="field-row">
        {{-- The branches component lives on the result set, not in the
             chrome (feature-rules §3.3). In the branch workspace the site is
             already pinned, so it states it rather than offering a choice
             that does not exist. --}}
        @if ($pinned)
            <div class="field">
                <label>Site</label>
                <p class="field-fixed">{{ $branches->firstWhere('BranchId', $branchId)?->Name ?? 'Not set' }}</p>
                <p class="field-help">Your workspace is pinned to this site.</p>
            </div>
            <input type="hidden" name="branch_id" value="{{ $branchId }}">
        @else
            <x-field name="branch_id" label="Site"
                     :choices="$branches->pluck('Name', 'BranchId')->all()"
                     :value="old('branch_id', $branchId)"
                     help="The site whose bank statement is being reconciled. Trading sites only — the administrative entities keep no banking." />
        @endif

        <x-field name="from" label="From" type="date" :value="old('from', $from)"
                 help="Bank line date and deposit date, inclusive." />

        <x-field name="to" label="To" type="date" :value="old('to', $to)" />

        {{-- What to call it. A clerk previews the same month several times
             while narrowing the dates, and "August ABSA, second attempt" is
             how they find the one they meant an hour later. --}}
        <x-field name="note" label="Name this run" :value="old('note')"
                 help="Optional. Yours to find it by — it appears on the Runs tab and on the run itself." />
    </div>

    @if ($options)
        <details class="params-extra" @if (old('options')) open @endif>
            <summary>Rules and readings ({{ count($options) }})</summary>
            <p class="field-help" style="margin:8px 0 12px">
                Each of these exists because something in
                <code>BRN_AutoReconCriteria</code> or in the data is ambiguous and we would not
                guess on the customer's behalf. The defaults are the reading the live system uses.
            </p>
            <div class="field-row">
                @foreach ($options as $name => $option)
                    <x-field :name="'options['.$name.']'"
                             :label="$option['label']"
                             :help="$option['help']"
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
        <button type="submit" class="btn-primary">Reconcile</button>
        <span class="field-help">Runs the automatic balancing as a preview — nothing in PumpIT changes until
            you execute. Suggestions and the manual match open beside it, over the same site and dates.</span>
    </div>
</form>
