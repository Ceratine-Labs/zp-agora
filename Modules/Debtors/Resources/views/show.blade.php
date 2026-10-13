{{--
    One debtor's statement of account, as the debtor will receive it.

    In the order somebody checks it: what is owed and how old it is, every
    posting in the period, where it goes, and what was mailed before. The PDF
    and the mail render the same agora.usp_Debtors_GetStatement result, so
    this screen IS the check.

    Props: $account, $lines, $sends, $mode, $testRecipient
--}}
@php
    use App\Support\Format;
    use App\Support\BusinessDate;
    use Illuminate\Support\Carbon;

    $a = $account;
    $d = fn ($v) => $v === null ? null : Carbon::parse((string) $v)->format('j M Y');
    $bal = fn ($v) => (float) $v < 0 ? Format::r(abs((float) $v)).' CR' : Format::r($v);
    $period = ['from' => substr((string) $a->PeriodFrom, 0, 10), 'to' => substr((string) $a->PeriodTo, 0, 10)];
    $key = ['branch' => $a->BranchId, 'account' => $a->AccountNo];
    $address = implode(', ', array_filter([$a->Address1, $a->Address2, $a->Address3, $a->Address4]))
        ?: implode(', ', array_filter([$a->Postal1, $a->Postal2, $a->Postal3, $a->Postal4]));
@endphp

