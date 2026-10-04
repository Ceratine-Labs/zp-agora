{{--
    Raise a staff short. One form, on the desk and on the phone, so the two
    cannot ask for different things.

    WHAT IT ASKS FOR: who the short is against (the site's employee list), how
    much, the day of the shift it came from, the reason — one of four codes, not
    free text, so "pump short" is one thing however it would have been spelt —
    and a free note. The till and shift of the cashup it belongs to are
    optional: given, Post offers that cashup first.

    Nothing here is `required` that is a rule rather than a shape: the note on
    "other", the person being on the list, the day having happened are the
    procedure's to insist on, and it refuses by name.

    Props: $scope, $from ('desk'|'phone'), $reasons, $employees, $tills, $shifts,
           $summary
--}}
@php
    $now = $summary?->ServerNow ? \Illuminate\Support\Carbon::parse($summary->ServerNow) : now();
    $people = $employees->mapWithKeys(fn ($e) => [$e->EmployeeCode => trim(($e->EmployeeName ?: $e->EmployeeCode).' · '.$e->EmployeeCode)
        .((bool) $e->IsActive ? '' : ' (inactive)')])->all();
    $reasonChoices = ['' => 'Choose why'] + $reasons->mapWithKeys(fn ($r) => [$r->Code => $r->Label.((bool) $r->NeedsNote ? ' — say what in the note' : '')])->all();
    $tillChoices = ['' => 'Not said'] + $tills->mapWithKeys(fn ($t) => [$t->TillNo => $t->TillNo.' · '.($t->TillDescription ?: 'till')])->all();
    $shiftChoices = ['' => 'Not said'] + $shifts->mapWithKeys(fn ($s) => [$s->ShiftNo => $s->ShiftNo.' · '.($s->ShiftDescription ?: 'shift')])->all();
@endphp

<form method="POST" action="{{ route('app.cash.staffshorts.store') }}" id="staffshort-raise-{{ $from }}"
      data-confirm="Raise this staff short in PumpIT?"
      data-confirm-text="One staff short is written to PumpIT under the next staff-short number, waiting for an approver, and the approvers are emailed. Nothing is charged until it is approved and posted to a cashup."
      data-confirm-action="Raise the short"
      data-loader="Writing to PumpIT…">
    @csrf
    <input type="hidden" name="from" value="{{ $from }}">
    @foreach ($scope->withShort(null)->query() as $name => $value)
        <input type="hidden" name="{{ $name }}" value="{{ $value }}">
    @endforeach

    <x-params legend="The short">
        <x-param name="employee" label="Against" searchable :choices="['' => 'Choose the person'] + $people"
                 :value="old('employee')" help="The person answerable for it." />
        <x-param name="amount" label="Amount (R)" :value="old('amount')" placeholder="e.g. 120.50" />
        <x-param name="day" label="Day of the shift" type="date" :value="old('day', $now->toDateString())"
                 :max="$now->toDateString()" />
        <x-param name="reason" label="Reason" :choices="$reasonChoices" :value="old('reason')" />
        <x-param name="note" label="Note" :value="old('note')" placeholder="What happened — kept with the short" />
        <x-param name="till" label="Cashup till" :choices="$tillChoices" :value="old('till')"
                 help="Optional — offered first when posting." />
        <x-param name="shift" label="Cashup shift" :choices="$shiftChoices" :value="old('shift')" />
    </x-params>

    <div class="form-actions">
        <button type="submit" class="btn primary">Raise the short</button>
    </div>
</form>
