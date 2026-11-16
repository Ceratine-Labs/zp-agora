{{--
    The Pivot (16 Nov 2026): every site down the side, the five areas — or the
    days, weeks or months — across, and in each cell what is still outstanding.
    The customer's ask, and the dashboard's default view.

    Everything in a cell, a row or a totals row was decided by
    Modules\Recon\Services\ReconPivot — the figure, its colour, its glyph, its
    link, its tooltip — and each figure cell is written whole by
    <x-recon::cell>. This only lays them out: the link chips that change the
    view (each served from the cached figures), the key, and the table with
    its two foot rows, which sorting, the pills and the filters never touch.

    Props: $table (ReconPivot::areas() or ::dates()), $params (cols, lens,
           side, area, status, fresh), $controls (the link chips),
           $exports (xlsx, csv, long)
--}}
@php
    use Modules\Recon\Services\ReconPivot;

    $columns = array_values(array_filter($table['columns'], fn (array $c) => $c['kind'] === 'figure'));
    $dates = $table['cols'] === 'dates';
    $lens = $table['lens'];
    $grain = in_array($table['scheme'] ?? null, ['day', 'week'], true) ? $table['scheme'] : 'month';
    $what = match (true) {
        $dates => ucfirst(($table['area'] ? ReconPivot::LABELS[$table['area']].' ' : '')
            .($table['side'] === 'Bank' ? 'bank lines' : 'PumpIT deposits'))
            .' outstanding by the '.$grain.' they are dated'
            .['day' => '', 'week' => ', weeks from Monday', 'month' => ', earlier years one column each'][$grain],
        $lens === 'difference' => 'Bank less PumpIT per area: + not declared, - not in bank',
        $lens === 'count' => 'Items outstanding per area, bank beside PumpIT',
        default => 'Amount outstanding per area, bank beside PumpIT',
    };
    $sub = implode(' · ', array_filter([$what, $table['subtitle']]));
    $blank = ReconPivot::cell();
@endphp

<x-card title="Outstanding by site" :sub="$sub" flush>
    <x-slot:actions>
        <span class="rp-export" title="The whole scope in the server's order: the browser's sort and pill filter are not carried">
            Export <a href="{{ $exports['xlsx'] }}">XLSX</a> · <a href="{{ $exports['csv'] }}">CSV</a>
            · <a href="{{ $exports['long'] }}" title="One row per site, area and side, ready to pivot in Excel">long form</a>
        </span>
    </x-slot:actions>

    <div class="rp-controls">
        @foreach ($controls as $control)
            <div class="rp-control">
                <span class="rp-label" aria-hidden="true">{{ $control['label'] }}</span>
                <x-tabs chips :label="$control['label']" :active="$control['active']" :items="$control['items']" />
            </div>
        @endforeach
    </div>

    {{-- The key: only the marks this view can show. --}}
    <div class="rp-legend" role="note" aria-label="Key">
        @if ($dates)
            <span><span class="rp-recent">R84k</span> dated in the last 30 days</span>
            @if ($table['side'] === 'PumpIT')
                <span><span class="rp-transit">~R84k</span> only in transit</span>
                <span>a dashed heading: the in-transit window reaches that period</span>
            @endif
            <span>blank: nothing outstanding dated then</span>
        @elseif ($lens === 'difference')
            <span><x-tone-value tone="warn">+R84k</x-tone-value> not declared: bank over PumpIT</span>
            <span><x-tone-value tone="crit">-R1.20m</x-tone-value> not in bank: PumpIT over bank, beyond transit</span>
            <span><span class="rp-transit">~R84k</span> only in transit</span>
            <span><span class="rp-clear">✓</span> clear</span>
            <span><span class="rp-stopped">stopped</span> / <span class="rp-flag-key" aria-hidden="true"></span> a side that stopped</span>
            <span><span class="rp-never">never</span> one side never had a row</span>
            <span><span class="rp-unused">·</span> neither side ever used</span>
            <span><span class="rp-unknown">—</span> feed history unknown</span>
        @else
            @if ($lens === 'amount')
                <span><x-tone-value tone="crit">R1.20m</x-tone-value> PumpIT not in bank</span>
                <span><x-tone-value tone="warn">R84k</x-tone-value> bank not declared</span>
            @endif
            <span><span class="rp-transit">~R84k</span> only in transit</span>
            <span><span class="rp-clear">✓</span> used, nothing outstanding</span>
            <span><span class="rp-stopped">stopped</span> / <span class="rp-flag-key" aria-hidden="true"></span> a side that stopped</span>
            <span><span class="rp-never">never</span> never had a row</span>
            <span><span class="rp-unused">·</span> neither side ever used</span>
            <span><span class="rp-unknown">—</span> feed history unknown</span>
        @endif
    </div>

    <x-table tools fit dense class="pin-first rp-pivot" data-tt-row-noun="site"
             :layout-key="'Recon.dashboard.pivot.'.$table['cols'].'.'.$lens"
             :procedure="ReconPivot::PROCEDURES" :count="$table['count']" empty="No site in this scope.">
        <x-slot:head>
            <tr>
                @foreach ($table['columns'] as $c)
                    @if ($c['kind'] === 'site')
                        <th class="rp-site">{{ $c['label'] }}</th>
                    @elseif ($c['kind'] === 'status')
                        {{-- `status` opens the pills on one Status: a KPI tile lands here. --}}
                        <th class="fit-min" data-tt-pills="{{ $params['status'] ?? '' }}" data-tt-pill-order="{{ ReconPivot::PILL_ORDER }}"
                            title="{{ $c['title'] }}">{{ $c['label'] }}</th>
                    @else
                        {{-- A long heading ("Not in bank, last 30 days") gets the room
                             to wrap onto two lines instead of five. --}}
                        <th class="{{ $c['class'] }}{{ mb_strlen($c['label']) > 20 ? ' rp-wide' : '' }}" @if ($c['noFilter']) data-tt-no-filter @endif
                            @if ($c['title']) title="{{ $c['title'] }}" @endif>{{ $c['label'] }}</th>
                    @endif
                @endforeach
            </tr>
        </x-slot:head>

        {{-- A row is written without indentation between its cells: sixteen
             cells on twenty-four rows, and the page is a few hundred
             tooltips already. A chip's and the status's tooltip lines are
             also written as .sr-only text: the tooltip box is aria-hidden,
             and nothing else on the page says them. Both cells sort and
             filter on data-sort, so the extra text changes neither. --}}
        @foreach ($table['rows'] as $row)
            <tr>
