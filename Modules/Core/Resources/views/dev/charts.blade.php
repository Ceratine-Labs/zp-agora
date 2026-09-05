{{--
    The chart gallery.

    Every type, drawn from the mockup's own August 2026 consolidation, so the
    questions this page exists to answer — does it read in dark, does it survive
    375px, does a negative step draw the right way round — are answered by
    looking. Local and testing only; the route is in Modules/Core/Routes/root.php.

    Nothing on this page fetches anything. Every figure came in as a prop from
    Modules\Core\Http\Controllers\ChartGalleryController.
--}}
<!doctype html>
<html lang="en" @if(request()->cookie('agora_theme')) data-theme="{{ request()->cookie('agora_theme') }}" @endif>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Charts · Agora</title>
    @vite(['resources/scss/app.scss', 'resources/js/app.js'])
</head>
<body>

<header class="appbar">
    <a class="brand" href="{{ url('/app') }}">
        <span class="brand-text">
            <span class="wm">AG<em>O</em>RA</span>
            <span class="sub">Charts</span>
        </span>
    </a>
    <nav class="primary"></nav>
    <div class="tools">
        <button class="iconbtn" id="themebtn" aria-label="Switch light / dark" title="Switch light / dark">
            <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M13.2 9.6A5.6 5.6 0 0 1 6.4 2.8a5.6 5.6 0 1 0 6.8 6.8Z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/></svg>
        </button>
    </div>
</header>

