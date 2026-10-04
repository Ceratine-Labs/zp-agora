{{--
    Z-reads with no cashup (T037): allocated Z-reads with sales on them and no
    cashup for their till and shift that day. The legacy "ZReadings vs Cashups -
    NO Cashup", over agora.usp_Cash_GridZreadsNoCashup. Each row goes to that
    day on Daily Banking.

    Props: $grid
--}}
<x-app-shell title="Z-reads with no cashup">
    <x-page-head eyebrow="Cash" title="Z-reads with no cashup">
        <x-slot:info>
            <p>Allocated Z-reads with sales on them and no cashup for their till and shift that day: takings the till
               reported that nobody has cashed up. A row opens that day on Daily Banking.</p>
            <p>A Z-read is allocated first, then cashed up — an unallocated one is on the Unallocated Z-reads
               queue.</p>
        </x-slot:info>
        <x-slot:actions>
            <a class="btn-ghost" href="{{ route('app.cash.zread') }}">ZREAD Allocation</a>
        </x-slot:actions>
    </x-page-head>

    @include('cash::cashups.partials.tabs', ['active' => 'nocashup'])

    <x-data-grid :grid="$grid" surface />
</x-app-shell>