<td class="rp-site" data-sort="{{ $row['sort'] }}">@if ($row['href'])<a href="{{ $row['href'] }}">{{ $row['name'] }}</a>@else{{ $row['name'] }}@endif
@foreach ($row['chips'] as $chip)<x-chip :tone="$chip['tone']" :dot="false" tabindex="0" :data-tip="$chip['tip']">{{ $chip['label'] }}<span class="sr-only">: {{ $chip['detail'] }}</span></x-chip>@endforeach</td>
<td class="fit-min" data-sort="{{ $row['status']['label'] }}" data-tone="{{ $row['status']['tone'] }}"><x-chip :tone="$row['status']['tone']" tabindex="0" :data-tip="$row['status']['tip']">{{ $row['status']['label'] }}<span class="sr-only">: {{ $row['status']['detail'] }}</span></x-chip></td>
@foreach ($columns as $c)<x-recon::cell :cell="$row['cells'][$c['key']] ?? $blank" />@endforeach
            </tr>
        @endforeach

        {{-- Row A, the entities with only Unclassified lines, then Row B, the
             whole scope. One cell per column, blank ones included: a partial
             colspan would switch the table layout's reordering off. Absent
             when one site is in scope, where it would repeat the row. --}}
        @if ($table['entities'] !== null || $table['total'] !== null)
            <x-slot:foot>
                @if ($table['entities'] !== null)
                    <tr class="rp-apart">
                        <td class="rp-site"><span tabindex="0" data-tip="{{ $table['entities']['tip'] }}">{{ $table['entities']['label'] }}<span class="sr-only">: {{ $table['entities']['detail'] }}</span></span></td>
                        <td></td>
@foreach ($columns as $c)<x-recon::cell :cell="$table['entities']['cells'][$c['key']] ?? $blank" />@endforeach
                    </tr>
                @endif
                @if ($table['total'] !== null)
                    <tr>
                        <td class="rp-site">{{ $table['total']['label'] }}</td>
                        <td></td>
@foreach ($columns as $c)<x-recon::cell :cell="$table['total']['cells'][$c['key']] ?? $blank" />@endforeach
                    </tr>
                @endif
            </x-slot:foot>
        @endif
    </x-table>
</x-card>
