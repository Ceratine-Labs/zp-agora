{{--
    One site, one day: is it reconciled, and if not, what keeps it open.

    In the order somebody acts on it: each counting area and the run that
    decides it, then the lines still open on that run with the reason the
    procedure gave — a possible duplicate, a missing shift, a correction too
    large to be a miscount — and last every run whose window includes the day,
    so the overlap is on the screen rather than in somebody's head.

    Props: $areas, $open, $runs, $branch, $date
--}}
@php
    use App\Support\BusinessDate;
    use Modules\StockRecon\Services\ReconDayService;

    $tone = ['done' => 'good', 'partial' => 'serious', 'open' => 'crit'];
    $word = ['done' => 'Reconciled', 'partial' => 'Partly reconciled', 'open' => 'Not reconciled'];
    $status = $areas->isEmpty() ? 'none'
        : ($areas->every(fn ($a) => $a->AreaStatus === 'done') ? 'done'
        : ($areas->every(fn ($a) => $a->AreaStatus === 'open') ? 'open' : 'partial'));
    $site = $branch?->Name ?? 'Site';
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

    <x-card title="Counting areas">
        <x-table layout-key="StockRecon.days.areas" procedure="agora.usp_StockRecon_DayStatus" :count="$areas->count()" dense
                 empty="Nothing was counted at this site on this day.">
            <x-slot:head>
                <tr><th>Area</th><th>State</th><th>What is open</th><th class="num">Lines</th><th>Decided by</th><th>Posted</th></tr>
            </x-slot:head>
            @foreach ($areas as $a)
                <tr>
                    <td>{{ $a->AreaDescription ?? 'Area '.$a->AreaNo }}</td>
                    <td><x-chip :tone="$tone[$a->AreaStatus] ?? 'neutral'">{{ $word[$a->AreaStatus] ?? $a->AreaStatus }}</x-chip></td>
                    <td>{{ $a->Summary }}</td>
                    <td class="num">{{ $a->CountedLines }}</td>
                    <td>
                        @if ($a->RunId)
                            <a href="{{ route('app.stockrecon.run', $a->RunId) }}">Run {{ $a->RunId }}</a>
                            <span class="muted">· {{ substr((string) $a->RunFrom, 0, 10) }} to {{ substr((string) $a->RunTo, 0, 10) }}</span>
                        @else
                            <span class="muted">No posted run</span>
                        @endif
                    </td>
                    <td>{{ $a->RunId ? BusinessDate::show($a->CommittedAt).($a->CommittedByName ? ' · '.$a->CommittedByName : '') : '' }}</td>
                </tr>
            @endforeach
        </x-table>
    </x-card>

    <x-card title="What keeps it open" :sub="$open->isEmpty() ? null : $open->count().' line(s) on the deciding runs'">
        <x-table layout-key="StockRecon.days.open" procedure="agora.usp_StockRecon_DayStatus" :count="$open->count()" dense tools
                 empty="Nothing is open on a posted run for this day.">
            <x-slot:head>
                <tr><th>Area</th><th>Item</th><th class="num">Shift</th><th>What</th><th>Why</th><th>Run</th></tr>
            </x-slot:head>
            @foreach ($open as $l)
                <tr>
                    <td>{{ $l->AreaDescription ?? 'Area '.$l->AreaNo }}</td>
                    <td>{{ $l->ItemDescription ?? $l->StockItemNo }} <span class="muted mono">{{ $l->StockItemNo }}</span></td>
                    <td class="num">{{ $l->ShiftNo }}</td>
                    <td><x-chip :tone="$l->Kind === 'Held back' ? 'serious' : ($l->Kind === 'Reversed' ? 'warn' : 'neutral')">{{ $l->Kind }}</x-chip></td>
                    <td>{{ $l->Reason }}</td>
                    <td><a href="{{ route('app.stockrecon.run', $l->RunId) }}">Run {{ $l->RunId }}</a></td>
                </tr>
            @endforeach
        </x-table>
    </x-card>

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
