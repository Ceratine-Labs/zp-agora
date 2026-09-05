{{--
    The parameter block above a report or a grid — the mockup's `params`.

    A responsive grid of <x-param> fields rather than a form layout, because
    the count varies wildly: the banking report takes two parameters and the
    Exco pack takes eight, and both have to look deliberate.

    `collapsible` folds it behind a summary line. That is a <details>, not a
    click handler: the browser supplies the keyboard behaviour and the state is
    correct before any script runs. What <details> does not do is remember, so
    `remember` hands the element a key and disclosure.js keeps the person's
    choice on this browser — a report they run every morning should not need
    the parameters folded away again each time.

    On a phone the block is folded by default (`fold-on-mobile`), because eight
    parameters above the answer means the answer is off the bottom of the
    screen.
--}}
@props([
    'legend' => null,
    'summary' => 'Parameters',
    'collapsible' => false,
    'open' => true,
    'remember' => null,
])

@if ($collapsible)
    <details {{ $attributes->merge(['class' => 'params-fold']) }}
             @if ($open) open @endif
             @if ($remember) data-remember="params:{{ $remember }}" @endif>
        <summary>{{ $summary }}</summary>
        <div class="params">{{ $slot }}</div>
    </details>
@else
    <div {{ $attributes->merge(['class' => 'params']) }}
         @if ($legend) role="group" aria-label="{{ $legend }}" @endif>{{ $slot }}</div>
@endif
