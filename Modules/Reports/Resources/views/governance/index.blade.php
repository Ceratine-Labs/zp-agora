<x-app-shell :title="$title">
    <x-page-head eyebrow="Setup · Governance" :title="$title" :blurb="$blurb">
        <x-slot:info>
            @foreach ($info as $line)
                <p>{{ $line }}</p>
            @endforeach
        </x-slot:info>
    </x-page-head>
    <x-data-grid :grid="$grid" :branches="$branches" surface />
</x-app-shell>
