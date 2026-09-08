{{--
    Tab two: manual match.

    Automatic reconciliation proposes what a configured rule can reach. This is
    the other half of the same job — a reference typed wrong at the till, a
    deposit banked under a colleague's slip, a batch split across two days.
    None of those has a rule and none ever will, and today they sit on the
    estate forever.

    A match made here goes through agora.usp_Recon_ManualMatch, which writes
    the same run, batch, per-side match and stamp an automatic one writes. So
    it is reversed by exactly the same path, appears on the same run page, and
    is found by the same trace.
--}}
@php($chosen = ($branchId ?? 0) > 0)

@if (session('refusal'))
    <x-notice tone="stop" title="Nothing was matched" style="margin-bottom:16px">
        <p>{{ session('refusal') }}</p>
    </x-notice>
@endif

<x-card title="What to pair"
        sub="The site and the period. Both sides are read live — nothing here is a stored preview.">
    <form method="GET" action="{{ route('app.recon.match', $area['key']) }}">
        <div class="field-row">
            @if ($pinned)
                <div class="field">
                    <label>Site</label>
                    <p class="field-fixed">{{ $branches->firstWhere('BranchId', $branchId)?->Name ?? 'Not set' }}</p>
                    <p class="field-help">Your workspace is pinned to this site.</p>
                </div>
                <input type="hidden" name="branch_id" value="{{ $branchId }}">
            @else
                <x-field name="branch_id" label="Site"
                         :choices="['' => 'Choose a site'] + $branches->pluck('Name', 'BranchId')->all()"
                         :value="$branchId ?: ''"
                         help="Pairing is always within one site — a deposit at one branch cannot settle another's statement." />
            @endif

            <x-field name="from" label="From" type="date" :value="$from" />
            <x-field name="to" label="To" type="date" :value="$to" />

            <x-field name="state" label="Show"
                     :choices="['outstanding' => 'Still outstanding', 'reconciled' => 'Already reconciled', 'all' => 'Everything']"
                     :value="$state ?? 'outstanding'"
                     help="Outstanding is what you pair. The other two are for checking a figure." />
        </div>

        <div class="form-actions">
            <button type="submit" class="btn-primary">Show both sides</button>
            <span class="field-help">Read-only. Nothing is written until you match.</span>
        </div>
    </form>
</x-card>

@if (! $chosen)
    <x-card title="Manual match" sub="Pick a site above">
        <x-empty-state title="Choose a site first">
            <p>Pairing is always within one site. An empty pair of lists before a site is chosen would
               read as “this site is clean”, which is a different answer from “you have not said which
               site”.</p>
        </x-empty-state>
    </x-card>
@else
    @if (($summary->HasCriteria ?? 1) == 0)
        {{-- On the live system twenty-four of twenty-six branches have no
             usable rule (finding 1). A preview must refuse in that case; this
             screen must NOT — pairing by hand is precisely what a site with no
             configuration needs. But it has to say so, or the missing colours
             look like an absence of matches. --}}
        <x-notice tone="warn" title="This site has no extraction rule configured" style="margin-bottom:16px">
            <p>Both sides are still listed and can still be paired by hand — that is what this screen is
               for. What is missing is the colour: with no <code>BRN_AutoReconCriteria</code> row there is
               nothing to extract a reference from on the bank side, so no pair can be highlighted for
               you. Automatic reconciliation cannot run here at all until the configuration exists.</p>
        </x-notice>
    @endif

    <x-card :title="'Pair by hand — '.$area['label']"
            sub="Coloured where the same reference appears on both sides. Tick both sides, then match."
            flush>
        <form method="POST" action="{{ route('app.recon.match.save', $area['key']) }}" id="manual-match"
              data-confirm="{{ $stampMode === 'live' ? 'Reconcile these rows in PumpIT?' : 'Record this match?' }}"
              data-confirm-text="{{ $stampMode === 'live'
                  ? 'This stamps ReconState and ReconBatchNo on the ticked bank lines and ReconBatchNoPumpIT on the ticked deposits, in the customer\'s live database. Every row is re-read and re-checked first, and exactly as many rows are claimed as you ticked. It can be reversed from the run page it takes you to.'
                  : 'Journal mode: the match is recorded here and nothing in PumpIT changes.' }}"
              data-confirm-action="{{ $stampMode === 'live' ? 'Match' : 'Record' }}"
              @if ($stampMode === 'live') data-confirm-danger @endif>
            @csrf
            <input type="hidden" name="branch_id" value="{{ $branchId }}">
            <input type="hidden" name="from" value="{{ $from }}">
            <input type="hidden" name="to" value="{{ $to }}">

            {{-- The running answer. Two totals and the difference between them,
                 updated as boxes are ticked, because the question "do these
                 two sides agree" is the only question this screen asks and
                 making somebody add up two columns to answer it would be
                 absurd. --}}
            <x-statstrip data-match-totals :stats="[
                ['label' => 'Selected on the bank', 'value' => 'R0.00', 'note' => '0 lines'],
                ['label' => 'Selected deposits', 'value' => 'R0.00', 'note' => '0 rows'],
                ['label' => 'Difference', 'value' => 'R0.00', 'note' => 'deposit less bank'],
                ['label' => 'References paired', 'value' => \App\Support\Format::n($summary->ColouredKeys ?? 0),
                 'note' => 'appear on both sides', 'tone' => ($summary->ColouredKeys ?? 0) > 0 ? 'good' : 'neutral'],
            ]" />

            <x-two-pane-recon :bank="$bank" :mops="$mops" :summary="$summary"
                              :key-label="$area['key_label']" />

            <footer class="run-actions">
                <div class="field" data-match-reason hidden>
                    <label for="match-reason">Why are these being matched despite the difference?</label>
                    <input type="text" id="match-reason" name="reason" maxlength="300"
                           placeholder="A bank charge deducted at source, a short banking, a split deposit…">
                    <p class="field-help">Required the moment the two sides do not agree. It is the only
                       record of the decision, and the variance travels with the batch wherever it is
                       shown afterwards.</p>
                </div>

                <button type="submit" class="btn-primary" data-match-submit disabled>
                    {{ $stampMode === 'live' ? 'Match' : 'Record match' }}
                </button>
                <p class="field-help">
                    @if ($stampMode === 'live')
                        Stamps both sides in PumpIT and allocates a batch number from the customer's own
                        counter. Every row is re-read first: anything reconciled by something else since
                        this screen was drawn is refused rather than stamped over, and exactly as many
                        rows are claimed as you ticked — never all the ones that look alike. Reversible
                        from the run it takes you to.
                    @else
                        <strong>Journal mode.</strong> The match is recorded here and nothing in PumpIT
                        changes.
                    @endif
                </p>
            </footer>
        </form>
    </x-card>
@endif
