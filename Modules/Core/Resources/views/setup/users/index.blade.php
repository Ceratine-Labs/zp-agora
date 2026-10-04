{{--
    The user list (T028).

    A grid like every other user-facing result set, so it inherits header
    filters, CSV/XLSX extract, the column chooser and the visible procedure
    name without this page knowing about any of them.
--}}
<x-app-shell title="Users and access">
    <x-page-head eyebrow="Setup · People and assets" title="Users and access">
        <x-slot:info>
            <p>Everyone who can sign in.</p>
            <p>Head office or branch follows from the sites a person is granted: exactly one site makes them a
               branch user at it, more than one makes them head office.</p>
        </x-slot:info>
    </x-page-head>

    @if (session('status'))
        <x-notice tone="info" title="Saved">{{ session('status') }}</x-notice>
    @endif

    <x-data-grid :grid="$grid" surface />
</x-app-shell>
