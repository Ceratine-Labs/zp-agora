{{--
    An in-cell variance bar, inline, with no library.

        <x-mini-bar :value="$row->act - $row->bud" :max="$columnMax" :label="..." />

    Three SVG elements: the zero tick, the bar, and — only when the value is off
    the end of the scale — a clamp mark. It appears once per grid row, which is
    the whole reason it is not a chart library.

    **`max` is required and is the COLUMN's max, not the row's.** Every bar in a
    column has to share one scale or the longest bar in each row looks the same
    and the column says nothing. Deriving it per bar is the failure mode.

    **Colour is semantic, not categorical**: over is `--good`, under is `--crit`,
    both from the tokens, and the sign is also carried by which side of the tick
    the bar sits on — so it is never colour alone. On budget draws the tick and
    no bar; a hairline there would read as a small miss.

    A missing variance renders an em dash, never a zero-width bar.
--}}
@props([
    'value' => null,
    'max' => null,
    'width' => 76,
    'height' => 12,
    'label' => null,
])

@php
    $geometry = \App\Support\Chart\MiniBar::geometry($value, $max, (float) $width, (float) $height);
@endphp

@if ($geometry === null)
    <span {{ $attributes->merge(['class' => 'mini-bar mini-bar-none']) }}>{{ \App\Support\Format::NOTHING }}</span>
@else
    <svg {{ $attributes->merge(['class' => 'mini-bar tone-'.$geometry['tone'].($geometry['clamped'] ? ' is-clamped' : '')]) }}
         viewBox="0 0 {{ $width }} {{ $height }}"
         width="{{ $width }}" height="{{ $height }}"
         focusable="false"
         @if ($label) role="img" aria-label="{{ $label }}" @else role="presentation" aria-hidden="true" @endif>
        <line class="mini-zero" x1="{{ $geometry['zero'] }}" x2="{{ $geometry['zero'] }}" y1="0" y2="{{ $height }}" />
        @if ($geometry['width'] > 0)
            <rect class="mini-fill"
                  x="{{ $geometry['x'] }}" y="1"
                  width="{{ $geometry['width'] }}" height="{{ $height - 2 }}"
                  rx="2" />
        @endif
        @if ($geometry['clamped'])
            {{-- Off the end of the column's scale. Without this the row is
                 indistinguishable from the one that is exactly at the max. --}}
            <circle class="mini-clamp"
                    cx="{{ $geometry['tone'] === 'crit' ? 2 : $width - 2 }}"
                    cy="{{ $height / 2 }}" r="1.6" />
        @endif
    </svg>
@endif
