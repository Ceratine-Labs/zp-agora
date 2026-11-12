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
        @if (\Modules\Masters\Services\MasterWriteService::canEdit($definition))
            <a class="btn sm" href="{{ route('app.setup.'.$definition->slug().'.edit', ['branch' => $row->BranchId, 'key' => $row->RowKey]) }}">Edit in PumpIT</a>
        @endif
    </x-slot:actions>
    <x-facts>
        @foreach ($definition->columns() as $column)
            <x-fact :label="$column->label" :value="new \Illuminate\Support\HtmlString(view('grid._cell', ['row' => $row, 'column' => $column])->render())">{{ $meanings[$column->key] ?? '' }}</x-fact>
        @endforeach
        <x-fact label="Kept in" :value="'PumpIT · '.$definition->legacySource()">{{ $definition->writeEntity() !== null ? 'Agora reads it live, and an edit here writes it back there.' : 'Changed in PumpIT, not here. Agora reads it live.' }}</x-fact>
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
                <x-slot:head><tr>@foreach ($headings as $heading)<th>{{ $heading }}</th>@endforeach</tr></x-slot:head>
                @foreach ($set as $item)
                    <tr>@foreach ($headings as $heading)<td>{{ $item->{$heading} ?? '—' }}</td>@endforeach</tr>
                @endforeach
            </x-table>
        @endif
    </x-card>
@endforeach

@if ($definition->writeEntity() !== null)
    @php($presses = $writes ?? collect())
    <x-card title="Written to PumpIT" :sub="$presses->count().' change(s) from Agora, newest first'">
        @if ($presses->isEmpty())
            <x-empty-state text="Agora has not changed this record in PumpIT." />
        @else
            @foreach ($presses as $writeId => $press)
                @php($first = $press->first())
                <p>
                    <strong>{{ \App\Support\BusinessDate::show($first->CreatedAt) }}</strong> · {{ $first->ChangedByName ?? 'User '.$first->CreatedBy }} · {{ $first->PumpItTable }} · “{{ $first->Reason }}”
                    @if ($first->State === 'reversed')
                        · <x-chip tone="neutral">Put back</x-chip> {{ \App\Support\BusinessDate::show($first->ReversedAt) }} by {{ $first->ReversedByName ?? '—' }}: “{{ $first->ReversalReason }}”
                    @endif
                </p>
                <x-table :layout-key="'Masters.'.$definition->slug().'.writes'" procedure="agora.usp_Masters_GetWrites" :count="$press->count()">
                    <x-slot:head><tr><th>PumpIT column</th><th>Before</th><th>After</th></tr></x-slot:head>
                    @foreach ($press as $change)
                        <tr><td class="mono">{{ $change->Field }}</td><td>{{ $change->PriorValue ?? '—' }}</td><td>{{ $change->NewValue ?? '—' }}</td></tr>
                    @endforeach
                </x-table>
                @if ($first->State === 'active' && \Modules\Masters\Services\MasterWriteService::canEdit($definition))
                    <form method="POST" action="{{ route('app.setup.'.$definition->slug().'.writes.reverse', ['branch' => $row->BranchId, 'write' => $writeId]) }}"
                          data-confirm="Put this back in PumpIT?" data-confirm-action="Put back" data-confirm-danger
                          data-confirm-text="PumpIT {{ $first->PumpItTable }} · {{ $definition->recordTitle($row) }}: {{ $press->map(fn ($c) => $c->Field.' '.($c->NewValue ?? 'blank').' → '.($c->PriorValue ?? 'blank'))->implode('; ') }}. Refused if PumpIT has changed since.">
                        @csrf
                        <input type="hidden" name="key" value="{{ $row->RowKey }}">
                        <x-field name="Reason" label="Why put it back" help="At least 10 characters." />
                        <div class="form-actions"><button class="btn" type="submit">Put back</button></div>
                    </form>
                @endif
            @endforeach
        @endif
    </x-card>
@endif
