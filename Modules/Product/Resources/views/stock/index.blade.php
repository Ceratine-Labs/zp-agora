{{--
    The stock master listing (T025).

    A grid like every other user-facing result set, so it inherits header
    filters, the CSV extract, the column chooser, the branch selector and the
    visible procedure name without this page knowing about any of them.

    THE PAGE IS THE GRID. It carried an eyebrow, a three-line blurb and a
    collapsible "where each column comes from" panel above the filters, and on
    21 September Ryan measured what that cost: half the screen before the first
    row. The provenance those blocks explained belongs on the columns, not in a
    preamble everybody scrolls past — the Pricing column already says why a GP
    is blank, and Last counted reads as a date or as blank.
--}}
<x-app-shell title="Stock master">
    <x-page-head title="Stock master" />

    @if (session('status'))
        <x-notice tone="info" title="Saved">{{ session('status') }}</x-notice>
    @endif

    <x-data-grid :grid="$grid" />
</x-app-shell>
