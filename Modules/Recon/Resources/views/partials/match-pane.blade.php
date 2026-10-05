{{--
    The manual match's two sides and its one press, without the form that
    asks which site.

    Rendered under the site-and-dates form on the standalone Manual match
    page, and on its own as the fragment the recon centre's Manual tab
    fetches — the "manual entries in the same load as the run" Ryan asked for.
    Inside the centre ($centre set) the match posts back to the centre, on this
    tab, with its scope.

    Expects the area frame, $bank, $mops, $summary, $state and optionally
    $centre.
--}}
@php($chosen = ($branchId ?? 0) > 0)

@if (! $chosen)
    <x-card title="Manual match">
        <x-empty-state title="Choose a site first">
            <p>Pairing is always within one site.</p>
        </x-empty-state>
    </x-card>
@else
    @if (($summary->HasCriteria ?? 1) == 0)
        {{-- On the live system twenty-four of twenty-six branches have no
             usable rule (finding 1). A preview must refuse in that case; this
             screen must NOT — pairing by hand is precisely what a site with no
             configuration needs. But it has to say so, or the missing colours
             look like an absence of matches. --}}
        <x-notice tone="warn" collapsible title="This site has no extraction rule configured" style="margin-bottom:16px">
            <p>Both sides can still be paired by hand. Nothing is coloured, because with no
               <code>BRN_AutoReconCriteria</code> row no reference can be read from the bank side, and
               automatic reconciliation cannot run here until one exists.</p>
        </x-notice>
    @endif

    <x-card :title="'Pair by hand — '.$area['label']" flush>
        <x-slot:info>
            <p>Rows are coloured where the same reference appears on both sides. Tick both sides, then match.</p>
            <p><strong>Bank less deposits</strong>: negative is money the bank did not receive.</p>
        </x-slot:info>
        <form method="POST" action="{{ route('app.recon.match.save', $area['key']) }}" id="manual-match"
              data-loader="{{ $stampMode === 'live' ? 'Matching in PumpIT…' : 'Recording the match…' }}"
              {{-- Both panes: a filter on either side takes rows out of the
                   same submission, so the dialog counts them together. --}}
              data-confirm-filtered="match-bank,match-mops"
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
            @include('recon::partials.centre-back')

            {{-- The running answer. Two totals and the difference between them,
                 updated as boxes are ticked, because the question "do these
                 two sides agree" is the only question this screen asks and
                 making somebody add up two columns to answer it would be
                 absurd. The difference is bank less deposits, the sign of the
                 split below it and of every recon screen (Ryan, 27 Sep 2026);
                 recon-match.js keeps it in step. --}}
            <x-statstrip data-match-totals :stats="[
                ['label' => 'Selected on the bank', 'value' => 'R0.00', 'note' => '0 lines'],
                ['label' => 'Selected deposits', 'value' => 'R0.00', 'note' => '0 rows'],
                ['label' => 'Bank less deposits', 'value' => 'R0.00', 'note' => 'the bank side less the deposits'],
                ['label' => 'References paired', 'value' => \App\Support\Format::n($summary->ColouredKeys ?? 0),
                 'tone' => ($summary->ColouredKeys ?? 0) > 0 ? 'good' : 'neutral'],
            ]" />

            {{-- The press, above the two lists it pairs. Both panes are as
                 tall as the window now that their heads stick, so a button
                 under them was a button nobody was going to scroll to. --}}
            <x-action-bar>
                <button type="submit" class="btn-primary" data-match-submit disabled>
                    {{ $stampMode === 'live' ? 'Match' : 'Record match' }}
                </button>

                {{-- Appears the moment the two sides stop agreeing, which is
                     also the moment the procedure starts refusing without it.
                     It belongs beside the button it gates, not under the
                     lists. --}}
                <div class="field" data-match-reason hidden>
                    <div class="field-label">
                        <label for="match-reason">Why are these being matched despite the difference?</label>
                        <x-info label="About the reason">
                            <p>Required whenever the two sides do not agree. It is the only record of the
                               decision, and the variance travels with the batch wherever it is shown.</p>
                            @if ($area['key'] === 'FNB' && $stampMode === 'live' && config('recon.fnb_adjust_mode') === 'live')
                                {{-- usp_Recon_ManualMatch → THE FNB ADJUSTMENT (5 Oct 2026). --}}
                                <p><strong>On FNB the difference is written to the deposits</strong>, as PumpIT's
                                   own forced batch does: each ticked deposit's amount moves by its share (shared by
                                   amount) and Adjusted By records it. The bank stays as it is.</p>
                            @endif
                        </x-info>
                    </div>
                    {{-- 200: agora.ReconRunLine.BlockReason, where a forced match's reason
                         is kept. 300 let a longer one through to a truncation
                         error in the procedure. --}}
                    <input type="text" id="match-reason" name="reason" maxlength="200"
                           placeholder="A bank charge deducted at source, a short banking, a split deposit…">
                </div>

                <x-info label="What this press does">
                    @if ($stampMode === 'live')
                        <p>Stamps both sides in PumpIT under a batch number from the customer's own counter.
                           Every row is re-read first: anything reconciled elsewhere since this screen was
                           drawn is refused, and exactly as many rows are claimed as you ticked.</p>
                        <p>Reversible from the run it takes you to.</p>
                    @else
                        <p><strong>Journal mode:</strong> recorded in Agora only; nothing in PumpIT changes.</p>
                    @endif
                </x-info>
            </x-action-bar>

            <x-two-pane-recon :bank="$bank" :mops="$mops" :summary="$summary"
                              :key-label="$area['key_label']" />
        </form>
    </x-card>
@endif
