{{--
    Cashup confirm, for a phone (plan §3.9: "cashup confirm" gets a
    purpose-built mobile blade).

    READ AND CONFIRM, nothing else: the day's cashups at one site, each with
    its Z read, what was declared and the variance, and — where it is still
    open — Confirm. No legs, no card load, no close: those are a desk's.
    Waiting on a person first, then the biggest variance.

    A cashup whose variance is beyond the tolerance, or whose Z-read does not
    agree, asks for the reason on the card itself, because the procedure will
    refuse it without one.

    Its own route (app.cash.banking.phone), as the Z-read phone screen has: no
    DeviceDetector or pick() exists yet (E18).

    Props: $scope, $branches, $pinned, $refusal, $cashups, $tolerance
--}}
@php
    $service = app(\Modules\Cash\Services\CashupService::class);
    $canConfirm = (bool) auth()->user()?->can('cash.cashup.confirm');
@endphp

<x-app-shell title="Confirm cashups">
    <x-page-head eyebrow="Cash" title="Confirm cashups">
        <x-slot:info>
            <p>The day's cashups, one card each. Confirm the ones whose figures you agree with; legs, card loads and
               closing are done at a desk. A variance beyond R{{ \App\Support\Format::n($tolerance) }} either way,
               or a Z-read that does not agree, needs a reason. Confirming is recorded in Agora only.</p>
        </x-slot:info>
    </x-page-head>

    @if (session('cashupRefusal'))
        <x-notice tone="stop" title="That was not confirmed"><p>{{ session('cashupRefusal') }}</p></x-notice>
    @elseif (session('cashupStatus'))
        <x-notice tone="info"><p>{{ session('cashupStatus') }}</p></x-notice>
    @endif

    <form method="GET" action="{{ route('app.cash.banking.phone') }}">
        <x-params collapsible :open="! $scope->isChosen()" remember="cash-banking-phone" summary="Site and day">
            @unless ($pinned)
                <x-param name="branch_id" label="Site"
                         :choices="['' => 'Choose a site'] + $branches->pluck('Name', 'BranchId')->all()"
                         :value="$scope->branchId" />
            @endunless
            <x-param name="date" label="Day" type="date" :value="$scope->date" />
        </x-params>
        <div class="form-actions"><button type="submit" class="btn">Show</button></div>
    </form>

    @if (! $scope->isChosen())
        <x-empty-state title="Choose a site" text="Then the day's cashups appear here, each with its variance." />
    @elseif ($refusal)
        <x-notice tone="stop" title="The cashups could not be read"><p>{{ $refusal }}</p></x-notice>
    @elseif ($cashups->isEmpty())
        <x-empty-state title="No cashups" text="PumpIT holds no cashups for this site on this day." />
    @else
        @foreach ($cashups as $c)
            @php($key = $scope->withCashup((int) $c->TillNo, (int) $c->ShiftNo))
            @php($needsReason = abs((float) $c->Variance) > $tolerance || $c->ZreadCheck !== 'Agrees')
            <x-card :title="($c->TillDescription ?? 'Till '.$c->TillNo).' · '.($c->ShiftDescription ?? 'shift '.$c->ShiftNo)"
                    :sub="$c->EmployeeName ?? $c->EmployeeCode" style="margin-bottom:12px">
                {{-- Variance first: on a phone the facts stack, and it is the one
                     figure the card is for. --}}
                <x-facts>
                    <x-fact label="Variance" :value="\App\Support\Format::r($c->Variance)" :tone="$service->varianceTone($c->Variance)" />
                    <x-fact label="Z read" :value="\App\Support\Format::r($c->ZRead)" />
                    <x-fact label="Actual" :value="\App\Support\Format::r($c->ActualTotal)" />
                </x-facts>
                <p>
                    <x-chip :tone="\Modules\Cash\Services\CashupService::statusTone($c->Status)">{{ $c->Status }}</x-chip>
                    <x-chip :tone="\Modules\Cash\Services\CashupService::zreadTone($c->ZreadCheck)">Z-read: {{ mb_strtolower($c->ZreadCheck) }}</x-chip>
                </p>
                @if ($canConfirm && ($c->Status === 'Open' || $c->Status === 'Day balanced' || ($c->Status === 'Confirmed' && $c->FiguresMoved)))
                    <form method="POST" action="{{ route('app.cash.banking.confirm') }}" class="inline-reason"
                          data-confirm="Confirm this cashup?"
                          data-confirm-text="You agree to a variance of {{ \App\Support\Format::r($c->Variance) }}. Recorded in Agora; nothing is written to PumpIT."
                          data-confirm-action="Confirm">
                        @csrf
                        @include('cash::banking.partials.key-inputs', ['scope' => $key, 'from' => 'phone'])
                        <input type="text" name="note" maxlength="400" @if ($needsReason) required @endif
                               placeholder="{{ $needsReason ? 'Why — required' : 'A note (optional)' }}" aria-label="Reason for confirming">
                        <button type="submit" class="btn primary">Confirm</button>
                    </form>
                @endif
            </x-card>
        @endforeach
    @endif
</x-app-shell>
