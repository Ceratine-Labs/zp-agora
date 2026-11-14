{{--
    One site, one day: is it reconciled, and if not, what keeps it open.

    In the order somebody acts on it: each counting area and the run that
    decides it, then the lines still open on that run with the reason the
    procedure gave — a possible duplicate, a missing shift, a correction too
    large to be a miscount — and last every run whose window includes the day,
    so the overlap is on the screen rather than in somebody's head.

    Each counting area is a fold (Ryan, 14 Nov 2026): open it for every item
    counted that day, with what the tills sold beside the POS on its recon
    lines (Chantal Peens's "COMPUTER QTY" check, agora.usp_StockRecon_DayItems)
    and the lines still open on the deciding run. An area with a missing till
    quantity starts open, under a notice that says how many.

    Props: $areas, $open, $runs, $items (rows by AreaNo), $branch, $date
--}}
@php
    use App\Support\BusinessDate;
    use App\Support\Format;
    use Modules\StockRecon\Services\ReconDayService;

    $tone = ['done' => 'good', 'partial' => 'serious', 'open' => 'crit'];
    $word = ['done' => 'Reconciled', 'partial' => 'Partly reconciled', 'open' => 'Not reconciled'];
    $status = $areas->isEmpty() ? 'none'
        : ($areas->every(fn ($a) => $a->AreaStatus === 'done') ? 'done'
        : ($areas->every(fn ($a) => $a->AreaStatus === 'open') ? 'open' : 'partial'));
    $site = $branch?->Name ?? 'Site';
    $items = $items ?? collect();
    $shown = $areas->pluck('AreaNo')->all();
    $flagged = $items->only($shown)->map(fn ($rows) => $rows->whereNotNull('Kind')->count())->filter();
    $missingItems = (int) $flagged->sum();
    $missingAreas = $flagged->count();
@endphp

<x-app-shell :title="$site.' · '.$date->format('j M Y')">
    <x-page-head eyebrow="Stock recon centre · reconciled days"
                 :title="$site.' · '.$date->format('l j F Y')"
                 :blurb="ReconDayService::LEGEND[$status] ?? 'No counts'">
        <x-slot:info>
            <p>A counting area's day is decided by the latest <strong>posted</strong> run that carries lines for it.
               It is reconciled when nothing is left open on that run: no chain held back for head office, no
               proposed amendment left unwritten, nothing reversed since, and no counts captured after the run.</p>
            <p>Amended shifts are balancing doing its job, so they do not make a day orange.</p>
        </x-slot:info>
        <x-slot:actions>
            <a class="btn" href="{{ route('app.stockrecon.index', ['branch_id' => $branch?->BranchId]) }}">Back to the centre</a>
        </x-slot:actions>
    </x-page-head>

    @if ($missingItems > 0)
        <x-notice tone="warn" title="Till quantities are missing in PumpIT — {{ $missingItems }} {{ Str::plural('item', $missingItems) }} in {{ $missingAreas }} {{ Str::plural('area', $missingAreas) }} cannot balance yet" style="margin-bottom:16px">
            <p>What the tills sold does not match the POS quantity on these items' recon lines, so they cannot balance
               however they are amended. Update the computer quantities in PumpIT, then preview this day again.
               They are listed under What remains.</p>
        </x-notice>
    @endif

    {{-- TABS (Ryan, 14 Nov 2026: "make each of those rows a tab header ... runs
         over this day as the main tab, second tab shows what products remain
         for the day, the rest are the areas"). Panel mode: every pane is on
         the page, the open one in ?tab= so a reload or a copied link lands on
         it. An area's tab takes its state's colour and counts its items. --}}
    @php
        $remains = $areas->flatMap(function ($a) use ($items, $open) {
            return $items->get($a->AreaNo, collect())
                ->filter(fn ($i) => $i->Kind !== null || abs((float) $i->QtyVar) > 0.0005
                    || $open->where('AreaNo', $a->AreaNo)->where('StockItemNo', $i->StockItemNo)->isNotEmpty())
                ->map(fn ($i) => (object) ((array) $i + ['AreaStatus' => $a->AreaStatus]));
        })->values();
        $tabItems = collect([
            ['key' => 'runs', 'label' => 'Runs over this day', 'count' => $runs->count()],
            ['key' => 'remains', 'label' => 'What remains', 'count' => $remains->count(),
                'tone' => $remains->isEmpty() ? 'good' : ($remains->whereNotNull('Kind')->isNotEmpty() ? 'warn' : 'crit')],
        ])->concat($areas->map(fn ($a) => [
            'key' => 'area-'.$a->AreaNo,
            'label' => $a->AreaDescription ?? 'Area '.$a->AreaNo,
            'count' => $items->get($a->AreaNo, collect())->count(),
            'tone' => $tone[$a->AreaStatus] ?? 'neutral',
        ]))->all();
        $asked = (string) request()->query('tab', 'runs');
        $activeTab = collect($tabItems)->contains('key', $asked) ? $asked : 'runs';
    @endphp

    <x-tabs :items="$tabItems" :active="$activeTab" query="tab" label="Day" class="day-tabs">
        <x-tab-panel key="runs">
            <x-card flush>
                <x-table layout-key="StockRecon.days.runs" procedure="agora.usp_StockRecon_DayStatus" :count="$runs->count()" dense
                         empty="No run has included this day.">
                    <x-slot:head>
                        <tr><th>Run</th><th>Areas</th><th>Window</th><th>State</th><th>Posted</th><th>Decides</th></tr>
                    </x-slot:head>
                    @foreach ($runs as $r)
                        <tr>
                            <td><a href="{{ route('app.stockrecon.run', $r->RunId) }}">Run {{ $r->RunId }}</a>@if ($r->Note) <span class="muted">· {{ $r->Note }}</span>@endif</td>
                            <td>{{ $r->AreaNo === null ? 'All areas' : ($r->AreaDescription ?? 'Area '.$r->AreaNo) }}</td>
                            <td>{{ substr((string) $r->FromDate, 0, 10) }} to {{ substr((string) $r->ToDate, 0, 10) }}</td>
                            <td>{{ $r->StatusLabel }}</td>
                            <td>{{ $r->CommittedAt ? BusinessDate::show($r->CommittedAt).($r->CommittedByName ? ' · '.$r->CommittedByName : '') : '' }}</td>
                            <td>{{ $r->Decides ? 'Yes' : '' }}</td>
                        </tr>
                    @endforeach
                </x-table>
            </x-card>
        </x-tab-panel>

        <x-tab-panel key="remains">
            <x-card flush>
                <x-table layout-key="StockRecon.days.remains.1" procedure="agora.usp_StockRecon_DayItems" :count="$remains->count()" dense tools
                         empty="Nothing remains: every item counted this day balances and has its till quantities.">
                    <x-slot:head>
                        <tr>
                            <th>Area</th><th>Item</th><th class="num">Variance</th><th class="num">POS</th><th class="num">Till sold</th>
                            <th class="num">Missing</th><th>What keeps it open</th>
                        </tr>
                    </x-slot:head>
                    @foreach ($remains as $i)
                        @php $lines = $open->where('AreaNo', $i->AreaNo)->where('StockItemNo', $i->StockItemNo); @endphp
                        <tr @class(['is-flagged' => $i->Kind !== null])>
                            <td><a href="{{ request()->fullUrlWithQuery(['tab' => 'area-'.$i->AreaNo]) }}">{{ $i->AreaDescription ?? 'Area '.$i->AreaNo }}</a></td>
                            <td>{{ $i->StockItemDescription ?? $i->StockItemNo }} <span class="muted mono">{{ $i->StockItemNo }}</span></td>
                            <td class="num">{{ Format::n($i->QtyVar, 3) }}</td>
                            <td class="num">{{ Format::n($i->QtyPOS, 3) }}</td>
                            <td class="num">{{ $i->QtyTill === null ? '—' : Format::n($i->QtyTill, 3) }}</td>
                            <td class="num">
                                @if ($i->Kind === 'day')
                                    <x-chip tone="warn" title="No POS on any shift this day">{{ Format::n($i->QtyMissing, 3) }} · whole day</x-chip>
                                @elseif ($i->Kind === 'part')
                                    <x-chip tone="warn" title="A shift is missing its POS">{{ Format::n($i->QtyMissing, 3) }} · a shift</x-chip>
                                @endif
                            </td>
                            <td>
                                @forelse ($lines as $l)
                                    <div>
                                        <x-chip :tone="$l->Kind === 'Held back' ? 'serious' : ($l->Kind === 'Reversed' ? 'warn' : 'neutral')">{{ $l->Kind }}</x-chip>
                                        <span class="muted">shift {{ $l->ShiftNo }} ·</span> {{ $l->Reason }}
                                        <a href="{{ route('app.stockrecon.run', $l->RunId) }}">Run {{ $l->RunId }}</a>
                                    </div>
                                @empty
                                    <span class="muted">{{ $i->AreaStatus === 'open' ? 'No posted run for this area' : 'Variance left in PumpIT' }}</span>
                                @endforelse
                            </td>
                        </tr>
                    @endforeach
                </x-table>
            </x-card>
        </x-tab-panel>

        @foreach ($areas as $a)
            @php
                $rows = $items->get($a->AreaNo, collect());
                $areaOpen = $open->where('AreaNo', $a->AreaNo);
            @endphp
            <x-tab-panel :key="'area-'.$a->AreaNo">
                <x-card flush>
                    <p class="muted day-area-meta" data-area="{{ $a->AreaNo }}">
                        <x-chip :tone="$tone[$a->AreaStatus] ?? 'neutral'">{{ $word[$a->AreaStatus] ?? $a->AreaStatus }}</x-chip>
                        {{ $a->Summary }} · {{ $a->CountedLines }} {{ Str::plural('line', (int) $a->CountedLines) }}
                        @if ($a->RunId)
                            · <a href="{{ route('app.stockrecon.run', $a->RunId) }}">decided by run {{ $a->RunId }}</a>
                            ({{ substr((string) $a->RunFrom, 0, 10) }} to {{ substr((string) $a->RunTo, 0, 10) }}),
                            posted {{ BusinessDate::show($a->CommittedAt) }}{{ $a->CommittedByName ? ' · '.$a->CommittedByName : '' }}
                        @else
                            · no posted run has balanced this area for this day
                        @endif
                    </p>
                    <x-table layout-key="StockRecon.days.items.1" procedure="agora.usp_StockRecon_DayItems" :count="$rows->count()" dense tools
                             empty="No item lines were found for this area.">
                        <x-slot:head>
                            <tr>
                                <th>Item</th><th class="num">Shifts</th><th class="num">Open</th><th class="num">Issued</th><th class="num">Close</th>
                                <th class="num">POS</th><th class="num">Till sold</th><th class="num">Missing</th><th class="num">Variance</th>
                                <th>What keeps it open</th>
                            </tr>
                        </x-slot:head>
                        @foreach ($rows as $i)
                            @php $lines = $areaOpen->where('StockItemNo', $i->StockItemNo); @endphp
                            <tr @class(['is-flagged' => $i->Kind !== null])>
                                <td>{{ $i->StockItemDescription ?? $i->StockItemNo }} <span class="muted mono">{{ $i->StockItemNo }}</span></td>
                                <td class="num">{{ $i->Shifts }}</td>
                                <td class="num">{{ Format::n($i->QtyOpen, 3) }}</td>
                                <td class="num">{{ Format::n($i->QtyIssued, 3) }}</td>
                                <td class="num">{{ Format::n($i->QtyClose, 3) }}</td>
                                <td class="num">{{ Format::n($i->QtyPOS, 3) }}</td>
                                <td class="num">{{ $i->QtyTill === null ? '—' : Format::n($i->QtyTill, 3) }}</td>
                                <td class="num">
                                    @if ($i->Kind === 'day')
                                        <x-chip tone="warn" title="No POS on any shift this day">{{ Format::n($i->QtyMissing, 3) }} · whole day</x-chip>
                                    @elseif ($i->Kind === 'part')
                                        <x-chip tone="warn" title="A shift is missing its POS">{{ Format::n($i->QtyMissing, 3) }} · a shift</x-chip>
                                    @endif
                                </td>
                                <td class="num">{{ Format::n($i->QtyVar, 3) }}</td>
                                <td>
                                    @foreach ($lines as $l)
                                        <div>
                                            <x-chip :tone="$l->Kind === 'Held back' ? 'serious' : ($l->Kind === 'Reversed' ? 'warn' : 'neutral')">{{ $l->Kind }}</x-chip>
                                            <span class="muted">shift {{ $l->ShiftNo }} ·</span> {{ $l->Reason }}
                                            <a href="{{ route('app.stockrecon.run', $l->RunId) }}">Run {{ $l->RunId }}</a>
                                        </div>
                                    @endforeach
                                </td>
                            </tr>
                        @endforeach
                    </x-table>
                </x-card>
            </x-tab-panel>
        @endforeach
    </x-tabs>
</x-app-shell>
