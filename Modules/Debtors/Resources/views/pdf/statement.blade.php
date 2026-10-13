{{--
    A debtor's statement of account, as the PDF that is mailed and downloaded.

    Rendered by Dompdf, which reads a narrow, older dialect of CSS: tables for
    layout, no flex or grid, no custom properties, no web fonts (DejaVu Sans is
    built in and carries the rand sign and every accented name). Self-contained
    on purpose — remote loading is switched off in DebtorStatementService.

    Every figure is agora.usp_Debtors_GetStatement's, exactly as the statement
    screen shows it. Black on white: it is printed.

    Props: $a (the account row), $lines, $issuer, $note
--}}
@php
    use App\Support\Format;
    use Illuminate\Support\Carbon;

    $d = fn ($v) => $v === null ? '' : Carbon::parse((string) $v)->format('d M Y');
    $short = fn ($v) => $v === null ? '' : Carbon::parse((string) $v)->format('d/m/Y');
    $m = fn ($v) => (float) $v == 0.0 ? '' : Format::r($v);
    $bal = fn ($v) => (float) $v < 0 ? Format::r(abs((float) $v)).' CR' : Format::r($v);
    $address = array_filter([$a->Address1, $a->Address2, $a->Address3, $a->Address4]);
    if ($address === []) {
        $address = array_filter([$a->Postal1, $a->Postal2, $a->Postal3, $a->Postal4]);
    }
@endphp
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Statement {{ $a->AccountNo }}</title>
<style>
    @page { margin: 18mm 14mm 20mm 14mm; }
    body { font-family: "DejaVu Sans", sans-serif; font-size: 9pt; color: #111; }
    h1 { font-size: 16pt; margin: 0 0 2mm 0; letter-spacing: 0.5pt; }
    .muted { color: #555; }
    .small { font-size: 7.5pt; }
    table { border-collapse: collapse; width: 100%; }
    .lines th { border-bottom: 0.8pt solid #111; text-align: left; padding: 1.5mm 1.2mm; font-size: 7.5pt; }
    .lines td { border-bottom: 0.3pt solid #ccc; padding: 1mm 1.2mm; font-size: 8pt; white-space: nowrap; }
    .num { text-align: right; white-space: nowrap; }
    .age th { border: 0.6pt solid #888; padding: 1.5mm; font-size: 8pt; text-align: right; background: #eee; }
    .age td { border: 0.6pt solid #888; padding: 2mm 1.5mm; text-align: right; }
</style>
</head>
<body>

<table>
    <tr>
        <td style="width:55%; vertical-align:top">
            <h1>STATEMENT</h1>
            <div><strong>{{ $a->BranchName }}</strong></div>
            @if ($issuer !== '' && $issuer !== $a->BranchName)
                <div class="muted">{{ $issuer }}</div>
            @endif
        </td>
        <td style="width:45%; vertical-align:top">
            <table>
                <tr><td class="muted">Account</td><td class="num"><strong>{{ $a->AccountNo }}</strong></td></tr>
                <tr><td class="muted">Statement date</td><td class="num">{{ $d($a->PeriodTo) }}</td></tr>
                <tr><td class="muted">Period</td><td class="num">{{ $d($a->PeriodFrom) }} – {{ $d($a->PeriodTo) }}</td></tr>
                @if ($a->CreditLimit !== null && (float) $a->CreditLimit > 0)
                    <tr><td class="muted">Credit limit</td><td class="num">{{ Format::r($a->CreditLimit) }}</td></tr>
                @endif
            </table>
        </td>
    </tr>
</table>

<table style="margin-top:6mm">
    <tr>
        <td style="width:55%; border:0.6pt solid #888; padding:3mm; vertical-align:top">
            <strong>{{ $a->AccountName }}</strong><br>
            @if ($a->Contact)
                Attn: {{ $a->Contact }}<br>
            @endif
            @foreach ($address as $line)
                {{ $line }}<br>
            @endforeach
            @if ($a->VatNo)
                <span class="small muted">VAT {{ $a->VatNo }}</span>
            @endif
        </td>
        <td style="width:5%"></td>
        <td style="width:40%; border:0.6pt solid #888; padding:3mm; vertical-align:top">
            <div class="muted">{{ (float) $a->Closing < 0 ? 'In credit' : 'Balance due' }}</div>
            <div style="font-size:12pt; font-weight:bold">{{ Format::r(abs((float) $a->Closing)) }}</div>
            <div class="small muted">at {{ $d($a->PeriodTo) }}</div>
        </td>
    </tr>
</table>

<table class="lines" style="margin-top:6mm">
    <thead>
        <tr>
            <th style="width:12%">Date</th>
            <th style="width:12%">Type</th>
            <th style="width:9%">Reference</th>
            <th>Detail</th>
            <th style="width:12%">Order</th>
            <th class="num" style="width:11%">Debit</th>
            <th class="num" style="width:11%">Credit</th>
            <th class="num" style="width:13%">Balance</th>
        </tr>
    </thead>
    <tbody>
        <tr style="font-style:italic">
            <td>{{ $short($a->PeriodFrom) }}</td>
            <td colspan="6">Balance brought forward</td>
            <td class="num">{{ $bal($a->Opening) }}</td>
        </tr>
        @foreach ($lines as $l)
            <tr>
                <td>{{ $short($l->TransactionDate) }}</td>
                <td>{{ $l->TranType }}</td>
                <td>{{ $l->Reference }}</td>
                <td>{{ $l->Description }}</td>
                <td>{{ $l->OrderNo }}</td>
                <td class="num">{{ $m($l->Debit) }}</td>
                <td class="num">{{ $m($l->Credit) }}</td>
                <td class="num">{{ $bal($l->Balance) }}</td>
            </tr>
        @endforeach
        <tr>
            <td colspan="5"><strong>Balance carried forward</strong></td>
            <td class="num"><strong>{{ Format::r($a->Debits) }}</strong></td>
            <td class="num"><strong>{{ Format::r($a->Credits) }}</strong></td>
            <td class="num"><strong>{{ $bal($a->Closing) }}</strong></td>
        </tr>
    </tbody>
</table>

<table class="age" style="margin-top:6mm">
    <tr>
        <th>Current</th><th>30 days</th><th>60 days</th><th>90 days</th><th>120 days +</th><th>Total</th>
    </tr>
    <tr>
        <td>{{ $bal($a->AgeCurrent) }}</td>
        <td>{{ Format::r($a->Age30) }}</td>
        <td>{{ Format::r($a->Age60) }}</td>
        <td>{{ Format::r($a->Age90) }}</td>
        <td>{{ Format::r($a->Age120) }}</td>
        <td><strong>{{ $bal($a->Closing) }}</strong></td>
    </tr>
</table>

@if ($note !== '')
    <p class="small" style="margin-top:6mm">{{ $note }}</p>
@endif

</body>
</html>
