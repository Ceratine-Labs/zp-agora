{{--
    Two readings of the same thing, side by side, with one of them in force.

    Built for the extraction configuration, where Agora shadows the customer's
    `BRN_AutoReconCriteria` rather than writing it — so the configuration has
    two sources of truth and a screen that showed only the effective one would
    hide a divergence. The moment anything else in Agora shadows a legacy
    value, it wants this same shape.

    `live` says which side is the one actually in force, and that side is the
    one the eye should land on. It is carried as a class rather than only by
    the heading, because "which of these two is real" is the question the
    reader arrives with.

    Props:
      left / right   slot content for each side
      leftTitle / rightTitle   the headings
      live           'left' | 'right' | null — which side is in force
--}}
@props([
    'leftTitle' => null,
    'rightTitle' => null,
    'live' => null,
])

<div {{ $attributes->merge(['class' => 'compare']) }}>
    <div class="compare-side {{ $live === 'left' ? 'is-live' : '' }}">
        @isset($leftTitle)<h4>{{ $leftTitle }}</h4>@endisset
        {{ $left }}
    </div>

    <div class="compare-side {{ $live === 'right' ? 'is-live' : '' }}">
        @isset($rightTitle)<h4>{{ $rightTitle }}</h4>@endisset
        {{ $right }}
    </div>
</div>
