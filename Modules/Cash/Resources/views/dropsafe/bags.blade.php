{{--
    Drop safe bags (T039) — the mockup's Drop Safe grid and PumpIT's drop safe
    report, across sites, as one grid over agora.usp_Cash_GridDropSafe.

    One row per bag; "In the safe" rows first. A row opens onto its site's Drop
    safe screen, and onto the bag itself while it can still be corrected.

    Props: $grid
--}}
<x-app-shell title="Drop safe bags">
    <x-page-head
        eyebrow="Cash"
        title="Drop safe bags"
        blurb="Every bag dropped into a manual safe — why, who dropped it, who witnessed it — and whether the security company has collected it yet.">
        <x-slot:actions>
            <a class="btn-ghost" href="{{ route('app.cash.dropsafe') }}">Drop safe</a>
        </x-slot:actions>
    </x-page-head>

    <x-data-grid :grid="$grid" surface />

    <x-explainer summary="Where these figures come from" remember="cash-dropsafe-bags">
        <x-notice tone="info" title="In the safe, and collected">
            <p>A bag is <strong>in the safe</strong> until the security company collects it; then it names the collection
               and the security company's bag it went in — the reference the bank statement quotes. <strong>Cashup</strong>
               says whether the bag is on a cashup's cash bags yet; that does not take it out of the safe.</p>
            <p>A bag over {{ \App\Support\Format::r(config('cash.dropsafe.reason_threshold', 2000)) }} with no reason is
               flagged under its amount. The period is the day each bag was dropped.</p>
        </x-notice>
    </x-explainer>
</x-app-shell>
