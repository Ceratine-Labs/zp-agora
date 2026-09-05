@props(['value' => null, 'invert' => false, 'suffix' => '%', 'dp' => 1])

{{--
    A movement, with its direction — the mockup's `delta up / dn / flat`.

    Both halves come from App\Support\Format and neither is computed here:
    `delta()` writes the text (arrow, sign, figure, suffix) and `deltaTone()`
    says which of the three classes it wears. Duplicating either would put a
    second, quietly different definition of "flat" in the codebase, and the
    JavaScript twin in resources/js/format.js would then agree with only one
    of them.

    `invert` is the whole point of the component. Up is good on turnover and
    bad on shrinkage, and only the caller knows which it is looking at — so the
    caller decides, and the colour follows the decision rather than the sign.

    A null value renders an em dash in the flat tone. A missing movement is
    missing; drawing it as a flat zero states something untrue.
--}}
<span {{ $attributes->merge(['class' => 'delta '.\App\Support\Format::deltaTone($value, $invert)]) }}>{{ \App\Support\Format::delta($value, $suffix, $dp) }}</span>
