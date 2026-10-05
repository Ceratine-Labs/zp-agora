{{--
    The duplicate issues on this run — what is clear, what is possible, and
    what is being done about each (Ryan, 1 Oct 2026: "show a view like the
    customer report on the stock recon itself, identifying what is what and
    what is being corrected").

    On both faces of a run, the proposals and the exceptions, because it is
    about the run rather than either list: a clear one (D1) is a row the commit
    writes, a possible one (D2) is a chain it will not.

    Everything comes out of agora.usp_StockRecon_GetDuplicates
    (StockReconService::duplicates()): each candidate with its state, the
    shifts that prove it, and the totals. One tab per candidate, grouped Clear
    then Possible, the way the customer report reads them; each opens the case —
    the shift where the issue arrived, the shift as keyed and, for a clear one,
    the same shift corrected.

    `dup` in the address is the case left open, so a release — which redirects
    back — lands on the case it was pressed on.
--}}
@php
    use App\Support\Format;
    use Illuminate\Support\Carbon;
    use Illuminate\Support\Str;

    $cases = $duplicates['cases'];
    $totals = $duplicates['totals'];
    $clear = (int) ($totals->Clear ?? 0);
    $possible = (int) ($totals->Possible ?? 0);
    $held = (int) ($totals->ChainsHeld ?? 0);

    /* The word after a tab's name: where the case stands, in two words. */
    $stateWords = [
        'will_remove' => 'to 0', 'removed' => 'set to 0', 'reversed' => 'reversed', 'skipped' => 'skipped',
        'left_out' => 'not written', 'not_removed' => 'not removed',
        'held' => 'held', 'released' => 'released', 'released_since' => 'released',
        'withdrawn' => 'withdrawn', 'listed' => 'listed',
    ];

    $items = $cases->map(fn ($case) => [
        'key' => 'dup-'.$case->LineId,
        'label' => Str::limit(Str::squish((string) $case->ItemDescription), 26)
            .' · '.Carbon::parse($case->TransactionDate)->format('j M').' shift '.$case->ShiftNo,
        'group' => $case->DupTier === 'D1' ? 'Clear' : 'Possible',
        'tone' => $case->DupTier === 'D1' ? 'good' : 'serious',
        'count' => $stateWords[$case->State] ?? null,
    ])->values()->all();

    $active = collect($items)->contains('key', $dupCase ?? null) ? $dupCase : ($items[0]['key'] ?? null);

    $sub = $cases->isEmpty()
        ? 'None on this run'
        : collect([
            $clear > 0 ? $clear.' clear' : null,
            $possible > 0 ? $possible.' possible' : null,
            $held > 0 ? $held.' '.Str::plural('chain', $held).' held back' : null,
        ])->filter()->implode(' · ');
@endphp

<x-card id="duplicates" title="Duplicate issues" :sub="$sub" collapsible :open="$cases->isNotEmpty()" remember="stockrecon-duplicates"
        flush style="margin:16px 0">
    <x-slot:info>
        A <strong>clear</strong> duplicate (D1) is an issue keyed twice: the shift is short by exactly the issue
        and the identical issue on the shift beside it arrived, so the issue is set to 0 and nobody is charged.
        A <strong>possible</strong> one (D2) cannot be proven from the counts, so it holds its item's chain back
        until the site corrects the issue in PumpIT or somebody releases it with a reason.
    </x-slot:info>

    @if ($cases->isEmpty())
        <x-empty-state style="margin:12px 14px"
                       text="No duplicate issues on this run: no shift is short by an issue that was keyed again on the shift beside it." />
    @else
        <x-statstrip :stats="[
            ['label' => 'Clear (D1)', 'value' => Format::n($clear),
             'note' => (int) ($totals->ClearCorrected ?? 0) > 0
                 ? Format::r($totals->ClearValue).' not charged'
                 : ($clear > 0 ? 'none taken out on this run' : null),
             'tone' => (int) ($totals->ClearCorrected ?? 0) > 0 ? 'good' : ($clear > 0 ? 'warn' : 'neutral')],
            ['label' => 'Possible (D2)', 'value' => Format::n($possible),
             'note' => $possible > 0 ? Format::r(abs((float) $totals->PossibleValue)).' short as counted' : null,
             'tone' => $possible > 0 ? 'serious' : 'neutral'],
            ['label' => 'Chains held back', 'value' => Format::n($held),
             'note' => (int) ($totals->PossibleReleased ?? 0) > 0 ? Format::n($totals->PossibleReleased).' released' : null,
             'tone' => $held > 0 ? 'serious' : 'neutral'],
        ]" />

        <x-tabs chips :items="$items" :active="$active" query="dup" label="Duplicate issues on this run">
            @foreach ($cases as $case)
                <x-tab-panel :key="'dup-'.$case->LineId" class="dup-pane">
                    @include('stockrecon::partials.duplicate-case', ['case' => $case, 'procedure' => $duplicates['procedure']])
                </x-tab-panel>
            @endforeach
        </x-tabs>
    @endif
</x-card>
