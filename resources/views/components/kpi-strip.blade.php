{{--
    The row of KPI tiles at the top of a screen — the mockup's `kpis`.

    A grid rather than a flex row, so four tiles across a desk become two and
    then one on a phone without anything being hidden. Nothing else about a
    KPI belongs here: the tile owns its own markup.
--}}
<div {{ $attributes->merge(['class' => 'kpis']) }}>{{ $slot }}</div>
