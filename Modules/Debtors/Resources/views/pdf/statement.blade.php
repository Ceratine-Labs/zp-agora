{{--
    A debtor's statement, as the PDF that is mailed and downloaded — ZP's
    DETAILED template (12 Nov 2026): title, period, a navy band with the
    account, its email, the last transaction date and the balance due (to) /
    from the customer, then one row per document with litres, fuel type, KM,
    registration, order number, amount and the running balance.

    Departures from the template, on purpose:
      * the running balance starts from the balance brought forward, not from
        nil, so the last row agrees with the balance in the band; the first
        row says what was brought forward and the last what is carried;
      * the ageing the statement has always carried sits under the table.

    Rendered by Dompdf, which reads a narrow, older dialect of CSS: tables for
    layout, no flex or grid, no custom properties, no web fonts (DejaVu Sans is
    built in). Self-contained on purpose — remote loading is switched off in
    DebtorStatementService. A4 landscape: eleven columns do not fit portrait.

    Every figure is agora.usp_Debtors_GetStatement's, exactly as the statement
    screen shows it.

    Props: $a (the account row), $lines, $issuer, $note
--}}
@php
    use Illuminate\Support\Carbon;

    $ymd = fn ($v) => $v === null ? '' : Carbon::parse((string) $v)->format('Y/m/d');
    $n2 = fn ($v) => $v === null ? '' : number_format((float) $v, 2, '.', '');
    $navy = '#14127a';
