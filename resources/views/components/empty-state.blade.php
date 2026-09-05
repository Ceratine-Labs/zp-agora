{{--
    What a screen says when the honest answer is "nothing" — the mockup's
    `emptystate`.

    An empty result is a real answer and gets designed like one. `title` is the
    headline in the display face, `text` the sentence under it that says what
    to do next. `.empty-state` stays on the element as an alias so older views
    keep their styling.

    Rendered as a <p> when there is no headline, so a bare
    <x-empty-state text="…" /> stays a single paragraph rather than becoming a
    box.
--}}
@props(['title' => null, 'text' => 'Nothing here yet.'])

@if ($title)
    <div {{ $attributes->merge(['class' => 'emptystate empty-state']) }}>
        <div class="big">{{ $title }}</div>
        {{ $slot->isEmpty() ? $text : $slot }}
    </div>
@else
    <p {{ $attributes->merge(['class' => 'emptystate empty-state']) }}>{{ $slot->isEmpty() ? $text : $slot }}</p>
@endif
