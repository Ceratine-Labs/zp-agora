<x-app-shell :title="$branch->Name">
    <x-page-head eyebrow="Setup · The business · Branches" :title="$branch->Name">
        <x-slot:actions>
            <a class="btn" href="{{ route('app.setup.branches.index') }}">Back to branches</a>
            @can('masters.branches.edit')<a class="btn-primary" href="{{ route('app.setup.branches.edit', ['branch' => $branch->BranchId]) }}">Edit branch</a>@endcan
        </x-slot:actions>
    </x-page-head>
    @if (session('status'))<x-notice tone="good" title="Saved">{{ session('status') }}</x-notice>@endif
    @include('masters::branches.detail')
</x-app-shell>
