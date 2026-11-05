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
        <x-table layout-key="StockRecon.counting-areas.detail.1" tools>
            <thead><tr><th>When</th><th>Who</th><th>Prepared food before</th><th>Prepared food after</th><th>Reason</th></tr></thead>
            <tbody>@foreach ($history as $change)
                <tr><td>{{ \App\Support\BusinessDate::show($change->CreatedAt) }}</td><td>{{ $change->ChangedByName ?? $change->CreatedBy }}</td><td>{{ $change->PriorIsPreparedFood ? 'Yes' : 'No' }}{{ $change->PriorConfirmedAt ? '' : ' (proposed)' }}</td><td>{{ $change->NewIsPreparedFood ? 'Yes' : 'No' }}</td><td>{{ $change->Reason }}</td></tr>
            @endforeach</tbody>
        </x-table>
    @endif
</x-card>

<x-card title="Items removed from this area" sub="Record the last shift on which the item was required to be counted.">
    @if ($errors->any())<p role="alert">{{ $errors->first() }}</p>@endif
    @can('stockrecon.countingareas.save')
    @can('stockrecon.countingareas.edit')
    <form method="post" action="{{ route('app.stockrecon.countingareas.removals.store', ['branch' => $area->BranchId, 'area' => $area->AreaNo]) }}">
        @csrf
        <label>Item number <input name="item" maxlength="10" required value="{{ old('item') }}"></label>
        <label>Last count date <input type="date" name="date" required value="{{ old('date') }}"></label>
        <label>Last count shift <select name="shift" required><option value="1">Day</option><option value="2">Afternoon</option><option value="3">Night</option></select></label>
        <button class="btn" type="submit">Record removal</button>
    </form>
    @endcan
    @endcan
    @if ($removals->isEmpty())<p>No item removals recorded.</p>@else
    <x-table layout-key="StockRecon.counting-areas.removals" tools>
        <thead><tr><th>Item</th><th>Last count</th><th>Recorded</th><th>By user</th><th>Status</th><th></th></tr></thead>
        <tbody>@foreach ($removals as $removal)
            <tr><td>{{ $removal->StockItemNo }}</td><td>{{ $removal->LastDate }} · shift {{ $removal->LastShift }}</td><td>{{ $removal->CreatedAt }}</td><td>{{ $removal->CreatedBy }}</td><td>{{ $removal->WithdrawnAt ? 'Withdrawn '.$removal->WithdrawnAt.' by user '.$removal->WithdrawnBy : 'Active' }}</td><td>
            @if (! $removal->WithdrawnAt) @can('stockrecon.countingareas.save') @can('stockrecon.countingareas.edit')
                <form method="post" action="{{ route('app.stockrecon.countingareas.removals.withdraw', ['branch' => $area->BranchId, 'area' => $area->AreaNo, 'removal' => $removal->Id]) }}">@csrf<button class="btn sm" type="submit">Withdraw</button></form>
            @endcan @endcan @endif
            </td></tr>
        @endforeach</tbody>
    </x-table>
    @endif
</x-card>

<x-card title="Completed area shifts" sub="Record when counting for the area and shift is complete. Missing item counts then hold reconciliation.">
    @can('stockrecon.countingareas.save') @can('stockrecon.countingareas.edit')
    <form method="post" action="{{ route('app.stockrecon.countingareas.completions.store', ['branch' => $area->BranchId, 'area' => $area->AreaNo]) }}">
        @csrf
        <label>Date <input type="date" name="date" required></label>
        <label>Shift <select name="shift" required><option value="1">Day</option><option value="2">Afternoon</option><option value="3">Night</option></select></label>
        <button class="btn" type="submit">Record completed shift</button>
    </form>
    @endcan @endcan
    @if ($completions->isEmpty())<p>No area shift completions recorded.</p>@else
    <x-table layout-key="StockRecon.counting-areas.completions" tools>
        <thead><tr><th>Date</th><th>Shift</th><th>Recorded</th><th>By user</th><th>Status</th><th></th></tr></thead>
        <tbody>@foreach ($completions as $completion)
        <tr><td>{{ $completion->TransactionDate }}</td><td>{{ $completion->ShiftNo }}</td><td>{{ $completion->CreatedAt }}</td><td>{{ $completion->CreatedBy }}</td><td>{{ $completion->WithdrawnAt ? 'Withdrawn '.$completion->WithdrawnAt.' by user '.$completion->WithdrawnBy : 'Active' }}</td><td>
        @if (! $completion->WithdrawnAt) @can('stockrecon.countingareas.save') @can('stockrecon.countingareas.edit')
        <form method="post" action="{{ route('app.stockrecon.countingareas.completions.withdraw', ['branch' => $area->BranchId, 'area' => $area->AreaNo, 'completion' => $completion->Id]) }}">@csrf<button class="btn sm" type="submit">Withdraw</button></form>
        @endcan @endcan @endif
        </td></tr>
        @endforeach</tbody>
    </x-table>
    @endif
</x-card>
