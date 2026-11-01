@props(['report', 'params', 'context', 'branches', 'open' => false])
@php
    $today = \App\Support\BusinessDate::today()->toDateString();
    $from = $params['DateFrom'];
    $to = $params['DateTo'];
    $sort = $params['SortColumn'];
    $asc = (bool) $params['SortAsc'];
    $sortChoices = ['' => 'Report default'];
    foreach ($report['columns'] as $column) {
        if (! empty($column['sort'])) $sortChoices[$column['sort']] = $column['label'];
    }
    $sortLabel = $sort === null ? 'Report default order' : ($sortChoices[$sort] ?? 'Report default').($asc ? ' ↑' : ' ↓');
    if ($report['key'] === 'my-queue' && $sort === 'AgeDays' && ! $asc) $sortLabel = 'Oldest work first';
    $scopeIds = $params['BranchIds'] === null ? [] : array_map('intval', explode(',', $params['BranchIds']));
    $selected = request()->has('branches') ? $scopeIds : [];
    $noSites = $scopeIds === [-1];
    $siteNames = $branches->whereIn('BranchId', $scopeIds)->pluck('Name');
    $siteLabel = $noSites ? 'No permitted sites selected' : ($context->isBranchWorkspace()
        ? ($siteNames->first() ?? 'Current site')
        : (request()->has('branches') && count($scopeIds) > 0
            ? ($siteNames->count() === 1 ? $siteNames->first() : count($scopeIds).' selected sites')
            : 'All permitted sites'));
    $query = \Modules\Reports\Services\ReportScope::query($params);
    $url = fn (array $changes) => route('app.reports.show', array_merge(['report' => $report['key']],
        array_filter(array_merge($query, $changes), fn ($value) => $value !== null && $value !== '')));
    $defaults = app(\Modules\Reports\Services\ReportsService::class)->parametersFor($report['key']);
    $presets = [
        'Default dates' => [$defaults['DateFrom'], $defaults['DateTo']],
        'Today only' => [$today, $today],
        'Last 7 days' => [\App\Support\BusinessDate::today()->subDays(6)->toDateString(), $today],
        'Last 30 days' => [\App\Support\BusinessDate::today()->subDays(29)->toDateString(), $today],
    ];
@endphp
<details {{ $attributes->class(['card', 'report-scope']) }} @if ($open) open @endif>
    <summary class="report-scope-summary">
        <strong>Scope</strong>
        <span class="report-scope-tags">
            <span class="report-scope-tag">{{ \Illuminate\Support\Carbon::parse($from)->format('d M Y') }} – {{ \Illuminate\Support\Carbon::parse($to)->format('d M Y') }}{{ $to === $today ? ' · through today' : '' }}</span>
            <span class="report-scope-tag">{{ $siteLabel }}</span>
            <span class="report-scope-tag">{{ $sortLabel }}</span>
            @if ($params['Search'])
                <span class="report-scope-tag" title="{{ $params['Search'] }}">Search: {{ Str::limit($params['Search'], 40) }}</span>
            @endif
        </span>
        <span class="report-scope-edit">Change scope</span>
    </summary>
    <div class="report-scope-body">
        <p class="report-scope-help">{{ $report['scope']['help'] }}</p>
        <nav class="report-scope-presets" aria-label="Date shortcuts">
            <span>Dates:</span>
            @foreach ($presets as $label => [$start, $end])
                <a data-loader="Loading report…" @class(['is-active' => $from === $start && $to === $end]) href="{{ $url(['from' => $start, 'to' => $end]) }}">{{ $label }}</a>
            @endforeach
        </nav>
        <form method="GET" action="{{ route('app.reports.show', $report['key']) }}" data-loader="{{ $report['key'] === 'my-queue' ? 'Refreshing your queue…' : 'Refreshing report…' }}">
            <div class="field-row">
                <x-field name="from" label="From" type="date" :value="$from" />
                <x-field name="to" label="Through" type="date" :value="$to" />
                <x-field name="q" label="Search" :value="$params['Search']" :placeholder="$report['scope']['search_placeholder']" />
                <x-field name="sort" label="Sort by" :value="$sort" :choices="$sortChoices" />
                <x-field name="dir" label="Order" :value="$asc ? 'asc' : 'desc'" :choices="['desc' => 'Highest / newest first', 'asc' => 'Lowest / oldest first']" />
                @unless ($context->isBranchWorkspace())
                    <div class="field field-wide">
                        <label for="f-report-branches">Sites</label>
                        @if ($noSites)<input type="hidden" name="branches[]" value="-1">@endif
                        <select id="f-report-branches" name="branches[]" multiple data-select data-placeholder="All permitted sites">
                            @foreach ($branches as $branch)
                                <option value="{{ $branch->BranchId }}" @selected(in_array((int) $branch->BranchId, $selected, true))>{{ $branch->Name }}</option>
                            @endforeach
                        </select>
                    </div>
                @endunless
            </div>
            <div class="form-actions">
                <button class="btn-primary" type="submit">Update {{ $report['key'] === 'my-queue' ? 'queue' : 'report' }}</button>
                <a data-loader="Loading report…" class="btn-ghost" href="{{ route('app.reports.show', $report['key']) }}">Reset scope</a>
            </div>
        </form>
    </div>
</details>