<x-app-shell :title="'Statement '.$a->AccountNo">
    <x-page-head eyebrow="Debtors statements · {{ $a->BranchName }}"
                 :title="$a->AccountNo.' · '.$a->AccountName"
                 :blurb="'Statement for '.$d($a->PeriodFrom).' to '.$d($a->PeriodTo).($address !== '' ? ' · '.$address : '')">
        <x-slot:info>
            <p>Every posting the till made to this account: invoices, debit notes and journals raise what is owed;
               payments, credit notes and credit journals lower it. A balance marked CR is money the debtor has paid
               ahead.</p>
            <p>Ageing takes payments against the oldest charges first, so what is still owed is the newest charges
               adding up to the balance, each aged from its own date.</p>
        </x-slot:info>
        <x-slot:actions>
            <a class="btn" href="{{ route('app.debtors.index', ['from' => $period['from'], 'to' => $period['to']]) }}">Back to debtors</a>
            <a class="btn" href="{{ route('app.debtors.pdf', $key + $period) }}" target="_blank" rel="noopener">View PDF</a>
            <a class="btn" href="{{ route('app.debtors.pdf', $key + $period + ['download' => 1]) }}">Download</a>
            <a class="btn" href="{{ route('app.debtors.preview', $key + $period) }}">Preview email</a>
            @can('debtors.contacts.edit')
                <button type="button" class="btn" data-modal-open="debtor-contact">Email address</button>
            @endcan
            @can('debtors.statements.send')
                @if ($mode !== 'off')
                    <form method="POST" action="{{ route('app.debtors.send', $key) }}" style="display:inline"
                          data-confirm="{{ $mode === 'test' ? 'Send a test statement to '.$testRecipient.'?' : 'Mail this statement to '.($a->Email ?? 'the debtor').'?' }}"
                          data-confirm-text="{{ $mode === 'test' ? 'Test mode: it goes to the test address, not to the debtor.' : 'It goes to the debtor'.($a->CcEmail ? ' and '.$a->CcEmail : '').', with the PDF attached.' }}"
                          data-confirm-action="Send it">
                        @csrf
                        <input type="hidden" name="from" value="{{ $period['from'] }}">
                        <input type="hidden" name="to" value="{{ $period['to'] }}">
                        <button type="submit" class="btn-primary" @disabled($a->Email === null)>{{ $mode === 'test' ? 'Send test' : 'Send statement' }}</button>
                    </form>
                @endif
            @endcan
        </x-slot:actions>
    </x-page-head>

    @if (session('status'))
        <x-notice tone="info" title="Done">{{ session('status') }}</x-notice>
    @endif

    @error('refusal')
        <x-notice tone="warn" title="That was refused">{{ $message }}</x-notice>
    @enderror

    @if ($errors->any() && ! $errors->has('refusal'))
        <x-notice tone="warn" title="That was not saved">
            <ul style="margin:0; padding-left:18px">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </x-notice>
    @endif

    @if ($mode === 'off')
        <x-notice tone="info" title="Not being mailed yet">
            Sending is switched off until the balances have been checked against the books. Check this statement,
            and download it to send by hand if it is right.
        </x-notice>
    @endif

    @if ($a->LedgerFrom !== null)
        <x-notice tone="info" title="The ledger at {{ $a->BranchName }} starts on {{ $d($a->LedgerFrom) }}" collapsible>
            Anything this account owed before then is not in the brought-forward balance. If the account is older,
            check its balance against the books before it is mailed.
        </x-notice>
    @endif

    <x-kpi-strip>
        <x-kpi label="Brought forward" :value="$bal($a->Opening)" :note="'At '.$d($a->PeriodFrom)" />
        <x-kpi label="Charged" :value="Format::r($a->Debits)" :note="$lines->where('Debit', '>', 0)->count().' posting(s)'" />
        <x-kpi label="Paid and credited" :value="Format::r($a->Credits)" :note="$lines->where('Credit', '>', 0)->count().' posting(s)'" />
        <x-kpi :label="(float) $a->Closing < 0 ? 'In credit' : 'Balance due'" :value="Format::r(abs((float) $a->Closing))"
               :note="'At '.$d($a->PeriodTo)" :tone="(float) $a->Age90 + (float) $a->Age120 > 0 ? 'warn' : 'neutral'" />
    </x-kpi-strip>

    <x-card title="Period">
        <form method="GET" action="{{ route('app.debtors.show', $key) }}" style="display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap">
            <div style="flex:0 1 200px"><x-field name="from" label="From" type="date" :value="$period['from']" /></div>
            <div style="flex:0 1 200px"><x-field name="to" label="To" type="date" :value="$period['to']" /></div>
            <div style="padding-bottom:12px"><button type="submit" class="btn">Show</button></div>
        </form>
    </x-card>

    <x-card title="Ageing at {{ $d($a->PeriodTo) }}">
        <x-facts>
            <x-fact label="Current" :value="$bal($a->AgeCurrent)">Charged in the last 30 days.</x-fact>
            <x-fact label="30 days" :value="Format::r($a->Age30)" />
            <x-fact label="60 days" :value="Format::r($a->Age60)" />
            <x-fact label="90 days" :value="Format::r($a->Age90)" :tone="(float) $a->Age90 > 0 ? 'warn' : null" />
            <x-fact label="120 days +" :value="Format::r($a->Age120)" :tone="(float) $a->Age120 > 0 ? 'warn' : null" />
            <x-fact label="Credit limit" :value="$a->CreditLimit !== null && (float) $a->CreditLimit > 0 ? Format::r($a->CreditLimit) : null">
                From the till's account master.
            </x-fact>
        </x-facts>
    </x-card>

    <x-card title="Postings">
        <x-table procedure="agora.usp_Debtors_GetStatement" :count="$lines->count()" dense tools
                 empty="Nothing was posted to this account in the period.">
            <x-slot:head>
                <tr>
                    <th>Date</th><th>Type</th><th>Reference</th><th>Detail</th><th>Order</th><th>Till</th>
                    <th class="num">Debit</th><th class="num">Credit</th><th class="num">Balance</th>
                </tr>
            </x-slot:head>
            <tr>
                <td class="mono">{{ $period['from'] }}</td>
                <td colspan="7"><em>Balance brought forward</em></td>
                <td class="num">{{ $bal($a->Opening) }}</td>
            </tr>
            @foreach ($lines as $l)
                <tr>
                    <td class="mono">{{ substr((string) $l->TransactionDate, 0, 10) }}</td>
                    <td>{{ $l->TranType }}</td>
                    <td class="mono">{{ $l->Reference }}</td>
                    <td>{{ $l->Description }}</td>
                    <td class="mono">{{ $l->OrderNo }}</td>
                    <td class="mono">{{ $l->TillNo }}</td>
                    <td class="num">{{ (float) $l->Debit != 0.0 ? Format::r($l->Debit) : '' }}</td>
                    <td class="num">{{ (float) $l->Credit != 0.0 ? Format::r($l->Credit) : '' }}</td>
                    <td class="num">{{ $bal($l->Balance) }}</td>
                </tr>
            @endforeach
        </x-table>
    </x-card>

    <x-card title="Where it goes">
        <x-facts>
            <x-fact label="Email" :value="$a->Email" :tone="$a->Email === null ? 'warn' : null">
                {{ $a->Email === null ? 'No address — the statement cannot be mailed.' : 'The debtor\'s address.' }}
            </x-fact>
            <x-fact label="Copies to" :value="$a->CcEmail" />
            <x-fact label="Monthly run" :value="$a->AutoSend ? 'On' : 'Off'">
                {{ $a->AutoSend ? 'Mailed last month\'s statement on the 1st.' : 'Mailed only when somebody presses Send.' }}
            </x-fact>
            <x-fact label="Note" :value="$a->Note" />
        </x-facts>
    </x-card>

    <x-card title="Mailed">
        <x-table procedure="agora.usp_Debtors_GetStatement" :count="$sends->count()" dense
                 empty="No statement has been mailed for this account.">
            <x-slot:head>
                <tr><th>When</th><th>Period</th><th class="num">Balance</th><th>To</th><th>Mode</th><th>How</th><th>Result</th><th>By</th></tr>
            </x-slot:head>
            @foreach ($sends as $s)
                <tr>
                    <td class="mono">{{ BusinessDate::show($s->SentAt) }}</td>
                    <td class="mono">{{ substr((string) $s->PeriodFrom, 0, 10) }} – {{ substr((string) $s->PeriodTo, 0, 10) }}</td>
                    <td class="num">{{ $bal($s->ClosingBalance) }}</td>
                    <td>{{ $s->SentTo }}</td>
                    <td>{{ $s->Mode === 'test' ? 'Test' : 'Live' }}</td>
                    <td>{{ $s->Trigger === 'schedule' ? 'Monthly run' : 'Sent by hand' }}</td>
                    <td>{{ $s->Status === 'sent' ? 'Sent' : 'Failed: '.$s->Error }}</td>
                    <td>{{ $s->SentBy ?? ($s->Trigger === 'schedule' ? 'Agora' : '') }}</td>
                </tr>
            @endforeach
        </x-table>
    </x-card>

    @can('debtors.contacts.edit')
        <x-modal id="debtor-contact" title="Where {{ $a->AccountNo }}'s statement goes">
            <form method="POST" action="{{ route('app.debtors.contact', $key) }}">
                @csrf
                <x-field name="email" label="Email" type="email" :value="old('email', $a->Email)"
                         help="One address. Leave empty to stop mailing this account." />
                <x-field name="cc_email" label="Copies to" :value="old('cc_email', $a->CcEmail)"
                         help="Optional. Further addresses, separated by commas." />
                <x-field name="note" label="Note" :value="old('note', $a->Note)" help="Optional. Who the contact is, or why." />
                <label class="field-check" style="display:flex; gap:8px; align-items:center; margin:8px 0 12px">
                    <input type="hidden" name="auto_send" value="0">
                    <input type="checkbox" name="auto_send" value="1" @checked(old('auto_send', $a->AutoSend))>
                    On the monthly run — mail last month's statement on the 1st
                </label>
                <div class="btn-row">
                    <button type="submit" class="btn-primary">Save</button>
                </div>
            </form>
        </x-modal>
    @endcan
</x-app-shell>
