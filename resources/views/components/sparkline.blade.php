{{--
    A twelve-point trend, inline, with no library.

        <x-sparkline :values="$litresPerDay" tone="s1" :label="'Litres, last 31 days'" />

    Five SVG elements at most — an optional wash, a line, an end dot and its
    surface ring. It goes in a KPI card and in a grid cell, where instantiating
    a chart library per instance would be absurd.

    **Colour.** The default is `currentColor`, so the mark takes the colour of
    the text it sits with — which is what you want inside a KPI card, where the
    card's tone already says whether the news is good. Pass `tone` to pin it to
    a token instead (`s1`…`s5`, `good`, `warn`, `crit`, `muted`). Either way no
    colour value appears in this file.

    **A missing trend is an em dash, not a flat line.** An empty series, or one
    with no numeric reading in it, renders `—`. See App\Support\Chart\Sparkline
    for the other three cases that break a hand-rolled sparkline: a single
    point, a flat series, and a series containing a negative.

    A null inside the series is a gap and breaks the line rather than being
    interpolated across, because a straight segment over a missing day is a
    reading nobody took.
--}}
@props([
    'values' => [],
    'tone' => null,
    'width' => 240,
    'height' => 30,
    'fill' => true,
    'label' => null,
])

@php
    $geometry = \App\Support\Chart\Sparkline::draw(
        is_array($values) ? array_values($values) : [],
        (float) $width,
        (float) $height,
    );
@endphp

@if ($geometry === null)
    <span {{ $attributes->merge(['class' => 'spark spark-none']) }}
          @if ($label) title="{{ $label }}" @endif>{{ \App\Support\Format::NOTHING }}</span>
@else
    <svg {{ $attributes->merge(['class' => 'spark'.($tone ? ' spark-'.$tone : '')]) }}
         viewBox="0 0 {{ $width }} {{ $height }}"
         preserveAspectRatio="none"
         focusable="false"
         @if ($label) role="img" aria-label="{{ $label }}" @else role="presentation" aria-hidden="true" @endif>
        @if ($fill && $geometry['area'])
            {{-- A wash, not a block: the dataviz rule is ~10% and it is set in
                 CSS so it can differ between themes without touching this file. --}}
            <path class="spark-area" d="{{ $geometry['area'] }}" />
        @endif
        {{-- vector-effect keeps the 2px stroke at 2px even though the viewBox is
             stretched to the container width by preserveAspectRatio="none". --}}
        <path class="spark-line" d="{{ $geometry['line'] }}" vector-effect="non-scaling-stroke" />
        <circle class="spark-end" cx="{{ $geometry['last']['x'] }}" cy="{{ $geometry['last']['y'] }}" r="2.6" />
    </svg>
@endif
