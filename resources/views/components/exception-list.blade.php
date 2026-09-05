{{--
    The container for a run of <x-exception-row> — the mockup's `exlist`.

    It exists separately from the row so that "nothing is open" is rendered by
    the list rather than by every screen that uses one. An exception register
    with nothing in it is the good outcome and should read like one.

    `scroll` caps the height and scrolls inside itself, which is how the branch
    console shows the open items for a site without pushing the rest of the
    page off the screen.
--}}
@props(['empty' => 'Nothing open.', 'scroll' => false])

<div {{ $attributes->merge(['class' => 'exlist'.($scroll ? ' exlist-scroll' : '')]) }}>
    @if (trim($slot) === '')
        <x-empty-state :text="$empty" />
    @else
        {{ $slot }}
    @endif
</div>
