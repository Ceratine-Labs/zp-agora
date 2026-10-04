{{--
    Pump shortages (T038) — the legacy "Pump Shortages by Attendant" report and
    the mockup's "Fills the attendant is short on, by attendant and by shift",
    as one grid over agora.usp_Cash_GridPumpShortages.

    Each row is a line of a cashup's pump shortages leg, so a till and shift's
    rows add up to exactly that cashup's Pump shortages on Daily Banking. A row
    opens onto its cashup.

    Props: $grid
--}}
<x-app-shell title="Pump shortages">
    <x-page-head eyebrow="Cash" title="Pump shortages">
        <x-slot:info>
            <p>What each pump attendant was short, shift by shift: the pump shortages leg of every cashup, with the
               staff short that put each line there. A pump shortage reaches a cashup when an approved staff short is
               posted to it. A row opens its cashup.</p>
            <p><strong>Short</strong> is that line; the cashup adds it to its actual total and the deduction schedule
               reads it as the attendant's short. <strong>Counted</strong> says whether the cashup counts it — PumpIT
               drops an attendant who is not on its employee list. A till balance is not a pump shortage: it moves a
               variance through expenses.</p>
        </x-slot:info>
        <x-slot:actions>
            <a class="btn-ghost" href="{{ route('app.cash.cashups') }}">Cashups</a>
            @can('cash.tillbalance.view')
                <a class="btn-ghost" href="{{ route('app.cash.tillbalance') }}">Till balance</a>
            @endcan
        </x-slot:actions>
    </x-page-head>

    <x-data-grid :grid="$grid" surface />

</x-app-shell>
