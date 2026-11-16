{{--
    The adjust modal's body (Ryan, 16 Nov 2026: "per detail line ... a pencil,
    and it shows a modal which asks for a reason, and then it allows the user
    to implement"). One shift's closing, set by hand with a reason, through
    agora.usp_StockRecon_AdjustClose. It changes the run only: the next shift's
    opening follows, both are ticked, and the Amend press writes them to
    PumpIT like any other amendment, priors kept, reversible.

    Props: $run, $line (StockReconRunLine)
--}}
@php
    $day = $line->TransactionDate->format('D j M');
    $shift = \Modules\StockRecon\Support\ShiftLabel::show($line->ShiftNo);
    $q = fn ($v) => \App\Support\Format::qty($v);
@endphp
<form method="POST" action="{{ route('app.stockrecon.line.adjust', [$run, $line]) }}" class="adjust-close"
      data-loader="Adjusting the closing…">
    @csrf
    <p><strong>{{ $line->ItemDescription ?? $line->StockItemNo }}</strong>
        <span class="muted">· {{ $line->AreaDescription ?? 'area '.$line->AreaNo }} · {{ $day }}, shift {{ $shift }}</span></p>

    <x-facts :columns="3">
        <x-fact label="Opening" :value="$q($line->QtyOpenNew)" />
        <x-fact label="Issued" :value="$q($line->QtyIssuedNew ?? $line->QtyIssued)" />
        <x-fact label="POS" :value="$q($line->QtyPOS)" />
        <x-fact label="Counted closing" :value="$q($line->QtyClose)" />
        <x-fact label="Agora proposes" :value="$q($line->QtyCloseNew)" />
    </x-facts>

    <x-field name="close" label="Closing count" type="number" step="0.001" min="0"
             :value="\App\Support\Format::n($line->QtyCloseNew, 3)"
             help="What the shift really closed on. The next shift's opening follows it." />

    <x-field name="reason" label="Reason" placeholder="Why the closing is changed, for the audit trail"
             help="At least five characters. Kept with the adjustment: who, when and why." />

    <p class="muted">Nothing reaches PumpIT yet. Both shifts are ticked on the run; press Amend to write them.</p>

    <div class="adjust-close-actions">
        <button type="submit" class="btn-primary">Adjust the closing</button>
    </div>
</form>
