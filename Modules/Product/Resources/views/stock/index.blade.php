{{--
    The stock master listing (T025).

    A grid like every other user-facing result set, so it inherits header
    filters, the CSV extract, the column chooser, the branch selector and the
    visible procedure name without this page knowing about any of them.

    The one thing the page adds is the notice below. "Last counted" is a
    materialised rollup over 6.2 million count lines, not a live read, and a
    cache that does not admit it is a column people will price and count from
    as though it were live.
--}}
<x-app-shell title="Stock master">
    <x-page-head
        eyebrow="Setup · Masters"
        title="Stock master"
        blurb="Every stock line a site carries. Item numbers are per site — the same product has a different number at each — and cost, GP and stock on hand come from that site's POS file rather than from the master itself.">
    </x-page-head>

    @if (session('status'))
        <x-notice tone="info" title="Saved">{{ session('status') }}</x-notice>
    @endif

    <x-notice tone="info" title="Where each column comes from" collapsible>
        <p>
            <strong>Description, POS code, counting area, price and the behaviour flags</strong> are the
            stock master itself — the customer's row, unless Agora holds an override for it, which the
            <em>Held by</em> column names.
        </p>
        <p>
            <strong>Cost, GP%, stock on hand, category and last sold</strong> come from that site's POS
            cost file, matched on branch, POS system and code. The stock master carries none of them.
            Where GP is blank the <em>Pricing</em> column says why: no POS record, no cost price, no POS
            sell price, or selling below cost. Sell is VAT-inclusive and cost is not, so GP is computed
            from the POS file's own pair rather than by mixing the two.
        </p>
        <p>
            <strong>Last counted</strong> is a rebuilt rollup over the stock count lines, not a live read.
            @if ($refreshedAt)
                It was last rebuilt <strong>{{ \Illuminate\Support\Carbon::parse($refreshedAt)->format('j M Y, H:i') }}</strong>.
            @else
                It has <strong>never been rebuilt</strong>, so every line will read as never counted until
                <code>php artisan agora:refresh-count-stats</code> has run.
            @endif
            A blank there is a real answer — most of the estate has lines nobody has counted in months.
        </p>
    </x-notice>

    <x-data-grid :grid="$grid" />
</x-app-shell>
