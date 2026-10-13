{{--
    The statement mail as it would go out: who it goes to, its subject, the
    covering note, and the PDF attached. Built by the same service methods the
    send uses (envelope() and mail()), so what is previewed is what is sent.
    Nothing is sent from here and nothing is recorded.

    The note is printed as rendered: it is Agora's own Blade view
    (mail/statement), every value in it escaped there, inline styles only.

    Props: $account, $mode, $envelope, $subject, $body, $filename
--}}
@php
    use Illuminate\Support\Carbon;

    $a = $account;
    $period = ['from' => substr((string) $a->PeriodFrom, 0, 10), 'to' => substr((string) $a->PeriodTo, 0, 10)];
    $key = ['branch' => $a->BranchId, 'account' => $a->AccountNo];
    $list = fn (array $addresses) => $addresses === [] ? null : implode(', ', $addresses);
@endphp

<x-app-shell :title="'Email preview '.$a->AccountNo">
    <x-page-head eyebrow="Debtors statements · {{ $a->BranchName }}"
                 :title="'Email preview · '.$a->AccountNo.' '.$a->AccountName"
                 :blurb="'Statement for '.Carbon::parse($period['from'])->format('j M Y').' to '.Carbon::parse($period['to'])->format('j M Y')">
        <x-slot:actions>
            <a class="btn" href="{{ route('app.debtors.show', $key + $period) }}">Back to the statement</a>
            <a class="btn" href="{{ route('app.debtors.pdf', $key + $period) }}" target="_blank" rel="noopener">Open the PDF</a>
        </x-slot:actions>
    </x-page-head>

    @if ($mode === 'off')
        <x-notice tone="info" title="Sending is switched off">
            Nothing is mailed yet. This is what the debtor will receive once sending is live.
        </x-notice>
    @elseif ($mode === 'test')
        <x-notice tone="warn" title="Test mode">
            This goes to the test address, not to the debtor.
        </x-notice>
    @endif

    @if ($envelope['problem'] !== null)
        <x-notice tone="warn" title="This would not be sent">{{ $envelope['problem'] }}</x-notice>
    @endif

    <x-card title="The mail">
        <x-facts>
            <x-fact label="To" :value="$list($envelope['to'])" :tone="$envelope['to'] === [] ? 'warn' : null" />
            <x-fact label="Copies" :value="$list($envelope['cc'])" />
            <x-fact label="Blind copies" :value="$list($envelope['bcc'])">Head office's own record, if one is set.</x-fact>
            <x-fact label="Subject" :value="$subject" />
            <x-fact label="Attached" :value="$filename">The PDF statement, exactly as View PDF shows it.</x-fact>
        </x-facts>
    </x-card>

    <x-card title="What the debtor reads">
        <div data-mail-preview style="background:#fff; color:#111; padding:16px 20px; border-radius:6px; max-width:720px">
            {!! $body !!}
        </div>
    </x-card>
</x-app-shell>
