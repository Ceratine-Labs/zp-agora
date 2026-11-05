<x-card :title="$case->Description" :sub="$case->BranchName.' · '.$case->AreaDescription.' · '.$case->WeekStart.' to '.$case->WeekEnd">
    <x-slot:actions>
        <a class="btn sm" href="{{ route('app.stockrecon.weekly.show', ['branch' => $case->BranchId, 'case' => $case->Id]) }}">View case</a>
        @can('stockrecon.weekly.edit')<a class="btn sm" href="{{ route('app.stockrecon.weekly.edit', ['branch' => $case->BranchId, 'case' => $case->Id]) }}">Record action</a>@endcan
    </x-slot:actions>
    <x-facts>
        <x-fact label="Register" :value="$case->RegisterName" />
        <x-fact label="Control period" :value="($case->PeriodFrom ?? $case->WeekStart).' to '.($case->PeriodTo ?? $case->WeekEnd)">Complete through {{ $case->PeriodThrough ?? 'Unavailable' }}. Recency as at {{ $case->ReportingAsOf }}.</x-fact>
        <x-fact label="Responsible role" :value="$case->ResponsibleRole" />
        <x-fact label="Period unit cost" :value="$case->UnitCost">{{ $case->CostBasis }}</x-fact>
        <x-fact label="Period recon at cost" :value="$case->ReconValueCost" />
        <x-fact label="Period count at cost" :value="$case->CountValueCost" />
        <x-fact label="Period storeroom at cost" :value="$case->StoreroomValueCost">Cost variance is not an approved charge or recovery.</x-fact>
        <x-fact label="Scenario" :value="$case->Scenario.' · '.$case->ScenarioName">{{ $case->NextAction }}</x-fact>
        <x-fact label="Recon variance (amended)" :value="$case->ReconQty === null ? null : \App\Support\Format::qty($case->ReconQty)" />
        <x-fact label="Recon variance (original)" :value="$case->OriginalReconQty === null ? 'Unknown' : \App\Support\Format::qty($case->OriginalReconQty)" />
        <x-fact label="Site count variance" :value="$case->CountQty === null ? 'Count not proven' : \App\Support\Format::qty($case->CountQty)" />
        <x-fact label="Count less recon" :value="$case->StoreroomQty === null ? 'Unknown' : \App\Support\Format::qty($case->StoreroomQty)" />
        <x-fact label="Previous week count variance" :value="$case->PreviousCountQty === null ? 'Unknown' : \App\Support\Format::qty($case->PreviousCountQty)" />
        <x-fact label="Last proven count" :value="$case->LastCountDate ?? 'Unknown'" />
        <x-fact label="Unit selling price" :value="$case->UnitSellingPrice ?? 'Unknown'" />
        <x-fact label="Exposure at selling" :value="$case->ValueAtRiskSelling ?? 'Unknown'">Exposure is not cost or a proven loss.</x-fact>
        <x-fact label="Status" :value="$case->Status" />
        <x-fact label="Owner" :value="$case->OwnerName ?? 'Unassigned'" />
        <x-fact label="Closure outcome" :value="$case->ClosureOutcome ?? 'Not recorded'" />
        <x-fact label="Rand charged / recovered" :value="$case->RecoveryAmount ?? 'Not recorded'">{{ $case->RecoveryReference }}</x-fact>
        <x-fact label="Corrected in PumpIT" :value="$case->CorrectionStatus ?? 'Not recorded'">{{ $case->CorrectionReference }}</x-fact>
        <x-fact label="Action completed" :value="$case->ActionTaken ?? 'Not recorded'">{{ $case->ActionDate }}</x-fact>
        <x-fact label="Head-office sign-off" :value="$case->SignedOffName ?? 'Awaiting approval'">{{ $case->SignoffNote }} {{ \App\Support\BusinessDate::show($case->SignedOffAt) }}</x-fact>
        <x-fact label="Closed date" :value="$case->ClosedDate" />
        <x-fact label="Due date" :value="$case->DueDate" />
        <x-fact label="Complete through" :value="$case->CompleteThrough">Source captured {{ \App\Support\BusinessDate::show($case->SourceReadAt) }}. Rule {{ $case->RuleVersion }}.</x-fact>
    </x-facts>
    @foreach (json_decode($case->CoverageJson, true) ?? [] as $coverage)
        @if (($coverage['State'] ?? '') !== 'Available')<x-notice tone="warn">{{ $coverage['SourceName'] ?? 'Source' }}: {{ $coverage['State'] ?? 'Unknown' }}. {{ $coverage['Note'] ?? '' }}</x-notice>@endif
    @endforeach
    @if ($case->SharedCountCases > 1)<x-notice tone="warn">This whole-site count is linked to {{ $case->SharedCountCases }} area cases. Do not add those count variances together. This product is excluded from monetary scorecard totals until the shared mapping is resolved.</x-notice>@endif
    @if ($case->Finding)<p><strong>Finding:</strong> {{ $case->Finding }}</p><p><strong>Evidence reference:</strong> {{ $case->EvidenceReference }}</p>@endif
    <x-facts>
        @foreach (json_decode($case->EvidenceJson, true) ?? [] as $key => $value)
            @if (!is_array($value))<x-fact :label="\Illuminate\Support\Str::headline($key)" :value="$value ?? 'Unknown'" />@endif
        @endforeach
    </x-facts>
    @if (!empty(json_decode($case->EvidenceJson)->CountWindows))
        <x-table layout-key="StockRecon.weekly.detail.1" tools><x-slot:head><tr><th>Window from</th><th>Count date</th><th>Staff recon</th><th>Balanced recon</th><th>Site count variance</th><th>Cost</th><th>Cost basis</th><th>Partial recon</th><th>Scenario</th></tr></x-slot:head>
            @foreach (json_decode($case->EvidenceJson)->CountWindows as $window)<tr><td>{{ $window->WindowStart }}</td><td>{{ $window->CountDate }}</td><td data-sort="{{ $window->Ro }}">{{ $window->Ro === null || $window->Ro === '' ? '' : \App\Support\Format::qty($window->Ro) }}</td><td data-sort="{{ $window->R }}">{{ $window->R === null || $window->R === '' ? '' : \App\Support\Format::qty($window->R) }}</td><td data-sort="{{ $window->S }}">{{ $window->S === null || $window->S === '' ? '' : \App\Support\Format::qty($window->S) }}</td><td>{{ $window->UnitCost }}</td><td>{{ $window->CostBasis }}</td><td>{{ $window->PartialRecon ? 'Yes' : 'No' }}</td><td>{{ $window->Class }}</td></tr>@endforeach
        </x-table>
    @endif
    @if (!empty(json_decode($case->EvidenceJson)->Deliveries))
        <x-table layout-key="StockRecon.weekly.detail.2" tools><x-slot:head><tr><th>Delivery date</th><th>Received</th><th>Same-day issue</th><th>Next-day issue</th><th>Compared issue</th><th>Matched</th><th>Issue without delivery today or yesterday</th></tr></x-slot:head>
            @foreach (json_decode($case->EvidenceJson)->Deliveries as $delivery)<tr><td>{{ $delivery->EvidenceDate }}</td><td data-sort="{{ $delivery->Received }}">{{ $delivery->Received === null || $delivery->Received === '' ? '' : \App\Support\Format::qty($delivery->Received) }}</td><td data-sort="{{ $delivery->SameDayIssued }}">{{ $delivery->SameDayIssued === null || $delivery->SameDayIssued === '' ? '' : \App\Support\Format::qty($delivery->SameDayIssued) }}</td><td data-sort="{{ $delivery->NextDayIssued }}">{{ $delivery->NextDayIssued === null || $delivery->NextDayIssued === '' ? '' : \App\Support\Format::qty($delivery->NextDayIssued) }}</td><td data-sort="{{ $delivery->MatchedIssued }}">{{ $delivery->MatchedIssued === null || $delivery->MatchedIssued === '' ? '' : \App\Support\Format::qty($delivery->MatchedIssued) }}</td><td>{{ $delivery->Matched ? 'Yes' : 'No' }}</td><td>{{ ($delivery->IssueWithoutDelivery ?? 0) ? 'Yes' : 'No' }}</td></tr>@endforeach
        </x-table>
    @endif
    @if (!empty(json_decode($case->EvidenceJson)->RelatedCases))
        <ul>@foreach (json_decode($case->EvidenceJson)->RelatedCases as $related)<li><a href="{{ route('app.stockrecon.weekly.show', ['branch' => $case->BranchId, 'case' => $related->CaseId]) }}">{{ $related->AreaDescription }} · {{ $related->POSCode }} · {{ $related->Scenario }}</a></li>@endforeach</ul>
    @endif
    @if (!empty(json_decode($case->EvidenceJson)->RecipeDaily))
        <x-table layout-key="StockRecon.weekly.detail.3" tools><x-slot:head><tr><th>Date</th><th>Product POS across areas</th><th>Recipe consumption</th><th>Difference</th><th>Daily comparison</th></tr></x-slot:head>
            @foreach (json_decode($case->EvidenceJson)->RecipeDaily as $day)<tr><td>{{ $day->DayDate }}</td><td data-sort="{{ $day->POSQty }}">{{ $day->POSQty === null || $day->POSQty === '' ? '' : \App\Support\Format::qty($day->POSQty) }}</td><td data-sort="{{ $day->RecipeQty }}">{{ $day->RecipeQty === null || $day->RecipeQty === '' ? '' : \App\Support\Format::qty($day->RecipeQty) }}</td><td data-sort="{{ $day->Difference }}">{{ $day->Difference === null || $day->Difference === '' ? '' : \App\Support\Format::qty($day->Difference) }}</td><td>{{ $day->Comparison }}</td></tr>@endforeach
        </x-table>
    @endif
