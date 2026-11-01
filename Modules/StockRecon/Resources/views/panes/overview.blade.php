{{-- Run-wide figures and supporting context, separate from the proposals worklist. --}}
@php
    /*
     * Balanceable, but nothing to write: the balanced answer is what PumpIT
     * already holds — head office balanced it by hand. Said on the strip, or
     * "45 balanceable" beside "0 to amend" reads as a press that does not
     * work (Ryan, 7 Oct 2026). Reset to original takes the hand balancing off.
     */
    $toBalance = $run->lines
        ->filter(fn ($l) => $l->WouldAmend && ! $l->ChainBlocked)
        ->unique(fn ($l) => $l->AreaNo.'|'.$l->StockItemNo)
        ->count();

    $alreadyBalanced = $run->lines
        ->groupBy(fn ($l) => $l->AreaNo.'|'.$l->StockItemNo)
        ->filter(fn ($chain) => ! $chain->first()->ChainBlocked
            && ! $chain->contains(fn ($l) => $l->WouldAmend)
            && $chain->contains(fn ($l) => abs((float) $l->QtyCloseNew - (float) $l->QtyClose) > 0.0005
                || abs((float) $l->QtyOpenNew - (float) $l->QtyOpen) > 0.0005))
        ->count();
@endphp

<x-card title="Overview"
        :sub="\App\Support\Format::n($run->TotalRows).' shifts across '.\App\Support\Format::n($run->ChainCount).' item chains · previewed in '.\App\Support\Format::n($run->PreviewMs).' ms'"
        flush>
    <x-statstrip :stats="[
        ['label' => 'Shifts to amend', 'href' => route('app.stockrecon.proposal-metric', [$run, 'amended']), 'value' => \App\Support\Format::n($run->AmendedRows),
         'note' => \App\Support\Format::n($run->UnitsAmended, 3).' units moved', 'tone' => 'good'],
        /* Chains with something to WRITE, not chains nothing holds back: a
           chain short with no over, or one head office already balanced, is
           not held back and has nothing to balance — and counting those as
           balanceable is what read as a press that does not work (Ryan,
           7 Oct 2026, twice). */
        ['label' => 'Chains to balance', 'href' => route('app.stockrecon.proposal-metric', [$run, 'balance']), 'value' => \App\Support\Format::n($toBalance),
         'note' => \App\Support\Format::n($run->BalanceableChains).' not held back'
             .($alreadyBalanced > 0 ? ' · '.\App\Support\Format::n($alreadyBalanced).' already balanced in PumpIT' : ''),
         'tone' => 'good'],
        ['label' => 'Chains reported', 'href' => route('app.stockrecon.proposal-metric', [$run, 'reported']), 'value' => \App\Support\Format::n($run->BlockedChains), 'tone' => 'serious'],
        ['label' => 'Unrecorded issue', 'href' => route('app.stockrecon.proposal-metric', [$run, 'unrecorded']), 'value' => \App\Support\Format::r($run->NetOverValue),
         'note' => \App\Support\Format::n($run->NetOverUnits, 3).' units', 'tone' => 'warn'],
        ['label' => 'Shifts over', 'links' => [
             ['href' => route('app.stockrecon.proposal-metric', [$run, 'over-before']), 'label' => 'Shifts over before: show records', 'value' => \App\Support\Format::n($run->OverRowsBefore)],
             ['href' => route('app.stockrecon.proposal-metric', [$run, 'over-after']), 'label' => 'Shifts over after: show records', 'value' => \App\Support\Format::n($run->OverRowsAfter)],
         ],
         'tone' => 'neutral'],
        ['label' => 'Shifts short', 'links' => [
             ['href' => route('app.stockrecon.proposal-metric', [$run, 'short-before']), 'label' => 'Shifts short before: show records', 'value' => \App\Support\Format::n($run->ShortRowsBefore)],
             ['href' => route('app.stockrecon.proposal-metric', [$run, 'short-after']), 'label' => 'Shifts short after: show records', 'value' => \App\Support\Format::n($run->ShortRowsAfter)],
         ],
         'note' => \App\Support\Format::r($run->ShortValueAfter).' after', 'tone' => 'neutral'],
        ['label' => 'Dormant shifts', 'href' => route('app.stockrecon.proposal-metric', [$run, 'dormant']), 'value' => \App\Support\Format::n($run->DormantRows)],
        /* Kept breaks are the ones of KeepBreaksUpTo or fewer that the chain
           runs through (ZRP, 4 Oct 2026); the rest split their chain. */
        ['label' => 'Openings repaired', 'href' => route('app.stockrecon.proposal-metric', [$run, 'openings']), 'value' => \App\Support\Format::n($run->RepairedOpenings),
         'note' => $run->KeptBreaks > 0 ? \App\Support\Format::n($run->KeptBreaks).' small, chain kept' : null,
         'tone' => $run->RepairedOpenings > 0 ? 'warn' : 'neutral'],
        ['label' => 'Hand balancing breaches', 'href' => route('app.stockrecon.proposal-metric', [$run, 'audit']), 'value' => \App\Support\Format::n($run->AuditRows),
         'note' => $run->AuditRows > 0 ? 'see Exceptions' : null, 'tone' => $run->AuditRows > 0 ? 'serious' : 'neutral'],
    ]" />

    @if ($run->ExcludedMeterRows > 0)
        <x-notice tone="info" title="Cumulative water meters are separate" style="margin:16px">
            <p>{{ \App\Support\Format::n($run->ExcludedMeterRows) }} mapped meter readings are excluded from physical
                stock balancing. No stock amendment or hand-count restoration is proposed for them.</p>
            @can('stockrecon.variance.view')
                <p><a href="{{ route('app.stockrecon.water', ['branches' => [$run->BranchId],
                    'from' => $run->FromDate->toDateString(), 'to' => $run->ToDate->toDateString()]) }}">Review the raw meter readings and POS litres</a>.</p>
            @endcan
        </x-notice>
    @endif

    @if (($countDriftRows = $run->lines->whereNotNull('CountDriftReason')->count()) > 0)
        <x-notice tone="info" title="Original/live counts differ" style="margin:16px">
            <p>{{ \App\Support\Format::n($countDriftRows) }} {{ Str::plural('shift', $countDriftRows) }}
                had a different live opening or closing at preview, without a pack audit code.
                This information does not change balancing eligibility or restore counts.
                @can('stockrecon.exceptions.view')
                    <a href="{{ route('app.stockrecon.exceptions', ['run' => $run,
                        'group' => 'all', 'f' => ['CountDriftReason' => ['in' => ['Opening', 'Closing', 'Opening and closing']]]]) }}">Compare the saved counts in Exceptions</a>.
                @endcan
            </p>
        </x-notice>
    @endif

    @if ($run->HeldBackRows > 0)
        {{-- Rule 10 of the client's pack. A data problem, not a finding, and
             the pack says to report it first. --}}
        <x-notice tone="stop" collapsible style="margin:12px 14px 0"
                  :title="\App\Support\Format::n($run->HeldBackRows).' shift lines held back from '.$run->CompleteTo?->copy()->addDay()->format('d M').' — the recon is not finished that day'">
            <p>A shift is missing or short of lines, closings are not captured, or POS is not posted. That day
               and every day after it are left out. Finish it at the site and preview again.</p>
        </x-notice>
    @endif

    @if ($run->BlockedChains > 0)
        {{-- The count is the finding and stays on screen; the reasoning behind
             it is the same paragraph every time and folds away once read. --}}
        <x-notice tone="warn" collapsible style="margin:12px 14px 0"
                  :title="$run->BlockedChains.' '.Str::plural('chain', $run->BlockedChains).' reported rather than balanced'">
            <p>If any shift trips a test, none of the chain is amended: no till sales linked, an issue that went
               nowhere, an amendment over a cap, too few active shifts, a possible duplicate issue, or a write the
               client's weekly audit would flag. Open a row to see which. A chain that totals nil is never held
               back by the first four: it balances, and the finding stays on the exceptions.</p>
            <p>A missing issue does not hold a chain back. The chain balances around it, and the over that is
               left stays on the shift that carries it, marked <strong>Unrecorded issue</strong>, to capture.</p>
        </x-notice>
    @endif

</x-card>

    <x-card title="What this run was given" collapsible flush>
        <x-table dense :count="count($run->params()) + 4">
            <x-slot:head>
                <tr><th class="l">Argument</th><th class="l">Value</th></tr>
            </x-slot:head>
            <tr><td class="mono">Procedure</td><td class="mono">{{ $run->ProcedureName }}</td></tr>
            <tr><td class="mono">Replaces</td><td class="mono">dbo.sp_UpdateAUTOStockReconBalancing</td></tr>
            <tr><td class="mono">Stamp mode</td><td>{{ $run->StampMode }}</td></tr>
            <tr><td class="mono">Counting area</td><td>{{ $run->AreaNo === null ? 'every area' : $run->AreaNo }}</td></tr>
            @foreach ($run->params() as $name => $value)
                <tr><td class="mono">{{ '@'.$name }}</td><td class="mono">{{ $value }}</td></tr>
            @endforeach
        </x-table>
    </x-card>

    @include('stockrecon::partials.duplicates')
