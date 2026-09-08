{{--
    Tab three: every site at once.

    The form is the Auto tab's form with the site taken out, and that absence
    is the whole feature — the clerks run the same area for twenty-six branches
    one at a time, and the only thing that changes between those presses is the
    site.
--}}
<x-card title="Run every site"
        :sub="'A period and the readings. '.$sites->count().' trading '.Str::plural('site', $sites->count()).' will be previewed, one at a time.'">
    <form method="POST" action="{{ route('app.recon.all.start', $area['key']) }}">
        @csrf
        <input type="hidden" name="area" value="{{ $area['key'] }}">

        <div class="field-row">
            <x-field name="from" label="From" type="date" :value="old('from', $from)"
                     help="Bank line date and deposit date, inclusive. The same period is used for every site." />

            <x-field name="to" label="To" type="date" :value="old('to', $to)" />

            <x-field name="note" label="Name this run" :value="old('note')"
                     help="Optional, and it goes on every run in the group — 'August month end' finds all twenty-six." />
        </div>

        @if ($options)
            <details class="params-extra" @if (old('options')) open @endif>
                <summary>Rules and readings ({{ count($options) }})</summary>
                <p class="field-help" style="margin:8px 0 12px">
                    Stored once on the group and given to every site, so a group whose runs disagree
                    about the reading they were given cannot happen.
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
            <button type="submit" class="btn-primary">Preview every site</button>
            <span class="field-help">Read-only. Nothing in PumpIT changes until you post the group.</span>
        </div>
    </form>
</x-card>

<x-card title="Recent group runs" sub="The last five presses across every site in this area" collapsible flush>
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
