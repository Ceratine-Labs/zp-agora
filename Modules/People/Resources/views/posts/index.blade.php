<x-app-shell title="Employee posts">
    <x-page-head eyebrow="Setup · People and assets" title="Employee posts" :blurb="$grid->definition->blurb()">
        <x-slot:info>
            <p>PumpIT's posts (BRN_EmployeePost). Each site numbers its own, so "Site Manager" is post 46 at one site and 48 at another; a post is always the site and the number together. A post's name opens the people who hold it at that site.</p>
            <p>Stock control marks the posts whose holders carry stock losses on the deduction schedule.</p>
        </x-slot:info>
        <x-slot:actions>
            @can('people.employees.view')<a class="btn" href="{{ route('app.setup.employees.index') }}">Employees</a>@endcan
        </x-slot:actions>
    </x-page-head>
    @if ($branch !== null && $nextPost !== null)
        <x-notice tone="info">The next post at {{ $branch->Name }} would be number {{ $nextPost }} (agora.usp_People_NextPost — PumpIT's sp_GetNextEmployeePost, rule for rule).</x-notice>
    @endif
    <x-data-grid :grid="$grid" :branches="$branches" surface />
</x-app-shell>
