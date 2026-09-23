{{--
    A group of explanatory panels folded behind one line.

    <x-notice collapsible> folds ONE panel and deliberately keeps its headline
    visible, because nobody should have to open something to learn a problem
    exists. That is the right rule for a single finding and the wrong shape for
    a screen that opens with several paragraphs of standing explanation: the
    reader who already knows how the screen works has to scroll past all of it
    every time to reach the work.

    So this folds the GROUP, and the summary line names what is inside. The
    existence of what it hides stays on the screen — one line instead of three
    panels — and the reader who needs it is one keystroke away.

    Closed by default: standing explanation is not the answer to anything, and
    a reader who wants it will come looking. `remember` keeps whichever choice
    they make on this browser, via disclosure.js, so someone who opens it once
    does not have to open it every morning.

    Not for a refusal, a result or anything about THIS run — those belong in
    their own <x-notice>, above, where they cannot be folded away unread.
--}}
@props([
    'summary' => 'How this screen works',
    'open' => false,
    'remember' => null,
])

<details {{ $attributes->merge(['class' => 'explainer']) }}
         @if ($open) open @endif
         @if ($remember) data-remember="explainer:{{ $remember }}" @endif>
    <summary>{{ $summary }}</summary>
    <div class="explainer-body">{{ $slot }}</div>
</details>
