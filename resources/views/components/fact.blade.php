@props([
    'label',
    'value' => null,
    'tone' => null,
])

{{--
    One labelled fact, with what it means underneath.

    THE SLOT IS THE POINT. A label and a value is a table row; the third line
    is what turns a detail screen into something a person learns from. Use it
    to say what the reader cannot see: where the number came from, what it is
    scoped by, what its absence means. Leave it out and this renders as a plain
    pair, which is correct for a fact that needs no explanation.

    `value` takes a string that has ALREADY been through App\Support\Format —
    the component does not format, because only the caller knows whether the
    figure is money, litres or a count. An em dash is the house rendering for
    "there is no value", and passing null produces one rather than an empty
    space that reads as a bug.

    `tone` is checked against the same five names every other component uses,
    so an unknown value is dropped rather than interpolated into a class.
--}}
@php
    $tones = ['good', 'warn', 'serious', 'crit', 'neutral'];
    $toneClass = in_array($tone, $tones, true) ? " tone-{$tone}" : '';
@endphp

<div {{ $attributes->merge(['class' => 'fact'.$toneClass]) }}>
    <span class="fact-label">{{ $label }}</span>
    <span class="fact-value">{{ $value ?? '—' }}</span>
    @if (trim($slot) !== '')
        <span class="fact-why">{{ $slot }}</span>
    @endif
</div>
