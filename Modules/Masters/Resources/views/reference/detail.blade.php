{{--
    One reference master record: what it is, then everything the listing
    knows about it, each value written exactly as the grid writes it.

    No action panel beyond View: these are PumpIT's and are changed there
    (feature-rules §3.5 — a control the user cannot use is not rendered).
--}}
@php($meanings = $definition->meanings())
<x-card :title="$definition->recordTitle($row)"
        :sub="$definition->noun().($definition->perBranch() ? ' · '.($row->BranchName ?? 'Branch '.$row->BranchId) : '')">
    <x-slot:actions>
        <a class="btn sm" href="{{ $definition->rowUrl($row) }}">View</a>
    </x-slot:actions>
    <x-facts>
        @foreach ($definition->columns() as $column)
            <x-fact :label="$column->label" :value="new \Illuminate\Support\HtmlString(view('grid._cell', ['row' => $row, 'column' => $column])->render())">{{ $meanings[$column->key] ?? '' }}</x-fact>
        @endforeach
        <x-fact label="Kept in" :value="'PumpIT · '.$definition->legacySource()">Changed in PumpIT, not here. Agora reads it live.</x-fact>
    </x-facts>
</x-card>

@foreach ($definition->related() as $index => [$title, $empty])
    @php($set = $related[$index] ?? collect())
    <x-card :title="$title" :sub="$set->count().' in all'">
        @if ($set->isEmpty())
            <x-empty-state :text="$empty" />
        @else
            @php($headings = array_keys(get_object_vars($set->first())))
            <x-table :layout-key="'Masters.'.$definition->slug().'.related.'.$index" :procedure="$definition->procedureName()" :count="$set->count()">
                <thead><tr>@foreach ($headings as $heading)<th>{{ $heading }}</th>@endforeach</tr></thead>
                <tbody>@foreach ($set as $item)
                    <tr>@foreach ($headings as $heading)<td>{{ $item->{$heading} ?? '—' }}</td>@endforeach</tr>
                @endforeach</tbody>
            </x-table>
        @endif
    </x-card>
@endforeach
