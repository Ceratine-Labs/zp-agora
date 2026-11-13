{{--
    A month of days, one coloured cell each, per row — "is each day done?".

        <x-day-calendar :rows="$rows" :month="$month" :href="fn ($row, $day) => ..." />

    `rows`: [{label, days: [{Day, DayStatus, Summary, AmendedLines}, ...]}, ...]
    — one row per site, each with a cell for every date of the month. A single
    row is split in two bands (1–16, 17–end) so a month reads at a glance
    without scrolling; several rows keep one band and scroll sideways on a
    phone inside their own container.

    The STATUS is the procedure's (agora.usp_StockRecon_DayStatus): done,
    partial, open, none, later — green, orange, red, grey, grey. Colour is
    never alone: the day number is in every cell, the procedure's sentence is
    the cell's title and accessible name, and the legend spells each colour
    out. A day with amendments carries a dot — amended is balancing doing its
    job, so it is a mark, never a colour (Ryan, 13 Nov 2026).

    `href(row, day)` gives the link for a cell with something behind it; grey
    cells are not links.
--}}
@props([
    'rows' => [],
    'month' => null,
    'href' => null,
    'legend' => [],
    'prev' => null,
    'next' => null,
])

@php
    use Illuminate\Support\Carbon;

    $rows = collect($rows);
    $single = $rows->count() === 1;
    $days = collect($rows->first()['days'] ?? [])->map(fn ($d) => Carbon::parse((string) $d->Day));
    $bands = $single && $days->count() > 16 ? [$days->slice(0, 16)->values(), $days->slice(16)->values()] : [$days];
    $linked = ['done', 'partial', 'open'];
@endphp

<div {{ $attributes->merge(['class' => 'dcal']) }}>
    <div class="dcal-bar">
        @if ($prev)<a class="btn-ghost dcal-nav" href="{{ $prev }}" data-fragment-link aria-label="Previous month">&lsaquo;</a>@endif
        <strong class="dcal-month">{{ $month?->format('F Y') }}</strong>
        @if ($next)<a class="btn-ghost dcal-nav" href="{{ $next }}" data-fragment-link aria-label="Next month">&rsaquo;</a>@endif
        <ul class="dcal-legend">
            @foreach ($legend as $status => $label)
                <li><span class="dcal-swatch is-{{ $status }}" aria-hidden="true"></span>{{ $label }}</li>
            @endforeach
            <li><span class="dcal-swatch is-done" aria-hidden="true"><i class="dcal-dot"></i></span>Amended</li>
        </ul>
    </div>

    <div class="dcal-scroll">
        @foreach ($bands as $band)
            <table class="dcal-grid">
                <thead>
                    <tr>
                        @unless ($single)<th class="dcal-site" scope="col"><span class="sr-only">Site</span></th>@endunless
                        @foreach ($band as $date)
                            <th scope="col" class="{{ $date->isWeekend() ? 'is-weekend' : '' }}">
                                <span class="dcal-wd">{{ substr($date->format('D'), 0, 2) }}</span>
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        @php
                            $byDay = collect($row['days'])->keyBy(fn ($d) => substr((string) $d->Day, 0, 10));
                        @endphp
                        <tr>
                            @unless ($single)<th class="dcal-site" scope="row">{{ $row['label'] }}</th>@endunless
                            @foreach ($band as $date)
                                @php
                                    $cell = $byDay->get($date->toDateString());
                                    $status = (string) ($cell->DayStatus ?? 'none');
                                    $label = $date->format('j M').': '.($cell->Summary ?? 'No counts');
                                    $link = $href && in_array($status, $linked, true) ? $href($row, $date) : null;
                                    $dot = (int) ($cell->AmendedLines ?? 0) > 0;
                                @endphp
                                <td>
                                    @if ($link)
                                        <a class="dcal-cell is-{{ $status }}" href="{{ $link }}" title="{{ $label }}" aria-label="{{ $label }}">
                                            {{ $date->day }}@if ($dot)<i class="dcal-dot" aria-hidden="true"></i>@endif
                                        </a>
                                    @else
                                        <span class="dcal-cell is-{{ $status }}" title="{{ $label }}" aria-label="{{ $label }}">{{ $date->day }}</span>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endforeach
    </div>
</div>
