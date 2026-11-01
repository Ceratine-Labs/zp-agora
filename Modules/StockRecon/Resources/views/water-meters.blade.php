@php
    $t = $grid->totals;
@endphp
<x-app-shell title="Water meter reconciliation" wide>
    <x-page-head eyebrow="Control · stock" title="Water meter reconciliation">
        <x-slot:info>
            <p>These mapped water items record a cumulative meter reading in litres. They are excluded from physical
                stock variance and new balancing previews. Dispensed litres are the current captured closing reading
                less the previous scheduled shift’s captured closing reading, even when the baseline is before the selected period.</p>
            <p>Negative, missing, duplicate, backward or reset readings and missing shifts cannot establish a valid interval.
                They remain visible with no calculated volume or monetary loss. A first reading needs a previous captured reading;
                the opening stock field is not a substitute baseline. No signs or decimal places are corrected automatically.</p>
            <p>POS litres are the original POS quantity for that shift. Only comparable intervals contribute to the summary.
                Original and working values remain available under Columns. Source corrections require separate approval.</p>
        </x-slot:info>
        <x-slot:actions>
            <a class="btn-ghost" href="{{ route('app.stockrecon.variance') }}">Physical stock variance</a>
            <a class="btn-ghost" href="{{ route('app.stockrecon.index') }}">Stock recon centre</a>
        </x-slot:actions>
    </x-page-head>
    @if ($grid->refusal === null)
        <x-statstrip style="margin-bottom:16px" :stats="[
            ['label' => 'Readings', 'value' => \App\Support\Format::n($t['Lines'] ?? 0)],
            ['label' => 'Comparable intervals', 'value' => \App\Support\Format::n($t['ComparableIntervals'] ?? 0)],
            ['label' => 'Needs source review', 'value' => \App\Support\Format::n($t['InvalidIntervals'] ?? 0), 'tone' => (int) ($t['InvalidIntervals'] ?? 0) > 0 ? 'warn' : 'neutral'],
            ['label' => 'Dispensed (L)', 'value' => \App\Support\Format::n($t['ComparableMeterLitres'] ?? null, 3), 'note' => 'Comparable intervals only'],
            ['label' => 'POS litres', 'value' => \App\Support\Format::n($t['ComparablePOSLitres'] ?? null, 3), 'note' => 'Same comparable intervals'],
            ['label' => 'Dispensed less POS (L)', 'value' => \App\Support\Format::n($t['DifferenceLitres'] ?? null, 3), 'note' => 'Quantity comparison, not a loss value'],
        ]" />
    @endif
    <p class="muted">Read only · Historical capture errors remain for source review. The report preserves the recorded readings.</p>
    <x-data-grid :grid="$grid" :branches="$branches" surface />
</x-app-shell>
