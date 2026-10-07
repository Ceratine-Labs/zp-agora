{{--
    A status pill — the mockup's `chip`.

    The tone is the class, exactly as the design sheet writes it
    (`chip good`, not `chip tone-good`), and the coloured dot is part of the
    component rather than something a caller remembers to add. `tone-*` is kept
    as an alias in the stylesheet so views written against the older spelling
    still render.

    The dot carries `background: currentColor`, so the pill is legible for a
    reader who cannot separate the five tones by hue: the shape says "status",
    the text says which. Set `dot="false"` where the chip is a plain label
    rather than a state.
--}}
@props(['tone' => 'neutral', 'dot' => true, 'as' => 'span'])

{{-- `as="button"`: a chip somebody presses — a stock recon code badge opens
     the codes modal (7 Oct 2026). Same pill, a real button underneath, so it
     is focusable and reachable from the keyboard. --}}
@if ($as === 'button')
<button type="button" {{ $attributes->merge(['class' => 'chip '.$tone.' tone-'.$tone]) }}>
    @if ($dot)<span class="dot" aria-hidden="true"></span>@endif
    {{ $slot }}
</button>
@else
<span {{ $attributes->merge(['class' => 'chip '.$tone.' tone-'.$tone]) }}>
    @if ($dot)<span class="dot" aria-hidden="true"></span>@endif
    {{ $slot }}
</span>
@endif
