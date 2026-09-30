{{--
    "Load card amounts": what the card terminal's batch file says this till's
    shift took by card, batch by batch, beside what the FNB leg already holds —
    and the one press that WRITES PUMPIT.

    agora.usp_Cash_LoadCardAmounts reads; nothing is written until Save. Each
    line says what the save would do to it: add it (new), leave it (same),
    correct it (changed), or refuse (reconciled — a line Recon has matched to
    the bank is never changed from here). A line on the leg that the file no
    longer has stays exactly where it is; nothing is ever removed.

    Props: $scope, $cards (['lines' => Collection, 'summary' => ?object]),
           $writeMode, $savable (array), $one (?object — null before the cashup exists)
--}}
@php
    $canSave = (bool) auth()->user()?->can('cash.cashup.save');
    $live = $writeMode === 'live' && in_array('FNB', $savable, true);
    $s = $cards['summary'];
    $proposed = $cards['lines']->filter(fn ($l) => $l->Amount !== null);
    $writes = $proposed->whereIn('Action', ['new', 'changed'])->count();
    $refused = $proposed->where('Action', 'reconciled')->count();
    $tones = ['new' => 'warn', 'same' => 'good', 'changed' => 'serious', 'reconciled' => 'crit', 'not in the file' => 'neutral'];
@endphp

<x-card title="Card amounts from the terminal"
        :sub="$s && (int) $s->Zreads > 0
            ? 'Z-read EOD '.$s->EodCounter.' · log file '.$s->LogFiles.' · operator '.$s->PosUserIds.' · '.\App\Support\Format::r($s->Total).' in the file against '.\App\Support\Format::r($s->OnLegTotal).' on the leg'
            : 'No Z-read is allocated to this till and shift, so there is no batch file to read.'"
        flush style="margin-top:16px">
    <x-table :count="$cards['lines']->count()" procedure="agora.usp_Cash_LoadCardAmounts" dense
             empty="The terminal's batch file has nothing for this Z-read.">
        <x-slot:head>
            <tr>
                <th>Batch</th>
                <th>Merchant</th>
                <th class="num">In the file</th>
                <th class="num">On the leg</th>
                <th>Saving would</th>
            </tr>
        </x-slot:head>
        @foreach ($cards['lines'] as $l)
            <tr>
                <td class="mono">{{ $l->BatchNo }}</td>
                <td class="mono">{{ $l->MerchantNo }}</td>
                <td class="num">{{ \App\Support\Format::r($l->Amount) }}</td>
                <td class="num">{{ \App\Support\Format::r($l->OnLegAmount) }}</td>
                <td>
                    <x-chip :tone="$tones[$l->Action] ?? 'neutral'">{{ match ($l->Action) {
                        'new' => 'add it',
                        'same' => 'leave it — already right',
                        'changed' => 'correct it',
                        'reconciled' => 'refuse — reconciled in batch '.$l->ReconBatchNo,
                        default => 'leave it — not in the file',
                    } }}</x-chip>
                </td>
            </tr>
        @endforeach
    </x-table>

    @if (! $live)
        <x-notice tone="info" style="margin:12px">
            <p>Saving card amounts to PumpIT is switched off, so this is read-only.</p>
        </x-notice>
    @elseif ($refused > 0)
        {{-- A save would be refused whole, so it is not offered: the line has
             to be put right on the Recon side first. --}}
        <x-notice tone="stop" title="Not saved from here" style="margin:12px">
            <p>{{ $refused }} card {{ \Illuminate\Support\Str::plural('line', $refused) }} on the leg {{ $refused === 1 ? 'is' : 'are' }} reconciled and the terminal now says something different.
               A reconciled line is never changed from a cashup — reverse its recon batch first, then load again.</p>
        </x-notice>
    @elseif ($writes === 0 && $one !== null)
        <p class="muted" style="margin:12px">The FNB leg already matches the terminal. Nothing to save.</p>
    @elseif ($canSave && $proposed->isNotEmpty())
        <form method="POST" action="{{ route('app.cash.banking.cards') }}" class="inline-reason" style="margin:12px;max-width:520px"
              data-confirm="Save the card amounts to PumpIT?"
              data-confirm-text="{{ ($one === null ? 'PumpIT has no cashup for this till and shift yet: one is created from its Z-read (person, day-end number, sales). ' : '')
                  .$writes.' FNB line(s) are added or corrected in BRN_DailyBankingFNB, and the cashup\'s card total and variance on BRN_DailyBanking are brought into line. What each held before is recorded. Nothing is deleted.'
                  .($refused > 0 ? ' '.$refused.' line(s) are reconciled and different — the save will be refused and nothing written.' : '') }}"
              data-confirm-action="Save to PumpIT" data-loader="Saving…">
            @csrf
            @include('cash::banking.partials.key-inputs', ['scope' => $scope, 'from' => 'banking'])
            <input type="text" name="note" maxlength="400" placeholder="A note on the cashup (optional)" aria-label="Note on the cashup">
            <button type="submit" class="btn primary">Save card amounts</button>
        </form>
    @endif
</x-card>
