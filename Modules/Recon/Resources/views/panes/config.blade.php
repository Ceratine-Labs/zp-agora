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
            <p>Every preview and both drills use this from now on. The customer's
               <code>BRN_AutoReconCriteria</code> is unchanged; switching the override off reverts it.</p>
        @endif
    </x-notice>
@endif

{{-- What the two views of the grid mean is behind the info button; which one
     is showing is the tab strip beside the title. --}}
<x-card title="Extraction configuration" flush info-wide>
    <x-slot:info>
        <p>Each rule shows what is in force beside what the customer's own table says. Click a rule to change
           it: an override lives in Agora and the customer's table is never written.</p>
        <p><strong>Positions only</strong> shows where each rule reads. <strong>Check the narratives</strong>
           reads the site's real bank narratives to show whether a rule reaches anything — a query across the
           live statement table, so it runs only when asked.</p>
    </x-slot:info>
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

    <x-data-grid :grid="$grid" :branches="$pinned ? null : $branches" />
</x-card>

@can('recon.criteria.edit')
    <x-modal id="copy-config" title="Copy a site's configuration">
        <form method="POST" action="{{ route('app.recon.config.copy', $area['key']) }}">
            @csrf

            <div class="field-row">
                <x-field name="from_branch_id" label="Copy from"
                         :choices="$branches->pluck('Name', 'BranchId')->all()"
                         :value="$branchId ?: ''"
                         info="Copies what is in force at the source — the customer's rules or Agora's overrides, whichever resolves there." />
                <x-field name="to_branch_id" label="Copy to"
                         :choices="$branches->pluck('Name', 'BranchId')->all()" />
            </div>

            <x-field name="reason" label="Why" required
                     info="Kept on every override the copy makes." />

            <div class="field-row">
                <x-field name="all_areas" label="All five areas" type="checkbox"
                         help="Off copies this area only." />
                <x-field name="overwrite" label="Replace overrides the target already has" type="checkbox"
                         info="Off leaves them alone — a rule somebody set deliberately outranks one copied in bulk." />
            </div>

            <div class="form-actions">
                <button type="submit" name="apply" value="0" class="btn-ghost">Show me what it would do</button>
                <button type="submit" name="apply" value="1" class="btn-primary">Copy it</button>
            </div>
        </form>
    </x-modal>
@endcan