<main class="shell-main">
    <div class="shell-in">
        <x-page-head
            eyebrow="Development"
            title="Charts"
            blurb="Every chart type, with the mockup's own numbers. Use the toggle in the bar to check both themes, and narrow the window to 375px to check the phone. Every chart here got its figures as a prop; none of them fetched anything." />

        <x-notice tone="info" title="How a colour gets into a chart" collapsible>
            <p>It does not — not as a value. Every colour in a chart's payload is a token
                reference such as <code>token:--s1</code>, and
                <code>resources/js/components/chart.js</code> resolves it against the live
                document immediately before the chart is built. Switch the theme with the
                button in the bar: each chart is torn down and rebuilt against the tokens as
                they are now, which is the only way ApexCharts can follow a theme it has
                never heard of.</p>
            <p><code>App\Support\Chart\ChartSpec</code> throws if a colour literal reaches
                the payload, including through a caller's own ApexCharts overrides, and
                <code>chart.js</code> stamps <code>data-chart-colour-literal</code> on any
                holder whose finished options still carry one.</p>
        </x-notice>

        {{-- ---------------------------------------------------------------
             Daily columns
             --------------------------------------------------------------- --}}
        <x-card title="Daily bars"
                sub="Fuel litres dispensed per day across the group, with a seven-day moving average. Saturdays and Sundays are the same measure on a day that trades differently, so they are drawn at a lower alpha of the same token rather than in a second colour.">
            <x-chart
                type="daily-bars"
                :series="collect($daily)->map(fn ($d) => [
                    'label' => $d['label'],
                    'value' => $d['litres'],
                    'quiet' => $d['weekend'],
                    'note' => $d['day'],
                ])->all()"
                :options="[
                    'name' => 'Litres dispensed',
                    'average' => 7,
                    'averageName' => '7-day average',
                    'format' => 'n',
                    'axisFormat' => 'Lk',
                    'labelHeading' => 'Day of August 2026',
                    'label' => 'Fuel litres dispensed per day across the group, August 2026',
                ]"
                :height="280" />
        </x-card>

        {{-- ---------------------------------------------------------------
             Line against a reference
             --------------------------------------------------------------- --}}
        <x-card title="Line, against a budget"
                sub="Blended fuel margin in cents per litre, per day, against the budgeted margin. The budget is an annotation rather than a second series — it does not move and it has no readings, so putting it in the legend as an equal would misrepresent it.">
            <x-chart
                type="line"
                :series="[[
                    'name' => 'Actual margin',
                    'points' => collect($margin)->map(fn ($d) => ['label' => $d['label'], 'value' => $d['cpl']])->all(),
                ]]"
                :options="[
                    'format' => ['cpl', 3],
                    'reference' => ['value' => $marginBudget, 'label' => 'Budget'],
                    'labelHeading' => 'Day of August 2026',
                    'label' => 'Blended fuel margin in cents per litre per day against budget',
                ]"
                :height="240" />
        </x-card>

        <div class="chart-pair">
            {{-- -----------------------------------------------------------
                 Donut
                 ----------------------------------------------------------- --}}
            <x-card title="Donut" sub="Litres by grade. Two slices, which is the honest shape of a fuel split — past six this should be a bar.">
                <x-chart
                    type="donut"
                    :series="$grades"
                    :options="[
                        'format' => 'Lk',
                        'centreFormat' => 'Lk',
                        'centreLabel' => 'dispensed',
                        'labelHeading' => 'Grade',
                        'name' => 'Litres',
                        'label' => 'Litres dispensed by fuel grade',
                    ]"
                    :height="300" />
            </x-card>

            {{-- -----------------------------------------------------------
                 Mix
                 ----------------------------------------------------------- --}}
            <x-card title="Mix" sub="Turnover by profit centre — last year, budget, actual, in that reading order. Last year wears the muted token because it is context, not a third thing being compared.">
                <x-chart
                    type="mix"
                    :series="$mix"
                    :options="[
                        'measures' => ['Last year', 'Budget', 'Actual'],
                        'format' => 'R',
                        'axisFormat' => 'Rk',
                        'labelHeading' => 'Profit centre',
                        'label' => 'Turnover by profit centre: last year, budget and actual',
                    ]"
                    :height="300" />
            </x-card>
        </div>

        {{-- ---------------------------------------------------------------
             Bridge
             --------------------------------------------------------------- --}}
        <x-card title="Bridge"
                sub="Budgeted gross profit walked to actual. Fuel is split into the two separately actionable effects — litres against budget at the budgeted margin, and margin against budget on the litres actually sold.">
            <x-chart
                type="bridge"
                :series="$bridge"
                :options="[
                    'name' => 'Effect on gross profit',
                    'format' => 'Rk',
                    'axisFormat' => 'Rk',
                    'labelHeading' => 'Step',
                    'label' => 'Bridge from budgeted gross profit to actual gross profit',
                ]"
                :height="320" />
        </x-card>

        {{-- ---------------------------------------------------------------
             Diverging
             --------------------------------------------------------------- --}}
        <x-card title="Diverging"
                sub="Every trading site's turnover against its budget. Sorted, because a diverging chart that is not sorted is a bar chart with some negatives in it — and scaled symmetrically, so a −2% bar and a +2% bar are the same length.">
            <x-chart
                type="diverging"
                :series="$sites"
                :options="[
                    'name' => 'Turnover vs budget',
                    'format' => ['pct', 2],
                    'axisFormat' => ['pct', 0],
                    'labelHeading' => 'Site',
                    'label' => 'Turnover against budget by site, August 2026',
                ]"
                :height="560" />
        </x-card>

        {{-- ---------------------------------------------------------------
             Empty state
             --------------------------------------------------------------- --}}
        <x-card title="A chart with no rows"
                sub="Renders an empty state and emits no [data-chart] at all — so a page whose only chart has no data never downloads ApexCharts.">
            <x-chart type="daily-bars" :series="[]" empty="No fuel movement for this branch and date." />
        </x-card>

        {{-- ---------------------------------------------------------------
             Sparkline
             --------------------------------------------------------------- --}}
        <x-card title="Sparkline"
                sub="Inline SVG, no library — four elements. It goes in a KPI card and, one day, in a grid cell, where a chart library per instance would be absurd. By default it takes the colour of the text around it.">
            <div class="kpi-strip">
                <div class="kpi tone-neutral">
                    <span class="kpi-label">Litres dispensed</span>
                    <span class="kpi-value">{{ \App\Support\Format::lk(array_sum($dailyLitres)) }}</span>
                    <span class="kpi-note">31 days · group</span>
                    <x-sparkline :values="$dailyLitres" tone="s1" label="Litres dispensed per day, August 2026" />
                </div>
                <div class="kpi tone-good">
                    <span class="kpi-label">Blended margin</span>
                    <span class="kpi-value">{{ \App\Support\Format::cpl(collect($daily)->avg('cpl')) }}</span>
                    <span class="kpi-note">Inherits the card's colour</span>
                    <x-sparkline :values="collect($daily)->pluck('cpl')->all()" label="Blended fuel margin per day" />
                </div>
                <div class="kpi tone-neutral">
                    <span class="kpi-label">Fuel gross profit</span>
                    <span class="kpi-value">{{ \App\Support\Format::rk(collect($daily)->sum('gp')) }}</span>
                    <span class="kpi-note">31 days · group</span>
                    <x-sparkline :values="collect($daily)->pluck('gp')->all()" tone="s3" label="Fuel gross profit per day" />
                </div>
            </div>

            <h3 class="sg-group">The cases that break a hand-rolled sparkline</h3>
            <div class="chart-cases">
                @foreach ($edgeCases as $name => $case)
                    <div class="chart-case">
                        <span class="chart-case-name">{{ $name }}</span>
                        <x-sparkline :values="$case['values']" tone="s1" :label="$name" />
                        <p class="chart-case-blurb">{{ $case['blurb'] }}</p>
                    </div>
                @endforeach
            </div>
        </x-card>

        {{-- ---------------------------------------------------------------
             Mini bar
             --------------------------------------------------------------- --}}
        <x-card title="Mini bar"
                sub="Inline SVG, no library, once per grid row. Every bar in a column shares one scale — the column's largest absolute variance — because a per-row scale would make the longest bar in every row look identical."
                flush>
            @php
                $variances = collect($mix)->map(fn ($r) => [
                    'label' => $r['label'],
                    'actual' => $r['values']['Actual'],
                    'budget' => $r['values']['Budget'],
                    'delta' => $r['values']['Actual'] - $r['values']['Budget'],
                ]);
                $scale = $variances->max(fn ($r) => abs($r['delta']));
            @endphp
            {{-- No `procedure`: these rows are fixtures in a controller, and
                 naming a procedure that does not exist is worse than naming none. --}}
            <x-table :count="$variances->count() + 3">
                <x-slot:head>
                    <tr>
                        <th>Profit centre</th>
                        <th class="num">Budget</th>
                        <th class="num">Actual</th>
                        <th class="num">Variance</th>
                        <th>Against budget</th>
                    </tr>
                </x-slot:head>
                @foreach ($variances as $row)
                    <tr>
                        <td>{{ $row['label'] }}</td>
                        <td class="num">{{ \App\Support\Format::rk($row['budget']) }}</td>
                        <td class="num">{{ \App\Support\Format::rk($row['actual']) }}</td>
                        <td class="num">{{ \App\Support\Format::rk($row['delta']) }}</td>
                        <td>
                            <x-mini-bar
                                :value="$row['delta']"
                                :max="$scale"
                                :label="$row['label'].' '.\App\Support\Format::rk($row['delta']).' against budget'" />
                        </td>
                    </tr>
                @endforeach
                <tr>
                    <td class="muted">On budget exactly</td>
                    <td class="num">{{ \App\Support\Format::rk(1000000) }}</td>
                    <td class="num">{{ \App\Support\Format::rk(1000000) }}</td>
                    <td class="num">{{ \App\Support\Format::rk(0) }}</td>
                    <td><x-mini-bar :value="0" :max="$scale" label="On budget" /></td>
                </tr>
                <tr>
                    <td class="muted">Off the end of the scale</td>
                    <td class="num">{{ \App\Support\Format::rk(1000000) }}</td>
                    <td class="num">{{ \App\Support\Format::rk(1000000 - $scale * 3) }}</td>
                    <td class="num">{{ \App\Support\Format::rk(-$scale * 3) }}</td>
                    <td><x-mini-bar :value="-$scale * 3" :max="$scale" label="Three times the column scale, under budget" /></td>
                </tr>
                <tr>
                    <td class="muted">No variance recorded</td>
                    <td class="num">{{ \App\Support\Format::rk(null) }}</td>
                    <td class="num">{{ \App\Support\Format::rk(null) }}</td>
                    <td class="num">{{ \App\Support\Format::rk(null) }}</td>
                    <td><x-mini-bar :value="null" :max="$scale" /></td>
                </tr>
            </x-table>
        </x-card>
    </div>
</main>

</body>
</html>
