{{--
    One staff short, opened (?short=): what it is, where it stands, and the
    presses it is open to — approve or decline while it waits; post, or
    withdraw, while it is approved and not on a cashup. Each press sits behind
    its own permission and is not rendered to anybody without it
    (feature-rules §4). Nothing deletes a short: a wrong one is declined, an
    approved one withdrawn. A short a cash-bag match raised is never posted
    (PM decision 01a0e5b5): deductions read it directly.

    Props: $scope, $opened, $trail, $tills, $shifts, $writeMode, $postMode
--}}
@php
    $user = auth()->user();
    $live = $writeMode === 'live';
    $canDecide = $live && (bool) $user?->can('cash.staffshort.approve') && $opened->Status === 'Waiting';
    $canPost = $postMode === 'live' && (bool) $user?->can('cash.staffshort.post') && (bool) $opened->CanPost;
    $canWithdraw = $live && (bool) $user?->can('cash.staffshort.withdraw') && (bool) $opened->CanWithdraw;
    $fromRecon = $opened->Source === 'recon';
    $day = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('j M Y') : \App\Support\Format::NOTHING;
    $tillChoices = ['' => 'Choose the till'] + $tills->mapWithKeys(fn ($t) => [$t->TillNo => $t->TillNo.' · '.($t->TillDescription ?: 'till')])->all();
    $shiftChoices = ['' => 'Choose the shift'] + $shifts->mapWithKeys(fn ($s) => [$s->ShiftNo => $s->ShiftNo.' · '.($s->ShiftDescription ?: 'shift')])->all();
@endphp

