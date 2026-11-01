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
            <span class="sr-overline">Your practical field guide</span>
            <h2>Every count tells a story.</h2>
            <p>See where the figures come from, try worked examples and learn the next step when a run needs attention.</p>
        </div>
        <div class="sr-tutorial-facts">
            <span><strong>{{ count($lessons) }}</strong> chapters</span>
            <span><strong>{{ count($scenarios) }}</strong> worked examples</span>
            <span><strong>{{ count($events) }}</strong> events explained</span>
        </div>
    </div>

    <div class="sr-tutorial-layout">
        <aside class="sr-tutorial-sidebar" aria-label="Tutorial contents">
            <div class="sr-tutorial-progress">
                <div><strong>Your place in the guide</strong><span data-tutorial-position>{{ $activeIndex + 1 }} / {{ count($lessons) }}</span></div>
                <progress data-tutorial-progress value="{{ $activeIndex + 1 }}" max="{{ count($lessons) }}" aria-label="Current chapter"></progress>
                <p>Move at your own pace. Choose any chapter.</p>
            </div>
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

                        <div class="sr-chapter-copy">
                            @foreach ($chapter['body'] as $paragraph)<p>{{ $paragraph }}</p>@endforeach
                        </div>

                        @if ($chapter['steps'])
                            <ol class="sr-practical-steps">
                                @foreach ($chapter['steps'] as $step)
                                    <li>
                                        <span class="sr-step-number" aria-hidden="true">{{ $loop->iteration }}</span>
                                        <div><h3>{{ $step['title'] }}</h3><p>{{ $step['text'] }}</p>
                                            @if ($step['screen'])<span class="sr-screen-path"><x-stockrecon::tutorial-icon name="search" /> {{ $step['screen'] }}</span>@endif
                                        </div>
                                    </li>
                                @endforeach
                            </ol>
                        @endif

                        @if ($chapter['scenario_id'] && $scenarios->has($chapter['scenario_id']))
                            <x-stockrecon::tutorial-scenario :scenario="$scenarios[$chapter['scenario_id']]" />
                        @endif

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
                                <details class="sr-checkpoint"><summary>One shift is +5 and the next is −5. Is that enough to commit?</summary><p>No. Net zero is a useful starting point, but the chain, supporting evidence, proposed counts and any holds still need review. A green total alone does not approve a change.</p></details>
                                <details class="sr-checkpoint"><summary>Can moving a count remove a real short?</summary><p>No. Within the same continuous chain, a count adjustment transfers variance between shifts. The net short remains unless another supported correction explains it.</p></details>
                                <details class="sr-checkpoint"><summary>Does opening a preview change the customer's stock?</summary><p>No. Preview saves the proposed result in Agora. A permitted commit is a separate action with its own checks and confirmation.</p></details>
                            </div>
                        @endif

                        <footer class="sr-chapter-footer">
                            @if ($chapterIndex > 0)
                                <a class="btn" href="{{ route('app.stockrecon.tutorial', ['lesson' => $lessons[$chapterIndex - 1]['id']]) }}" data-lesson-link="{{ $lessons[$chapterIndex - 1]['id'] }}">← Previous</a>
                            @else
                                <span class="sr-chapter-first">Start here. No stock knowledge needed.</span>
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
