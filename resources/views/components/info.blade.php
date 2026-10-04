{{--
    An info button: the explanation behind a small "i".

    For standing text people read once and then skip — how a screen works, what
    an option means, why a rule exists. Ryan, 4 Oct 2026: the stock recon
    screens carried a paragraph under every heading and the users ignored all
    of it. The text still exists; it is one press away instead of in the way.

    A native <details>, so it opens with no script and from the keyboard.
    info.js adds the rest: Escape or a click elsewhere closes it, opening one
    closes the others, and the panel is kept inside the viewport.

    Not for a refusal, a result or a count. Those are the answer to THIS visit
    and stay on the page.

    <x-page-head>, <x-card> and <x-field> take an `info` slot or prop and place
    the button beside their title or label.
--}}
@props(['label' => 'What is this?'])

<details {{ $attributes->merge(['class' => 'info']) }}>
    <summary class="info-btn" aria-label="{{ $label }}" title="{{ $label }}"><span aria-hidden="true">i</span></summary>
    <div class="info-body">{{ $slot }}</div>
</details>
