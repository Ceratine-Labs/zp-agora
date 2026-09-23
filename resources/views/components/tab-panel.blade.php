{{--
    One pane behind a tab, for <x-tabs> in panel mode.

    `active` is pulled off the parent <x-tabs> with @aware, so a page lists its
    panes without repeating which one is showing — one source of truth for the
    selection instead of a prop that can be set inconsistently on the fifth
    pane.

    The inactive panes carry `hidden` from the server. That is what makes the
    first paint correct with no JavaScript and no flash of every pane at once.

    `src` makes the pane LAZY: its content is an HTML fragment fetched the
    first time the tab is opened, and not before (tabs.js). Built for the recon
    centre, where the three tabs are three procedures over one site-month —
    rendering all of them to show one would run the suggestion algorithm for a
    clerk who only wanted the manual match. The server renders the fragment
    (HTML, never JSON-to-markup, the contract row-detail.js works to) and
    window.Agora.hydrate() brings its tables and forms to life. Whatever is in
    the slot is shown until then, and is the fallback with scripting off, so
    put a plain link to the same content there.
--}}
@props(['key' => null, 'src' => null])

@aware(['active' => null])

<div {{ $attributes->merge(['class' => 'tabpane']) }}
     data-tab-panel="{{ $key }}"
     @if ($src) data-tab-src="{{ $src }}" @endif
     role="tabpanel"
     @if ((string) $key !== (string) $active) hidden @endif>{{ $slot }}</div>
