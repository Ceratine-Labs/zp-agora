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
               The areas concerned are open below.</p>
        </x-notice>
    @endif

    <div class="day-areas">
        @forelse ($areas as $a)
            @php
                $rows = $items->get($a->AreaNo, collect());
                $gaps = $rows->whereNotNull('Kind')->count();
                $areaOpen = $open->where('AreaNo', $a->AreaNo);
                $sub = trim(($a->Summary ? $a->Summary.' · ' : '').$a->CountedLines.' '.Str::plural('line', (int) $a->CountedLines).' · '.$rows->count().' '.Str::plural('item', $rows->count())
                    .($a->RunId ? ' · decided by run '.$a->RunId.' ('.substr((string) $a->RunFrom, 0, 10).' to '.substr((string) $a->RunTo, 0, 10).')' : ' · no posted run'));
            @endphp
            <x-card :title="$a->AreaDescription ?? 'Area '.$a->AreaNo" :sub="$sub" collapsible flush :open="$gaps > 0"
                    data-area="{{ $a->AreaNo }}">
                <x-slot:actions>
                    @if ($gaps > 0)<x-chip tone="warn">{{ $gaps }} missing till {{ Str::plural('quantity', $gaps) }}</x-chip>@endif
                    <x-chip :tone="$tone[$a->AreaStatus] ?? 'neutral'">{{ $word[$a->AreaStatus] ?? $a->AreaStatus }}</x-chip>
                </x-slot:actions>
                <div class="day-area-body">
                    <p class="muted day-area-meta">
                        @if ($a->RunId)
                            <a href="{{ route('app.stockrecon.run', $a->RunId) }}">Open run {{ $a->RunId }}</a>
                            · posted {{ BusinessDate::show($a->CommittedAt) }}{{ $a->CommittedByName ? ' · '.$a->CommittedByName : '' }}
                        @else
                            No posted run has balanced this area for this day.
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
                            @php($lines = $areaOpen->where('StockItemNo', $i->StockItemNo))
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
                </div>
            </x-card>
        @empty
            <x-empty-state text="Nothing was counted at this site on this day." />
        @endforelse
    </div>

    <x-card title="Runs over this day" sub="Every run whose window includes it, newest first">
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
</x-app-shell>
