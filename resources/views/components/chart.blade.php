{{--
    One chart.

        <x-chart type="daily-bars" :series="$daily" :options="[...]" />

    Six types, all drawn by ApexCharts: daily-bars, line, donut, mix, bridge,
    diverging. `<x-sparkline>` and `<x-mini-bar>` are NOT here — they are
    hand-rolled SVG, because a chart library per KPI card or per grid cell is
    absurd.

    Three properties this component exists to guarantee:

      - **It never fetches.** `series` arrives from the controller, already
        shaped. There is no URL in this file.
      - **It carries no colour.** Every colour in the payload is a token
        reference (`token:--s1`) resolved from the live theme at draw time by
        resources/js/components/chart.js, so a chart follows the light/dark
        switch instead of keeping the palette it was born with.
        App\Support\Chart\ChartSpec throws if a hex literal reaches the payload.
      - **It never gates a value behind a hover.** Every chart ships a table of
        the same figures, formatted through App\Support\Format, folded behind a
        <details>. That is the accessibility twin the dataviz rules require, and
        it is also the relief for the three light-theme series colours that sit
        under 3:1 against white.

    An empty series renders an empty state and emits NO `[data-chart]`, which
    means a page whose only chart has no data never downloads ApexCharts.

    `series` shapes, one per type — all reduced to `label` and `value`:

      daily-bars  [ ['label' => '01', 'value' => 160998, 'quiet' => true, 'note' => 'Sat'], … ]
      line        [ ['name' => 'Actual', 'points' => [ ['label' => …, 'value' => …], … ]], … ]
      donut       [ ['label' => 'ULP 95', 'value' => 1920971], … ]
      mix         [ ['label' => 'Fuel', 'values' => ['Last year' => …, 'Budget' => …, 'Actual' => …]], … ]
      bridge      [ ['label' => 'Budget GP', 'value' => …, 'total' => true, 'note' => …], … ]
      diverging   [ ['label' => 'Nyala', 'value' => 4.77], … ]

    `options` are documented in docs/charts.md.
--}}
@props([
    'type',
    'series' => [],
    'options' => [],
    'title' => null,
    'sub' => null,
    'height' => 280,
    'empty' => 'Nothing to chart for this scope.',
    'table' => true,
])

@php
    $spec = \App\Support\Chart\ChartSpec::make($type, $series, $options + ['height' => $height]);
    $caption = $spec->caption();
    // A chart is a figure with a name. The aria-label says what it plots,
    // because "chart" is not a description and SVG has no alt text.
    $describe = $options['label'] ?? $title ?? ucfirst(str_replace('-', ' ', $type)).' chart';
@endphp

<figure {{ $attributes->merge(['class' => 'chart-figure chart-'.$type]) }}>
    @if ($title)
        <figcaption class="chart-title">
            <span class="chart-title-text">{{ $title }}</span>
            @if ($sub)<span class="chart-sub">{{ $sub }}</span>@endif
        </figcaption>
    @endif

    @if ($spec->isEmpty())
        <x-empty-state :text="$empty" />
    @else
        {{--
            The skeleton is a real element rather than a CSS pseudo, because it
            has to be removable: chart.js clears the holder before ApexCharts
            mounts into it. It shows for exactly as long as the dynamic import
            takes on a cold cache, which is the only moment a chart is blank.
        --}}
        <div class="chart-holder"
             style="min-height: {{ (int) $height }}px"
             role="img"
             aria-label="{{ $describe }}"
             data-chart="{{ $spec->json() }}"><span class="chart-skeleton" aria-hidden="true"></span></div>

        @if ($caption)
            <p class="chart-caption">{{ $caption }}</p>
        @endif

        @if ($table)
            @php($twin = $spec->table())
            <details class="chart-table">
                <summary>The figures behind this chart</summary>
                <div class="table-scroll">
                    <table class="dt dense">
                        <thead>
                            <tr>
                                @foreach ($twin['columns'] as $i => $column)
                                    <th @class(['num' => ($twin['aligns'][$i] ?? 'text') === 'num'])>{{ $column }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($twin['rows'] as $row)
                                <tr>
                                    @foreach ($row as $i => $cell)
                                        <td @class(['num' => ($twin['aligns'][$i] ?? 'text') === 'num'])>{{ $cell }}</td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </details>
        @endif
    @endif
</figure>
