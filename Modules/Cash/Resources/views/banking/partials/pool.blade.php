{{--
    "Add from PumpIT": the rows of one leg's pool nobody has banked, around the
    cashup's day, to tick — and the press that WRITES PUMPIT.

    agora.usp_Cash_GetCashupPool reads; nothing is written until the press.
    agora.usp_Cash_SaveCashupLeg then copies each ticked row onto the leg and
    marks the pool row banked in the same transaction, exactly as the PumpIT
    client does — so the legacy screen cannot bank it a second time. A row
    another cashup already carries, or PumpIT already marks banked, is refused
    by name; a POS account no PumpIT customer or supplier answers to cannot be
    ticked at all.

    Rows the procedure thinks belong here come first — the cashier's own bags,
    the POS rows from the Z-read's own day-end number — marked with a hint.

    Props: $scope (naming the cashup), $pool (['rows' => Collection, 'summary' => ?object]),
           $channel, $poolName, $one (?object)
--}}
@php
    $s = $pool['summary'];
    $rows = $pool['rows'];
    $label = config("cash.cashup.channels.{$channel}", $channel);
@endphp

<x-card :title="'Add to '.mb_strtolower($label).' from '.$poolName"
        :sub="$s ? 'Not yet banked, dated '.$s->WindowFrom.' to '.$s->WindowTo.' · '.\App\Support\Format::n($s->Available).' row(s), '.\App\Support\Format::r($s->Total).($s->Hinted > 0 ? ' · '.$s->Hinted.' look like this cashup\'s' : '') : null"
        flush style="margin-top:16px">
    <form method="POST" action="{{ route('app.cash.banking.legs') }}" id="cashup-pool"
          data-confirm="Add the ticked rows to this cashup?"
          data-confirm-text="{{ ($one === null ? 'PumpIT has no cashup for this till and shift yet: one is created from its Z-read. ' : '')
              .'Each ticked row is copied onto the '.mb_strtolower($label).' leg in PumpIT and marked banked in '.$poolName.', so it cannot be banked again. The cashup\'s variance is brought into line. What each held before is recorded. Nothing is deleted.' }}"
          data-confirm-action="Add to PumpIT" data-loader="Saving…">
        @csrf
        @include('cash::banking.partials.key-inputs', ['scope' => $scope, 'from' => 'banking'])
        <input type="hidden" name="channel" value="{{ $channel }}">

        <x-table :count="$rows->count()" :total="$s?->Available" procedure="agora.usp_Cash_GetCashupPool" dense
                 :empty="'Nothing unbanked in '.$poolName.' around this day.'">
            <x-slot:head>
                <tr>
                    <th class="pick"><input type="checkbox" data-check-all aria-label="Tick every row that can be added"></th>
                    <th>Dated</th>
                    <th>Reference</th>
                    <th>Who</th>
                    <th>Detail</th>
                    <th class="num">Amount</th>
                    <th>Note</th>
                </tr>
            </x-slot:head>
            @foreach ($rows as $r)
                <tr>
                    <td class="pick">
                        <input type="checkbox" name="keys[]" value="{{ $r->PoolKey }}" data-check
                               @if ($r->Problem) disabled @endif
                               aria-label="Add {{ $r->Reference }}">
                    </td>
                    <td class="mono">{{ \Illuminate\Support\Carbon::parse($r->PoolDate)->format('Y-m-d H:i') }}</td>
                    <td class="mono">{{ $r->Reference }}</td>
                    <td>{{ $r->Counterparty ?? \App\Support\Format::NOTHING }}@if ($r->MasterCode)<br><span class="muted">→ {{ $r->MasterCode }}</span>@endif</td>
                    <td>{{ $r->Detail ?? \App\Support\Format::NOTHING }}</td>
                    <td class="num">{{ \App\Support\Format::r($r->Amount) }}</td>
                    <td>
                        @if ($r->Problem)
                            <x-chip tone="serious">cannot be banked</x-chip><br><span class="muted">{{ $r->Problem }}</span>
                        @elseif ($r->Hint)
                            <x-chip tone="good">{{ $r->Hint }}</x-chip>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-table>

        @if ($rows->isNotEmpty())
            <div class="form-actions inline-reason" style="margin:12px;max-width:520px">
                <input type="text" name="note" maxlength="400" placeholder="A note on the cashup (optional)" aria-label="Note on the cashup">
                <button type="submit" class="btn primary">Add ticked rows to PumpIT</button>
            </div>
        @endif
    </form>
</x-card>
