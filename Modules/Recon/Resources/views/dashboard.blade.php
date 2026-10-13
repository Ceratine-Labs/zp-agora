{{--
    The bank recon dashboard (13 Oct 2026): what is still outstanding, per
    site, per area, bank against PumpIT, aged. Three views of one scope —
    Summary, Per site, Items — chosen by link tabs that carry the scope, so
    moving between them never changes what is being looked at.

    Props: $view, $views, $asAt, $from, $to, $selected, $branches (null in the
           branch workspace), $area, $side, $areaLabels, $refusal,
           $figures (summary and sites), $grid (items)
--}}
@php
    use App\Support\Format;
    use Modules\Recon\Services\ReconDashboardService as D;

    $scope = array_filter(['branches' => $selected ?: null, 'from' => $from, 'to' => $to]);
    $link = fn (array $extra = []) => route('app.recon.dashboard', $scope + $extra);
    $items = fn (array $extra) => $link(['view' => 'items'] + $extra);
    $sideTone = ['Bank' => 'serious', 'PumpIT' => 'warn'];
@endphp
<x-app-shell title="Bank recon dashboard">
    <x-page-head eyebrow="Bank reconciliation" title="What is outstanding"
                 :blurb="'As at '.\Illuminate\Support\Carbon::parse($asAt)->format('j M Y')
                    .' · '.(count($selected) === 0 ? 'every site' : count($selected).' '.Str::plural('site', count($selected)))
                    .($from || $to ? ' · '.($from ? \Illuminate\Support\Carbon::parse($from)->format('j M Y') : 'start').' to '.($to ? \Illuminate\Support\Carbon::parse($to)->format('j M Y') : 'today') : '')">
        <x-slot:info>
            <p><strong>Outstanding</strong> means what the area screens mean by it. A <strong>bank</strong> line is
               outstanding while it is classified to an area and not yet reconciled; a <strong>PumpIT</strong> deposit
               while it carries no recon batch. Bank lines nobody has classified to an area are shown on their own
               as <strong>Unclassified</strong>: no area can match them until they are.</p>
            <p>The figures are added up on the server (<code>agora.usp_Recon_DashboardOutstanding</code>), and the
               Items tab reads the rows a page at a time (<code>agora.usp_Recon_GridOutstanding</code>), with its
               own export — so a scope too big for Excel still opens here.</p>
            <p>Age is in days from the transaction date to today.</p>
        </x-slot:info>
        <x-slot:actions>
            <a class="btn-ghost" href="{{ route('app.recon.index') }}">Bank reconciliation</a>
        </x-slot:actions>
    </x-page-head>

    <x-card title="Scope" style="margin-bottom:16px">
        <form method="GET" action="{{ route('app.recon.dashboard') }}">
            <input type="hidden" name="view" value="{{ $view }}">
            <div class="field-row">
                @if ($branches !== null)
                    <div class="field field-wide">
                        <label for="d-branches">Sites</label>
                        <select id="d-branches" name="branches[]" multiple data-select
                                data-placeholder="Every site you are granted">
                            @foreach ($branches as $branch)
                                <option value="{{ $branch->BranchId }}"
                                    @selected(in_array((int) $branch->BranchId, $selected, true))>{{ $branch->Name }}</option>
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
                <a class="btn-ghost" href="{{ route('app.recon.dashboard', ['view' => $view]) }}">Every site</a>
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

    @if ($view === 'items')
        @if ($area || $side)
            <x-notice tone="info" style="margin-bottom:12px">
                <p>Showing {{ $side ? $side.' side' : 'both sides' }} of
                   {{ $area ? ($areaLabels[$area] ?? $area) : 'every classified area' }}.
                   <a href="{{ $items([]) }}">Show everything outstanding</a>.</p>
            </x-notice>
        @endif
        <x-card flush>
            <x-data-grid :grid="$grid" :branches="null" />
        </x-card>
    @elseif ($figures)
        @php
            $bank = $figures['sides']['Bank'];
            $pumpit = $figures['sides']['PumpIT'];
            $over90 = fn (string $side) => $figures['ageing'][$side]['Age365']['rows'] + $figures['ageing'][$side]['AgeOver']['rows'];
        @endphp

        <x-kpi-strip>
            <x-kpi label="Bank lines outstanding" :value="Format::n($bank['rows'])"
                   :note="Format::r($bank['amount'])" tone="serious" stripe="serious"
                   :href="$items(['side' => 'Bank'])" />
            <x-kpi label="PumpIT deposits outstanding" :value="Format::n($pumpit['rows'])"
                   :note="Format::r($pumpit['amount'])" tone="warn" stripe="warn"
                   :href="$items(['side' => 'PumpIT'])" />
            <x-kpi label="Older than 90 days" :value="Format::n($over90('Bank') + $over90('PumpIT'))"
                   :note="Format::n($over90('Bank')).' bank · '.Format::n($over90('PumpIT')).' PumpIT'"
                   :tone="$over90('Bank') + $over90('PumpIT') > 0 ? 'crit' : 'good'" />
            <x-kpi label="Unclassified bank lines" :value="Format::n($figures['unclassified']['rows'])"
                   :note="$figures['unclassified']['oldest'] ? 'oldest '.\Illuminate\Support\Carbon::parse($figures['unclassified']['oldest'])->format('j M Y').' · not in the figures beside' : 'none'"
                   tone="neutral" :href="$items(['area' => 'Unclassified'])" />
            <x-kpi label="Oldest outstanding"
                   :value="collect([$bank['oldest'], $pumpit['oldest']])->filter()->min()
                        ? \Illuminate\Support\Carbon::parse(collect([$bank['oldest'], $pumpit['oldest']])->filter()->min())->format('j M Y')
                        : '—'"
                   :note="count($figures['sites']).' '.Str::plural('site', count($figures['sites'])).' with something outstanding'" />
        </x-kpi-strip>

        @if ($view === 'summary')
            <div class="two-col" style="margin:16px 0">
                <x-card title="By area" sub="Amount outstanding, bank against PumpIT">
                    <x-chart type="mix" :height="280"
                             :series="collect(D::AREAS)->reject(fn ($a) => $a === 'Unclassified')->map(fn ($a) => ['label' => $areaLabels[$a] ?? $a, 'values' => [
                                 'Bank' => $figures['areas'][$a]['Bank']['amount'],
                                 'PumpIT' => $figures['areas'][$a]['PumpIT']['amount'],
                             ]])->filter(fn ($r) => $r['values']['Bank'] != 0 || $r['values']['PumpIT'] != 0)->values()->all()"
                             :options="['measures' => D::SIDES, 'format' => 'R', 'axisFormat' => 'Rk',
                                        'labelHeading' => 'Area', 'label' => 'Outstanding amount by area, bank and PumpIT']" />
                </x-card>
                <x-card title="By age" sub="Items outstanding, by days since the transaction">
                    <x-chart type="mix" :height="280"
                             :series="collect(D::BUCKETS)->map(fn ($label, $prefix) => ['label' => $label, 'values' => [
                                 'Bank' => $figures['ageing']['Bank'][$prefix]['rows'],
                                 'PumpIT' => $figures['ageing']['PumpIT'][$prefix]['rows'],
                             ]])->values()->all()"
                             :options="['measures' => D::SIDES, 'format' => 'n',
                                        'labelHeading' => 'Age', 'label' => 'Outstanding items by age, bank and PumpIT']" />
                </x-card>
            </div>

            <x-card title="Per site" flush>
                <x-slot:info>
                    <p>One row per site with anything outstanding. A figure opens the rows behind it on the Items tab;
                       a site's name opens its own breakdown.</p>
                </x-slot:info>
                <x-table :count="count($figures['sites'])" procedure="agora.usp_Recon_DashboardOutstanding" tools fit
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
                    @foreach ($figures['sites'] as $site)
                        @php
                            $cells = collect($site['cells'])->except('Unclassified');
                            $sum = fn (string $side, string $field) => $cells->sum(fn ($c) => (float) ($c[$side]->{$field} ?? 0));
                            $old = $cells->sum(fn ($c) => collect(D::SIDES)->sum(fn ($s) => (int) ($c[$s]->Age365Rows ?? 0) + (int) ($c[$s]->AgeOverRows ?? 0)));
                            $loose = (int) ($site['cells']['Unclassified']['Bank']->Rows ?? 0);
                        @endphp
                        <tr>
                            <td><a href="{{ route('app.recon.dashboard', ['view' => 'sites', 'branches' => [$site['id']], 'from' => $from, 'to' => $to]) }}">{{ $site['name'] }}</a></td>
                            <td class="num"><a href="{{ route('app.recon.dashboard', ['view' => 'items', 'branches' => [$site['id']], 'side' => 'Bank', 'from' => $from, 'to' => $to]) }}">{{ Format::n($sum('Bank', 'Rows')) }}</a></td>
                            <td class="num" data-sort="{{ $sum('Bank', 'Amount') }}">{{ Format::r($sum('Bank', 'Amount')) }}</td>
                            <td class="num"><a href="{{ route('app.recon.dashboard', ['view' => 'items', 'branches' => [$site['id']], 'side' => 'PumpIT', 'from' => $from, 'to' => $to]) }}">{{ Format::n($sum('PumpIT', 'Rows')) }}</a></td>
                            <td class="num" data-sort="{{ $sum('PumpIT', 'Amount') }}">{{ Format::r($sum('PumpIT', 'Amount')) }}</td>
                            <td class="num" data-tone="{{ $old > 0 ? 'crit' : 'good' }}">{{ $old > 0 ? Format::n($old) : '—' }}</td>
                            <td class="num">@if ($loose > 0)<a href="{{ route('app.recon.dashboard', ['view' => 'items', 'branches' => [$site['id']], 'area' => 'Unclassified', 'from' => $from, 'to' => $to]) }}">{{ Format::n($loose) }}</a>@else — @endif</td>
                            <td class="fit-min" data-sort="{{ $site['oldest'] }}">{{ $site['oldest'] ? \Illuminate\Support\Carbon::parse($site['oldest'])->format('j M Y') : '—' }}</td>
                        </tr>
                    @endforeach
                </x-table>
            </x-card>
        @else
            {{-- Per site: each site's areas, bank against PumpIT, aged. --}}
            @forelse ($figures['sites'] as $site)
                <x-card :title="$site['name']" :sub="Format::n($site['rows']).' items outstanding · oldest '.\Illuminate\Support\Carbon::parse($site['oldest'])->format('j M Y')"
                        collapsible :open="count($figures['sites']) <= 3" flush style="margin-top:16px">
                    <x-table :count="count($site['cells']) * 2" dense fit>
                        <x-slot:head>
                            <tr>
                                <th>Area</th><th>Side</th>
                                <th class="num">Items</th><th class="num">Amount</th>
                                @foreach (D::BUCKETS as $label)
                                    <th class="num">{{ $label }}</th>
                                @endforeach
                                <th class="fit-min">Oldest</th>
                            </tr>
                        </x-slot:head>
                        @foreach (D::AREAS as $a)
                            @foreach (D::SIDES as $s)
                                @continue(! isset($site['cells'][$a][$s]))
                                @php($c = $site['cells'][$a][$s])
                                <tr>
                                    <td>{{ $areaLabels[$a] ?? $a }}</td>
                                    <td><x-chip :tone="$sideTone[$s]" :dot="false">{{ $s }}</x-chip></td>
                                    <td class="num"><a href="{{ route('app.recon.dashboard', ['view' => 'items', 'branches' => [$site['id']], 'area' => $a, 'side' => $s, 'from' => $from, 'to' => $to]) }}">{{ Format::n($c->Rows) }}</a></td>
                                    <td class="num">{{ Format::r($c->Amount) }}</td>
                                    @foreach (array_keys(D::BUCKETS) as $prefix)
                                        <td class="num" title="{{ Format::r($c->{$prefix.'Amount'}) }}">{{ (int) $c->{$prefix.'Rows'} > 0 ? Format::n($c->{$prefix.'Rows'}) : '—' }}</td>
                                    @endforeach
                                    <td class="fit-min">{{ \Illuminate\Support\Carbon::parse($c->OldestDate)->format('j M Y') }}</td>
                                </tr>
                            @endforeach
                        @endforeach
                    </x-table>
                </x-card>
            @empty
                <x-empty-state title="Nothing outstanding" text="Every bank line and deposit in this scope is reconciled." />
            @endforelse
        @endif
    @endif
</x-app-shell>
