<x-app-shell :title="$definition->recordTitle($row)">
    <x-page-head :eyebrow="$definition->eyebrow().' · '.$definition->title()" :title="$definition->recordTitle($row)">
        <x-slot:actions>
            <a class="btn" href="{{ route('app.setup.'.$definition->slug().'.index', $definition->perBranch() ? ['branches' => [$row->BranchId]] : []) }}">Back to {{ $definition->title() }}</a>
        </x-slot:actions>
    </x-page-head>
    @include('masters::reference.detail')
</x-app-shell>
