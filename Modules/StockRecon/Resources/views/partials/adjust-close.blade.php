{{--
    The adjust modal's body (Ryan, 16 Nov 2026: "per detail line ... a pencil,
    and it shows a modal which asks for a reason, and then it allows the user
    to implement"; that afternoon: "adjust opening, closing and issue values...
    not just closing"). One shift's opening, issue and/or closing, set by hand
    with a reason, through agora.usp_StockRecon_AdjustShift. It changes the run
    only: the opening moves the previous shift's closing, the closing the next
    shift's opening, the issue is this shift's own. Everything it touches is
    ticked, and the Amend press writes it to PumpIT like any other amendment,
    priors kept, reversible. A field left as proposed is not changed.

    Props: $run, $line (StockReconRunLine), $firstShift, $lastShift (bool:
    the chain's first or last shift in this run)
--}}
@php
    $day = $line->TransactionDate->format('D j M');
    $shift = \Modules\StockRecon\Support\ShiftLabel::show($line->ShiftNo);
    $q = fn ($v) => \App\Support\Format::qty($v);
    // A number input needs a plain figure: Format::n's thousands space empties it from 1 000 up.
    $n = fn ($v) => $v === null ? '' : rtrim(rtrim(number_format((float) $v, 3, '.', ''), '0'), '.');
    $issued = $line->QtyIssuedNew ?? $line->QtyIssued;
@endphp
<form method="POST" action="{{ route('app.stockrecon.line.adjust', [$run, $line]) }}" class="adjust-close"
      data-loader="Adjusting the shift…">
    @csrf
    <p><strong>{{ $line->ItemDescription ?? $line->StockItemNo }}</strong>
        <span class="muted">· {{ $line->AreaDescription ?? 'area '.$line->AreaNo }} · {{ $day }}, shift {{ $shift }}</span></p>

    <x-facts :columns="4">
        <x-fact label="Counted opening" :value="$q($line->QtyOpen)" />
        <x-fact label="Issued in PumpIT" :value="$q($line->QtyIssued)" />
        <x-fact label="Counted closing" :value="$q($line->QtyClose)" />
        <x-fact label="POS" :value="$q($line->QtyPOS)" />
    </x-facts>

    <p class="muted">The fields start at what Agora proposes. Change one or more; a field left as it is stays as proposed.</p>

    @if ($firstShift)
        <x-field name="open" label="Opening" type="number" step="0.001" min="0" :value="$n($line->QtyOpenNew)" :readonly="true"
                 help="The chain's first shift: its opening is the closing of a count before this run. Preview a longer period to change it." />
    @else
        <x-field name="open" label="Opening" type="number" step="0.001" min="0" :value="$n($line->QtyOpenNew)"
                 help="The shift before closes at the same figure, so the chain still joins." />
    @endif

    <x-field name="issued" label="Issued" type="number" step="0.001" min="0" :value="$n($issued)"
             help="This shift only. Amend writes it to PumpIT's issue." />

    @if ($lastShift)
        <x-field name="close" label="Closing" type="number" step="0.001" min="0" :value="$n($line->QtyCloseNew)" :readonly="true"
                 help="The chain's last shift: its closing is the next count's opening, after this run. Preview a longer period to change it." />
    @else
        <x-field name="close" label="Closing" type="number" step="0.001" min="0" :value="$n($line->QtyCloseNew)"
                 help="What the shift really closed on. The next shift's opening follows it." />
    @endif

    <x-field name="reason" label="Reason" placeholder="Why the shift is changed, for the audit trail"
             help="At least five characters. Kept with the adjustment: who, when and why." />

    <p class="muted">Nothing reaches PumpIT yet. The shifts that change are ticked on the run; press Amend to write them.</p>

    <div class="adjust-close-actions">
        <button type="submit" class="btn-primary">Adjust the shift</button>
    </div>
</form>
