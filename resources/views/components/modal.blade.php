{{--
    A dialog: a form or a confirmation that needs the page kept behind it.

    Native <dialog>, so the browser supplies the modal semantics that are
    genuinely hard to get right — the top layer, the backdrop, focus trapping,
    Escape to close, and returning focus where it came from. modal.js adds the
    two things it does not: opening from a `data-modal-open` control anywhere
    on the page, and fetching the body from a URL when the content depends on
    which row was clicked.

    WHEN NOT TO USE IT. A modal interrupts. A row that merely wants to show
    more of itself belongs in `data-row-detail`, which expands in place and
    keeps the list visible; a modal is for a form the person must finish or
    abandon before carrying on, which is what editing a live configuration is.

    Fetched content is fetched EVERY time it opens, unlike row-detail's
    fetch-once — the whole point of editing is that what you saw last time is
    no longer what is there.

    Props:
      id     the handle `data-modal-open="…"` refers to. Required.
      title  the heading, and the dialog's accessible name.
      wide   for a form with two columns of values to compare.
--}}
@props(['id', 'title' => null, 'wide' => false])

<dialog {{ $attributes->merge(['class' => 'modal'.($wide ? ' modal-wide' : '')]) }}
        id="{{ $id }}" data-modal aria-labelledby="{{ $id }}-title">
    <form method="dialog" class="modal-x">
        <button type="submit" class="iconbtn" aria-label="Close">
            <svg width="14" height="14" viewBox="0 0 14 14" fill="none" aria-hidden="true">
                <path d="M3 3l8 8M11 3l-8 8" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>
            </svg>
        </button>
    </form>

    <h2 class="modal-title" id="{{ $id }}-title">{{ $title }}</h2>

    {{-- Where a fetched body lands. A slot given inline stays put and is used
         as the resting state — which is what the person sees for the moment
         between the click and the answer. --}}
    <div class="modal-body" data-modal-body>{{ $slot }}</div>
</dialog>
