{{--
    One choice on the sign-in screen — the mockup's `rolebtn`.

    "Agora opens on the work your role actually does, not on a menu." The card
    names the role, who holds it, and what the person will land on, because a
    list of five role names alone does not tell a new branch manager which one
    is theirs.

    An <a> when it is a destination and a <button> when it submits — never a
    div with a click handler. The distinction is the difference between a
    control the keyboard can reach and one it cannot, and this is the first
    screen anyone sees.
--}}
@props([
    'label' => '',
    'who' => null,
    'detail' => null,
    'href' => null,
    'type' => 'submit',
])

@php($tag = $href ? 'a' : 'button')

<{{ $tag }} {{ $attributes->merge(['class' => 'rolebtn']) }}
    @if ($href) href="{{ $href }}" @else type="{{ $type }}" @endif>
    <span class="rolebtn-text">
        <span class="rolebtn-label">{{ $label }}</span>
        @if ($who)<span class="asat">{{ $who }}</span>@endif
        @if ($detail)<span class="rolebtn-detail">{{ $detail }}</span>@endif
    </span>
    <span class="rolebtn-go asat" aria-hidden="true">→</span>
</{{ $tag }}>