@endphp
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Debtor statement {{ $a->StatementAccountNo }}</title>
<style>
    @page { margin: 10mm 10mm 12mm 10mm; }
    body { font-family: "DejaVu Sans", sans-serif; font-size: 8pt; color: #111; }
    table { border-collapse: collapse; width: 100%; }
    h1 { font-size: 15pt; margin: 0; text-align: center; letter-spacing: 0.3pt; }
    .period { font-size: 7.5pt; font-weight: bold; margin: 1.5mm 0 1.5mm 0; }
    .band { background: {{ $navy }}; color: #fff; font-weight: bold; }
    .band td { padding: 1.2mm 2.5mm; }
    .band .big { font-size: 10.5pt; }
    .lines th { background: {{ $navy }}; color: #fff; font-size: 7pt; font-weight: bold; text-align: center;
                padding: 1.6mm 1mm; border: 0.4pt solid #fff; }
    .lines td { border: 0.4pt solid #9a9a9a; padding: 1.1mm 1.2mm; font-size: 7.5pt; text-align: center; }
    .lines td.l { text-align: left; }
    .lines td.r { text-align: right; white-space: nowrap; }
    .lines tr.edge td { font-style: italic; background: #f1f1f6; }
    .age th { border: 0.5pt solid #888; padding: 1.2mm; font-size: 7pt; text-align: right; background: #eee; }
    .age td { border: 0.5pt solid #888; padding: 1.5mm 1.2mm; text-align: right; }
    .small { font-size: 7pt; }
</style>
</head>
<body>

<table>
    <tr>
        <td style="width:30%"></td>
        <td style="width:40%; vertical-align:bottom"><h1>DEBTOR STATEMENT</h1></td>
        <td style="width:30%; vertical-align:top; text-align:right" class="small">
            <strong>{{ $a->BranchName }}</strong>
            @if ($issuer !== '' && $issuer !== $a->BranchName)
                <br>{{ $issuer }}
            @endif
        </td>
    </tr>
</table>

<div class="period">Date From : {{ $ymd($a->PeriodFrom) }},&nbsp; Date To : {{ $ymd($a->PeriodTo) }}</div>

<table class="band">
    <tr>
        <td class="big" style="width:22%; padding-top:2.5mm">ACCOUNT NUMBER</td>
        <td class="big" style="width:30%; padding-top:2.5mm">{{ $a->StatementAccountNo }}</td>
        <td style="width:30%"></td>
        <td style="width:18%"></td>
    </tr>
    <tr>
        <td class="big">ACCOUNT NAME</td>
        <td class="big" colspan="3">{{ $a->AccountName }}</td>
    </tr>
    <tr>
        <td class="big" style="padding-top:3mm">EMAIL :</td>
        <td style="padding-top:3mm">{{ $a->Email }}</td>
        <td style="padding-top:3mm">LAST TRANSACTION DATE</td>
        <td style="padding-top:3mm">{{ $ymd($a->LastTransactionDate) }}</td>
    </tr>
    <tr>
        <td style="padding-bottom:2.5mm"></td>
        <td style="padding-bottom:2.5mm"></td>
        <td style="padding-bottom:2.5mm">BALANCE DUE (TO) / FROM CUSTOMER</td>
        <td style="padding-bottom:2.5mm">{{ $n2($a->Closing) }}</td>
    </tr>
</table>

<table class="lines" style="margin-top:1mm">
    <thead>
        <tr>
            <th style="width:8%">DOCUMENT<br>DATE</th>
            <th style="width:8%">DOCUMENT<br>NUMBER</th>
            <th style="width:7.5%">DOCUMENT<br>TYPE</th>
            <th>DESCRIPTION</th>
            <th style="width:5.5%">LT</th>
            <th style="width:8.5%">FUEL TYPE</th>
            <th style="width:6.5%">KM</th>
            <th style="width:8.5%">REG</th>
            <th style="width:7%">ORDER NR</th>
            <th style="width:8%">AMOUNT</th>
            <th style="width:8%">BALANCE</th>
        </tr>
    </thead>
    <tbody>
        <tr class="edge">
            <td>{{ $ymd($a->PeriodFrom) }}</td>
            <td></td>
            <td></td>
            <td class="l">Balance brought forward</td>
            <td></td><td></td><td></td><td></td><td></td>
            <td class="r"></td>
            <td class="r">{{ $n2($a->Opening) }}</td>
        </tr>
        @foreach ($lines as $l)
            <tr>
                <td>{{ $ymd($l->TransactionDate) }}</td>
                <td>{{ $l->DocumentNo }}</td>
                <td>{{ $l->TranType }}</td>
                <td class="l">{{ $l->Description }}</td>
                <td class="r">{{ $n2($l->Litres) }}</td>
                <td>{{ $l->FuelType }}</td>
                <td>{{ $l->Km }}</td>
                <td>{{ $l->Reg }}</td>
                <td>{{ $l->OrderNo }}</td>
                <td class="r">{{ $n2($l->Amount) }}</td>
                <td class="r">{{ $n2($l->Balance) }}</td>
            </tr>
        @endforeach
        <tr class="edge">
            <td>{{ $ymd($a->PeriodTo) }}</td>
            <td></td>
            <td></td>
            <td class="l"><strong>Balance carried forward</strong></td>
            <td></td><td></td><td></td><td></td><td></td>
            <td class="r"></td>
            <td class="r"><strong>{{ $n2($a->Closing) }}</strong></td>
        </tr>
    </tbody>
</table>

<table class="age" style="margin-top:4mm; width:60%">
    <tr>
        <th>Current</th><th>30 days</th><th>60 days</th><th>90 days</th><th>120 days +</th><th>Total</th>
    </tr>
    <tr>
        <td>{{ $n2($a->AgeCurrent) }}</td>
        <td>{{ $n2($a->Age30) }}</td>
        <td>{{ $n2($a->Age60) }}</td>
        <td>{{ $n2($a->Age90) }}</td>
        <td>{{ $n2($a->Age120) }}</td>
        <td><strong>{{ $n2($a->Closing) }}</strong></td>
    </tr>
</table>

<p class="small" style="margin-top:3mm">
    A negative balance is money held for the customer (paid ahead).
    @if ($note !== '')
        {{ $note }}
    @endif
</p>

</body>
</html>
