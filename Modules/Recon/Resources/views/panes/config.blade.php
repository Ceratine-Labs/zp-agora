{{--
    Tab five: the extraction configuration.

    What the previews resolve against, per site, with the customer's own values
    beside the effective ones — because Agora shadows BRN_AutoReconCriteria
    rather than writing it, and ZP can still edit theirs in SSMS without
    telling us. A screen that showed only what is in force would make that
    divergence invisible, which is the failure this module exists to end.
--}}
@if (session('refusal'))
    <x-notice tone="stop" title="Nothing was changed" style="margin-bottom:16px">
        <p>{{ session('refusal') }}</p>
    </x-notice>
@endif

@if (session('configured'))
    <x-notice :tone="session('copyApplied') === false ? 'info' : 'info'"
              :title="session('configured')" style="margin-bottom:16px">
        @php($plan = collect(session('copyPlan', [])))
        @if ($plan->isNotEmpty())
            <p>Rule by rule:</p>
            <ul>
                @foreach ($plan as $row)
                    <li>
                        <code>{{ $row['BankReconArea'] }} · {{ $row['ProcessOrder'] }}</code> —
                        <strong>{{ $row['Verdict'] }}</strong>:
                        {{ $row['BankStart'] ?? '—' }} / {{ $row['BankEnd'] ?? '—' }}
                        @if ($row['TargetSource'] !== 'nothing')
                            (was {{ $row['TargetBankStart'] ?? '—' }} / {{ $row['TargetBankEnd'] ?? '—' }},
                            {{ $row['TargetSource'] }})
                        @else
                            (the site had nothing here)
                        @endif
                    </li>
                @endforeach
            </ul>
        @else
            <p>Every preview and both drills resolve against this from now on. Nothing was written to the
               customer's <code>BRN_AutoReconCriteria</code> — reverting is switching the override off.</p>
        @endif
    </x-notice>
@endif

<x-card title="Extraction configuration"
        sub="What is in force, and what the customer's own table says. Click a rule to change it."
        flush>
    <x-slot:actions>
        {{-- The narrative check is a read across the customer's 249 GB
             statement table, so the screen asks rather than doing it on every
             load. It is also the single most useful thing here — it is how
             finding 11 would have been visible at a glance. --}}
        <x-tabs label="Check against real narratives"
                :active="$checked ? 'on' : 'off'"
                :items="[
                    ['key' => 'off', 'label' => 'Positions only', 'href' => route('app.recon.config', $area['key'])],
                    ['key' => 'on', 'label' => 'Check the narratives', 'href' => route('app.recon.config', [$area['key'], 'check' => 1])],
                ]" />

        @can('recon.criteria.edit')
            <button type="button" class="btn-ghost" data-modal-open="copy-config">Copy to a site</button>
        @endcan
    </x-slot:actions>

    @unless ($checked)
        <p class="field-help" style="margin:12px 14px 0">
            <strong>Positions only.</strong> Whether a rule actually reaches anything can only be answered
            by reading the site's real narratives, which is a query across the customer's live statement
            table — so it is a button rather than something this page does every time it loads. Branch 7's
            CashMachine rule reads from position 43 of 32-character narratives, and that is what
            <em>Check the narratives</em> says out loud.
        </p>
    @endunless

    <x-data-grid :grid="$grid" :branches="$pinned ? null : $branches" />
</x-card>

{{-- One dialog for the whole tab: the row that opened it comes in through the
     fetched body, so a rule is never edited from a form that was rendered
     before somebody else changed it. --}}
<x-modal id="rule-editor" title="Extraction rule" wide>
    <p class="muted">Pick a rule from the table.</p>
</x-modal>

@can('recon.criteria.edit')
    <x-modal id="copy-config" title="Copy a site's configuration">
        <form method="POST" action="{{ route('app.recon.config.copy', $area['key']) }}">
            @csrf
            <p class="field-help" style="margin-top:0">
                It takes what is IN FORCE at the source — the customer's rules, Agora's overrides,
                whichever is actually resolving there — so a site whose configuration is entirely the
                customer's own is a perfectly good source, and usually is.
            </p>

            <div class="field-row">
                <x-field name="from_branch_id" label="Copy from"
                         :choices="$branches->pluck('Name', 'BranchId')->all()"
                         :value="$branchId ?: ''" />
                <x-field name="to_branch_id" label="Copy to"
                         :choices="$branches->pluck('Name', 'BranchId')->all()" />
            </div>

            <x-field name="reason" label="Why" required
                     help="Kept on every override the copy makes." />

            <div class="field-row">
                <x-field name="all_areas" label="All five areas" type="checkbox"
                         help="Off copies this area only." />
                <x-field name="overwrite" label="Replace overrides the target already has" type="checkbox"
                         help="Off leaves them alone — a rule somebody set deliberately outranks one copied in bulk." />
            </div>

            <div class="form-actions">
                <button type="submit" name="apply" value="0" class="btn-ghost">Show me what it would do</button>
                <button type="submit" name="apply" value="1" class="btn-primary">Copy it</button>
            </div>
        </form>
    </x-modal>
@endcan
