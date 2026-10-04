{{--
    Tab three: every site at once.

    The form is the Auto tab's form with the site taken out, and that absence
    is the whole feature — the clerks run the same area for twenty-six branches
    one at a time, and the only thing that changes between those presses is the
    site.
--}}
<x-card title="Run every site"
        :sub="$sites->count().' trading '.Str::plural('site', $sites->count()).' to preview'">
    <x-slot:info>
        <p>One period and one set of readings, previewed for every site one at a time. Each site is recorded as
           its own run, so a site that refuses is a row rather than a group that half happened.</p>
        <p>The readings are stored once on the group and given to every site, so the runs in a group cannot
           disagree about what they were given.</p>
    </x-slot:info>
    @include('recon::partials.unconfigured-sites', ['unconfigured' => $unconfigured, 'area' => $area])

    <form method="POST" action="{{ route('app.recon.all.start', $area['key']) }}" data-loader="Starting every site…">
        @csrf
        <input type="hidden" name="area" value="{{ $area['key'] }}">

        <div class="field-row">
            <x-field name="from" label="From" type="date" :value="old('from', $from)"
                     info="Bank line date and deposit date, inclusive. The same period is used for every site." />

            <x-field name="to" label="To" type="date" :value="old('to', $to)" />

            <x-field name="note" label="Name this run" :value="old('note')"
                     info="Optional. It goes on every run in the group, so one name finds them all." />
        </div>

        @if ($options)
            <details class="params-extra" @if (old('options')) open @endif>
                <summary>Rules and readings ({{ count($options) }})</summary>
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
            <button type="submit" class="btn-primary">Preview every site</button>
            <span class="field-help">Read-only. Nothing in PumpIT changes until you post the group.</span>
        </div>
    </form>
</x-card>

<x-card title="Recent group runs" sub="The last five, every site in this area" collapsible flush>
    <x-table :count="$groups->count()" empty="No group has been run in this area yet.">
        <x-slot:head>
            <tr>
                <th>Group</th><th>Period</th><th class="num">Sites</th>
                <th class="num">Done</th><th class="num">Refused</th><th>Status</th><th>Run at</th>
            </tr>
        </x-slot:head>
        @foreach ($groups as $past)
            <tr>
                <td><a href="{{ route('app.recon.group', $past->GroupRef) }}">{{ $past->Note ?: 'Group #'.$past->Id }}</a></td>
                <td class="mono">{{ $past->FromDate?->toDateString() }} → {{ $past->ToDate?->toDateString() }}</td>
                <td class="num">{{ \App\Support\Format::n($past->BranchCount) }}</td>
                <td class="num">{{ \App\Support\Format::n($past->CompletedCount) }}</td>
                <td class="num">{{ \App\Support\Format::n($past->FailedCount) }}</td>
                <td><x-chip :tone="$past->Status === 'committed' ? 'good' : ($past->Status === 'running' ? 'warn' : 'neutral')">{{ $past->Status }}</x-chip></td>
                <td class="muted">{{ $past->CreatedAt?->diffForHumans() }}</td>
            </tr>
        @endforeach
    </x-table>
</x-card>
