{{--
    Cash → Deductions (T041): PumpIT's five head-office payroll reports as link
    tabs — each its own grid over its own procedure, ported row for row — and
    the Payroll tab, where a pay period's schedule is built into Agora and the
    payroll file taken from it.

    Every tab carries the grid's own scope (sites, period) to the next, so
    moving between them never changes what is being looked at. The stat strip
    reads the grid's grand totals, so strip and footer are the same numbers.

    Props: $tab, $grid, $totals, $rows, $from, $to, $branchIds, $leniency,
           $unposted (Cash and Payroll: approved shorts on no cashup),
           $branches (Payroll only)
--}}
@php
    use App\Support\Format;

    $keep = collect(request()->query())->only(['branches', 'from', 'to'])->all();
    $t = fn (string $key) => (float) ($totals[$key] ?? 0);
    $money = fn (string $key) => Format::r(abs($t($key)));
    $built = (array) session('deductionBuilt', []);
    $refused = (array) session('deductionRefused', []);
@endphp
<x-app-shell title="Cash shorts and deductions">
    <x-page-head
        eyebrow="Cash · head office"
        title="Cash shorts and deductions"
        blurb="What each person was short, the leniency allowed, and what comes off their pay — PumpIT's five payroll reports, and the schedule payroll is given.">
        <x-slot:actions>
            @can('cash.staffshort.view')
                <a class="btn-ghost" href="{{ route('app.cash.staffshorts.list') }}">Staff shorts</a>
            @endcan
            @can('cash.pumpshortages.view')
                <a class="btn-ghost" href="{{ route('app.cash.pumpshortages') }}">Pump shortages</a>
            @endcan
        </x-slot:actions>
    </x-page-head>

    <x-tabs :active="$tab" label="Deduction reports" style="margin-bottom:16px" :items="[
        ['key' => 'cash', 'label' => 'Cash short deductions', 'href' => route('app.cash.deductions', $keep)],
        ['key' => 'stock', 'label' => 'Stock loss — summary', 'href' => route('app.cash.deductions.stockloss', $keep)],
        ['key' => 'stockdetail', 'label' => 'Stock loss — detail', 'href' => route('app.cash.deductions.stocklossdetail', $keep)],
        ['key' => 'nonint', 'label' => 'Non-integrated POS — summary', 'href' => route('app.cash.deductions.nonint', $keep)],
        ['key' => 'nonintdetail', 'label' => 'Non-integrated POS — detail', 'href' => route('app.cash.deductions.nonintdetail', $keep)],
        ['key' => 'payroll', 'label' => 'Payroll', 'href' => route('app.cash.deductions.payroll', $keep)],
    ]" />

    @foreach ($built as $line)
        <x-notice tone="good" title="Built" style="margin-bottom:12px"><p>{{ $line }}</p></x-notice>
    @endforeach
    @foreach ($refused as $line)
        <x-notice tone="stop" title="Not built" style="margin-bottom:12px"><p>{{ $line }}</p></x-notice>
    @endforeach

    @if (($unposted->Shorts ?? 0) > 0)
        {{-- T042: a short reaches payroll only once it is on a cashup; say which are not, before the period is built. --}}
        <x-notice tone="warn" title="{{ Format::n((int) $unposted->Shorts) }} approved staff {{ (int) $unposted->Shorts === 1 ? 'short is' : 'shorts are' }} on no cashup yet"
                  style="margin-bottom:12px">
            <p>{{ Format::r((float) $unposted->Amount) }} approved in this period, the oldest from
               {{ \Illuminate\Support\Carbon::parse($unposted->Oldest)->format('j M Y') }}, is on no cashup's pump shortages,
               so nobody is deducted for it. Post it to the cashup it belongs to, then build the schedule.</p>
            @can('cash.staffshort.view')
                <p><a href="{{ route('app.cash.staffshorts.list', array_filter([
                        'f' => ['Status' => ['in' => ['Approved']]],
                        'from' => $from, 'to' => $to,
                        'branches' => $branchIds === [] ? null : implode(',', $branchIds),
                    ])) }}">The approved shorts →</a></p>
            @endcan
        </x-notice>
    @endif

    @switch($tab)
        @case('cash')
            <x-statstrip :stats="[
                ['label' => 'Shifts deducted', 'value' => Format::n($rows), 'note' => Format::n($t('Employees')).' people', 'tone' => 'neutral'],
                ['label' => 'Cashier shorts', 'value' => $money('AllCashierShorts'), 'note' => 'before leniency', 'tone' => 'neutral'],
                ['label' => 'Leniency allowed', 'value' => $money('WrittenOffAmount'),
                 'note' => Format::n($t('WrittenOffShifts')).' shifts written off at or inside '.Format::r($leniency), 'tone' => 'good'],
                ['label' => 'Deducted', 'value' => $money('Deduction'), 'note' => 'recovered from pay', 'tone' => 'serious'],
            ]" />
            @break
        @case('stock')
        @case('stockdetail')
        @case('nonint')
        @case('nonintdetail')
            <x-statstrip :stats="[
                ['label' => $tab === 'stock' || $tab === 'nonint' ? 'Employee / area lines' : 'Detail lines', 'value' => Format::n($rows), 'tone' => 'neutral'],
                ['label' => 'Total loss', 'value' => $money('EmployeeTotalLoss'), 'note' => 'at the selling price', 'tone' => 'serious'],
                ['label' => 'Employees', 'value' => Format::n($t('Employees')), 'tone' => 'neutral'],
                ['label' => 'Areas', 'value' => Format::n($t('Areas')), 'tone' => 'neutral'],
            ]" />
            @break
        @case('payroll')
            <x-statstrip :stats="[
                ['label' => 'Schedule lines', 'value' => Format::n($rows), 'note' => Format::n($t('Runs')).' run(s) in the period', 'tone' => 'neutral'],
                ['label' => 'Short', 'value' => $money('Amount'), 'note' => 'everything that was short', 'tone' => 'neutral'],
                ['label' => 'Written off', 'value' => $money('WrittenOffAmount'), 'note' => 'inside an allowance', 'tone' => 'good'],
                ['label' => 'Deducted', 'value' => $money('Deducted'), 'note' => Format::n($t('Employees')).' people · the payroll file adds up to this', 'tone' => 'serious'],
            ]" />
            @break
    @endswitch

    <div style="margin-top:16px">
        <x-data-grid :grid="$grid" surface />
    </div>

    @if ($tab === 'payroll')
        @php
            $siteNames = $branches->whereIn('BranchId', $branchIds)->pluck('Name')->all();
            $scopeWords = $siteNames === [] ? 'every trading site you may see' : implode(', ', $siteNames);
        @endphp
        <x-card title="The pay period" sub="Build the schedule, check it above, then take the payroll file." style="margin-top:16px">
            <p class="field-help" style="margin-bottom:12px">
                <strong>{{ $scopeWords }}</strong> · {{ \Illuminate\Support\Carbon::parse($from)->format('j M Y') }}
                to {{ \Illuminate\Support\Carbon::parse($to)->format('j M Y') }}. Change the sites and the dates on the grid.
            </p>
            <div class="form-actions" style="gap:8px">
                @can('cash.deductions.build')
                    <form method="POST" action="{{ route('app.cash.deductions.build') }}"
                          data-confirm="Build the deduction schedule?"
                          data-confirm-text="For {{ $scopeWords }}, {{ $from }} to {{ $to }}. A site that already has a schedule for exactly this period gets a new one; the old one is kept, marked superseded. Nothing in PumpIT changes."
                          data-confirm-action="Build" data-loader="Building the schedule…">
                        @csrf
                        @foreach ($branchIds as $id)<input type="hidden" name="branches[]" value="{{ $id }}">@endforeach
                        <input type="hidden" name="from" value="{{ $from }}">
                        <input type="hidden" name="to" value="{{ $to }}">
                        <button type="submit" class="btn-primary">Build the schedule</button>
                    </form>
                @endcan
                @can('cash.deductions.export')
                    <form method="POST" action="{{ route('app.cash.deductions.export') }}"
                          data-confirm="Take the payroll file?"
                          data-confirm-text="One line per person per site, from the schedules built for {{ $from }} to {{ $to }}. Those schedules are marked exported: the period cannot then be rebuilt."
                          data-confirm-action="Download">
                        @csrf
                        @foreach ($branchIds as $id)<input type="hidden" name="branches[]" value="{{ $id }}">@endforeach
                        <input type="hidden" name="from" value="{{ $from }}">
                        <input type="hidden" name="to" value="{{ $to }}">
                        <button type="submit" class="btn-ghost">Payroll file (CSV)</button>
                    </form>
                @endcan
            </div>
        </x-card>
    @endif

    <x-explainer summary="Where these figures come from" remember="cash-deductions">
        <x-notice tone="info" title="The same reports PumpIT prints">
            <p>Each tab is PumpIT's report of the same name, row for row — measured identical on Engen Bethlehem for
               August 2026. <strong>Cash short deductions</strong>: a cashup's legs added up, less the Z reading; a short
               at or inside {{ Format::r($leniency) }} is written off, anything worse is deducted <em>in full</em> — there is
               no partial rule. Pump attendant shorts and approved cash-bag shorts are deducted in full.</p>
            <p><strong>Stock loss</strong> is attributed to whoever was signed on to the counting area, and shared equally
               when several were — read it as where to look, not as proof of who took it. In the schedule it is measured
               per area group against the days in the period times the group's daily grace: inside it, written off;
               beyond it, deducted in full.</p>
            <p><strong>Non-integrated POS</strong> in PumpIT means the Lotto / Electricity / Airtime areas: a person's share
               of the loss there, R1.00 or more.</p>
        </x-notice>
    </x-explainer>
</x-app-shell>
