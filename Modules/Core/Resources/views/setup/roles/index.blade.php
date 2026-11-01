<x-app-shell title="Roles">
    <x-page-head eyebrow="Setup · People and assets" title="Roles" blurb="Group permissions and menu entries, then assign one or more roles to each person.">
        <x-slot:actions>
            @can('setup.users.view')<a class="btn" href="{{ route('app.setup.users.index') }}">Users and access</a>@endcan
            @can('setup.roles.create')<a class="btn-primary" href="{{ route('app.setup.roles.create') }}">Create role</a>@endcan
        </x-slot:actions>
    </x-page-head>
    @if (session('status'))<x-notice tone="info" title="Saved">{{ session('status') }}</x-notice>@endif
    <x-data-grid :grid="$grid" surface />
</x-app-shell>
