{{--
    One extraction rule, as the modal opens onto it.

    THE CUSTOMER'S ROW IS ALWAYS ON SCREEN, beside the override. That is the
    accepted cost of the design: Agora shadows BRN_AutoReconCriteria rather
    than writing it, so the configuration has two sources of truth and ZP can
    still edit theirs in SSMS without telling us. A form that showed only the
    effective value would make that divergence invisible.

    Expects: $area, $branch, $branchId, $order, $override, $legacy, $effective,
             $mayEdit.
--}}
@php
    $live = $override && $override->IsActive;
    $fields = [
        'BankStartPosition'   => ['Bank from',      'BANK_StartPosition'],
        'BankEndPosition'     => ['Bank to / len',  'BANK_EndPosition'],
        'BankStartPosition2'  => ['Second from',    'BANK_StartPosition2'],
        'BankEndPosition2'    => ['Second to / len','BANK_EndPosition2'],
        'MopsStartPosition'   => ['Deposit from',   'MOPS_StartPosition'],
        'MopsEndPosition'     => ['Deposit to / len','MOPS_EndPosition'],
        'FilterStartPosition' => ['Filter from',    'FILTER_StartPosition'],
        'FilterEndPosition'   => ['Filter to / len','FILTER_EndPosition'],
    ];
@endphp

<p class="field-help" style="margin-top:0">
    {{ $branch?->Name ?? 'Site '.$branchId }} · {{ $area['label'] }} · rule {{ $order }}.
    Read by <code>agora.{{ $area['procedure'] }}</code> and both drills.
</p>

<x-compare :live="$live ? 'right' : 'left'"
           :left-title="'The customer\'s row'.($live ? '' : ' — in force')"
           :right-title="'Agora\'s override'.($live ? ' — in force' : ($override ? ' — parked' : ' — none yet'))">
    <x-slot:left>
        @if ($legacy)
            <dl>
                <dt>Rule id</dt><dd>{{ $legacy->AutoReconId }}</dd>
                <dt>Bank</dt><dd>{{ $legacy->BANK_StartPosition ?? '—' }} / {{ $legacy->BANK_EndPosition ?? '—' }}</dd>
                <dt>Second</dt><dd>{{ $legacy->BANK_StartPosition2 ?? '—' }} / {{ $legacy->BANK_EndPosition2 ?? '—' }}</dd>
                <dt>Deposit</dt><dd>{{ $legacy->MOPS_StartPosition ?? '—' }} / {{ $legacy->MOPS_EndPosition ?? '—' }}</dd>
                <dt>Filter</dt><dd>{{ $legacy->FILTER_Value ?? '—' }}</dd>
                <dt>Filter at</dt><dd>{{ $legacy->FILTER_StartPosition ?? '—' }} / {{ $legacy->FILTER_EndPosition ?? '—' }}</dd>
            </dl>
        @else
            {{-- Twenty-four of twenty-six branches are in this position for at
                 least one area. It is finding 1, and it is why an override may
                 ADD a rule rather than only correct one. --}}
            <p class="field-help">This site has no rule of its own for this area. Without an override
               its previews can only refuse.</p>
        @endif
    </x-slot:left>

    <x-slot:right>
        @if ($override)
            <dl>
                <dt>Bank</dt><dd>{{ $override->BANK_StartPosition ?? '—' }} / {{ $override->BANK_EndPosition ?? '—' }}</dd>
                <dt>Resolves to</dt><dd>{{ $override->resolvedLength() ?? '—' }} characters</dd>
                <dt>Deposit</dt><dd>{{ $override->MOPS_StartPosition ?? '—' }} / {{ $override->MOPS_EndPosition ?? '—' }}</dd>
                <dt>Filter</dt><dd>{{ $override->FILTER_Value ?? '—' }}</dd>
                <dt>Why</dt><dd>{{ $override->Reason ?? '—' }}</dd>
                <dt>Changed</dt><dd>{{ $override->UpdatedAt?->diffForHumans() ?? $override->CreatedAt?->diffForHumans() ?? '—' }}</dd>
            </dl>
        @else
            <p class="field-help">Nothing here yet. Saving below creates one; it takes effect for every
               preview and both drills the moment it does.</p>
        @endif
    </x-slot:right>
</x-compare>

@unless ($mayEdit)
    <x-notice tone="info" title="You may look, not change">
        <p>Changing an extraction rule decides which bank lines reconcile against which deposits across a
           whole site, so it sits behind <code>recon.criteria.edit</code> — Finance and Admin.</p>
    </x-notice>
@else
    <form method="POST" action="{{ route('app.recon.config.save', $area['key']) }}">
        @csrf
        <input type="hidden" name="branch_id" value="{{ $branchId }}">
        <input type="hidden" name="process_order" value="{{ $order }}">

        <div class="field-row">
            @foreach ($fields as $name => [$label, $column])
                <x-field :name="$name" :label="$label" type="number" min="0"
                         :value="old($name, $override?->{$column} ?? $legacy?->{$column})" />
            @endforeach

            <x-field name="FilterValue" label="Filter value"
                     :value="old('FilterValue', $override?->FILTER_Value ?? $legacy?->FILTER_Value)"
                     help="The narrative slice a rule only applies to. Blank means it applies to every line." />
        </div>

        <x-field name="reason" label="Why are you changing this?" required
                 help="Kept with the override for good. A configuration change with no reason is what the legacy estate is full of." />

        <p class="field-help">
            An override <strong>replaces</strong> the customer's rule rather than patching it, so every
            position above is part of it — a box left blank means this rule has no such position, not
            &ldquo;leave what was there&rdquo;.
        </p>

        <div class="form-actions">
            <button type="submit" name="action" value="save" class="btn-primary">
                {{ $override ? 'Save the override' : 'Create the override' }}
            </button>

            @if ($override && $override->IsActive)
                <button type="submit" name="action" value="park" class="btn-ghost"
                        formnovalidate>Switch it off</button>
            @elseif ($override)
                <button type="submit" name="action" value="unpark" class="btn-ghost"
                        formnovalidate>Switch it back on</button>
            @endif
        </div>
    </form>
@endunless
