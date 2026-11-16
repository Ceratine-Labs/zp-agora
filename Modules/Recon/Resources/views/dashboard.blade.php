{{--
    The bank recon dashboard (13 Oct 2026; the Pivot, 16 Nov 2026): what is
    still outstanding, per site, per area, bank against PumpIT. Four views of
    one scope — Pivot, Summary, Per site, Items — chosen by link tabs that
    carry the scope, so moving between them never changes what is being
    looked at.

    Props: $view, $views, $asAt, $from, $to, $selected, $branches (null in the
           branch workspace), $area, $side (the Items drill), $params (the
           pivot's cols, lens, side, area, status, fresh), $keep (what the
           scope form carries beside sites and dates), $presets, $link (the
           page's link closure: the query wins, a null drops the key),
           $areaLabels, $inverted, $refusal, $figures (shape(): Summary and
           Per site), $pivot (ReconPivot), $table, $controls, $exports (the
           Pivot), $kpis, $notices, $readAt, $grid (Items)
--}}
@php
    use App\Support\BusinessDate;
    use App\Support\Format;
    use Illuminate\Support\Carbon;
    use Modules\Recon\Services\ReconDashboardService as D;

    $items = fn (array $extra) => $link(['view' => 'items'] + $extra + ['area' => null, 'side' => null]);
    $day = fn (?string $date) => $date ? Carbon::parse($date)->format('j M Y') : Format::NOTHING;
    $sideTone = ['Bank' => 'serious', 'PumpIT' => 'warn'];
    $settings = (array) config('recon.dashboard');
@endphp
<x-app-shell title="Bank recon dashboard">
    <x-page-head eyebrow="Bank reconciliation" title="What is outstanding"
                 :blurb="'As at '.Carbon::parse($asAt)->format('j M Y')
                    .' · '.(count($selected) === 0 ? 'every site' : count($selected).' '.Str::plural('site', count($selected)))
                    .($from || $to ? ' · '.($from ? Carbon::parse($from)->format('j M Y') : 'start').' to '.($to ? Carbon::parse($to)->format('j M Y') : 'today') : '')">
        <x-slot:info>
            <p><strong>Outstanding</strong> means what the area screens mean by it. A <strong>bank</strong> line is
               outstanding while it is classified to an area and not yet reconciled; a <strong>PumpIT</strong> deposit
               while it carries no recon batch. Bank lines nobody has classified to an area are shown on their own
               as <strong>Unclassified</strong>, in no total: no area can match them until they are.</p>
            @if ($pivot)
                <p><strong>One meaning per colour</strong>, on the whole page:
                   <x-tone-value tone="crit">red</x-tone-value> is PumpIT money with no bank line beyond what is still
                   in transit (<em>not in bank</em>); <x-tone-value tone="warn">amber</x-tone-value> is bank money with no
                   PumpIT deposit (<em>not declared</em>). A figure is coloured only beyond the larger of
                   {{ Format::rk($settings['tolerance_rand'] ?? 100) }} and {{ Format::pct($settings['tolerance_pct'] ?? 1, 0) }}
                   of the larger side; every sum is exact. <span class="rp-transit">~R84k</span> is money only in transit.
                   Where a side has nothing outstanding: ✓ it was used and is clear, <em>never</em> it has never had a
                   row, <em>stopped</em> its rows stopped while the other side's carry on (a corner mark when it still
                   holds rows), · neither side was ever used, and — the feed history could not be read.</p>
                <p><strong>In transit</strong>: a deposit dated on or after its area's latest classified statement line,
                   less the days a deposit takes to reach the statement. Today:</p>
                <ul>
                    @foreach ($pivot->info()['transit'] as $line)
                        <li>{{ $line }}</li>
                    @endforeach
                </ul>
                <p><strong>The tiles add up</strong>, to the cent:</p>
                <ul>
                    @foreach ($pivot->info()['identity'] as $line)
                        <li>{{ $line }}</li>
                    @endforeach
                </ul>
                <p>The figures are added up on the server by <code>agora.usp_Recon_DashboardOutstanding</code>, kept
                   {{ $settings['cache_minutes'] ?? 5 }} minutes per scope and read again as soon as Agora reconciles
                   anything; the Pivot's feed history is <code>agora.usp_Recon_DashboardFeeds</code>, kept an hour. The
                   Items tab reads the rows a page at a time (<code>agora.usp_Recon_GridOutstanding</code>), with its
                   own export.</p>
            @else
                <p>The figures are added up on the server (<code>agora.usp_Recon_DashboardOutstanding</code>), and the
                   Items tab reads the rows a page at a time (<code>agora.usp_Recon_GridOutstanding</code>), with its
                   own export — so a scope too big for Excel still opens here.</p>
            @endif
            <p>Age is in days from the transaction date to today.</p>
        </x-slot:info>
        <x-slot:actions>
            @if ($readAt)
                <span class="rp-read">Figures read {{ BusinessDate::show($readAt, 'H:i') }} ·
                    <a href="{{ request()->fullUrlWithQuery(['fresh' => '1']) }}" data-loader="Reading the figures again…">Refresh</a></span>
            @endif
            <a class="btn-ghost" href="{{ route('app.recon.index') }}">Bank reconciliation</a>
        </x-slot:actions>
    </x-page-head>

    <x-card title="Scope" style="margin-bottom:16px">
        <form method="GET" action="{{ route('app.recon.dashboard') }}">
            <input type="hidden" name="view" value="{{ $view }}">
            @foreach ($keep as $name => $value)
                <input type="hidden" name="{{ $name }}" value="{{ $value }}">
            @endforeach
            <div class="field-row">
                @if ($branches !== null)
                    <div class="field field-wide">
                        <label for="d-branches">Sites</label>
                        <select id="d-branches" name="branches[]" multiple data-select
                                data-placeholder="Every site you are granted">
                            @foreach ($branches as $branch)
                                <option value="{{ $branch->BranchId }}"
                                    @selected(in_array((int) $branch->BranchId, $selected, true))>{{ $branch->Name }}{{ $branch->IsTrading ? '' : ' · not trading' }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
                <x-field name="from" label="From" type="date" :value="$from"
                         info="Leave empty for everything still outstanding, however old." />
                <x-field name="to" label="To" type="date" :value="$to" info="Leave empty for today." />
            </div>
            <div class="form-actions">
                <button type="submit" class="btn-primary" data-loader="Adding up what is outstanding…">Show</button>
                <a class="btn-ghost" href="{{ route('app.recon.dashboard', ['view' => $view] + $keep) }}">Every site</a>
                <span class="rp-presets" role="group" aria-label="Window">
                    @foreach ($presets as $preset)
                        <a class="btn-ghost" href="{{ $preset['href'] }}" @if ($preset['on']) aria-current="true" @endif
                           data-loader="Adding up what is outstanding…">{{ $preset['label'] }}</a>
                    @endforeach
                </span>
            </div>
        </form>
    </x-card>

    <x-tabs :active="$view" label="Dashboard views" style="margin-bottom:16px" :items="collect($views)
        ->map(fn ($label, $key) => ['key' => $key, 'label' => $label, 'href' => $link(['view' => $key])])
        ->values()->all()" />

    @if ($refusal)
        <x-notice tone="stop" title="The figures could not be read" style="margin-bottom:16px">
            <p>{{ $refusal }}</p>
        </x-notice>
    @endif

    @if ($inverted)
        <x-notice tone="warn" title="From is after To" style="margin-bottom:12px">
            <p>Nothing can be dated in a window that ends before it starts. Change the dates, or pick a preset.</p>
        </x-notice>
    @endif

    @foreach ($notices as $notice)
        <x-notice :tone="$notice['tone']" :title="$notice['title']" style="margin-bottom:12px">
            @foreach ($notice['lines'] as $line)
                <p>{{ $line }}</p>
            @endforeach
        </x-notice>
    @endforeach

    @if ($view === 'items')
        @if ($area || $side)
            <x-notice tone="info" style="margin-bottom:12px">
                @if ($area === 'Unclassified')
                    <p>Showing the unclassified bank lines.
                       <a href="{{ $items([]) }}">Show everything outstanding</a>.</p>
                @else
                    <p>Showing {{ $side ? $side.' side' : 'both sides' }} of
                       {{ $area ? ($areaLabels[$area] ?? $area) : 'every classified area' }}.
                       <a href="{{ $items([]) }}">Show everything outstanding</a>.</p>
                @endif
            </x-notice>
        @endif
        @if ($grid && $grid->total > $grid->definition->exportCeiling())
            {{-- The items extract stops at its ceiling (every site with no window
                 is about 133 000 rows on live). Each side alone is under it, and
                 the pivot's long form is never near it. --}}
            <x-notice tone="info" style="margin-bottom:12px">
                <p>This is {{ Format::n($grid->total) }} rows, more than a download holds
                   ({{ Format::n($grid->definition->exportCeiling()) }}). Narrow it:
                   <a href="{{ $items(['side' => 'Bank', 'area' => $area]) }}">bank side only</a> ·
                   <a href="{{ $items(['side' => 'PumpIT', 'area' => $area]) }}">PumpIT side only</a>, or
                   <a href="{{ $exports['long'] }}">extract the pivot</a>.</p>
            </x-notice>
        @endif
        <x-card flush>
            <x-data-grid :grid="$grid" :branches="null" />
        </x-card>
    @elseif ($figures)
        <x-kpi-strip class="rp-kpis">
            @foreach ($kpis as $tile)
                <x-kpi :label="$tile['label']" :value="$tile['value']" :note="$tile['note']"
                       :tone="$tile['tone']" :stripe="$tile['stripe']" :href="$tile['href']" />
            @endforeach
        </x-kpi-strip>

        @if ($view === 'pivot')
            @include('recon::partials.dashboard-pivot')
        @elseif ($view === 'summary')
            @php
                // One row per site, classified figures only; the totals row is
                // their sum, so it equals the tiles above to the cent.
                $summary = collect($figures['sites'])->map(function (array $site) {
                    $cells = collect($site['cells'])->except('Unclassified');
                    $sum = fn (string $side, string $field) => $cells->sum(fn ($c) => (float) ($c[$side]->{$field} ?? 0));

                    return [
                        'id' => $site['id'],
                        'name' => $site['name'],
                        'bankRows' => (int) $sum('Bank', 'Rows'),
                        'bankAmount' => $sum('Bank', 'Amount'),
                        'pumpitRows' => (int) $sum('PumpIT', 'Rows'),
                        'pumpitAmount' => $sum('PumpIT', 'Amount'),
                        'old' => (int) $cells->sum(fn ($c) => collect(D::SIDES)->sum(fn ($s) => (int) ($c[$s]->Age365Rows ?? 0) + (int) ($c[$s]->AgeOverRows ?? 0))),
                        'loose' => (int) ($site['cells']['Unclassified']['Bank']->Rows ?? 0),
                        'oldest' => $site['oldest'],
                    ];
                });
                $sites = $summary->count();
                $scopeBranches = $selected === [] ? null : $selected;
                // The bank series in a series blue rather than --muted, which is
                // black or white: Bank is a measure here, not context.
                $colours = ['colors' => ['token:--s1', 'token:--s4']];
            @endphp
            <div class="two-col" style="margin:16px 0">
                <x-card title="By area" sub="Amount outstanding, bank against PumpIT">
                    <x-chart type="mix" :height="280"
                             :series="collect(D::AREAS)->reject(fn ($a) => $a === 'Unclassified')->map(fn ($a) => ['label' => $areaLabels[$a] ?? $a, 'values' => [
                                 'Bank' => $figures['areas'][$a]['Bank']['amount'],
                                 'PumpIT' => $figures['areas'][$a]['PumpIT']['amount'],
                             ]])->filter(fn ($r) => $r['values']['Bank'] != 0 || $r['values']['PumpIT'] != 0)->values()->all()"
                             :options="['measures' => D::SIDES, 'format' => 'R', 'axisFormat' => 'Rk', 'apex' => $colours,
                                        'labelHeading' => 'Area', 'label' => 'Outstanding amount by area, bank and PumpIT']" />
                </x-card>
                <x-card title="By age" sub="Items outstanding, by days since the transaction">
                    <x-chart type="mix" :height="280"
                             :series="collect(D::BUCKETS)->map(fn ($label, $prefix) => ['label' => $label, 'values' => [
                                 'Bank' => $figures['ageing']['Bank'][$prefix]['rows'],
                                 'PumpIT' => $figures['ageing']['PumpIT'][$prefix]['rows'],
                             ]])->values()->all()"
                             :options="['measures' => D::SIDES, 'format' => 'n', 'apex' => $colours,
                                        'labelHeading' => 'Age', 'label' => 'Outstanding items by age, bank and PumpIT']" />
                </x-card>
            </div>

            <x-card title="Per site" flush>
                <x-slot:info>
                    <p>One row per site with anything outstanding. A figure opens the rows behind it on the Items tab;
                       a site's name opens its own breakdown. The totals row is the whole scope, whatever the filters
                       show.</p>
                </x-slot:info>
                <x-table layout-key="Recon.dashboard.1" :count="$sites" procedure="agora.usp_Recon_DashboardOutstanding" tools fit
                         empty="Nothing is outstanding for this scope.">
                    <x-slot:head>
                        <tr>
                            <th>Site</th>
                            <th class="num">Bank lines</th><th class="num">Bank amount</th>
                            <th class="num">PumpIT deposits</th><th class="num">PumpIT amount</th>
                            <th class="num">Over 90 days</th>
                            <th class="num">Unclassified</th>
                            <th class="fit-min">Oldest</th>
                        </tr>
                    </x-slot:head>
                    @foreach ($summary as $row)
                        <tr>
                            <td><a href="{{ $link(['view' => 'sites', 'branches' => [$row['id']]]) }}">{{ $row['name'] }}</a></td>
                            <td class="num">@if ($row['bankRows'] > 0)<a href="{{ $items(['branches' => [$row['id']], 'side' => 'Bank']) }}">{{ Format::n($row['bankRows']) }}</a>@else{{ Format::n(0) }}@endif</td>
                            <td class="num" data-sort="{{ $row['bankAmount'] }}">{{ Format::r($row['bankAmount']) }}</td>
                            <td class="num">@if ($row['pumpitRows'] > 0)<a href="{{ $items(['branches' => [$row['id']], 'side' => 'PumpIT']) }}">{{ Format::n($row['pumpitRows']) }}</a>@else{{ Format::n(0) }}@endif</td>
                            <td class="num" data-sort="{{ $row['pumpitAmount'] }}">{{ Format::r($row['pumpitAmount']) }}</td>
                            <td class="num" data-sort="{{ $row['old'] }}">@if ($row['old'] > 0)<x-tone-value tone="crit">{{ Format::n($row['old']) }}</x-tone-value>@else — @endif</td>
                            <td class="num" data-sort="{{ $row['loose'] }}">@if ($row['loose'] > 0)<a href="{{ $items(['branches' => [$row['id']], 'area' => 'Unclassified']) }}">{{ Format::n($row['loose']) }}</a>@else — @endif</td>
                            <td class="fit-min" data-sort="{{ $row['oldest'] }}">{{ $day($row['oldest']) }}</td>
                        </tr>
                    @endforeach
                    @if ($sites > 1)
                        <x-slot:foot>
                            <tr>
                                <td>All sites in scope ({{ $sites }} with something outstanding)</td>
                                <td class="num"><a href="{{ $items(['branches' => $scopeBranches, 'side' => 'Bank']) }}">{{ Format::n($summary->sum('bankRows')) }}</a></td>
                                <td class="num">{{ Format::r($summary->sum('bankAmount')) }}</td>
                                <td class="num"><a href="{{ $items(['branches' => $scopeBranches, 'side' => 'PumpIT']) }}">{{ Format::n($summary->sum('pumpitRows')) }}</a></td>
                                <td class="num">{{ Format::r($summary->sum('pumpitAmount')) }}</td>
                                <td class="num">@if ($summary->sum('old') > 0)<x-tone-value tone="crit">{{ Format::n($summary->sum('old')) }}</x-tone-value>@else — @endif</td>
                                <td class="num">@if ($summary->sum('loose') > 0)<a href="{{ $items(['branches' => $scopeBranches, 'area' => 'Unclassified']) }}">{{ Format::n($summary->sum('loose')) }}</a>@else — @endif</td>
                                <td class="fit-min">{{ $day($summary->pluck('oldest')->filter()->min()) }}</td>
                            </tr>
                        </x-slot:foot>
                    @endif
                </x-table>
            </x-card>
        @else
            {{-- Per site: each site's areas, bank against PumpIT, aged, and the
                 screen that clears each area one click away. --}}
            @forelse ($figures['sites'] as $site)
                @php
                    $sub = Format::n($site['classified']).' items outstanding · oldest '.$day($site['oldest'])
                        .($site['unclassified'] > 0 ? ' · '.Format::n($site['unclassified']).' unclassified' : '');
                    $lines = collect($site['cells'])->sum(fn (array $sides) => count($sides));
                @endphp
                <x-card :title="$site['name']" :sub="$sub"
                        collapsible :open="count($figures['sites']) <= 3" flush style="margin-top:16px">
                    <x-table layout-key="Recon.dashboard.2" :count="$lines" dense fit>
                        <x-slot:head>
                            <tr>
                                <th>Area</th><th>Side</th>
                                <th class="num">Items</th><th class="num">Amount</th>
                                <th class="num" title="PumpIT deposits dated inside the area's in-transit window">In transit</th>
                                @foreach (D::BUCKETS as $label)
                                    <th class="num">{{ $label }}</th>
                                @endforeach
                                <th class="fit-min">Oldest</th>
                                <th class="fit-min">Work</th>
                            </tr>
                        </x-slot:head>
                        @foreach (D::AREAS as $a)
                            @php
                                // The Work link sits on the area's first row. None
                                // for Unclassified: Agora cannot classify a line.
                                $work = $a === 'Unclassified' ? null
                                    : $pivot->workWindow($site['cells'][$a]['Bank'] ?? null, $site['cells'][$a]['PumpIT'] ?? null, $from, $to);
                                $first = true;
                            @endphp
                            @foreach (D::SIDES as $s)
                                @continue(! isset($site['cells'][$a][$s]))
                                @php
                                    $c = $site['cells'][$a][$s];
                                    $transit = (int) ($c->TransitRows ?? 0);
                                @endphp
                                <tr>
                                    <td>{{ $areaLabels[$a] ?? $a }}</td>
                                    <td><x-chip :tone="$sideTone[$s]" :dot="false">{{ $s }}</x-chip></td>
                                    <td class="num"><a href="{{ $items(['branches' => [$site['id']], 'area' => $a, 'side' => $s]) }}">{{ Format::n($c->Rows) }}</a></td>
                                    <td class="num">{{ Format::r($c->Amount) }}</td>
                                    <td class="num">@if ($s === 'PumpIT')@if ($transit > 0)<span title="{{ Format::r($c->TransitAmount ?? 0) }}">{{ Format::n($transit) }}</span>@else — @endif @endif</td>
                                    @foreach (array_keys(D::BUCKETS) as $prefix)
                                        <td class="num" title="{{ Format::r($c->{$prefix.'Amount'}) }}">{{ (int) $c->{$prefix.'Rows'} > 0 ? Format::n($c->{$prefix.'Rows'}) : '—' }}</td>
                                    @endforeach
                                    <td class="fit-min">{{ $day($c->OldestDate) }}</td>
                                    <td class="fit-min">@if ($first && $work)<a href="{{ route('app.recon.area', array_filter(['area' => $a, 'branch_id' => $site['id'], 'from' => $work['from'], 'to' => $work['to'], 'tab' => $work['tab']])) }}" title="{{ $work['title'] }}">{{ $work['tab'] === 'suggest' ? 'Suggestions' : 'Manual match' }}</a>@endif</td>
                                </tr>
                                @php
                                    $first = false;
                                @endphp
                            @endforeach
                        @endforeach
                    </x-table>
                </x-card>
            @empty
                <x-empty-state title="Nothing outstanding" text="Every bank line and deposit in this scope is reconciled." />
            @endforelse
        @endif
    @endif

    {{-- The page's one tooltip (tip.js), for the pivot's data-tip cells, chips
         and the entity row. The shell does not mount it. --}}
    @if ($table)
        <x-tip />
    @endif
</x-app-shell>
