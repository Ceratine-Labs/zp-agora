{{--
    Tab one: the automatic preview.

    Lifted out of area.blade.php unchanged when the area grew four tabs. The
    scope above it — site, dates, stamp mode — is resolved once by
    ReconController::workbench() and is the same on every pane; what is here is
    only what the AUTOMATIC reconciliation adds to it.
--}}
    @include('recon::partials.open-run')

    <x-card title="Preview"
            :sub="'Reads '.$area['legacy'].'\'s two sides and compares them. Writes nothing.'">
            <form method="POST" action="{{ route('app.recon.preview') }}">
                @csrf
                <input type="hidden" name="area" value="{{ $area['key'] }}">

                <div class="field-row">
                    {{-- The branches component lives on the result set, not in
                         the chrome (feature-rules §3.3). In the branch
                         workspace the site is already pinned, so it states it
                         rather than offering a choice that does not exist. --}}
                    @if ($pinned)
                        <div class="field">
                            <label>Site</label>
                            <p class="field-fixed">{{ $branches->firstWhere('BranchId', $branchId)?->Name ?? 'Not set' }}</p>
                            <p class="field-help">Your workspace is pinned to this site.</p>
                        </div>
                        <input type="hidden" name="branch_id" value="{{ $branchId }}">
                    @else
                        <x-field name="branch_id" label="Branch"
                                 :choices="$branches->pluck('Name', 'BranchId')->all()"
                                 :value="old('branch_id', $branchId)"
                                 help="The site whose bank statement is being reconciled. Trading sites only — the administrative entities keep no banking." />
                    @endif

                    <x-field name="from" label="From" type="date" :value="old('from', $from)"
                             help="Bank line date and deposit date, inclusive." />

                    <x-field name="to" label="To" type="date" :value="old('to', $to)" />

                    {{-- What to call it. A clerk previews the same month
                         several times while narrowing the dates, and "August
                         ABSA, second attempt" is how they find the one they
                         meant an hour later. Optional: an unnamed run is
                         listed by its period rather than by a dash. --}}
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
                    <button type="submit" class="btn-primary">Preview</button>
                    <span class="field-help">Read-only. Nothing in PumpIT changes.</span>
                </div>
            </form>
    </x-card>

    {{--
        The run list lives on the Runs tab now, as a real grid — filterable,
        sortable, extractable and scoped to the person who made the runs. A
        five-row summary here as well would be a second list that drifts from
        it, which is the duplication the component rule exists to stop.
    --}}
    <p class="muted" style="margin-top:16px">
        Everything previewed in this area is on
        <a href="{{ route('app.recon.runs', $area['key']) }}">the Runs tab</a> — yours by default,
        everyone's on request, with filters and an extract.
    </p>
