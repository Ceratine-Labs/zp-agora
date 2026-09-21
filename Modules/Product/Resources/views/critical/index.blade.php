{{--
    Critical lines (T025).

    The list of lines that must never be out of stock, led by how many of them
    are. That count is the reason somebody opens this screen, so it is a KPI
    rather than something to be found by sorting a grid — and the grid's own
    default order puts Out of stock at the top for the same reason.

    The editor is a modal per row rather than one form: a critical line is
    three fields, and the thing people do here is take one off the list or put
    one back, not fill in a page.
--}}
<x-app-shell title="Critical lines">
    <x-page-head
        eyebrow="Setup · Trading rules"
        title="Critical lines"
        blurb="The lines that must never be out of stock, and whether they are. Keyed to the site's POS file rather than to the stock master — a third of this list is not counted, which is a fact about the list rather than a gap in it." />

    @if (session('status'))
        <x-notice tone="info" title="Saved">{{ session('status') }}</x-notice>
    @endif

    @error('refusal')
        <x-notice tone="warn" title="That change was refused">{{ $message }}</x-notice>
    @enderror

    <x-kpi-strip>
        <x-kpi
            label="Out of stock now"
            :value="\App\Support\Format::n($outOfStock, 0)"
            note="active critical lines at or below zero on hand"
            :tone="$outOfStock > 0 ? 'crit' : 'good'" />
    </x-kpi-strip>

    <x-notice tone="info" title="What this list is, and what it is not" collapsible>
        <p>
            <strong>It is keyed to the POS file, not to the stock master.</strong> Every line here has a row in
            that site's cost file — that is what <em>On hand</em> and <em>Last sold</em> come from — but roughly a
            third have no stock master row at all. Those are real products the site sells that nobody counts, so
            the <em>Counted</em> column is information rather than a problem, and a line without one does not open
            a stock master page because there is none to open.
        </p>
        <p>
            <strong>Low means one pack or less</strong>, using the POS file's own pack size. Where that is missing
            it falls back to one, so Low still means something.
        </p>
        <p>
            <strong>Taking a line off the list is not deleting it.</strong> It writes an Agora override with the
            line switched off; the customer's own list is untouched and the line can be put back. <em>Held by</em>
            says which list a row came from.
        </p>
    </x-notice>

    <x-data-grid :grid="$grid" />
</x-app-shell>
