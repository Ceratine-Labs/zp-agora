<x-app-shell :title="$area['label'].' — auto reconciliation'">
    <x-page-head
        eyebrow="Auto reconciliation"
        :title="$area['label']"
        :blurb="$area['blurb']">
        <x-slot:actions>
            <a class="btn-ghost" href="{{ route('app.recon.index') }}">All areas</a>
        </x-slot:actions>
    </x-page-head>

    @if (session('discarded') !== null)
        <x-notice tone="info" :title="session('discarded') === 0 ? 'Nothing to discard' : session('discarded').' '.Str::plural('preview', session('discarded')).' discarded'" style="margin-bottom:16px">
            <p>Previews only. Nothing in the customer's databases was touched, and any run that had been
               executed was kept — a committed run is the only record of what it stamped.</p>
        </x-notice>
    @endif

    @if (session('refusal'))
        {{-- "Nothing to reconcile" and "this branch has no rule configured" are
             opposite answers, and on the live system that distinction is
             finding 1: twenty-four of twenty-six branches produce nothing and
             the screen never says why. --}}
        {{-- Open by default: a refusal is the answer, not context for one. --}}
        <x-notice tone="stop" title="The procedure could not run for this branch" style="margin-bottom:16px">
            <p>{{ session('refusal') }}</p>
            @if (session('refusalDetail'))
                <p><code>{{ collect(session('refusalDetail'))->map(fn ($v, $k) => "$k = ".($v ?? 'null'))->implode('   ') }}</code></p>
            @endif
            @if (session('refusalProcedure'))
                <p class="muted">Reported by <code>{{ session('refusalProcedure') }}</code>.
                   Nothing was read or written beyond the configuration lookup.</p>
            @endif
        </x-notice>
    @endif

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

    <x-card title="Recent previews here" sub="Runs in this area, newest first" collapsible flush>
        @if ($recent->isNotEmpty())
            <x-slot:actions>
                {{-- A clerk previews the same month several times while
                     narrowing the dates. The list of attempts is not the work. --}}
                <form method="POST" action="{{ route('app.recon.clear') }}"
                      data-confirm="Discard the previews for {{ $area['label'] }}?"
                      data-confirm-text="Nothing in PumpIT is affected — a preview is a record of a read. Any run that has been executed is kept."
                      data-confirm-action="Discard previews" data-confirm-danger>
                    @csrf
                    @method('DELETE')
                    <input type="hidden" name="area" value="{{ $area['key'] }}">
                    <button type="submit" class="btn-ghost">Clear previews</button>
                </form>
            </x-slot:actions>
        @endif

        <x-table :count="$recent->count()" empty="No preview has been run for this area yet.">
            <x-slot:head>
                <tr>
                    <th>Run</th><th>Period</th><th class="num">Rows</th>
                    <th class="num">Would reconcile</th><th class="num">Bank only</th><th>Run at</th>
                </tr>
            </x-slot:head>
            @foreach ($recent as $past)
                <tr>
                    <td><a href="{{ route('app.recon.run', $past) }}">#{{ $past->Id }}</a></td>
                    <td class="mono">{{ $past->FromDate?->toDateString() }} → {{ $past->ToDate?->toDateString() }}</td>
                    <td class="num">{{ \App\Support\Format::n($past->TotalRows) }}</td>
                    <td class="num">{{ \App\Support\Format::n($past->MatchedRows) }}</td>
                    <td class="num">{{ \App\Support\Format::n($past->BankOnlyRows) }}</td>
                    <td class="muted">{{ $past->CreatedAt?->diffForHumans() }}</td>
                </tr>
            @endforeach
        </x-table>
    </x-card>
</x-app-shell>
