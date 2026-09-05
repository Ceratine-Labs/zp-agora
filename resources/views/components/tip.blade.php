{{--
    The one floating tooltip the page owns — the mockup's `tip`.

    A singleton, mounted once by the shell, positioned by tip.js. There is
    exactly one because a chart with 31 daily bars must not mount 31 tooltip
    elements, and because a tooltip that follows the pointer has to be able to
    flip when it would run off the right or bottom edge — which needs a
    measured element, not a CSS pseudo-element.

    Two ways in:

      * `data-tip="…"` on any element. tip.js shows it on hover AND on focus,
        so it is reachable from the keyboard — the mockup's was hover-only,
        which is a tooltip nobody tabbing can read.
      * `window.Agora.tip.show(event, html)` / `.hide()`, for a chart that
        renders its own rows into it.

    It is `hidden` and `aria-hidden` until something asks for it: a tooltip is
    a duplicate of information that must also exist somewhere permanent, so it
    is deliberately outside the accessibility tree rather than read twice.
--}}
<div {{ $attributes->merge(['class' => 'tip']) }} id="tip" role="tooltip" aria-hidden="true" hidden></div>
