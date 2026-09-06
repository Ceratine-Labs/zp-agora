{{--
    The user list (T028).

    A grid like every other user-facing result set, so it inherits header
    filters, CSV/XLSX extract, the column chooser and the visible procedure
    name without this page knowing about any of them.
--}}
<x-app-shell title="Users and access">
    <x-page-head
        eyebrow="Setup · People and assets"
        title="Users and access"
        blurb="Everyone who can sign in. Head office or branch is derived from how many branches a person is granted — exactly one is a site, more than one is head office.">
        <x-slot:actions>
            @can('setup.roles.view')
                <a class="btn" href="{{ route('app.setup.roles.index') }}">Roles and permissions</a>
            @endcan
        </x-slot:actions>
    </x-page-head>

    @if (session('status'))
        <x-notice tone="info" title="Saved">{{ session('status') }}</x-notice>
    @endif

    <x-data-grid :grid="$grid" />
</x-app-shell>
