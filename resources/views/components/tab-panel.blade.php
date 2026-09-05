{{--
    One pane behind a tab, for <x-tabs> in panel mode.

    `active` is pulled off the parent <x-tabs> with @aware, so a page lists its
    panes without repeating which one is showing — one source of truth for the
    selection instead of a prop that can be set inconsistently on the fifth
    pane.

    The inactive panes carry `hidden` from the server. That is what makes the
    first paint correct with no JavaScript and no flash of every pane at once.
--}}
@props(['key' => null])

@aware(['active' => null])

<div {{ $attributes->merge(['class' => 'tabpane']) }}
     data-tab-panel="{{ $key }}"
     role="tabpanel"
     @if ((string) $key !== (string) $active) hidden @endif>{{ $slot }}</div>
