{{--
    The Suggestions tab: what the batch number could not pair, proposed by
    value.

    The step between the other two. Auto reconciliation settles what a batch
    number can reach; this proposes pairings for what is left — mostly FNB's
    'FN' lines, which carry no batch number at all — and the manual match is
    for whatever neither can reach.

    Everything is worked out in agora.usp_Recon_SuggestMatches, read live on
    every visit: the estate moves, and every accepted suggestion changes what
    is left. Accepting one posts it to the same route, the same procedure and
    the same ledger as a match made by hand, with its reading recorded on the
    run — so it is reversed by the same path and found by the same trace.

    STRONG means nothing else wants any of its rows. POSSIBLE means something
    does, or the batch number on the bank line disagrees; the row says which.
    Each tier has its own one-press "match all" (possible added on Ryan's ask,
    23 Sep 2026, looking at Ngwenya, where the till files deposits under a
    different merchant and batch numbering from the bank's, so every tie there
    is possible). They are two buttons, never one: the possible press is the
    weaker claim and its confirmation says so, and the run it writes records
    that the suggestion was a possible one and why.

    CLOSE is the third tier (23 Sep 2026): what the exact two left, a few rand
    off — sites 8, 23, 25 and 26 bank days that never tie to the cent. A close
    match is a forced match, so each row carries its own reason box and the
    procedure refuses it without one. Its "Force all close" press (Ryan, the
    same day: "yes to match all") asks for one reason in its dialog and posts
    it with every close row on screen; a row with its own reason keeps it.
--}}
@if (session('suggestMatched'))
    <x-notice tone="info" title="Matched" style="margin-bottom:16px">
        <p>{{ session('suggestMatched') }}
           @if (session('suggestRun'))<a href="{{ session('suggestRun') }}">Open the run</a> — it can be reversed from there.@endif</p>
    </x-notice>
@endif

@if (session('suggestRefusal'))
    <x-notice tone="stop" title="Nothing was matched" style="margin-bottom:16px">
        <p>{{ session('suggestRefusal') }}</p>
    </x-notice>
@endif

<x-card title="What to suggest for"
        sub="The site and the period. Both sides are read live, and nothing is written until a suggestion is accepted.">
    <form method="GET" action="{{ route('app.recon.suggest', $area['key']) }}" data-loader="Working out suggestions…">
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
                         help="Suggestions are always within one site." />
            @endif

            <x-field name="from" label="From" type="date" :value="$from"
                     help="Bank line dates. Deposits are read from a few days earlier, because a line settles takings from before it." />
            <x-field name="to" label="To" type="date" :value="$to" />
        </div>

        <div class="form-actions">
            <button type="submit" class="btn-primary">Suggest matches</button>
            <span class="field-help">Read-only. Takes a few seconds on a busy month.</span>
        </div>
    </form>
</x-card>

@include('recon::partials.suggest-results')
