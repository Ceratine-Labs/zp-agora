{{--
    A panel that slides in from the right, over whatever is behind it.

    General-purpose, not the grid's. The extract is the first thing that needs
    one (T014), but a drawer is where anything belongs that a screen wants to
    show WITHOUT losing the reader's place: a definition, a procedure's text, a
    long note, the audit trail behind a figure. A modal in the middle of the
    screen would take the place away; a route change would take the screen.

    It is closed markup until something opens it: `hidden` on the element, and
    `data-drawer-open="{id}"` on any button anywhere on the page. The behaviour
    is in resources/js/components/data-grid.js, which also handles Escape, the
    scrim, and returning focus to whatever opened it — a drawer that swallows
    the keyboard is a drawer somebody has to reach for a mouse to leave.

    Props:
      id     the handle a trigger names. Required.
      title  the heading, read out by the dialog's aria-labelledby.
      note   a line above the body, for the thing the reader has to know before
             they use what is in it. Optional.
      copy   render a "Copy to clipboard" button that copies the body's textarea.
      wide   a wider panel, for a table rather than a block of text.
--}}
@props([
    'id',
    'title' => null,
    'note' => null,
    'copy' => false,
    'wide' => false,
])

{{-- @class emits the whole `class="…"` attribute, so it cannot be nested inside
     one — doing that rendered `class="drawer class=""`, which every browser
     recovers from silently and which no assertion about the panel would have
     caught. --}}
<div @class(['drawer', 'is-wide' => $wide])
     id="{{ $id }}"
     data-drawer
     hidden
     role="dialog"
     aria-modal="true"
     aria-labelledby="{{ $id }}-title">

    {{-- The scrim. Clicking it closes, which is what everybody tries first. --}}
    <div class="drawer-scrim" data-drawer-close></div>

    <div class="drawer-panel" role="document">
        <header class="drawer-head">
            <h3 id="{{ $id }}-title">{{ $title ?? 'Details' }}</h3>
            @isset($meta)<span class="drawer-meta">{{ $meta }}</span>@endisset

            <div class="drawer-actions">
                @isset($actions){{ $actions }}@endisset
                @if ($copy)
                    <button type="button" class="btn-ghost" data-drawer-copy="{{ $id }}">Copy to clipboard</button>
                @endif
                <button type="button" class="btn-ghost" data-drawer-close>Close</button>
            </div>
        </header>

        <div class="drawer-body">
            @if ($note)
                <p class="drawer-note">{{ $note }}</p>
            @endif

            {{ $slot }}
        </div>
    </div>
</div>
