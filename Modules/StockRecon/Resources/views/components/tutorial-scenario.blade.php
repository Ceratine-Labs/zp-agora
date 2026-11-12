@props(['scenario'])

@php
    $scenarioId = $scenario['id'].'-'.\Illuminate\Support\Str::random(6);
    $states = ['before' => $scenario['before']];
    $labels = ['before' => 'Original figures', 'prepared' => $scenario['prepared_label'] ?? 'Repaired opening basis', 'after' => 'Worked proposal'];
    if ($scenario['prepared'] !== null) {
        $states['prepared'] = $scenario['prepared'];
    }
    if ($scenario['after'] !== null) {
        $states['after'] = $scenario['after'];
    }
    $hasWaste = collect($scenario['before'])->contains(fn ($row) => array_key_exists('waste', $row));
    $separatePeriods = $scenario['separate_periods'] ?? false;
@endphp

<section class="sr-example" data-tutorial-scenario="{{ $scenario['id'] }}" aria-labelledby="example-{{ $scenarioId }}">
    <header class="sr-example-head">
        <div>
            <span class="sr-overline">Worked example</span>
            <h3 id="example-{{ $scenarioId }}">{{ $scenario['title'] }}</h3>
            <p>{{ $scenario['summary'] }}</p>
        </div>
        <span class="sr-example-mark"><x-stockrecon::tutorial-icon name="scale" /></span>
    </header>

    <p class="sr-training-label"><x-stockrecon::tutorial-icon name="book" /> Training example — no stock changes</p>

    @if (count($states) > 1)
        <div class="sr-example-switch" data-scenario-controls hidden role="group" aria-label="Compare example figures">
            @foreach ($states as $state => $rows)
                <button type="button" data-scenario-show="{{ $state }}" aria-pressed="{{ $loop->first ? 'true' : 'false' }}" aria-controls="{{ $scenarioId }}-{{ $state }}">{{ $loop->iteration }}. {{ $labels[$state] }}</button>
            @endforeach
        </div>
    @endif

    @foreach ($states as $state => $rows)
        @php
            $variances = array_map(fn ($row) => $row['pos'] - ($row['open'] + $row['issued'] - $row['close']) + ($row['waste'] ?? 0), $rows);
            $net = round(array_sum($variances), 3);
            $unbalanced = count(array_filter($variances, fn ($variance) => abs($variance) >= .0005));
            $isBalanced = $unbalanced === 0;
            $short = -array_sum(array_filter($variances, fn ($variance) => $variance < 0));
            $over = array_sum(array_filter($variances, fn ($variance) => $variance > 0));
            $tone = $isBalanced ? 'good' : ($short > 0 ? 'serious' : 'warn');
        @endphp
        <div class="sr-example-state" data-scenario-state="{{ $state }}" id="{{ $scenarioId }}-{{ $state }}">
            <div class="sr-example-result">
                <div>
                    <span class="sr-overline">{{ $labels[$state] }}</span>
                    @if ($separatePeriods)
                        <p class="sr-example-number" data-tone="serious">Keep separate</p>
                        <span>Two continuous periods · no combined result</span>
                    @else
                        <p class="sr-example-number" data-tone="{{ $tone }}">{{ $net > 0 ? '+' : '' }}{{ \App\Support\Format::qty($net) }} <span>units net</span></p>
                        <span>Short {{ \App\Support\Format::qty($short) }} · Over {{ \App\Support\Format::qty($over) }}{{ $hasWaste ? ' · after applied waste' : '' }}</span>
                    @endif
                </div>
                <div class="sr-example-outcome">
                    <x-chip :tone="$tone">{{ $separatePeriods ? 'Separate results' : ($isBalanced ? 'All shifts balance' : (abs($net) < .0005 ? 'Net zero · differences remain' : ($net < 0 ? 'Short remains' : 'Over remains'))) }}</x-chip>
                    <span>{{ $unbalanced === 0 ? 'Every shift balances.' : $unbalanced.' '.\Illuminate\Support\Str::plural('shift', $unbalanced).' still '.($unbalanced === 1 ? 'has' : 'have').' a variance.' }}</span>
                </div>
            </div>

            <div class="sr-shift-results" aria-label="Variance on each shift">
                @foreach ($rows as $rowIndex => $row)
                    <span><strong>{{ $row['shift'] }}</strong><x-chip :tone="abs($variances[$rowIndex]) < .0005 ? 'good' : ($variances[$rowIndex] < 0 ? 'serious' : 'warn')">{{ $variances[$rowIndex] > 0 ? '+' : '' }}{{ \App\Support\Format::qty($variances[$rowIndex]) }}</x-chip></span>
                @endforeach
            </div>

            <x-table :layout-key="$hasWaste ? 'StockRecon.components.tutorial-scenario.waste' : 'StockRecon.components.tutorial-scenario.1'" :count="count($rows)" :aria-label="$scenario['title'].' — '.$labels[$state]">
                <x-slot:head>
                    <tr>
                        <th scope="col">Shift</th>
                        <th scope="col" class="num">Open</th>
                        <th scope="col" class="num">Issued</th>
                        <th scope="col" class="num">POS</th>
                        <th scope="col" class="num">Close</th>
                        @if ($hasWaste)<th scope="col" class="num">Applied waste</th>@endif
                        <th scope="col" class="num">Variance</th>
                    </tr>
                </x-slot:head>
                @foreach ($rows as $rowIndex => $row)
                    <tr>
                        <th scope="row">{{ $row['shift'] }}</th>
                        @foreach ($hasWaste ? ['open', 'issued', 'pos', 'close', 'waste'] : ['open', 'issued', 'pos', 'close'] as $column)
                            @php($changed = $state !== 'before' && $row[$column] !== ($scenario['before'][$rowIndex][$column] ?? $row[$column]))
                            <td class="num">
                                @if ($changed)
                                    <span class="sr-example-prior"><span class="visually-hidden">Was </span>{{ \App\Support\Format::qty($scenario['before'][$rowIndex][$column]) }}</span>
                                @endif
                                <span @class(['sr-example-changed' => $changed])>{{ \App\Support\Format::qty($row[$column]) }}</span>
                            </td>
                        @endforeach
                        <td class="num"><strong class="sr-example-variance" data-tone="{{ abs($variances[$rowIndex]) < .0005 ? 'good' : ($variances[$rowIndex] < 0 ? 'serious' : 'warn') }}">{{ $variances[$rowIndex] > 0 ? '+' : '' }}{{ \App\Support\Format::qty($variances[$rowIndex]) }}</strong></td>
                    </tr>
                @endforeach
            </x-table>
            @if ($state !== 'before')
                <p class="sr-example-caption">Highlighted figures differ from the original example; the original value appears beside each change.{{ $hasWaste ? ' Variance includes the applied good waste, shown separately from source quantities.' : '' }}</p>
            @endif
        </div>
    @endforeach

    <div class="sr-example-explanation">
        <h4>Read the result</h4>
        <p>{{ $scenario['explanation'] }}</p>
        @if ($scenario['steps'])
            <ol class="sr-example-steps">
                @foreach ($scenario['steps'] as $step)
                    <li><strong>{{ $step['title'] }}</strong><span>{{ $step['text'] }}</span></li>
                @endforeach
            </ol>
        @endif
        @if ($scenario['checks'])
            <details class="sr-checkpoint">
                <summary><x-stockrecon::tutorial-icon name="search" /> What should I check?</summary>
                <ul>@foreach ($scenario['checks'] as $check)<li>{{ $check }}</li>@endforeach</ul>
            </details>
        @endif
    </div>
</section>
