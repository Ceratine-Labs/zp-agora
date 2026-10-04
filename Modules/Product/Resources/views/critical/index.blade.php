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
    {{-- What the list is and how it behaves sits behind the info button
         beside the title (Ryan, 4 Oct 2026: nobody read the panel). The
         out-of-stock count and the grid stay on the page. --}}
    <x-page-head eyebrow="Setup · Trading rules" title="Critical lines">
        <x-slot:info>
            <p>The lines that must never be out of stock, and whether they are. The count at the top is the
               active lines at or below zero on hand.</p>
            <p><strong>Keyed to the POS file, not the stock master.</strong> <em>On hand</em> and <em>Last sold</em>
               come from the site's cost file. About a third of the lines have no stock master row: they are sold
               but not counted, so <em>Counted</em> is information rather than a problem, and those lines have no
               stock master page to open.</p>
            <p><strong>Low</strong> means one pack or less, by the POS file's pack size (one where it has none).
               <strong>Taking a line off the list</strong> writes an Agora override with the line switched off; the
               customer's own list is untouched and the line can be put back. <em>Held by</em> says which list a
               row came from.</p>
        </x-slot:info>
    </x-page-head>

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
            :tone="$outOfStock > 0 ? 'crit' : 'good'" />
    </x-kpi-strip>

    <x-data-grid :grid="$grid" surface />
</x-app-shell>
