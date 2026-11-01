<x-app-shell title="Weekly stock investigations">
    <x-page-head eyebrow="Control · Stock" title="Weekly stock investigations">
        <x-slot:actions>
            <a class="btn-ghost" href="{{ route('app.stockrecon.index') }}">Stock recon centre</a>
            @if (request()->query('snapshot'))<a class="btn-ghost" href="{{ route('app.stockrecon.weekly') }}">Latest snapshots</a>@endif
            @can('stockrecon.weekly.create')<a class="btn-primary" href="{{ route('app.stockrecon.weekly.create') }}">Build a week</a>@endcan
        </x-slot:actions>
    </x-page-head>
    <x-notice tone="info">Latest snapshot per site and week is shown unless a specific snapshot is selected. Scenarios describe quantity differences that need investigation; they do not establish a cause or authorise a staff charge. Blank counts are unknown.</x-notice>
    <x-data-grid :grid="$grid" />
    <x-card title="Cross-site patterns" sub="Same POS code, same control period, at least three permitted sites with 75% moving in the same direction by more than 3% of sales. Only complete periods with original quantities contribute.">
        <x-table tools><x-slot:head><tr><th>Period</th><th>Code</th><th>Item</th><th>Sites</th><th>Short</th><th>Over</th><th>Median variance / sales</th><th>Sites compared</th><th>Action</th></tr></x-slot:head>
            @forelse ($patterns as $pattern)<tr><td>{{ $pattern->PeriodFrom }} to {{ $pattern->PeriodTo }}</td><td>{{ $pattern->POSCode }}</td><td>{{ $pattern->Description }}</td><td>{{ $pattern->Sites }}</td><td>{{ $pattern->ShortSites }}</td><td>{{ $pattern->OverSites }}</td><td>{{ number_format((float) $pattern->MedianPct * 100, 2) }}%</td><td>{{ $pattern->SiteNames }}</td><td>{{ $pattern->NextAction }}<ul>@foreach (json_decode($pattern->CasesJson) ?? [] as $related)<li><a href="{{ route('app.stockrecon.weekly.show', ['branch' => $related->BranchId, 'case' => $related->CaseId]) }}">Review and assign {{ $related->BranchName }}</a></li>@endforeach</ul></td></tr>
            @empty<tr><td colspan="9">No pattern established in the selected snapshots. A single-site snapshot cannot establish a cross-site pattern.</td></tr>@endforelse
        </x-table>
    </x-card>
    @foreach ($sites as $site)
        @php($product = $products->first(fn ($row) => (int) $row->BranchId === (int) $site->BranchId && $row->SnapshotRef === $site->SnapshotRef))
        <x-card :title="$site->BranchName.' · '.$site->WeekStart.' to '.$site->WeekEnd" sub="Site scorecard uses the branch/date selection above. Column filters apply to the case grid only.">
            <x-slot:actions><a class="btn sm" href="{{ route('app.stockrecon.weekly', ['snapshot' => $site->SnapshotRef]) }}">Open snapshot</a></x-slot:actions>
            <x-facts>
                <x-fact label="Source captured" :value="\App\Support\BusinessDate::show($site->SourceReadAt)" />
                <x-fact label="Complete through" :value="$site->CompleteThrough">{{ $site->CompleteThrough < $site->WeekEnd ? 'Later recon and count dates are held pending complete shifts.' : 'Complete week under the shift-coverage rules.' }}</x-fact>
                <x-fact label="Approved charges / recoveries" :value="$site->ApprovedRecoveryAmount ?? 'None approved'">Only signed closed cases contribute. Exposure is separate.</x-fact>
                <x-fact label="Weekly lines to investigate" :value="$site->LinesToInvestigate" />
                <x-fact label="Weekly exposure at selling" :value="$site->ValueAtRiskSelling" />
                <x-fact label="Storeroom exposure at selling" :value="$site->StoreroomExposureSelling" />
                <x-fact label="Recon-point exposure at selling" :value="$site->ReconPointExposureSelling" />
                <x-fact label="Cases closed" :value="$site->ClosedCases.' / '.$site->Cases" />
                <x-fact label="Unassigned" :value="$site->UnassignedCases" />
                <x-fact label="Overdue actions" :value="$site->OverdueCases" />
                <x-fact label="Unanswered after 24 hours" :value="$site->Unanswered24h" />
                <x-fact label="No finding after 48 hours" :value="$site->NoFinding48h" />
                <x-fact label="Physical products counted" :value="($product->CountProven ?? 0).' / '.($product->PhysicalProducts ?? 0)" />
                <x-fact label="Count not proven" :value="$product->CountUnproven ?? 0" />
                <x-fact label="Virtual / meter cases excluded" :value="$site->VirtualCases" />
                <x-fact label="Recon variance at selling" :value="$product?->ReconValueSelling === null ? 'Unknown' : number_format((float) $product->ReconValueSelling, 2)" />
                <x-fact label="Count less recon at selling" :value="$product?->StoreroomValueSelling === null ? 'Unknown' : number_format((float) $product->StoreroomValueSelling, 2)">Shared counts and inconsistent prices are excluded from monetary totals ({{ $product->ExcludedFromValueTotals ?? 0 }} products). Values are selling exposure, not cost or proven loss.</x-fact>
            </x-facts>
            <p>Control period {{ $site->PeriodFrom ?? $site->WeekStart }} to {{ $site->PeriodTo ?? $site->WeekEnd }}; complete through {{ $site->PeriodThrough ?? 'unavailable' }}. Count recency as at {{ $site->ReportingAsOf }}.</p>
            <x-table tools><x-slot:head><tr><th>Register</th><th>Closed / cases</th><th>Recon at cost</th><th>Count at cost</th><th>Storeroom at cost</th><th>Estimated cost cases</th><th>Partial cases</th><th>Approved recovery</th></tr></x-slot:head>
                @foreach ($registers->filter(fn ($r) => $r->SnapshotRef === $site->SnapshotRef && (int) $r->BranchId === (int) $site->BranchId) as $register)
                    <tr><td>{{ $register->RegisterName }}</td><td>{{ $register->ClosedCases }} / {{ $register->Cases }}</td><td>{{ $register->ReconValueCost }}</td><td>{{ $register->CountValueCost }}</td><td>{{ $register->StoreroomValueCost }}</td><td>{{ $register->EstimatedCostCases }}</td><td>{{ $register->PartialCases }}</td><td>{{ $register->ApprovedRecovery }}</td></tr>
                @endforeach
            </x-table>
            <x-table tools><x-slot:head><tr><th>Source</th><th>Availability</th><th>Evidence limits</th></tr></x-slot:head>
                @foreach (json_decode($site->CoverageJson) ?? [] as $source)<tr><td>{{ $source->SourceName }}</td><td>{{ $source->State }}</td><td>{{ $source->Note }}</td></tr>@endforeach
            </x-table>
        </x-card>
    @endforeach
</x-app-shell>
