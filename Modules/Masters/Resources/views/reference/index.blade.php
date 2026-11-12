<x-app-shell :title="$definition->title()">
    <x-page-head :eyebrow="$definition->eyebrow()" :title="$definition->title()" :blurb="$definition->blurb()">
        <x-slot:info>
            <p>Kept in PumpIT ({{ $definition->legacySource() }}). Agora lists it and does not change it: PumpIT is still the system the sites trade on, so a change is made there and shows here on the next load.</p>
            @if ($definition->perBranch())
                <p>Kept per branch — the same number means a different thing at another site — so the list answers for the branches you choose and not before.</p>
            @endif
        </x-slot:info>
    </x-page-head>
    <x-data-grid :grid="$grid" :branches="$branches" surface />
</x-app-shell>
