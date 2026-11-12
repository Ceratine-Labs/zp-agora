<x-app-shell title="Branches">
    <x-page-head eyebrow="Setup · The business" title="Branches" blurb="Every site's region, brand, format, status, POS type and profit centres.">
        <x-slot:info>
            <p>Branch ids are PumpIT's own and never change. The branches arrived from PumpIT's SS_Branch; a change made here is Agora's, recorded in the branch's history, and PumpIT is not written.</p>
            <p>A branch that is mothballed or non-trading drops out of the trading lists and the site picker. The six administrative entities — the group, the property companies and the trusts — are non-trading by definition.</p>
        </x-slot:info>
        <x-slot:actions>
            @if ($includesAdmin)
                <a class="btn" href="{{ route('app.setup.branches.index') }}">Hide administrative entities</a>
            @else
                <a class="btn" href="{{ route('app.setup.branches.index', ['admin' => 1]) }}">Show administrative entities</a>
            @endif
            @can('masters.groupings.view')<a class="btn" href="{{ route('app.setup.branch-groupings.index') }}">Regions, brands and formats</a>@endcan
        </x-slot:actions>
    </x-page-head>
    @if (session('status'))<x-notice tone="info" title="Saved">{{ session('status') }}</x-notice>@endif
    <x-data-grid :grid="$grid" surface />
</x-app-shell>
