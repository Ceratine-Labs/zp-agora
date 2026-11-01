<x-app-shell title="Counting areas">
    <x-page-head eyebrow="Control · Stock" title="Counting areas">
        <x-slot:info>Head office confirms whether each area contains prepared food. Prepared-food areas are excluded from clear duplicate-issue removal. A proposed value stays in effect until it is explicitly confirmed.</x-slot:info>
    </x-page-head>
    <x-data-grid :grid="$grid" surface />
</x-app-shell>
