{{--
    The bag: dropped new, or corrected while it is still in the safe and on no
    cashup. One form, on the desk and on the phone, so the two cannot ask for
    different things.

    WHAT IT ASKS FOR is what PumpIT's drop screen asks for, in its order: the
    number printed on the bag, when it was dropped, how much is in it, why it
    went into the manual safe (and the fault reference where that reason needs
    one), the reason for a bag over the threshold, the cashier and the manager.

    Nothing here is `required` that is a rule rather than a shape: the manager,
    the reason over the threshold and the fault reference are the procedure's
    to insist on, and it refuses by name — which is what the person sees, as a
    toast and a notice. A browser's "please fill in this field" would say
    something different from the procedure, on one screen only.

    Props: $scope, $from ('desk'|'phone'), $editing (the bag being corrected,
           or null), $reasons, $limitReasons, $employees, $summary, $threshold
--}}
@php
    $now = $summary?->ServerNow ? \Illuminate\Support\Carbon::parse($summary->ServerNow) : now();
    $isEdit = $editing !== null;
    $val = fn (string $field, $fromBag, $default = null) => old($field, $isEdit ? $fromBag : $default);
    $reasonChoices = ['' => 'Choose why'] + $reasons
        ->filter(fn ($r) => (bool) $r->IsActive || ($isEdit && (int) $r->ReasonForManualBagId === (int) $editing->ReasonForManualBagId))
        ->mapWithKeys(fn ($r) => [$r->ReasonForManualBagId => $r->ReasonForManualBag.((bool) $r->IsRefNoRequired ? ' — needs a fault reference' : '')])
        ->all();
    $people = $employees->mapWithKeys(fn ($e) => [$e->EmployeeCode => trim(($e->EmployeeName ?: $e->EmployeeCode).' · '.$e->EmployeeCode)])->all();
    $needsRef = $reasons->filter(fn ($r) => (bool) $r->IsRefNoRequired && (bool) $r->IsActive)->pluck('ReasonForManualBag')->implode(', ');
    $limit = \App\Support\Format::r($threshold);
    $listId = 'dropsafe-limit-reasons-'.$from;
@endphp

<form method="POST" action="{{ $isEdit ? route('app.cash.dropsafe.update') : route('app.cash.dropsafe.store') }}"
      id="dropsafe-{{ $isEdit ? 'edit' : 'drop' }}-{{ $from }}"
      data-confirm="{{ $isEdit ? 'Correct bag '.$editing->BagNo.' in PumpIT?' : 'Drop this bag into PumpIT\'s safe?' }}"
      data-confirm-text="{{ $isEdit
          ? 'The bag\'s row in PumpIT\'s drop safe is changed to what is on this form. What it held before is kept in Agora. Nothing is deleted.'
          : 'One row is written to PumpIT\'s drop safe, exactly as its own drop screen writes it. The bag stays in the safe until it is collected. Nothing is deleted.' }}"
      data-confirm-action="{{ $isEdit ? 'Save the correction' : 'Drop the bag' }}"
      data-loader="Writing to PumpIT…">
    @csrf
    <input type="hidden" name="from" value="{{ $from }}">
    @foreach ($scope->withBag(null)->query() as $name => $value)
        <input type="hidden" name="{{ $name }}" value="{{ $value }}">
    @endforeach
    @if ($isEdit)
        <input type="hidden" name="bag_no" value="{{ $editing->BagNo }}">
    @endif

    <x-params legend="{{ $isEdit ? 'Bag '.$editing->BagNo : 'The bag' }}">
        @if ($isEdit)
            <x-param name="new_bag_no" label="Bag number" :value="old('new_bag_no', $editing->BagNo)"
                     help="Correct a mistyped number here. It must not be used at this site already." />
        @else
            <x-param name="bag_no" label="Bag number" :value="old('bag_no')" placeholder="The number printed on the bag" />
        @endif
        <x-param name="drop_date" label="Dropped on" type="date"
                 :value="$val('drop_date', $isEdit ? substr((string) $editing->DropDate, 0, 10) : null, $now->toDateString())"
                 :max="$now->toDateString()" />
        <x-param name="drop_time" label="At" type="time" step="1"
                 :value="$val('drop_time', $editing?->DropTime, $now->format('H:i:s'))" />
        <x-param name="amount" label="Amount (R)" :value="$val('amount', $isEdit ? number_format((float) $editing->Amount, 2, '.', '') : null)"
                 placeholder="e.g. 2500.00" />
        <x-param name="reason" label="Why the manual safe" :choices="$reasonChoices"
                 :value="$val('reason', $editing?->ReasonForManualBagId)" />
        <x-param name="ref_no" label="Fault reference" :value="$val('ref_no', $editing?->RefNoFaultReported)"
                 :help="$needsRef !== '' ? 'Needed for: '.$needsRef.'.' : null" />
        <x-param name="limit_reason" label="Reason over the limit" help="Needed for a bag over {{ $limit }} — pick one, or type your own.">
            <input type="text" id="p-limit_reason" name="limit_reason" maxlength="50" list="{{ $listId }}"
                   value="{{ $val('limit_reason', $editing?->LimitReason) }}" placeholder="Only over {{ $limit }}">
            <datalist id="{{ $listId }}">
                @foreach ($limitReasons as $r)
                    <option value="{{ $r->LimitReason }}"></option>
                @endforeach
            </datalist>
        </x-param>
        <x-param name="cashier" label="Cashier" searchable :choices="['' => 'Choose the cashier'] + $people"
                 :value="$val('cashier', $editing?->CashierCode)" />
        <x-param name="manager" label="Manager" searchable :choices="['' => 'Choose the manager'] + $people"
                 :value="$val('manager', $editing?->ManagerCode)"
                 help="The manager who witnessed the drop — every bag needs one." />
        <x-param name="note" label="Note" :value="old('note')" placeholder="Optional — kept in Agora" />
    </x-params>

    <div class="form-actions">
        <button type="submit" class="btn primary">{{ $isEdit ? 'Save the correction' : 'Drop the bag' }}</button>
        @if ($isEdit)
            <a class="btn-ghost" href="{{ route($from === 'phone' ? 'app.cash.dropsafe.phone' : 'app.cash.dropsafe', $scope->withBag(null)->query()) }}">Cancel</a>
        @endif
    </div>
</form>
