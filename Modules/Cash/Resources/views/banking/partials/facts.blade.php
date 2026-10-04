{{--
    One cashup's figures, as facts: the Z-read it is balanced against, what the
    legs actually total, the variance between them, whether the Z-read agrees,
    and where the cashup stands. Every figure is agora.usp_Cash_GetCashup's —
    nothing here adds anything up. What each figure means is in the screen's
    info panel; the line under a figure is only what is true of THIS cashup.

    Props: $one (a usp_Cash_GetCashup cashup row), $tolerance (float)
--}}
@php
    $service = app(\Modules\Cash\Services\CashupService::class);
    $varianceTone = $service->varianceTone($one->Variance);
@endphp

<x-facts :columns="3">
    <x-fact label="Z read" :value="\App\Support\Format::r($one->ZRead)">
        @if ((int) $one->Zreads === 1 && abs((float) $one->ZreadSales - (float) $one->ZRead) > 0.01)
            The Z-read allocated to this till and shift says {{ \App\Support\Format::r($one->ZreadSales) }}.
        @else
            EOD {{ $one->DayEndNo ?? \App\Support\Format::NOTHING }}.
        @endif
    </x-fact>
    <x-fact label="Actual total" :value="\App\Support\Format::r($one->ActualTotal)">
        @if ((int) $one->NotCountedLines > 0)
            {{ $one->NotCountedLines }} {{ \Illuminate\Support\Str::plural('line', (int) $one->NotCountedLines) }} not counted: the customer, supplier or person is not on PumpIT's list.
        @endif
    </x-fact>
    <x-fact label="Variance" :value="\App\Support\Format::r($one->Variance)" :tone="$varianceTone">
        @if (abs((float) $one->Variance) > $tolerance)
            Beyond R{{ \App\Support\Format::n($tolerance) }} either way — confirming it needs a reason.
        @endif
        @if (abs((float) $one->StoredVariance - (float) $one->Variance) > 0.005)
            PumpIT last stored {{ \App\Support\Format::r($one->StoredVariance) }}; a leg has moved since it was saved.
        @endif
    </x-fact>
    <x-fact label="Z-read" :value="$one->ZreadCheck" :tone="\Modules\Cash\Services\CashupService::zreadTone($one->ZreadCheck)">
        @switch($one->ZreadCheck)
            @case('Agrees') The Z-read allocated to this till and shift is this cashup's, at this figure. @break
            @case('No Z-read') No Z-read is allocated to this till and shift that day. Allocate it on ZREAD Allocation. @break
            @case('Z-read differs') The allocated Z-read's sales are not the figure this cashup was balanced against. @break
            @case('Other cashier') The allocated Z-read belongs to {{ $one->ZreadEmployeeCode ?? 'someone else' }} (EOD {{ $one->ZreadEodCounter ?? '—' }}), not this cashier — PumpIT's own Daily Banking grid does not list this cashup. @break
        @endswitch
    </x-fact>
    <x-fact label="Cashup" :value="$one->Status" :tone="\Modules\Cash\Services\CashupService::statusTone($one->Status)">
        @switch($one->Status)
            @case('Open') Nobody has confirmed these figures. @break
            @case('Confirmed') Confirmed by {{ $one->ConfirmedByName ?? 'somebody' }} at {{ \App\Support\Format::r($one->ConfirmedVariance) }} variance — waiting on a manager's close. @break
            @case('Closed') Closed by {{ $one->ClosedByName ?? 'a manager' }}. Nothing changes it now. @break
            @case('Day balanced') Not confirmed in Agora, but PumpIT's day is balanced (closed off there). @break
        @endswitch
        @if ($one->ReopenedAt ?? null)
            Reopened by {{ $one->ReopenedByName ?? 'a manager' }}: {{ $one->ReopenReason }}.
        @endif
        @if ($one->FiguresMoved)
            <strong>The figures have moved since they were confirmed</strong> ({{ \App\Support\Format::r($one->ConfirmedActual) }} then) — confirm again before it can be closed.
        @endif
    </x-fact>
    <x-fact label="Cashier" :value="$one->EmployeeName ?? $one->EmployeeCode">
        {{ $one->TillDescription ?? 'Till '.$one->TillNo }} · {{ $one->ShiftDescription ?? 'shift '.$one->ShiftNo }}.
        @if ($one->ConfirmNote) Why: {{ $one->ConfirmNote }} @endif
    </x-fact>
</x-facts>
