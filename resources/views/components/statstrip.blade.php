{{--
    A row of figures that describe the result set immediately below them — the
    mockup's `statstrip`.

    Not the same thing as <x-kpi>. A KPI is a headline about the business and
    sits at the top of the page; a stat strip describes this answer — how many
    rows, how much on each side, what the difference is — and sits inside the
    card that carries it. Making them look alike was what stopped a reader
    knowing which figures were the point of the screen.

    Class names are the mockup's: `.statstrip > .s` with `.l`, `.v`, `.n`. The
    longer `.stat / .stat-label / .stat-value` spelling is kept as an alias in
    the stylesheet so views written against it still render.

    `stats` is a list of ['label' =>, 'value' =>, 'note' =>, 'tone' =>].
    Values arrive already formatted by App\Support\Format.
--}}
@props(['stats' => []])

<div {{ $attributes->merge(['class' => 'statstrip']) }}>
    @foreach ($stats as $stat)
        <div class="s stat tone-{{ $stat['tone'] ?? 'neutral' }}">
            @if (isset($stat['icon']))
                <svg class="stat-illustration" viewBox="0 0 32 32" aria-hidden="true" focusable="false">
                    <rect x="3" y="5" width="26" height="22" rx="4" fill="var(--brand-soft)" />
                    @switch($stat['icon'])
                        @case('match') <path d="m9 16 4 4 10-10" /> @break
                        @case('compare') <path d="M9 12h14M9 20h14M16 8v16" /> @break
                        @case('bank') <path d="m7 13 9-5 9 5M9 14v8m5-8v8m4-8v8m5-8v8M7 24h18" /> @break
                        @case('deposit') <path d="M8 18v6h16v-6M16 8v12m-4-4 4 4 4-4" /> @break
                        @default <path d="M16 9v8m0 5h.01" />
                    @endswitch
                </svg>
            @endif
            <div class="l stat-label">{{ $stat['label'] ?? '' }}</div>
            <div class="v stat-value">
                @if (isset($stat['links']))
                    @foreach ($stat['links'] as $link)
                        @if (! $loop->first) → @endif<a href="{{ $link['href'] }}" aria-label="{{ $link['label'] }}">{{ $link['value'] }}</a>
                    @endforeach
                @elseif (isset($stat['href']))
                    <a href="{{ $stat['href'] }}" aria-label="{{ $stat['label'] }}: show records">{{ $stat['value'] ?? \App\Support\Format::NOTHING }}</a>
                @else
                    {{ $stat['value'] ?? \App\Support\Format::NOTHING }}
                @endif
            </div>
            @if (! empty($stat['note']))<div class="n stat-note">{{ $stat['note'] }}</div>@endif
        </div>
    @endforeach
</div>