</x-card>
<x-card title="Count evidence" sub="The source records are preserved as read. Missing expected quantities remain unknown.">
    <x-table layout-key="StockRecon.weekly.detail.4" tools><x-slot:head><tr><th>Date</th><th>Counted</th><th>Expected</th><th>Variance</th><th>Unit cost</th><th>Reference</th><th>Source facts</th></tr></x-slot:head>
        @forelse ($counts as $count)<tr><td>{{ $count->CountDate }}</td><td data-sort="{{ $count->CountedQty }}">{{ $count->CountedQty === null || $count->CountedQty === '' ? '' : \App\Support\Format::qty($count->CountedQty) }}</td><td data-sort="{{ $count->ExpectedQty }}">{{ $count->ExpectedQty === null || $count->ExpectedQty === '' ? '' : \App\Support\Format::qty($count->ExpectedQty) }}</td><td data-sort="{{ $count->VarianceQty }}">{{ $count->VarianceQty === null || $count->VarianceQty === '' ? '' : \App\Support\Format::qty($count->VarianceQty) }}</td><td>{{ $count->UnitCost }}</td><td>{{ $count->Reference }}</td><td>{{ $count->DetailJson }}</td></tr>@empty<tr><td colspan="7">No count evidence for this product.</td></tr>@endforelse
    </x-table>
