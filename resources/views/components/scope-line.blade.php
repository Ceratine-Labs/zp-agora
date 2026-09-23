{{--
    The scope a screen is answering, folded to one line once it is chosen.

    Built for the recon centre (ZP via Ryan, 23 Sep 2026): pick a site and a
    period once and the form gets out of the way — "Ngwenya · 23 Aug – 23 Sep
    · Change" — so the answer is what the eye lands on. Opening it shows the
    same form the screen started with, filled in, so changing one date is one
    field and one press rather than starting again.

    A <details>, so it opens on Enter and Space and is correct before any
    script runs. The parts are the facts of the scope in the order a person
    says them; the caller formats them, because only it knows which are dates.

    Not <x-params collapsible>: that folds a grid of report parameters behind a
    label, and its summary says "Parameters". This one's summary IS the answer
    to "what am I looking at", which is the whole point of folding it.
--}}
@props([
    'parts' => [],
    'open' => false,
    'change' => 'Change',
])

<details {{ $attributes->merge(['class' => 'scope-line']) }} @if ($open) open @endif>
    <summary>
        <span class="scope-line-parts">
            @foreach (array_values(array_filter($parts, fn ($part) => $part !== null && $part !== '')) as $i => $part)
                @if ($i > 0)<span class="scope-line-sep" aria-hidden="true">·</span>@endif
                <span class="scope-line-part">{{ $part }}</span>
            @endforeach
        </span>
        <span class="scope-line-change">{{ $change }}</span>
    </summary>
    <div class="scope-line-body">{{ $slot }}</div>
</details>
