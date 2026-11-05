@if ($globalRefusal)
    <x-notice tone="warn" title="Global comparison is unavailable">
        <p>{{ $globalRefusal }}</p>
    </x-notice>
@else
<x-notice tone="warn" title="Raw quantities — ratios are still being prepared">
    <p>ZP is preparing the production ratios and expected issue multiples. These comparisons do not convert
        quantities by Ratio. A raw net is an arithmetic comparison, not a confirmed loss, production yield or staff charge.</p>
    <p>Linked candidates need investigation. This page never changes counts or issues, and a chain may have several
        possible partners. Candidate quantities must not be added together as if each were a separate correction.</p>
</x-notice>

<div class="dg-bar" style="margin:16px 0">
    <a class="{{ $basis === 'balanced' ? 'btn-primary' : 'btn-ghost' }}" href="{{ route('app.stockrecon.global', [$run, 'basis' => 'balanced']) }}">After proposed balancing</a>
    <a class="{{ $basis === 'original' ? 'btn-primary' : 'btn-ghost' }}" href="{{ route('app.stockrecon.global', [$run, 'basis' => 'original']) }}">As counted</a>
    <span class="dg-bar-gap"></span>
    @foreach (['chains' => 'Chains CSV', 'pairs' => 'Candidates CSV', 'production' => 'Production CSV'] as $kind => $label)
        <a class="btn-ghost" href="{{ route('app.stockrecon.global', [$run, 'basis' => $basis, 'export' => $kind]) }}">{{ $label }}</a>
    @endforeach
</div>
<p class="muted">Same site, period and areas as this saved run. Quantities are the run’s snapshot;
    product grouping, ratios and production records are current reference data.
    After proposed balancing includes any proposed missing-issue or duplicate corrections, whether committed or not.</p>
@if ($run->AreaNo !== null)
    <p class="muted">This run covers one counting area. Preview every counting area to compare across areas.</p>
@endif

<x-card title="Master → chain → shift detail">
    @forelse ($global['groups'] as $group)
        <details id="group-{{ $group->PreProductionTypeNo }}" style="margin:12px 0">
            <summary>
                <strong>{{ (int) $group->PreProductionTypeNo > 0 ? 'Production group '.$group->PreProductionTypeNo : 'Items without a production group' }}</strong>
                · {{ $group->ChainCount }} chains
                @if ((int) $group->PreProductionTypeNo > 0 && (int) $group->UnitKinds === 1 && (int) $group->UnknownUnits === 0)
                    · gross raw {{ \App\Support\Format::n($group->GrossRawQuantity, 3) }}
                    · net raw {{ \App\Support\Format::n($group->NetRawQuantity, 3) }}
                    · gross reduction {{ \App\Support\Format::n($group->CancelledRawQuantity, 3) }}
                @else
                    · no combined quantity ({{ (int) $group->PreProductionTypeNo > 0 ? 'mixed or unknown units' : 'unrelated items' }})
                @endif
            </summary>
            <p class="muted">Ratios not applied. {{ $group->UnsetRatios }} chains have a missing or zero ratio.
                Gross reduction counts both sides of an arithmetic offset; it is not a correction amount.</p>
            <x-table layout-key="StockRecon.panes.global.1" dense data-row-detail :count="$global['chains']->where('PreProductionTypeNo', $group->PreProductionTypeNo)->count()" procedure="agora.usp_StockRecon_PreviewGlobal">
                <x-slot:head><tr><th>Area / item</th><th>Unit</th><th class="num">Ratio</th><th class="num">As counted</th><th class="num">After proposed balancing</th><th>Evidence</th></tr></x-slot:head>
                @foreach ($global['chains']->where('PreProductionTypeNo', $group->PreProductionTypeNo) as $chain)
                    <tr data-detail-url="{{ route('app.stockrecon.line', [$run, $chain->LineId]) }}" tabindex="0" aria-expanded="false">
                        <td><a href="{{ route('app.stockrecon.line', [$run, $chain->LineId]) }}">{{ $chain->ItemDescription }}</a><br><span class="muted">Area {{ $chain->AreaNo }} · {{ $chain->StockItemNo }} · {{ $chain->ShiftCount }} shifts<br>{{ $chain->FirstShift }} – {{ $chain->LastShift }}</span></td>
                        <td>{{ $chain->UOMCode ?: 'Unknown' }}</td>
                        <td class="num">{{ \App\Support\Format::n($chain->Ratio, 3) }}</td>
                        <td class="num">{{ \App\Support\Format::n($chain->OriginalVariance, 3) }}</td>
                        <td class="num">{{ \App\Support\Format::n($chain->BalancedVariance, 3) }}</td>
                        <td>
                            @if ($chain->CandidateCount > 0)<a href="#candidates">{{ $chain->CandidateCount }} linked candidates</a>@else No linked candidate @endif
                            @if ($chain->Held)<br>Chain held back @endif
                            @if ($chain->ProposedMissingIssue > 0)<br>Missing issue proposed: {{ \App\Support\Format::n($chain->ProposedMissingIssue, 3) }} @endif
                            @if ($chain->ClearDuplicates > 0)<br>{{ $chain->ClearDuplicates }} clear duplicate findings @endif
                            @if ($chain->PossibleDuplicates > 0)<br>{{ $chain->PossibleDuplicates }} possible duplicate findings @endif
                        </td>
                    </tr>
                @endforeach
            </x-table>
        </details>
    @empty
        <p>No chains were recorded for this run.</p>
    @endforelse
