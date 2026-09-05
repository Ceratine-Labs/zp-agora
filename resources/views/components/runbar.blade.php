{{--
    The bar under a parameter block that runs the thing — the mockup's
    `runbar`.

    Three parts: the primary action, whatever secondary actions the screen
    offers (slot), and a status line. The status is a live region, so the
    result of pressing Execute is announced rather than only drawn.

    runbar.js is the behaviour, and it is real behaviour rather than dressing:
    on submit it disables the button and swaps the status to `busy`, which is
    what stops a second press queuing a second run of a procedure that takes
    four seconds over a 249 GB database. It then reports how long the round
    trip took, because "is it slow or is it broken" is the question a person
    actually has while waiting.

    `procedure` puts the name of the stored procedure on the bar — feature-rules
    §3.4. The customer opens these in SSMS and changes them; a screen that will
    not say what it called is a support conversation that starts with a
    screenshot.
--}}
@props([
    'action' => 'Execute',
    'type' => 'submit',
    'status' => 'Ready.',
    'busy' => 'Executing…',
    'procedure' => null,
    'disabled' => false,
])

<div {{ $attributes->merge(['class' => 'runbar']) }} data-runbar data-busy="{{ $busy }}">
    <button type="{{ $type }}" class="btn primary" data-runbar-go @disabled($disabled)>
        <span aria-hidden="true">▶</span> {{ $action }}
    </button>

    {{ $slot }}

    @if ($procedure)
        <span class="proc mono" title="The stored procedure behind this screen">{{ $procedure }}</span>
    @endif

    <span class="status" data-runbar-status role="status" aria-live="polite">{{ $status }}</span>
</div>
