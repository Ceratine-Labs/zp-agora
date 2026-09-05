{{--
    One figure, on a tile — the mockup's `kpi`.

    Markup and class names are the mockup's: an optional `.stripe` down the
    left edge, `.lbl`, `.val` (with an optional `<small>` unit), `.cmp` for the
    comparisons underneath, and a `spark` slot the chart component fills.

    `stripe` names a design token, not a colour. It is checked against the list
    below and dropped if it is not one of them — a component that interpolated
    whatever it was handed into a style attribute would be both a hex leak and
    an injection point. Everything visible here comes from
    resources/scss/_tokens.scss.

    `value` is expected to have been through App\Support\Format already: the
    component does not format, because only the caller knows whether the figure
    is money, litres or a count. A missing figure must arrive as Format::NOTHING
    (an em dash), never as a zero.

    `compare` is the line under the figure — a string, or a list of strings that
    render as separate columns the way the mockup's comparisons do. `note` is
    the older single-line spelling and still works.
--}}
@props([
    'label' => '',
    'value' => '',
    'unit' => null,
    'compare' => null,
    'note' => null,
    'tone' => 'neutral',
    'stripe' => null,
    'href' => null,
])

@php
    // Token names a stripe may use. Anything else is ignored rather than
    // written into the style attribute.
    $stripeTokens = ['brand', 'good', 'warn', 'serious', 'crit', 's1', 's2', 's3', 's4', 's5'];
    $stripeToken = in_array($stripe, $stripeTokens, true) ? $stripe : null;

    $comparisons = array_values(array_filter(
        is_array($compare) ? $compare : [$compare, $note],
        fn ($line) => $line !== null && $line !== ''
    ));

    $tag = $href ? 'a' : 'div';
@endphp

<{{ $tag }} {{ $attributes->merge(['class' => 'kpi tone-'.$tone]) }} @if ($href) href="{{ $href }}" @endif>
    @if ($stripeToken)<span class="stripe" style="background: var(--{{ $stripeToken }})"></span>@endif
    <div class="lbl">{{ $label }}</div>
    <div class="val">{{ $value }}@if ($unit)<small>{{ $unit }}</small>@endif</div>
    @if ($comparisons)
        <div class="cmp">
            @foreach ($comparisons as $line)
                <span>{{ $line }}</span>
            @endforeach
        </div>
    @endif
    @isset($spark)<div class="spark">{{ $spark }}</div>@endisset
</{{ $tag }}>