</x-card>

<div id="candidates" style="margin-top:16px">
<x-card title="Linked candidates to investigate">
    <p>{{ $global['summary']->ArithmeticPairs }} arithmetic matches;
        <strong>{{ $global['summary']->RejectedPairs }} rejected</strong> because they lack a usable link or comparable unit;
        {{ $global['summary']->LinkedPairs }} linked candidates. Matching tolerance: 0.01 raw units.</p>
    <p class="muted">Ranked by shared production group, then shared POS code, then same area and shared first description word.
        A shared word is a weak clue. Even a strong link is not proof that stock moved between these items.</p>
    <x-table layout-key="StockRecon.panes.global.2" dense :count="$global['pairs']->count()" procedure="agora.usp_StockRecon_PreviewGlobal" empty="No linked offset candidates in this basis.">
        <x-slot:head><tr><th>Over</th><th class="num">Quantity</th><th>Short</th><th class="num">Quantity</th><th>Unit</th><th>Link</th></tr></x-slot:head>
        @foreach ($global['pairs'] as $pair)
            <tr>
                <td><a href="{{ route('app.stockrecon.line', [$run, $pair->OverLineId]) }}">{{ $pair->OverItem }}</a><br>Area {{ $pair->OverArea }} · {{ $pair->OverItemNo }}</td>
                <td class="num">{{ \App\Support\Format::n($pair->OverQuantity, 3) }}</td>
                <td><a href="{{ route('app.stockrecon.line', [$run, $pair->ShortLineId]) }}">{{ $pair->ShortItem }}</a><br>Area {{ $pair->ShortArea }} · {{ $pair->ShortItemNo }}</td>
                <td class="num">{{ \App\Support\Format::n($pair->ShortQuantity, 3) }}</td>
                <td>{{ $pair->UOMCode }}</td><td>{{ $pair->LinkReason }}</td>
            </tr>
        @endforeach
    </x-table>
</x-card>
</div>

<x-card title="Recorded production in this run’s period" style="margin-top:16px">
    <p>Production records for the same site, items, POS system and covered dates. These records provide context;
        they do not establish that production explains a variance. Ratios and the production mapping still need ZP’s confirmation.</p>
    <x-table layout-key="StockRecon.panes.global.3" dense :count="$global['production']->count()" procedure="agora.usp_StockRecon_PreviewGlobal" empty="No matching production records found. This does not prove that no production occurred.">
        <x-slot:head><tr><th>Date</th><th>Shift</th><th>Item</th><th>POS system</th><th>Base code</th><th>Reference</th><th class="num">Recorded quantity</th></tr></x-slot:head>
        @foreach ($global['production'] as $record)
            <tr><td>{{ substr($record->TransactionDate, 0, 10) }}</td><td data-sort="{{ $record->ShiftNo }}">{{ \Modules\StockRecon\Support\ShiftLabel::show($record->ShiftNo) }}</td><td>{{ $record->StockItemNo }}</td><td>{{ $record->PosSystem }}</td><td>{{ $record->BaseCode }}</td><td>{{ $record->TrackingNumber }}</td><td class="num">{{ \App\Support\Format::n($record->TotalQuantity, 3) }}</td></tr>
        @endforeach
    </x-table>
</x-card>
@endif
