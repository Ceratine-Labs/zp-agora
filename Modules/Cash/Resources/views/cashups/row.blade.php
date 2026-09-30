{{--
    One cashup, opened under its row on the Cashups grid: its figures, its legs
    in brief (the ones with money on them), and Confirm and Close where the
    person may press them. The whole of it — every line, the card load, the
    trail — is on Daily Banking, one click away.

    Props: $scope (naming the cashup), $one, $lines, $channels, $tolerance
--}}
@php
    $totals = $lines->groupBy('Channel')->map(fn ($g) => ['n' => $g->count(), 'amount' => (float) $g->where('InActualTotal', true)->sum(fn ($l) => (float) $l->Amount)]);
    $labels = (array) config('cash.cashup.channels', []);
@endphp

<div>
    <p class="eyebrow">{{ \Illuminate\Support\Carbon::parse($one->TransactionDate)->format('j M Y') }} · {{ $one->TillDescription ?? 'Till '.$one->TillNo }} · {{ $one->ShiftDescription ?? 'shift '.$one->ShiftNo }} · EOD {{ $one->DayEndNo ?? \App\Support\Format::NOTHING }}</p>

    @include('cash::banking.partials.facts', ['one' => $one, 'tolerance' => $tolerance])

    <x-table :count="$totals->count()" procedure="agora.usp_Cash_GetCashup" dense empty="No legs on this cashup." style="margin-top:12px">
        <x-slot:head>
            <tr>
                <th>Leg</th>
                <th class="num">Lines</th>
                <th class="num">Counted</th>
            </tr>
        </x-slot:head>
        @foreach ($totals as $channel => $t)
            <tr>
                <td>{{ $labels[$channel] ?? $channel }}</td>
                <td class="num">{{ \App\Support\Format::n($t['n']) }}</td>
                <td class="num">{{ \App\Support\Format::r($t['amount']) }}</td>
            </tr>
        @endforeach
    </x-table>

    @include('cash::banking.partials.actions', ['scope' => $scope, 'one' => $one, 'tolerance' => $tolerance, 'from' => 'cashups'])

    <div class="form-actions">
        <a class="btn-ghost" href="{{ route('app.cash.banking', $scope->query()) }}">Open on Daily Banking</a>
    </div>
</div>
