{{--
    Original vs amended variance (ZRP, 19 Oct 2026) — after balancing, every
    count line's variance as counted beside its variance as amended, over any
    period, with the totals of both sides above the grid: what PumpIT's Stock
    Recon Balancing screen shows in its corner, here for every line that matched
    rather than one area.

    The strip reads the procedure's third result set (GridResult::totals), so
    it always describes exactly the lines the grid matched — filters included.

    Props: $grid (App\Grid\GridResult), $branches (sites for the selector, or null)
--}}
@php
    $t = $grid->totals;
    $was = fn (string $key, int $places = 2) => $places === 3
        ? 'As counted '.\App\Support\Format::n($t[$key] ?? null, 3)
        : 'As counted '.\App\Support\Format::r($t[$key] ?? null);
    $tone = fn (?float $value) => $value === null || abs($value) < 0.005 ? 'neutral' : ($value < 0 ? 'crit' : 'warn');
@endphp
{{-- Wide: a grid of 25 columns is scanned across, not read. --}}
<x-app-shell title="Original vs amended variance" wide>
    <x-page-head eyebrow="Control · stock" title="Original vs amended variance">
        <x-slot:info>
            <p>Every count line in the period, two ways. <strong>Original</strong> is the variance on the quantities as
               counted and sold — <code>POS &minus; (Open + Issued &minus; Close)</code> on PumpIT's
               <code>_Original</code> columns. <strong>Amended</strong> is the same sum on the quantities as they stand
               now, after balancing, whether Agora balanced them or somebody did by hand in PumpIT.
               <strong>Moved by balancing</strong> is the amended value less the original.</p>
            <p>Balancing moves a short from one shift onto the over on another, so the totals show what it did: the
               shorts and the overs as counted, and what is left of them after. The arithmetic and the colours are
               PumpIT's Stock Recon Balancing screen's — red is short, yellow is over by more than the item's
               allowance. Good waste can explain a short on the amended side; it never makes an over.</p>
            <p>Any period can be chosen. A very wide one across every site is refused with a count of the lines, so
               narrow the sites or the dates.</p>
        </x-slot:info>
        <x-slot:actions>
            <a class="btn-ghost" href="{{ route('app.stockrecon.index') }}">Stock recon centre</a>
        </x-slot:actions>
    </x-page-head>

    @if ($grid->refusal === null && (int) ($t['Lines'] ?? 0) > 0)
        {{-- After balancing, with the figure as counted under each. --}}
        <x-statstrip style="margin-bottom:16px" :stats="[
            ['label' => 'Value variance', 'value' => \App\Support\Format::r($t['ValueVar'] ?? null),
             'note' => $was('ValueVarOriginal'), 'tone' => $tone($t['ValueVar'] ?? null)],
            ['label' => 'Value short', 'value' => \App\Support\Format::r($t['ValueShort'] ?? null),
             'note' => $was('ValueShortOriginal'), 'tone' => $tone($t['ValueShort'] ?? null)],
            ['label' => 'Value over', 'value' => \App\Support\Format::r($t['ValueOver'] ?? null),
             'note' => $was('ValueOverOriginal'), 'tone' => $tone($t['ValueOver'] ?? null)],
            ['label' => 'Quantity variance', 'value' => \App\Support\Format::n($t['QtyVar'] ?? null, 3),
             'note' => $was('QtyVarOriginal', 3)],
            ['label' => 'POS quantity', 'value' => \App\Support\Format::n($t['QtyComputer'] ?? null, 3),
             'note' => $was('QtyComputerOriginal', 3)],
            ['label' => 'Lines', 'value' => \App\Support\Format::n($t['Lines'] ?? null),
             'note' => \App\Support\Format::n($t['LinesAmended'] ?? null).' amended by balancing'],
        ]" />
    @endif

    <x-data-grid :grid="$grid" :branches="$branches" surface />
</x-app-shell>
