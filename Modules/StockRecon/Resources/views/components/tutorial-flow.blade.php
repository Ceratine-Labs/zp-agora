@props(['cases', 'scenarios'])

<div class="sr-visual-cases">
    <nav class="sr-flow-picker" aria-label="Diagram cases">
        @foreach ($cases as $case)
            <a href="{{ route('app.stockrecon.tutorial', ['lesson' => 'visual-summary', 'example' => $case['id']]) }}#tutorial-flow-{{ $case['id'] }}"
               data-flow-case-link="{{ $case['id'] }}" aria-controls="tutorial-flow-{{ $case['id'] }}">
                <span>{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>{{ $case['title'] }}
            </a>
        @endforeach
    </nav>

    @foreach ($cases as $case)
        @php($scenario = $scenarios[$case['scenario_id']])
        <section class="sr-flow-case" id="tutorial-flow-{{ $case['id'] }}" data-flow-case="{{ $case['id'] }}" aria-labelledby="flow-heading-{{ $case['id'] }}">
            <header class="sr-flow-heading">
                <p class="sr-overline">Case {{ $loop->iteration }} of {{ count($cases) }} · fixed training example</p>
                <h3 id="flow-heading-{{ $case['id'] }}" tabindex="-1" data-flow-title>{{ $case['title'] }}</h3>
                <p>{{ $case['summary'] }}</p>
                <p class="sr-flow-assumptions"><x-stockrecon::tutorial-icon name="box" />{{ $case['assumptions'] }}</p>
            </header>

            <div class="sr-flow-inputs">
                <h4>Start here: the recorded quantities</h4>
                <p>Variance = POS − (Opening + Issued − Closing). Negative = short; positive = over.</p>
                <div class="sr-flow-shifts">
                    @foreach ($scenario['before'] as $row)
                        @php($variance = $row['pos'] - ($row['open'] + $row['issued'] - $row['close']))
                        <div class="sr-flow-shift">
                            <strong>{{ $row['shift'] }}</strong>
                            <dl>
                                @foreach (['open' => 'Opening', 'issued' => 'Issued', 'pos' => 'POS', 'close' => 'Closing'] as $key => $label)
                                    <div><dt>{{ $label }}</dt><dd>{{ \App\Support\Format::qty($row[$key]) }}</dd></div>
                                @endforeach
                            </dl>
                            <span class="sr-flow-variance" data-tone="{{ $variance === 0 ? 'good' : 'warn' }}">{{ $variance > 0 ? '+' : '' }}{{ \App\Support\Format::qty($variance) }} · {{ $variance < 0 ? 'short' : ($variance > 0 ? 'over' : 'balanced') }}</span>
                        </div>
                    @endforeach
                </div>
            </div>

            <p class="sr-flow-key"><span aria-hidden="true">↓</span> Follow the solid arrows. Side branches explain a different outcome.</p>
            <ol class="sr-flow-spine" aria-label="{{ $case['title'] }} — decision path">
                @foreach ($case['steps'] as $step)
                    <li>
                        <div class="sr-flow-node {{ isset($step['answer']) ? 'sr-flow-decision' : '' }}" data-flow-stage="{{ $step['stage'] }}">
                            <span class="sr-flow-step">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }} · {{ isset($step['answer']) ? 'Decision' : 'Step' }}</span>
                            <h4>{{ $step['title'] }}</h4>
                            <p>{{ $step['text'] }}</p>
                            <strong class="sr-flow-value">{{ $step['value'] }}</strong>
                            @isset($step['answer'])<span class="sr-flow-answer">{{ $step['answer'] }} <span aria-hidden="true">↓</span></span>@endisset
                        </div>
                        @isset($step['alternative'])
                            <aside class="sr-flow-branch"><strong>Other path</strong><p>{{ $step['alternative'] }}</p></aside>
                        @endisset
                    </li>
                @endforeach
            </ol>

            <div class="sr-flow-finish" data-flow-outcome="{{ $case['outcome'] }}">
                <p class="sr-overline">{{ $case['outcome'] === 'proposal' ? 'Proposed result · no write yet' : ($case['outcome'] === 'held' ? 'Stop · evidence required' : 'Review the remaining differences') }}</p>
                <h4>What happens next?</h4>
                <p>{{ $case['next'] }}</p>
                @if ($case['outcome'] === 'proposal')
                    <div class="sr-flow-commit-gate">
                        <strong>A reviewer authorises Amend → the selected writes are rechecked</strong>
                        <div class="sr-flow-fork">
                            <div><span>Checks pass</span><strong>Eligible changes write</strong><p>Read the receipt and audit priors. Check the remaining variance.</p></div>
                            <div><span>A recheck fails</span><strong>Writes are skipped or refused</strong><p>Read the reason and preview again. A committed run may still contain skipped rows; some checks refuse the whole run.</p></div>
                        </div>
                    </div>
                @endif
                <a href="{{ route('app.stockrecon.tutorial', ['lesson' => $case['lesson_id']]) }}" data-lesson-link="{{ $case['lesson_id'] }}">Read the full explanation →</a>
            </div>
        </section>
    @endforeach
</div>
