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
@if (session('refusal'))
    <x-notice tone="stop" title="Nothing was matched" style="margin-bottom:16px">
        <p>{{ session('refusal') }}</p>
    </x-notice>
@endif

<x-card title="What to pair"
        sub="The site and the period. Both sides are read live — nothing here is a stored preview.">
    <form method="GET" action="{{ route('app.recon.match', $area['key']) }}" data-loader="Reading both sides…">
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

@include('recon::partials.match-pane')
