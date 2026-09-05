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
            <div class="l stat-label">{{ $stat['label'] ?? '' }}</div>
            <div class="v stat-value">{{ $stat['value'] ?? \App\Support\Format::NOTHING }}</div>
            @if (! empty($stat['note']))<div class="n stat-note">{{ $stat['note'] }}</div>@endif
        </div>
    @endforeach
</div>
