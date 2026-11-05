@php
    // All displayed scope and navigation come from the parameters actually sent
    // to the procedure, including its fail-closed branch selection.
    $today = \App\Support\BusinessDate::today()->toDateString();
    $from = $params['DateFrom'];
    $to = $params['DateTo'];
    $sort = $params['SortColumn'];
    $asc = (bool) $params['SortAsc'];
    $page = (int) $params['Page'];
    $pageSize = (int) $params['PageSize'];
    $pages = max(1, (int) ceil($total / max(1, $pageSize)));
    $scopeIds = $params['BranchIds'] === null ? [] : array_map('intval', explode(',', $params['BranchIds']));
    $query = \Modules\Reports\Services\ReportScope::query($params);
    $url = fn (array $changes = []) => route('app.reports.show', array_merge(['report' => 'my-queue'],
        array_filter(array_merge($query, $changes), fn ($value) => $value !== null && $value !== '')));
    $queues = array_intersect_key(\Modules\Reports\Services\MyQueueAccess::TYPES, array_flip($queueKeys));
    $units = ['day-close' => 'days open', 'unallocated-zreads' => 'unallocated', 'open-cashups' => 'open',
        'purchase-approvals' => 'pending', 'staff-shorts' => 'pending', 'drop-safe' => 'bags', 'waste-approvals' => 'pending'];
    $reviewUrl = fn (string $key, ?int $branch = null) => route('app.reports.show', array_filter([
        'report' => $key === 'waste-approvals' ? 'waste' : $key, 'from' => $from, 'to' => $to,
        'branches' => $branch !== null ? [$branch] : ($scopeIds ?: null),
    ], fn ($value) => $value !== null));
    // A warning about the returned page, never a fabricated total or SLA.
    $aged = $rows->filter(fn ($row) => $row->AgeDays !== null && (int) $row->AgeDays >= 30)->count();
@endphp

