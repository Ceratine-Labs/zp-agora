{{--
    A button that opens a short menu of actions — the page's secondary presses
    folded behind one (Ryan, 14 Nov 2026, the stock recon run: "the buttons at
    the top can be put into a drop down including the shifts bar").

    A native <details>, so it opens from the keyboard and with no script.
    info.js gives it what a menu is expected to do: one open at a time, Escape
    or a click elsewhere closes it. Items are links, buttons, or a form holding
    one button; each fills the row. `danger` on a button paints it as one.

    Not for the page's PRIMARY press: that stays a button of its own.
--}}
@props(['label' => 'Actions'])
<details {{ $attributes->merge(['class' => 'menu-button']) }}>
    <summary class="btn-ghost">{{ $label }}<span class="menu-button-caret" aria-hidden="true"></span></summary>
    <div class="menu-button-body" role="menu">{{ $slot }}</div>
</details>
