<x-app-shell :title="$case->Description">
    <x-page-head eyebrow="Weekly stock investigation" :title="$case->Description">
        <x-slot:actions><a class="btn-ghost" href="{{ route('app.stockrecon.weekly', ['snapshot' => $case->SnapshotRef]) }}">Weekly cases</a></x-slot:actions>
    </x-page-head>
    @include('stockrecon::weekly.detail')
</x-app-shell>
