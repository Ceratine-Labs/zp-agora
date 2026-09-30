{{--
    cash.staff_short_raised — to each approver when a staff short is raised.

    Plain, like the password mail: it is read on a phone, and its one job is to
    put the short and the link to decide it in front of the approver. T019
    replaces this file with an editable agora.EmailTemplate under the same code;
    the variables used here are the ones that template's registry declares:
    short.no, short.site, short.employee, short.code, short.amount, short.reason,
    short.day, short.note, short.raisedBy, short.source, approver, url.
--}}
<p>Hello {{ $approver }},</p>

<p>Staff short <strong>{{ $short['no'] }}</strong> is waiting for your approval.</p>

<table cellpadding="4" style="border-collapse:collapse">
    <tr><td>Site</td><td><strong>{{ $short['site'] }}</strong></td></tr>
    <tr><td>Against</td><td><strong>{{ $short['employee'] }}</strong> ({{ $short['code'] }})</td></tr>
    <tr><td>Amount</td><td><strong>{{ $short['amount'] }}</strong></td></tr>
    <tr><td>Reason</td><td>{{ $short['reason'] }}</td></tr>
    <tr><td>Day</td><td>{{ $short['day'] }}</td></tr>
    @if ($short['note'])
        <tr><td>Note</td><td>{{ $short['note'] }}</td></tr>
    @endif
    <tr><td>Raised by</td><td>{{ $short['raisedBy'] }}{{ $short['source'] ? ' — '.$short['source'] : '' }}</td></tr>
</table>

<p>Approve or decline it here: <a href="{{ $url }}">{{ $url }}</a></p>

<p>An approved short is charged to the person once it is posted to a cashup. A
   declined one is not charged, and the person who raised it is told why.</p>

<p>Agora — Zululand Retail &amp; Petroleum</p>
