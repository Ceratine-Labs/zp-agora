@php
    /*
     * The scope travels in the query string (feature-rules, proposed §C), so
     * every control here and every sortable header rebuilds the CURRENT url
     * with one thing changed. A link pasted to a colleague opens exactly what
     * the sender was looking at.
     */
    $url = fn (array $changes) => route('app.reports.show', array_merge(
        ['report' => $report['key']],
        array_filter(array_merge(\Modules\Reports\Services\ReportScope::query($params), $changes), fn ($v) => $v !== null && $v !== ''),
    ));

    $sort = $params['SortColumn'] ?? null;
    $asc = (bool) ($params['SortAsc'] ?? true);
    $page = (int) ($params['Page'] ?? 1);
    $pageSize = (int) ($params['PageSize'] ?? 50);
    $pages = $pageSize > 0 ? (int) ceil($total / $pageSize) : 1;
@endphp

<x-app-shell :title="$report['label']">
    {{-- What the report is sits behind the info button beside the title (Ryan,
         4 Oct 2026). The caveat below stays open: it is what stops a report
         like "Waste to approve" being read as something it is not. --}}
    <x-page-head eyebrow="Today" :title="$report['label']">
        <x-slot:info>
            <p>{{ $report['blurb'] }}</p>
        </x-slot:info>
        <x-slot:actions>
            <a data-loader="Loading report…" class="btn-ghost" href="{{ route('app.reports.index') }}">All reports</a>
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

    @if ($report['key'] === 'waste' && ($errors->any() || session('status')))
        <x-notice :tone="$errors->any() ? 'warn' : 'good'" title="Waste authorization">
            <p>{{ $errors->first() ?: session('status') }}</p>
        </x-notice>
    @endif
    <x-reports::scope :report="$report" :params="$params" :context="$context" :branches="$branches" :open="$refusal || $rows->isEmpty()" />

    <x-card :title="$report['label']" flush>
        @if ($total > $ceiling)
            <x-notice tone="warn" title="{{ __('reports::reports.over_ceiling', ['ceiling' => number_format($ceiling)]) }}">
                <p>This result set is {{ number_format($total) }} rows; the page shows
                   {{ number_format($pageSize) }} at a time.</p>
            </x-notice>
        @endif

        {{-- A report that declares `detail` opens each row under itself
             (feature-rules §3.5, row-detail.js): the route, and which of the
             row's columns fill its parameters. --}}
        @php($detail = $report['detail'] ?? null)
        <x-table layout-key="Reports.show.1" :count="$rows->count()" :total="$total"
                 procedure="agora.{{ $report['procedure'] }}"
                 :empty="__('reports::reports.nothing')"
                 :data-row-detail="$detail ? 'row' : null">
            <x-slot:head>
                <tr>
                    @foreach ($report['columns'] as $column)
                        @php($numeric = in_array($column['type'], ['number', 'money', 'litres'], true))
                        <th @class(['num' => $numeric])>
                            @if (! empty($column['sort']))
                                {{-- Clicking the column that is already sorted flips it. --}}
                                <a data-loader="Loading report…" href="{{ $url(['sort' => $column['sort'], 'dir' => ($sort === $column['sort'] && $asc) ? 'desc' : 'asc', 'page' => null]) }}">
                                    {{ $column['label'] }}@if ($sort === $column['sort'])<span class="sort-arrow">{{ $asc ? '▲' : '▼' }}</span>@endif
                                </a>
                            @else
                                {{ $column['label'] }}
                            @endif
                        </th>
                    @endforeach
                    @if ($report['key'] === 'waste')<th>Operations decision</th>@endif
                </tr>
            </x-slot:head>

            @foreach ($rows as $row)
                <tr @if ($detail) data-detail-url="{{ route($detail['route'], collect($detail['params'])->map(fn (string $column) => $row->{$column} ?? null)->all()) }}" @endif>
                    @foreach ($report['columns'] as $column)
                        @php($numeric = in_array($column['type'], ['number', 'money', 'litres'], true))
                        <td @class(['num' => $numeric])>
                            <x-reports::cell :type="$column['type']" :value="$row->{$column['key']} ?? null" />
                        </td>
                    @endforeach
                    @if ($report['key'] === 'waste')
                        <td>
                            @can('reports.waste.approve')
                                @if ((float) $row->QtyGoodWaste > 0)
                                <form method="POST" action="{{ route('app.reports.waste.authorization') }}">
                                    @csrf
                                    <input type="hidden" name="branch_id" value="{{ $row->BranchId }}">
                                    <input type="hidden" name="date" value="{{ \Illuminate\Support\Carbon::parse($row->TransactionDate)->toDateString() }}">
                                    <input type="hidden" name="shift" value="{{ $row->ShiftNo }}">
                                    <input type="hidden" name="area" value="{{ $row->AreaNo }}">
                                    <input type="hidden" name="item" value="{{ $row->StockItemNo }}">
                                    <input type="hidden" name="captured_good" value="{{ $row->QtyGoodWaste }}">
                                    <input type="hidden" name="captured_at" value="{{ $row->CapturedAt ? \Illuminate\Support\Carbon::parse($row->CapturedAt)->format('Y-m-d H:i:s.v') : '' }}">
                                    <label>Good quantity <input type="number" name="authorized_good" min="0.0001" max="{{ $row->QtyGoodWaste }}" step="0.0001" value="{{ in_array($row->Status, ['Authorized', 'Partially authorized'], true) ? $row->AuthorizedGood : $row->QtyGoodWaste }}" required></label>
                                    <button type="submit" name="decision" value="authorize" class="btn sm">Authorize</button>
                                    @if (in_array($row->Status, ['Authorized', 'Partially authorized'], true))
                                        <button type="submit" name="decision" value="withdraw" formnovalidate class="btn sm">Withdraw</button>
                                    @endif
                                </form>
                                @if ($row->DecisionAt)
                                    <small>Last decision: {{ $row->DecisionByName ?: 'user '.$row->DecisionBy }} · {{ \Illuminate\Support\Carbon::parse($row->DecisionAt, 'UTC')->format('d M Y H:i') }} UTC</small>
                                @endif
                                @else
                                    <span class="muted">No good waste to authorize</span>
                                @endif
                            @else
                                <span class="muted">Operations authorization required</span>
                            @endcan
                        </td>
                    @endif
                </tr>
            @endforeach
        </x-table>

        @if ($pages > 1)
            <nav class="pager">
                {{-- A dead end is rendered as text, not as a link that goes
                     nowhere: rule 3.7 says a reference that leads nowhere
                     should not be a link. --}}
                @if ($page > 1)
                    <a data-loader="Loading report…" class="btn-ghost" href="{{ $url(['page' => $page - 1]) }}">Previous</a>
                @else
                    <span class="btn-ghost is-off">Previous</span>
                @endif
                <span class="pager-at">Page {{ number_format($page) }} of {{ number_format($pages) }}</span>
                @if ($page < $pages)
                    <a data-loader="Loading report…" class="btn-ghost" href="{{ $url(['page' => $page + 1]) }}">Next</a>
                @else
                    <span class="btn-ghost is-off">Next</span>
                @endif
            </nav>
        @endif
    </x-card>
</x-app-shell>
