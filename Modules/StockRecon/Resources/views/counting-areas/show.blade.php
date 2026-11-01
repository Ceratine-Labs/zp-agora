<x-app-shell :title="$area->AreaDescription">
    <x-page-head eyebrow="Control · Stock · Counting areas" :title="$area->AreaDescription">
        <x-slot:actions><a class="btn" href="{{ route('app.stockrecon.countingareas') }}">Back to counting areas</a></x-slot:actions>
    </x-page-head>
    @if (session('status'))<x-notice tone="good" title="Confirmed">{{ session('status') }}</x-notice>@endif
    @include('stockrecon::counting-areas.detail')
</x-app-shell>
