@php
    /*
     * The scope travels in the query string (feature-rules, proposed §C), so
     * every control here and every sortable header rebuilds the CURRENT url
     * with one thing changed. A link pasted to a colleague opens exactly what
     * the sender was looking at.
     */
    $url = fn (array $changes) => route('app.reports.show', array_merge(
        ['report' => $report['key']],
        array_filter(array_merge(request()->query(), $changes), fn ($v) => $v !== null && $v !== ''),
    ));

    $sort = $params['SortColumn'] ?? null;
    $asc = (bool) ($params['SortAsc'] ?? true);
    $page = (int) ($params['Page'] ?? 1);
    $pageSize = (int) ($params['PageSize'] ?? 50);
    $pages = $pageSize > 0 ? (int) ceil($total / $pageSize) : 1;
    // The selector posts branches[], so this arrives as an array; a pasted
    // short link sends a comma-separated string. One helper reads both.
    $selected = \Modules\Reports\Services\ReportsService::branchIds(request()->query('branches'));
@endphp

<x-app-shell :title="$report['label']">
    <x-page-head eyebrow="Today" :title="$report['label']" :blurb="$report['blurb']">
        <x-slot:actions>
            <a class="btn-ghost" href="{{ route('app.reports.index') }}">All reports</a>
        </x-slot:actions>
    </x-page-head>

    @if (! empty($report['caveat']))
        <x-notice tone="warn" title="What this report cannot tell you" style="margin-bottom:16px">
            <p>{{ $report['caveat'] }}</p>
        </x-notice>
    @endif

    @if ($refusal)
        <x-notice tone="stop" :title="__('reports::reports.refused')" style="margin-bottom:16px">
            <p>{{ $refusal }}</p>
        </x-notice>
    @endif

    <x-card title="Scope" sub="{{ __('reports::reports.read_only') }}">
        <form method="GET">
            <div class="field-row">
            <x-field name="from" label="From" type="date" :value="request('from')"
                     help="Leave empty and the procedure applies its own default window." />
            <x-field name="to" label="To" type="date" :value="request('to')" />
            <x-field name="q" label="Search" :value="request('q')" placeholder="Site, reference, name…"
                     help="One filter across the columns worth searching. The procedure applies it, not the browser." />

            {{-- Rule 3.3: the branches selector belongs to head office. In the
                 branch workspace BranchContext has already pinned the site and
                 the controller ignores whatever arrives here. --}}
            @if ($context->workspace() !== 'branch')
                <div class="field field-wide">
                    <label for="f-branches">Sites</label>
                    <select id="f-branches" name="branches[]" multiple data-select
                            data-placeholder="Every site you are granted">
                        @foreach ($branches as $branch)
                            <option value="{{ $branch->BranchId }}"
                                @selected(in_array((int) $branch->BranchId, $selected, true))>{{ $branch->Name }}</option>
                        @endforeach
                    </select>
                    <p class="field-help">None selected means every site you are granted.</p>
                </div>
            @endif

            </div>

            <div class="form-actions">
                <button type="submit" class="btn-primary">Run</button>
                <a class="btn-ghost" href="{{ route('app.reports.show', $report['key']) }}">Reset</a>
            </div>
        </form>
    </x-card>

    <x-card :title="$report['label']" flush>
        @if ($total > $ceiling)
            <x-notice tone="warn" title="{{ __('reports::reports.over_ceiling', ['ceiling' => number_format($ceiling)]) }}">
                <p>This result set is {{ number_format($total) }} rows. The page shows
                   {{ number_format($pageSize) }} at a time; a full extract of this size belongs in the
                   export centre, which produces it out of the request cycle.</p>
            </x-notice>
        @endif

        <x-table :count="$rows->count()" :total="$total"
                 procedure="agora.{{ $report['procedure'] }}"
                 :empty="__('reports::reports.nothing')">
            <x-slot:head>
                <tr>
                    @foreach ($report['columns'] as $column)
                        @php($numeric = in_array($column['type'], ['number', 'money', 'litres'], true))
                        <th @class(['num' => $numeric])>
                            @if (! empty($column['sort']))
                                {{-- Clicking the column that is already sorted flips it. --}}
                                <a href="{{ $url(['sort' => $column['sort'], 'dir' => ($sort === $column['sort'] && $asc) ? 'desc' : 'asc', 'page' => null]) }}">
                                    {{ $column['label'] }}@if ($sort === $column['sort'])<span class="sort-arrow">{{ $asc ? '▲' : '▼' }}</span>@endif
                                </a>
                            @else
                                {{ $column['label'] }}
                            @endif
                        </th>
                    @endforeach
                </tr>
            </x-slot:head>

            @foreach ($rows as $row)
                <tr>
                    @foreach ($report['columns'] as $column)
                        @php($numeric = in_array($column['type'], ['number', 'money', 'litres'], true))
                        <td @class(['num' => $numeric])>
                            <x-reports::cell :type="$column['type']" :value="$row->{$column['key']} ?? null" />
                        </td>
                    @endforeach
                </tr>
            @endforeach
        </x-table>

        @if ($pages > 1)
            <nav class="pager">
                {{-- A dead end is rendered as text, not as a link that goes
                     nowhere: rule 3.7 says a reference that leads nowhere
                     should not be a link. --}}
                @if ($page > 1)
                    <a class="btn-ghost" href="{{ $url(['page' => $page - 1]) }}">Previous</a>
                @else
                    <span class="btn-ghost is-off">Previous</span>
                @endif
                <span class="pager-at">Page {{ number_format($page) }} of {{ number_format($pages) }}</span>
                @if ($page < $pages)
                    <a class="btn-ghost" href="{{ $url(['page' => $page + 1]) }}">Next</a>
                @else
                    <span class="btn-ghost is-off">Next</span>
                @endif
            </nav>
        @endif
    </x-card>
</x-app-shell>
