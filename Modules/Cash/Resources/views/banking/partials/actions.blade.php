{{--
    The presses on one cashup — Confirm, Close and Reopen — each behind its
    own permission (Reopen is the closer's) and each shown only where the
    procedure would take it. The
    procedure is still the one that decides: a press that arrives anyway is
    refused by name.

    The reason box is REQUIRED where the procedure would refuse without one —
    a variance beyond the tolerance, or a Z-read that does not agree — and
    optional otherwise, so the person is asked before the round trip rather
    than after it.

    Props: $scope (naming the cashup), $one (its GetCashup row), $tolerance, $from
--}}
@php
    $canConfirm = (bool) auth()->user()?->can('cash.cashup.confirm');
    $canClose = (bool) auth()->user()?->can('cash.cashup.close');
    $needsReason = abs((float) $one->Variance) > $tolerance || $one->ZreadCheck !== 'Agrees';
    $confirmable = $canConfirm && $one->Status !== 'Closed'
        && ($one->Status !== 'Confirmed' || $one->FiguresMoved);
    $closable = $canClose && $one->Status === 'Confirmed' && ! $one->FiguresMoved;
    $reopenable = $canClose && $one->Status === 'Closed';
@endphp

@if ($confirmable || $closable || $reopenable)
    <div class="form-actions">
        @if ($confirmable)
            <form method="POST" action="{{ route('app.cash.banking.confirm') }}" class="inline-reason" style="max-width:420px"
                  data-confirm="Confirm this cashup?"
                  data-confirm-text="You agree to a Z read of {{ \App\Support\Format::r($one->ZRead) }}, an actual total of {{ \App\Support\Format::r($one->ActualTotal) }} and a variance of {{ \App\Support\Format::r($one->Variance) }}. Recorded in Agora; nothing is written to PumpIT."
                  data-confirm-action="Confirm">
                @csrf
                @include('cash::banking.partials.key-inputs', ['scope' => $scope, 'from' => $from ?? 'banking'])
                <input type="text" name="note" maxlength="400" @if ($needsReason) required @endif
                       placeholder="{{ $needsReason ? 'Why — required for this one' : 'A note (optional)' }}" aria-label="Reason for confirming">
                <button type="submit" class="btn primary">Confirm</button>
            </form>
        @endif
        @if ($closable)
            <form method="POST" action="{{ route('app.cash.banking.close') }}" class="inline-reason" style="max-width:420px"
                  data-confirm="Close this cashup?"
                  data-confirm-text="A manager's sign-off at a variance of {{ \App\Support\Format::r($one->Variance) }}. Nothing changes a closed cashup afterwards — not a save, not another confirmation — unless a manager reopens it, with a reason. Recorded in Agora; nothing is written to PumpIT."
                  data-confirm-action="Close it">
                @csrf
                @include('cash::banking.partials.key-inputs', ['scope' => $scope, 'from' => $from ?? 'banking'])
                <input type="text" name="note" maxlength="400" placeholder="A note (optional)" aria-label="Note on closing">
                <button type="submit" class="btn">Close</button>
            </form>
        @endif
        @if ($reopenable)
            <form method="POST" action="{{ route('app.cash.banking.reopen') }}" class="inline-reason" style="max-width:420px"
                  data-confirm="Reopen this closed cashup?"
                  data-confirm-text="It goes back to open, so it can be corrected, confirmed again and closed again. The reason is kept. Recorded in Agora; nothing is written to PumpIT."
                  data-confirm-action="Reopen" data-confirm-danger>
                @csrf
                @include('cash::banking.partials.key-inputs', ['scope' => $scope, 'from' => $from ?? 'banking'])
                <input type="text" name="note" maxlength="400" required placeholder="Why it is being reopened" aria-label="Reason for reopening">
                <button type="submit" class="btn">Reopen</button>
            </form>
        @endif
    </div>
@endif