<x-card :title="'Staff short '.$opened->StaffShortsNo"
        :sub="\Modules\Cash\Services\StaffShortService::statusWords($opened->Status).' · raised in '.$opened->RaisedIn"
        style="margin-top:16px">
    <x-facts>
        <x-fact label="Against" :value="$opened->EmployeeName ?? $opened->EmployeeCode">{{ $opened->EmployeeCode }}{{ (bool) $opened->OnEmployeeList ? '' : ' — not on PumpIT\'s employee list' }}</x-fact>
        <x-fact label="Amount" :value="\App\Support\Format::r($opened->AmountShort)" />
        <x-fact label="Reason" :value="$opened->ReasonLabel">{{ $opened->ReasonFrom === 'alias' ? 'read from the reason as typed: "'.$opened->LegacyReason.'"' : ($opened->ReasonFrom === 'none' ? 'no code matched the reason as typed' : 'the code it was raised under') }}</x-fact>
        <x-fact label="Day" :value="$day($opened->TransactionDate)" />
        <x-fact label="Status" :value="$opened->Status" :tone="\Modules\Cash\Services\StaffShortService::statusTone($opened->Status)">
            @if ($opened->Status === 'Declined')
                {{ $opened->DeclineReasonFull ?? $opened->DeclinedReason }}{{ $opened->DecidedBy ? ' — '.$opened->DecidedBy : '' }}
            @elseif ($opened->Status === 'Posted')
                on the cashup for till {{ $opened->PostedTillNo }}, shift {{ $opened->PostedShiftNo }}, {{ $day($opened->PostedDate) }}
            @elseif ($opened->DecidedBy)
                approved by {{ $opened->DecidedBy }}
            @endif
        </x-fact>
        <x-fact label="Raised by" :value="$opened->RaisedBy ?? \App\Support\Format::NOTHING">{{ $opened->SourceRef ?? '' }}</x-fact>
    </x-facts>
    @if ($opened->Note)
        <p><strong>Note:</strong> {{ $opened->Note }}</p>
    @endif

    @if ($canDecide)
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px;margin-top:12px">
            <form method="POST" action="{{ route('app.cash.staffshorts.approve') }}" id="staffshort-approve"
                  data-confirm="Approve staff short {{ $opened->StaffShortsNo }} in PumpIT?"
                  data-confirm-text="{{ \App\Support\Format::r($opened->AmountShort) }} against {{ $opened->EmployeeName ?? $opened->EmployeeCode }} is approved in PumpIT and can then be posted to a cashup, where it is charged. The person who raised it is emailed."
                  data-confirm-action="Approve" data-loader="Writing to PumpIT…">
                @csrf
                @foreach ($scope->withShort(null)->query() as $name => $value)
                    <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                @endforeach
                <input type="hidden" name="short" value="{{ $opened->StaffShortsNo }}">
                <x-params legend="Approve">
                    <x-param name="note" label="Note" :value="old('note')" placeholder="Optional — kept in Agora" />
                </x-params>
                <div class="form-actions"><button type="submit" class="btn primary">Approve</button></div>
            </form>

            <form method="POST" action="{{ route('app.cash.staffshorts.decline') }}" id="staffshort-decline"
                  data-confirm="Decline staff short {{ $opened->StaffShortsNo }} in PumpIT?"
                  data-confirm-text="The short is declined in PumpIT and is not charged. Your reason is kept whole in Agora (PumpIT keeps its first 50 characters) and the person who raised it is emailed."
                  data-confirm-action="Decline" data-confirm-danger data-loader="Writing to PumpIT…">
                @csrf
                @foreach ($scope->withShort(null)->query() as $name => $value)
                    <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                @endforeach
                <input type="hidden" name="short" value="{{ $opened->StaffShortsNo }}">
                <x-params legend="Decline">
                    <x-param name="decline_reason" label="Why" :value="old('decline_reason')"
                             placeholder="e.g. No slips received" help="Required — emailed to the raiser." />
                </x-params>
                <div class="form-actions"><button type="submit" class="btn">Decline</button></div>
            </form>
        </div>
    @endif

    @if ($fromRecon && in_array($opened->Status, ['Waiting', 'Approved'], true))
        <x-notice tone="info" title="Raised by a cash-bag match — never put on a cashup" collapsible style="margin-top:12px">
            <p>{{ $opened->SourceRef ?? 'A cash-bag match' }} raised this short. The cashup already counted those bags,
               so deductions read the short directly once it is approved. To reverse the match, withdraw an approved
               short here first.</p>
        </x-notice>
    @endif

    @if ($canWithdraw)
        <form method="POST" action="{{ route('app.cash.staffshorts.withdraw') }}" id="staffshort-withdraw" style="margin-top:12px"
              data-confirm="Withdraw staff short {{ $opened->StaffShortsNo }} in PumpIT?"
              data-confirm-text="The approval is taken back and the short is declined in PumpIT, so it is not charged. Your reason is kept whole in Agora (PumpIT keeps its first 50 characters), with the approval it replaced, and the person who raised it is emailed."
              data-confirm-action="Withdraw" data-confirm-danger data-loader="Writing to PumpIT…">
            @csrf
            @foreach ($scope->withShort(null)->query() as $name => $value)
                <input type="hidden" name="{{ $name }}" value="{{ $value }}">
            @endforeach
            <input type="hidden" name="short" value="{{ $opened->StaffShortsNo }}">
            <x-params legend="Withdraw the approval">
                <x-param name="withdraw_reason" label="Why" :value="old('withdraw_reason')"
                         placeholder="e.g. Recon batch reversed — wrong bags"
                         help="Required." />
            </x-params>
            <div class="form-actions"><button type="submit" class="btn">Withdraw</button></div>
        </form>
    @endif

    @if ($canPost)
        <form method="POST" action="{{ route('app.cash.staffshorts.post') }}" id="staffshort-post" style="margin-top:12px"
              data-confirm="Post staff short {{ $opened->StaffShortsNo }} to this cashup in PumpIT?"
              data-confirm-text="A pump-shortage line of {{ \App\Support\Format::r($opened->AmountShort) }} for {{ $opened->EmployeeName ?? $opened->EmployeeCode }} is written to the cashup, the short is marked as on a cashup, and the cashup's variance moves by the same amount. What each held before is kept in Agora. Nothing is deleted."
              data-confirm-action="Post to the cashup" data-loader="Writing to PumpIT…">
            @csrf
            @foreach ($scope->withShort(null)->query() as $name => $value)
                <input type="hidden" name="{{ $name }}" value="{{ $value }}">
            @endforeach
            <input type="hidden" name="short" value="{{ $opened->StaffShortsNo }}">
            <x-params legend="Post to a cashup">
                <x-param name="post_date" label="Cashup day" type="date"
                         :value="old('post_date', substr((string) ($opened->ForDate ?? $opened->TransactionDate), 0, 10))" />
                <x-param name="post_till" label="Till" :choices="$tillChoices" :value="old('post_till', $opened->ForTillNo)" />
                <x-param name="post_shift" label="Shift" :choices="$shiftChoices" :value="old('post_shift', $opened->ForShiftNo)" />
            </x-params>
            <div class="form-actions"><button type="submit" class="btn primary">Post to the cashup</button></div>
        </form>
    @endif

    @if ($trail->isNotEmpty())
        <h3 style="margin:16px 0 4px">What has been done to it</h3>
        <x-table :count="$trail->count()" procedure="agora.usp_Cash_GetStaffShorts" dense empty="Nothing pressed yet.">
            <x-slot:head>
                <tr><th>When</th><th>What</th><th>By</th><th>Detail</th></tr>
            </x-slot:head>
            @foreach ($trail as $e)
                <tr>
                    <td>{{ \App\Support\BusinessDate::show($e->CreatedAt, 'j M Y H:i') }}</td>
                    <td>{{ $e->Event }}</td>
                    <td>{{ $e->ByUser ?? \App\Support\Format::NOTHING }}</td>
                    <td>{{ $e->Event === 'posted' ? 'till '.$e->TillNo.', shift '.$e->ShiftNo.', '.$day($e->TransactionDate) : ($e->Note ?? '') }}</td>
                </tr>
            @endforeach
        </x-table>
    @endif

    <p><a href="{{ route('app.cash.staffshorts', $scope->withShort(null)->query()) }}">Close</a></p>
</x-card>
