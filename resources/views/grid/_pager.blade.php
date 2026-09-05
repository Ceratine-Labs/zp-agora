{{--
    Pages.

    Plain links, so a page is a URL somebody can send, bookmark, or open in a
    second tab to compare. The end of a range is rendered as TEXT rather than as
    a disabled link, because a disabled anchor is still focusable and still
    reads as a control to a screen reader — the same call the Reports pager
    made, and the same class.

    Props: $grid
--}}
@if ($grid->pages() > 1)
    <nav class="pager" aria-label="Pages of {{ $grid->definition->title() }}">
        @if ($grid->page() > 1)
            <a class="btn-ghost" href="{{ $grid->pageUrl($grid->page() - 1) }}" rel="prev">Previous</a>
        @else
            <span class="btn-ghost is-off">Previous</span>
        @endif

        <span class="pager-at">
            Page {{ \App\Support\Format::n($grid->page()) }}
            of {{ \App\Support\Format::n($grid->pages()) }}
        </span>

        @if ($grid->page() < $grid->pages())
            <a class="btn-ghost" href="{{ $grid->pageUrl($grid->page() + 1) }}" rel="next">Next</a>
        @else
            <span class="btn-ghost is-off">Next</span>
        @endif
    </nav>
@endif