</x-card>
<x-card title="Receipts and recipe evidence" sub="Receipts include dates around the control period for timing checks. Values retain their stated source basis.">
    <x-table layout-key="StockRecon.weekly.detail.5" tools><x-slot:head><tr><th>Date</th><th>Type</th><th>Quantity</th><th>Value</th><th>Reference</th><th>Source facts</th></tr></x-slot:head>
        @forelse ($evidence as $fact)<tr><td>{{ $fact->EvidenceDate }}</td><td>{{ $fact->EvidenceType }}</td><td data-sort="{{ $fact->Quantity }}">{{ $fact->Quantity === null || $fact->Quantity === '' ? '' : \App\Support\Format::qty($fact->Quantity) }}</td><td>{{ $fact->Value }}</td><td>{{ $fact->Reference }}</td><td>{{ $fact->DetailJson }}</td></tr>@empty<tr><td colspan="6">No matching receipt or recipe facts. See source availability on the weekly scorecard.</td></tr>@endforelse
    </x-table>
</x-card>
<x-card title="Shift evidence" sub="Earlier shifts are retained for context. The weekly register uses the selected week; control registers use the stated control period and cutoff.">
    <x-table layout-key="StockRecon.weekly.detail.6" tools><x-slot:head><tr><th>Date</th><th>Shift</th><th>Original open</th><th>Original issue</th><th>Original close</th><th>Original POS</th><th>Current open</th><th>Current issue</th><th>Current close</th><th>Current POS</th></tr></x-slot:head>
        @foreach ($shifts as $shift)<tr><td>{{ $shift->TransactionDate }}</td><td data-sort="{{ $shift->ShiftNo }}">{{ \Modules\StockRecon\Support\ShiftLabel::show($shift->ShiftNo) }}</td><td data-sort="{{ $shift->OriginalOpen }}">{{ $shift->OriginalOpen === null || $shift->OriginalOpen === '' ? '' : \App\Support\Format::qty($shift->OriginalOpen) }}</td><td data-sort="{{ $shift->OriginalIssued }}">{{ $shift->OriginalIssued === null || $shift->OriginalIssued === '' ? '' : \App\Support\Format::qty($shift->OriginalIssued) }}</td><td data-sort="{{ $shift->OriginalClose }}">{{ $shift->OriginalClose === null || $shift->OriginalClose === '' ? '' : \App\Support\Format::qty($shift->OriginalClose) }}</td><td data-sort="{{ $shift->OriginalPOS }}">{{ $shift->OriginalPOS === null || $shift->OriginalPOS === '' ? '' : \App\Support\Format::qty($shift->OriginalPOS) }}</td><td data-sort="{{ $shift->QtyOpen }}">{{ $shift->QtyOpen === null || $shift->QtyOpen === '' ? '' : \App\Support\Format::qty($shift->QtyOpen) }}</td><td data-sort="{{ $shift->QtyIssued }}">{{ $shift->QtyIssued === null || $shift->QtyIssued === '' ? '' : \App\Support\Format::qty($shift->QtyIssued) }}</td><td data-sort="{{ $shift->QtyClose }}">{{ $shift->QtyClose === null || $shift->QtyClose === '' ? '' : \App\Support\Format::qty($shift->QtyClose) }}</td><td data-sort="{{ $shift->QtyPOS }}">{{ $shift->QtyPOS === null || $shift->QtyPOS === '' ? '' : \App\Support\Format::qty($shift->QtyPOS) }}</td></tr>@endforeach
    </x-table>
</x-card>
<x-card title="Action history">
    <x-table layout-key="StockRecon.weekly.detail.7" tools><x-slot:head><tr><th>When</th><th>Who</th><th>Reason</th><th>Before</th><th>After</th></tr></x-slot:head>
        @forelse ($history as $change)<tr><td>{{ \App\Support\BusinessDate::show($change->CreatedAt) }}</td><td>{{ $change->ActorName }}</td><td>{{ $change->Reason }}</td><td>{{ $change->PriorJson }}</td><td>{{ $change->NewJson }}</td></tr>@empty<tr><td colspan="5">No investigation action has been recorded.</td></tr>@endforelse
    </x-table>
</x-card>
