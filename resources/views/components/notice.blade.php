{{--
    Something the reader has to take in before the figures underneath mean
    what they look like: a procedure that refused, a step the system is
    deliberately not taking, a count that is evidence of a defect.

    Not an error page. The screen still works — it just cannot answer this
    particular question, or the answer needs a sentence of context.

    `collapsible` folds the explanation behind the headline, using
    <details>/<summary> so the browser supplies the keyboard behaviour and it
    works with no JavaScript. The headline stays visible: on a finding, the
    number IS the message and only the reasoning folds. Nobody should have to
    open something to learn that a problem exists.

    Tones: warn (default) · stop · info.
--}}
{{-- `icon`: a round mark in the tone's colour before the title, for the one
     notice a screen most needs read (Ryan, 14 Nov 2026). With `collapsible`
     it is one thin line, icon then headline, and a "Details" toggle at the
     right opens the explanation (Ryan, 15 Nov 2026: "reduce the size of the
     warning, put the description in a collapsible, collapsed by default"). --}}
@props(['tone' => 'warn', 'title' => null, 'collapsible' => false, 'open' => false, 'icon' => false])

@php($class = 'notice notice-'.$tone.($collapsible ? ' notice-fold' : '').($icon ? ' notice-with-icon' : ''))

@if ($collapsible && $title)
    <details {{ $attributes->merge(['class' => $class]) }} @if ($open) open @endif>
        <summary>@if ($icon)<span class="notice-icon" aria-hidden="true">!</span>@endif<h3>{{ $title }}</h3>@if ($icon)<span class="notice-toggle">Details</span>@endif</summary>
        <div class="notice-body">{{ $slot }}</div>
    </details>
@else
    <div {{ $attributes->merge(['class' => $class]) }}>
        @if ($icon)<span class="notice-icon" aria-hidden="true">!</span>@endif
        @if ($title)<h3>{{ $title }}</h3>@endif
        <div class="notice-body">{{ $slot }}</div>
    </div>
@endif
