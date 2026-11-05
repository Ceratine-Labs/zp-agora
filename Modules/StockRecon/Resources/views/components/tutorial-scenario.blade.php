@props(['scenario'])

@php
    $scenarioId = $scenario['id'].'-'.\Illuminate\Support\Str::random(6);
    $states = ['before' => $scenario['before']];
    if ($scenario['after'] !== null) {
        $states['after'] = $scenario['after'];
    }
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
            <button type="button" data-scenario-show="before" aria-pressed="true" aria-controls="{{ $scenarioId }}-before">1. Original figures</button>
            <button type="button" data-scenario-show="after" aria-pressed="false" aria-controls="{{ $scenarioId }}-after">2. Worked proposal</button>
        </div>
    @endif

    @foreach ($states as $state => $rows)
        @php
            $variances = array_map(fn ($row) => $row['pos'] - ($row['open'] + $row['issued'] - $row['close']), $rows);
            $net = round(array_sum($variances), 3);
            $unbalanced = count(array_filter($variances, fn ($variance) => abs($variance) >= .0005));
            $isBalanced = abs($net) < .0005;
        @endphp
        <div class="sr-example-state" data-scenario-state="{{ $state }}" id="{{ $scenarioId }}-{{ $state }}">
            <div class="sr-example-result">
                <div>
                    <span class="sr-overline">{{ $state === 'before' ? 'Original figures' : 'Worked proposal' }}</span>
                    <p class="sr-example-number" data-tone="{{ $isBalanced ? 'good' : ($net < 0 ? 'serious' : 'warn') }}">{{ $net > 0 ? '+' : '' }}{{ \App\Support\Format::qty($net) }} <span>units</span></p>
                    <span>Net variance across these shifts</span>
                </div>
                <div class="sr-example-outcome">
                    <x-chip :tone="$isBalanced ? 'good' : ($net < 0 ? 'serious' : 'warn')">{{ $isBalanced ? 'Net zero' : ($net < 0 ? 'Short remains' : 'Over remains') }}</x-chip>
                    <span>{{ $unbalanced === 0 ? 'Every shift balances.' : $unbalanced.' '.\Illuminate\Support\Str::plural('shift', $unbalanced).' still '.($unbalanced === 1 ? 'has' : 'have').' a variance.' }}</span>
                </div>
            </div>

            <x-table layout-key="StockRecon.components.tutorial-scenario.1" :count="count($rows)" :aria-label="$scenario['title'].' — '.($state === 'before' ? 'original figures' : 'worked proposal')">
                <x-slot:head>
                    <tr>
                        <th scope="col">Shift</th>
                        <th scope="col" class="num">Open</th>
                        <th scope="col" class="num">Issued</th>
                        <th scope="col" class="num">POS</th>
                        <th scope="col" class="num">Close</th>
                        <th scope="col" class="num">Variance</th>
                    </tr>
                </x-slot:head>
                @foreach ($rows as $rowIndex => $row)
                    <tr>
                        <th scope="row">{{ $row['shift'] }}</th>
                        @foreach (['open', 'issued', 'pos', 'close'] as $column)
                            @php($changed = $state === 'after' && $row[$column] !== ($scenario['before'][$rowIndex][$column] ?? $row[$column]))
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
            @if ($state === 'after')
                <p class="sr-example-caption">Highlighted figures change in this worked example. The previous value appears beside each change.</p>
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
