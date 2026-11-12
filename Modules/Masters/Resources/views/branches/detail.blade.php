@php($show = route('app.setup.branches.show', ['branch' => $branch->BranchId]))
<x-card :title="$branch->Name" :sub="'Branch '.$branch->BranchId.' · '.$branch->Status">
    <x-slot:actions>
        <a class="btn sm" href="{{ $show }}">View</a>
        @can('masters.branches.edit')
            <a class="btn sm" href="{{ route('app.setup.branches.edit', ['branch' => $branch->BranchId]) }}">Edit branch</a>
        @endcan
    </x-slot:actions>
    <x-facts>
        <x-fact label="Status" :value="$branch->Status" :tone="$branch->Status === 'Trading' ? 'good' : ($branch->Status === 'Mothballed' ? 'crit' : null)">
            {{ $branch->IsTrading ? 'In the trading lists and the site picker.' : 'Left out of the trading lists and the site picker.' }}
        </x-fact>
        <x-fact label="Region" :value="$branch->RegionName ?? '—'" />
        <x-fact label="Brand" :value="$branch->BrandName ?? '—'" />
        <x-fact label="Format" :value="$branch->FormatName ?? '—'">PumpIT calls this the class.</x-fact>
        <x-fact label="POS type" :value="$branch->POSType">{{ $branch->POSType === 'NAMOS' ? 'Day-end arrives from NAMOS, not the branch POS.' : 'Day-end arrives from the branch POS.' }}</x-fact>
        <x-fact label="Administrative entity" :value="$branch->IsAdminEntity ? 'Yes' : 'No'">{{ $branch->IsAdminEntity ? 'A branch for accounting; it never keeps a trading day.' : '' }}</x-fact>
        <x-fact label="Active" :value="$branch->IsActive ? 'Yes' : 'No'" />
        <x-fact label="Name in PumpIT" :value="$branch->LegacyName ?? '—'">SS_Branch.BranchName when it was migrated.</x-fact>
        <x-fact label="Migrated" :value="$branch->MigratedAt ? \App\Support\BusinessDate::show($branch->MigratedAt) : 'Not yet'" />
        <x-fact label="Last changed" :value="$branch->UpdatedAt ? \App\Support\BusinessDate::show($branch->UpdatedAt) : '—'">{{ $branch->UpdatedByName }}</x-fact>
    </x-facts>
</x-card>

<x-card title="Profit centres" :sub="$profitCentres->count().' at this branch'">
    @if ($profitCentres->isEmpty())
        <x-empty-state text="No profit centres. PumpIT's category map (SDK_CategoryGLCode) names none for this branch." />
    @else
        <ul class="perm-list">
            @foreach ($profitCentres as $centre)
                <li>{{ $centre->Name ?? 'Profit centre '.$centre->ProfitCentreId }} <x-chip tone="neutral" :dot="false">{{ $centre->Source === 'PumpIT' ? 'from PumpIT' : 'added in Agora' }}</x-chip></li>
            @endforeach
        </ul>
    @endif
</x-card>

<x-card title="History" sub="Every change to this branch, newest first">
    @if ($history->isEmpty())
        <x-empty-state text="Nothing recorded yet." />
    @else
        <x-table layout-key="Masters.branches.history" tools>
            <x-slot:head><tr><th>When</th><th>Who</th><th>What</th><th>Before</th><th>After</th><th>Why</th></tr></x-slot:head>
            @foreach ($history as $change)
                <tr>
                    <td>{{ \App\Support\BusinessDate::show($change->CreatedAt) }}</td>
                    <td>{{ $change->ChangedByName ?? ($change->Source === 'migrate' ? 'Migration' : $change->CreatedBy) }}</td>
                    <td>{{ $change->Field }}</td>
                    <td>{{ $change->PriorValue ?? '—' }}</td>
                    <td>{{ $change->NewValue ?? '—' }}</td>
                    <td>{{ $change->Reason }}</td>
                </tr>
            @endforeach
        </x-table>
    @endif
</x-card>
