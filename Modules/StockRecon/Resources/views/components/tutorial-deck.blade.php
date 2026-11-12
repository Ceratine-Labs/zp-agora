@props(['content', 'lesson' => 'purpose'])

@php
    $lessons = $content['lessons'];
    $events = collect($content['events'])->keyBy('id');
    $scenarios = collect($content['scenarios'])->keyBy('id');
    $activeIndex = collect($lessons)->search(fn ($chapter) => $chapter['id'] === $lesson);
    $activeIndex = $activeIndex === false ? 0 : $activeIndex;
    $lesson = $lessons[$activeIndex]['id'];
@endphp

<div {{ $attributes->merge(['class' => 'sr-tutorial']) }} data-stock-recon-tutorial data-active-lesson="{{ $lesson }}">
    <div class="sr-tutorial-intro">
        <span class="sr-tutorial-emblem"><x-stockrecon::tutorial-icon name="box" /></span>
        <div>
            <span class="sr-overline">For administrators, finance and management</span>
            <h2>Every correction needs an explanation.</h2>
            <p>Trace inputs → rules → proposal → actual write. Compare worked examples, including the shortages that must remain visible.</p>
        </div>
        <div class="sr-tutorial-facts">
            <span><strong>{{ count($lessons) }}</strong> chapters</span>
            <span><strong>{{ count($scenarios) }}</strong> worked examples</span>
            <span><strong>{{ count($events) }}</strong> events explained</span>
        </div>
    </div>

    <search class="sr-guide-search" data-guide-search hidden aria-label="Search the stock reconciliation guide">
        <label for="stock-recon-guide-search">Search this guide</label>
        <div class="sr-search-input-row">
            <x-stockrecon::tutorial-icon name="search" />
            <input id="stock-recon-guide-search" type="search" data-guide-query autocomplete="off" maxlength="200"
                   placeholder="Try issue multiple, opening, waste or D2…" aria-describedby="stock-recon-search-status" aria-controls="stock-recon-search-results">
            <button type="button" class="btn" data-guide-clear hidden>Clear</button>
        </div>
        <p id="stock-recon-search-status" data-guide-search-status role="status" aria-live="polite">Search every chapter, worked example, diagram and event code.</p>
        <ul id="stock-recon-search-results" class="sr-search-results" data-guide-search-results hidden></ul>
    </search>

    <div class="sr-tutorial-layout">
        <aside class="sr-tutorial-sidebar" aria-label="Tutorial contents">
            <div class="sr-tutorial-progress">
                <div><strong>Your place in the guide</strong><span data-tutorial-position>{{ $activeIndex + 1 }} / {{ count($lessons) }}</span></div>
                <progress data-tutorial-progress value="{{ $activeIndex + 1 }}" max="{{ count($lessons) }}" aria-label="Current chapter"></progress>
                <p>Move at your own pace. Choose any chapter.</p>
            </div>
            <a class="sr-search-shortcut" href="#stock-recon-guide-search" data-guide-search-link hidden><x-stockrecon::tutorial-icon name="search" /> Search this guide</a>
            <nav class="sr-chapter-nav" aria-label="Stock recon chapters">
                @foreach ($lessons as $chapter)
                    <a href="{{ route('app.stockrecon.tutorial', ['lesson' => $chapter['id']]) }}" data-lesson-link="{{ $chapter['id'] }}"
                       @if ($lesson === $chapter['id']) aria-current="step" @endif>
                        <span class="sr-chapter-number">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
                        <span>{{ $chapter['title'] }}</span>
                        <x-stockrecon::tutorial-icon :name="$chapter['icon']" />
                    </a>
                @endforeach
            </nav>
            <a class="sr-event-index-link" href="#tutorial-events"><x-stockrecon::tutorial-icon name="search" /> Find an event or code</a>
        </aside>

        <div class="sr-tutorial-stage" id="tutorial-stage">
            @foreach ($lessons as $chapter)
                @php($chapterIndex = $loop->index)
                <article class="sr-chapter" data-tutorial-lesson="{{ $chapter['id'] }}" aria-labelledby="chapter-{{ $chapter['id'] }}" @if ($chapter['id'] !== $lesson) hidden @endif>
                    <x-card class="sr-chapter-card">
                        <header class="sr-chapter-heading">
                            <span class="sr-chapter-icon"><x-stockrecon::tutorial-icon :name="$chapter['icon']" /></span>
                            <div>
                                <p class="sr-overline">Chapter {{ $loop->iteration }} · {{ $chapter['kicker'] }}</p>
                                <h2 id="chapter-{{ $chapter['id'] }}" tabindex="-1">{{ $chapter['title'] }}</h2>
                            </div>
                        </header>

                        <p class="sr-chapter-lead">{{ $chapter['summary'] }}</p>

                        @if (in_array($chapter['id'], ['purpose', 'figures'], true))
                            <div class="sr-formula" aria-label="Variance equals POS minus stock sold. Stock sold equals opening plus issued minus closing.">
                                <div class="sr-formula-top"><x-stockrecon::tutorial-icon name="scale" /><strong>The one calculation to remember</strong></div>
                                <div class="sr-formula-terms" aria-hidden="true">
                                    <span><strong>POS</strong><small>Sales recorded</small></span><b>−</b>
                                    <span><strong>Stock sold</strong><small>Open + Issued − Close</small></span><b>=</b>
                                    <span class="sr-formula-answer"><strong>Variance</strong><small>What needs explaining</small></span>
                                </div>
                                <div class="sr-formula-signs"><span><strong>0</strong> Balances</span><span><strong>−</strong> Short</span><span><strong>+</strong> Over</span></div>
                            </div>
                        @endif

                        @if ($chapter['id'] === 'chains')
                            <div class="sr-chain-visual" aria-label="A chain connects consecutive shifts for the same item and counting area. The previous close becomes the next open.">
                                <span><x-stockrecon::tutorial-icon name="box" /><strong>First shift</strong><small>Closing count</small></span>
                                <span class="sr-chain-join"><x-stockrecon::tutorial-icon name="link" /><small>Close becomes open</small></span>
                                <span><x-stockrecon::tutorial-icon name="box" /><strong>Next shift</strong><small>Opening count</small></span>
                            </div>
                        @endif

                        @if ($chapter['flow'])
                            <ol class="sr-logic-flow" aria-label="{{ $chapter['title'] }} — sequence">
                                @foreach ($chapter['flow'] as $step)
                                    <li>
                                        <span class="sr-step-number" aria-hidden="true">{{ $loop->iteration }}</span>
                                        <div><h3>{{ $step['title'] }}</h3><p>{{ $step['text'] }}</p></div>
                                    </li>
                                @endforeach
                            </ol>
                        @endif

                        <div class="sr-chapter-copy">
                            @foreach ($chapter['body'] as $paragraph)<p>{{ $paragraph }}</p>@endforeach
                        </div>

                        @if ($chapter['id'] === 'visual-summary')
                            <x-stockrecon::tutorial-flow :cases="$content['flows']" :scenarios="$scenarios" />
                        @endif

                        @if (isset($chapter['comparison']))
                            <section class="sr-setting-comparison" aria-label="Changing the issue multiple">
                                <h3>Same item quantities. Different issue multiple.</h3>
                                <p>Interior shift: opening 90, issued 20, POS 32, closing 90 → over 12. Assume a complete eligible chain, enabled issue modes, no opening break, duplicate or waste.</p>
                                <x-table layout-key="StockRecon.tutorial.issue-multiple" :count="count($chapter['comparison'])" aria-label="Effect of issue multiple on the same over of 12">
                                    <x-slot:head><tr><th>Issue multiple</th><th class="num">Issue before</th><th class="num">Issue after</th><th class="num">Over left</th><th>Why</th></tr></x-slot:head>
                                    @foreach ($chapter['comparison'] as $example)
                                        <tr><th scope="row">{{ $example['multiple'] }}</th><td class="num">{{ \App\Support\Format::qty(20) }}</td><td class="num">{{ \App\Support\Format::qty($example['issued']) }}</td><td class="num">{{ $example['remainder'] > 0 ? '+' : '' }}{{ \App\Support\Format::qty($example['remainder']) }}</td><td>{{ $example['why'] }}</td></tr>
                                    @endforeach
                                </x-table>
                                <p>On the first or last observed shift, all these ordinary issue corrections are disabled: issued stays 20 and the over stays 12. D1 has its own evidence test.</p>
                            </section>
                        @endif

                        @foreach ($chapter['scenario_ids'] as $scenarioId)
                            @if ($scenarios->has($scenarioId))
                                <x-stockrecon::tutorial-scenario :scenario="$scenarios[$scenarioId]" />
                            @endif
                        @endforeach

                        @if ($chapter['event_ids'])
                            <section class="sr-related-events" aria-label="Events in this chapter">
                                <h3>Meet the events</h3>
                                <p>Choose an event for its meaning, the evidence to check and the route to it in Agora.</p>
                                <div class="sr-event-links">
                                    @foreach ($chapter['event_ids'] as $eventId)
                                        @if ($events->has($eventId))
                                            @php($event = $events[$eventId])
                                            <a href="#tutorial-event-{{ $event['id'] }}" data-tutorial-event-link="{{ $event['id'] }}">
                                                <x-stockrecon::tutorial-icon :name="in_array($event['tone'], ['crit', 'serious', 'warn'], true) ? 'alert' : 'search'" />
                                                <span>{{ $event['title'] }}<small>{{ $event['code'] }}</small></span><span aria-hidden="true">↗</span>
                                            </a>
                                        @endif
                                    @endforeach
                                </div>
                            </section>
                        @endif

                        @if ($chapter['id'] === 'review')
                            <div class="sr-review-prompts">
                                <h3>Check your understanding</h3>
                                <details class="sr-checkpoint"><summary>One shift is −5 and the next is +5. Can the shared count erase both?</summary><p>An ordinary continuous pair must keep both differences. Only the qualifying high opening-break pair gets the narrow count-offset exception. Net zero alone is insufficient.</p></details>
                                <details class="sr-checkpoint"><summary>Does an issue multiple of 5 convert five units into one pack?</summary><p>No. It restricts an eligible issue correction to steps of five in the item’s existing quantity unit. An over of 12 adds 10 and leaves 2. No unit or recipe-ratio conversion is performed.</p></details>
                                <details class="sr-checkpoint"><summary>Short 15, authorised waste 4, issue multiple 10: what remains?</summary><p>Apply waste first: short 11. If the interior shift is eligible and has enough issued stock, reduce the issue by 10. Short 1 remains; the waste is not counted twice.</p></details>
                                <details class="sr-checkpoint"><summary>Does opening a preview change the customer's stock?</summary><p>No. Preview saves the proposed result in Agora. A permitted commit is a separate action with its own checks and confirmation.</p></details>
                            </div>
                        @endif

                        <footer class="sr-chapter-footer">
                            @if ($chapterIndex > 0)
                                <a class="btn" href="{{ route('app.stockrecon.tutorial', ['lesson' => $lessons[$chapterIndex - 1]['id']]) }}" data-lesson-link="{{ $lessons[$chapterIndex - 1]['id'] }}">← Previous</a>
                            @else
                                <span class="sr-chapter-first">Start with the sequence, then inspect each rule.</span>
                            @endif
                            @if ($chapterIndex < count($lessons) - 1)
                                <a class="btn primary" href="{{ route('app.stockrecon.tutorial', ['lesson' => $lessons[$chapterIndex + 1]['id']]) }}" data-lesson-link="{{ $lessons[$chapterIndex + 1]['id'] }}">Next: {{ $lessons[$chapterIndex + 1]['title'] }} →</a>
                            @else
                                <a class="btn primary" href="{{ route('app.stockrecon.index') }}">Open stock recon →</a>
                            @endif
                        </footer>
                    </x-card>
                </article>
            @endforeach
            <p class="sr-tutorial-live" data-tutorial-announcement role="status" aria-live="polite"></p>
        </div>
    </div>

    <section class="sr-event-explorer" id="tutorial-events" aria-labelledby="tutorial-events-title">
        <div class="sr-section-heading">
            <span class="sr-chapter-icon"><x-stockrecon::tutorial-icon name="search" /></span>
            <div><p class="sr-overline">Keep this nearby</p><h2 id="tutorial-events-title">The event field guide</h2><p>Click a description to understand the event and where to investigate it.</p></div>
        </div>
        <div class="sr-event-grid">
            @foreach ($events as $event)
                <details class="sr-event-detail" id="tutorial-event-{{ $event['id'] }}" data-tutorial-event="{{ $event['id'] }}">
                    <summary>
                        <span class="sr-event-symbol" data-tone="{{ $event['tone'] }}"><x-stockrecon::tutorial-icon :name="in_array($event['tone'], ['crit', 'serious', 'warn'], true) ? 'alert' : 'search'" /></span>
                        <span><strong>{{ $event['title'] }}</strong><small>{{ $event['code'] }}</small></span>
                        <span class="sr-event-chevron" aria-hidden="true">+</span>
                    </summary>
                    <div class="sr-event-body">
                        <p class="sr-event-meaning">{{ $event['meaning'] }}</p>
                        <p class="sr-event-example"><strong>Training example</strong> {{ $event['example'] }}</p>
                        <h3>Check the evidence</h3>
                        <ul>@foreach ($event['checks'] as $check)<li>{{ $check }}</li>@endforeach</ul>
                        <h3>What to do next</h3>
                        <ol>@foreach ($event['next'] as $next)<li>{{ $next }}</li>@endforeach</ol>
                        <div class="sr-event-location"><x-stockrecon::tutorial-icon name="search" /><div><strong>Find it in Agora</strong><p>{{ $event['location'] }}</p></div></div>
                        <p class="sr-event-write"><strong>Effect on stock:</strong> {{ $event['write_effect'] }}</p>
                        <a class="btn sm" href="{{ route('app.stockrecon.tutorial', ['lesson' => $event['lesson_id']]) }}" data-lesson-link="{{ $event['lesson_id'] }}">Open this chapter →</a>
                    </div>
                </details>
            @endforeach
        </div>
    </section>
</div>
