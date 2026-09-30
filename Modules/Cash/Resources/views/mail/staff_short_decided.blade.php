{{--
    cash.staff_short_decided — to the person who raised a staff short, when it is
    approved or declined.

    T019 replaces this file with an editable agora.EmailTemplate under the same
    code; the variables used here are the ones that template's registry
    declares: short.no, short.site, short.employee, short.code, short.amount,
    short.reason, short.day, short.note, short.decision, short.decidedBy,
    short.why, raiser, url.
--}}
<p>Hello {{ $raiser }},</p>

<p>Staff short <strong>{{ $short['no'] }}</strong> — {{ $short['amount'] }} against {{ $short['employee'] }}
   ({{ $short['code'] }}) at {{ $short['site'] }}, {{ $short['reason'] }}, {{ $short['day'] }} — was
   <strong>{{ $short['decision'] }}</strong>{{ $short['decidedBy'] ? ' by '.$short['decidedBy'] : '' }}.</p>

@if ($short['why'])
    <p>Reason: {{ $short['why'] }}</p>
@endif

@if ($short['decision'] === 'approved')
    <p>It is charged once it is posted to a cashup's pump shortages.</p>
@elseif ($short['decision'] === 'withdrawn')
    <p>Its approval was taken back and it is declined, so it is not charged.</p>
@else
    <p>It is not charged. If the short is still due, raise it again with what was missing.</p>
@endif

<p><a href="{{ $url }}">{{ $url }}</a></p>

<p>Agora — Zululand Retail &amp; Petroleum</p>
