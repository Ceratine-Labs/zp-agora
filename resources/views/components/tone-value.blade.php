{{--
    A figure washed in its tone, the way the legacy screens colour a variance
    cell — red where a count came up short, yellow where it came up over.

    Not <x-chip>: a chip is a label, set small and bold, and a column of
    numbers with some of them chipped stops reading as a column. This keeps the
    figure at the size and weight of its neighbours and only washes it.

    The slot is the figure, already formatted. `tone` is one of the five names
    every component checks against; anything else, or null, renders the figure
    bare — a zero variance has no tone and gets no wash.

    Props: tone (good|warn|serious|crit|neutral|null)
--}}
@props(['tone' => null])

@php($tone = in_array($tone, ['good', 'warn', 'serious', 'crit', 'neutral'], true) ? $tone : null)

<span {{ $attributes->class(['tone-value', 'tone-'.$tone => $tone !== null]) }}>{{ $slot }}</span>
