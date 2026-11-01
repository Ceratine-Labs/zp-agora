<x-card :title="$area->AreaDescription" :sub="$area->BranchName.' · Area '.$area->AreaNo">
    <x-slot:actions>
        <a class="btn sm" href="{{ route('app.stockrecon.countingareas.show', ['branch' => $area->BranchId, 'area' => $area->AreaNo]) }}">View</a>
        @can('stockrecon.countingareas.edit')
            <a class="btn sm" href="{{ route('app.stockrecon.countingareas.edit', ['branch' => $area->BranchId, 'area' => $area->AreaNo]) }}">Edit classification</a>
        @endcan
    </x-slot:actions>
    <x-facts>
        <x-fact label="Prepared food" :value="$area->IsPreparedFood ? 'Yes' : 'No'">Prepared-food areas are never clear duplicate issues (D1).</x-fact>
        <x-fact label="Status" :value="$area->IsConfirmed ? 'Confirmed' : 'Proposed'">{{ $area->FoodBasis ?? 'No food indicator in the area group or name' }}</x-fact>
        <x-fact label="Area group" :value="$area->AreaGroup" />
        <x-fact label="Proposal from area group and name" :value="$area->ProposedPreparedFood ? 'Prepared food' : 'Not prepared food'" />
        <x-fact label="Confirmed by" :value="$area->ConfirmedByName" />
        <x-fact label="Confirmed at" :value="\App\Support\BusinessDate::show($area->ConfirmedAt)" />
    </x-facts>
</x-card>
<x-card title="Confirmation history">
    @if ($history->isEmpty())
        <p>No confirmation has been recorded. The proposed classification remains in effect.</p>
    @else
        <x-table tools>
            <thead><tr><th>When</th><th>Who</th><th>Prepared food before</th><th>Prepared food after</th><th>Reason</th></tr></thead>
            <tbody>@foreach ($history as $change)
                <tr><td>{{ \App\Support\BusinessDate::show($change->CreatedAt) }}</td><td>{{ $change->ChangedByName ?? $change->CreatedBy }}</td><td>{{ $change->PriorIsPreparedFood ? 'Yes' : 'No' }}{{ $change->PriorConfirmedAt ? '' : ' (proposed)' }}</td><td>{{ $change->NewIsPreparedFood ? 'Yes' : 'No' }}</td><td>{{ $change->Reason }}</td></tr>
            @endforeach</tbody>
        </x-table>
    @endif
</x-card>
