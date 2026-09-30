{{--
    Cashups — the manager's view (T037): the legacy "Daily Banking - Managers
    View" and the mockup's #/op/cashups, as one grid over
    agora.usp_Cash_GridCashups.

    The queues are tabs: every cashup, the open ones (not yet confirmed, or
    confirmed and waiting on a manager's close), and the ones whose Z-read does
    not agree. A row opens underneath itself onto the cashup, with Confirm and
    Close where the person may press them.

    Props: $show, $grid
--}}
<x-app-shell title="Cashups">
    <x-page-head
        eyebrow="Cash"
        title="Cashups"
        blurb="Every cashup with its Z read, what was actually declared and the variance — and whether anybody has agreed to it yet.">
        <x-slot:actions>
            <a class="btn-ghost" href="{{ route('app.cash.banking') }}">Daily Banking</a>
        </x-slot:actions>
    </x-page-head>

    @if (session('cashupRefusal'))
        <x-notice tone="stop" title="That was not done" style="margin-bottom:16px">
            <p>{{ session('cashupRefusal') }}</p>
        </x-notice>
    @elseif (session('cashupStatus'))
        <x-notice tone="info" style="margin-bottom:16px">
            <p>{{ session('cashupStatus') }}</p>
        </x-notice>
    @endif

    @include('cash::cashups.partials.tabs', ['active' => $show])

    <x-data-grid :grid="$grid" surface />
</x-app-shell>