<x-app-shell title="My queue">
<div class="my-queue">
    <x-page-head eyebrow="Today" title="My queue">
        <x-slot:info><p>{{ $report['blurb'] }}</p><p>One row is one queue at one site. Open counts and values can overlap across queues; they are not a combined total.</p></x-slot:info>
        <x-slot:actions><a data-loader="Loading report…" class="btn-ghost" href="{{ route('app.reports.index') }}">All reports</a></x-slot:actions>
    </x-page-head>

    <section class="queue-intro" aria-label="Queue overview">
        <x-report-art variant="my-queue" class="queue-intro-art" />
        <div class="queue-intro-copy">
            <h2>Work that needs attention</h2>
            <p>Review outstanding work by site. Start with the oldest items.</p>
        </div>
        @unless ($refusal)
            <div class="queue-intro-meta"><strong class="queue-count">{{ \App\Support\Format::n($total) }}</strong><span>site queues in scope</span></div>
        @endunless
    </section>

    <nav class="queue-shortcuts" aria-label="Review a queue">
        @foreach ($queues as $key => $label)
            <a data-loader="Loading report…" class="queue-shortcut" href="{{ $reviewUrl($key) }}" title="Open {{ $label }} for the selected dates and sites">
                <x-report-art :variant="$key" /><span class="queue-shortcut-label">{{ $label }}</span>
                @php($summary = $summaries->get($key))
                <span class="queue-shortcut-metrics" data-queue-total="{{ $key }}">
                    @if ($summariesAvailable)
                        <strong>{{ \App\Support\Format::n($summary->OpenItems ?? 0) }}</strong>
                        <small>{{ $units[$key] }}</small>
                        @if (! in_array($key, ['day-close', 'waste-approvals'], true))
                            @php($value = $summary === null ? 0 : $summary->Value)
                            <span class="queue-shortcut-value" title="{{ \App\Support\Format::r($value) }}" aria-label="Value: {{ \App\Support\Format::r($value) }}">{{ \App\Support\Format::rk($value) }}</span>
                        @endif
                    @else
                        <strong>—</strong><small>Unavailable</small>
                    @endif
                </span>
            </a>
        @endforeach
    </nav>

    <x-reports::scope :report="$report" :params="$params" :context="$context" :branches="$branches" :open="$refusal || $rows->isEmpty()" />

    @if ($refusal)
        <x-notice tone="stop" :title="__('reports::reports.refused')"><p>{{ $refusal }}</p></x-notice>
    @elseif ($aged > 0)
        <aside class="queue-warning" aria-label="Older work needs a review">
            <x-report-art variant="day-close" class="queue-warning-art" />
            <div><h3>Older work needs a review</h3><p>{{ \App\Support\Format::n($aged) }} {{ Str::plural('site queue', $aged) }} on this page {{ $aged === 1 ? 'has' : 'have' }} work at least 30 days old. Age is measured to {{ \Illuminate\Support\Carbon::parse($to)->format('d M Y') }}.</p></div>
        </aside>
    @endif

    @unless ($refusal)
    @php($resultsSummary = $rows->isEmpty() && $total > 0
        ? 'No rows on page '.$page.' · '.\App\Support\Format::n($total).' site queues in scope'
        : \App\Support\Format::n($rows->count()).' of '.\App\Support\Format::n($total).' site queues · page '.$page.' of '.$pages)
    <x-card class="queue-results" title="Outstanding by site" :sub="$resultsSummary" flush>
        <x-slot:actions>
            <nav class="queue-sort-links" aria-label="Order queues">
                <a data-loader="Loading report…" @class(['is-active' => $sort === 'AgeDays' && ! $asc]) href="{{ $url(['sort' => 'AgeDays', 'dir' => 'desc']) }}">Oldest work</a>
                <a data-loader="Loading report…" @class(['is-active' => $sort === 'OpenItems' && ! $asc]) href="{{ $url(['sort' => 'OpenItems', 'dir' => 'desc']) }}">Most open</a>
                <a data-loader="Loading report…" @class(['is-active' => $sort === 'BranchName' && $asc]) href="{{ $url(['sort' => 'BranchName', 'dir' => 'asc']) }}">By site</a>
            </nav>
        </x-slot:actions>
        @if ($rows->isEmpty())
            <div class="queue-empty">
                <x-report-art variant="day-close" />
                <h3>{{ $total > 0 ? 'No rows on this page' : 'No outstanding queues in this scope' }}</h3>
                <p>{{ $total > 0 ? 'Return to the first page to see the matching queues.' : 'Try a wider date range or clear your search to check other work.' }}</p>
                <a data-loader="Loading report…" class="btn-ghost" href="{{ $total > 0 ? $url() : route('app.reports.show', 'my-queue') }}">{{ $total > 0 ? 'First page' : 'Reset scope' }}</a>
            </div>
            <footer class="table-foot"><span class="table-proc">agora.{{ $report['procedure'] }}</span></footer>
        @else
            <x-table layout-key="Reports.my-queue.1" :count="$rows->count()" :total="$total" procedure="agora.{{ $report['procedure'] }}">
                <x-slot:head><tr>
                    @foreach ($report['columns'] as $column)
                        <th @class(['num' => in_array($column['type'], ['number', 'money'])]) aria-sort="{{ $sort === $column['sort'] ? ($asc ? 'ascending' : 'descending') : 'none' }}">
                            <a data-loader="Loading report…" href="{{ $url(['sort' => $column['sort'], 'dir' => $sort === $column['sort'] && $asc ? 'desc' : 'asc']) }}">{{ $column['label'] }}@if ($sort === $column['sort'])<span class="sort-arrow" aria-hidden="true">{{ $asc ? '▲' : '▼' }}</span>@endif</a>
                        </th>
                    @endforeach
                    <th><span class="visually-hidden">Review queue</span></th>
                </tr></x-slot:head>
                @foreach ($rows as $row)
                    @php($key = (string) ($row->QueueKey ?? ''))
                    <tr @class(['queue-row-aged' => $row->AgeDays !== null && (int) $row->AgeDays >= 30])>
                        <td class="queue-site-cell" data-label="Site"><strong>{{ $row->BranchName }}</strong></td>
                        <td class="queue-type-cell" data-label="Queue"><span class="queue-type"><x-report-art :variant="array_key_exists($key, $queues) ? $key : 'reports'" /><span>{{ $row->Queue }}</span></span></td>
                        <td class="num" data-label="Open"><x-reports::cell type="number" :value="$row->OpenItems" /></td>
                        <td data-label="Oldest"><x-reports::cell type="date" :value="$row->OldestDate" /></td>
                        <td class="num" data-label="Age"><span @class(['queue-age', 'is-aged' => $row->AgeDays !== null && $row->AgeDays >= 30, 'is-waiting' => $row->AgeDays !== null && $row->AgeDays >= 7 && $row->AgeDays < 30])><x-reports::cell type="number" :value="$row->AgeDays" />{{ $row->AgeDays !== null ? 'd' : '' }}</span></td>
                        <td class="num" data-label="Value"><x-reports::cell type="money" :value="$row->Value" /></td>
                        <td data-label="Action">
                            @if (array_key_exists($key, $queues) && (int) $row->BranchId > 0)
                                <a data-loader="Loading report…" class="queue-review" href="{{ $reviewUrl($key, (int) $row->BranchId) }}" aria-label="Review {{ $row->Queue }} at {{ $row->BranchName }}">Review <span aria-hidden="true">→</span></a>
                            @else<span class="muted">—</span>@endif
                        </td>
                    </tr>
                @endforeach
            </x-table>
            <p class="queue-scope-help queue-results-note">Age colours: 7–29 days / 30+ days. These show age, not missed deadlines. Values are specific to each queue and can overlap.</p>
        @endif
        @if ($pages > 1)
            <nav class="pager queue-pagination" aria-label="Queue pages">
                @if ($page > 1)<a data-loader="Loading report…" class="btn-ghost" href="{{ $url(['page' => min($page - 1, $pages)]) }}">Previous</a>@endif
                <span class="pager-at">{{ $page > $pages ? "Page $page is outside this result" : "Page $page of $pages" }}</span>
                @if ($page < $pages)<a data-loader="Loading report…" class="btn-ghost" href="{{ $url(['page' => $page + 1]) }}">Next</a>@endif
            </nav>
        @endif
    </x-card>
    @endunless
</div>
</x-app-shell>
