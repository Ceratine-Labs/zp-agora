{{--
    Z-reads with no cashup (T037): allocated Z-reads with sales on them and no
    cashup for their till and shift that day. The legacy "ZReadings vs Cashups -
    NO Cashup", over agora.usp_Cash_GridZreadsNoCashup. Each row goes to that
    day on Daily Banking.

    Props: $grid
--}}
<x-app-shell title="Z-reads with no cashup">
    <x-page-head
        eyebrow="Cash"
        title="Z-reads with no cashup"
        blurb="Takings the till reported and nobody has cashed up. Allocated first, then cashed up — an unallocated Z-read is on the Unallocated Z-reads queue.">
        <x-slot:actions>
            <a class="btn-ghost" href="{{ route('app.cash.zread') }}">ZREAD Allocation</a>
        </x-slot:actions>
    </x-page-head>

    @include('cash::cashups.partials.tabs', ['active' => 'nocashup'])

    <x-data-grid :grid="$grid" surface />
</x-app-shell>
