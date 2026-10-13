{{--
    debtors.statement — the covering note on a debtor's statement of account.

    Plain, like the staff short mail: it is read on a phone, and the statement
    itself is the PDF attached. T019 replaces this file with an editable
    agora.EmailTemplate under the same code; the variables used here are the
    ones that template's registry declares: statement.site, statement.accountNo,
    statement.accountName, statement.period, statement.periodEnd,
    statement.closing, statement.owing, testFor.
--}}
@if ($testFor)
    <p style="padding:8px;border:1px solid #c90;background:#fff6dd">
        <strong>Test statement.</strong> In live mode this goes to {{ $testFor }}. Nobody outside the business has seen it.
    </p>
@endif

<p>Dear {{ $statement['accountName'] }},</p>

<p>Please find attached your statement of account <strong>{{ $statement['accountNo'] }}</strong> with
   {{ $statement['site'] }} for {{ $statement['period'] }}.</p>

<p>
    @if ($statement['owing'])
        The balance due at {{ $statement['periodEnd'] }} is <strong>{{ $statement['closing'] }}</strong>.
    @else
        At {{ $statement['periodEnd'] }} your account is <strong>{{ $statement['closing'] }} in credit</strong>.
    @endif
</p>

<p>Please quote your account number with every payment. If anything on the statement does not agree with
   your records, reply to this mail or contact {{ $statement['site'] }}.</p>

<p>{{ $statement['site'] }}</p>
