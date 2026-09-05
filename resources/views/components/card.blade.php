{{--
    A bordered panel.

    `collapsible` turns the head into the toggle for the body, using <details>
    rather than a click handler — the browser then supplies the keyboard
    behaviour, the disclosure state and the correct semantics for a screen
    reader, and the panel still works with no JavaScript at all.

    `open` says which way it starts. A card that carries provenance rather than
    the answer — the arguments a run was given, the raw response behind a
    figure — should start closed: it has to be there, and it should not be the
    first thing between the reader and the work.
--}}
@props(['title' => null, 'sub' => null, 'flush' => false, 'collapsible' => false, 'open' => false])

@if ($collapsible && $title)
    <details {{ $attributes->merge(['class' => 'card card-collapsible']) }} @open($open)>
        <summary class="card-head">
            <div>
                <h2>{{ $title }}</h2>
                @if ($sub)<p class="card-sub">{{ $sub }}</p>@endif
            </div>
            @isset($actions)<div class="card-actions">{{ $actions }}</div>@endisset
        </summary>
        <div class="card-body {{ $flush ? 'flush' : '' }}">{{ $slot }}</div>
    </details>
@else
    <section {{ $attributes->merge(['class' => 'card']) }}>
        @if ($title)
            <header class="card-head">
                <div>
                    <h2>{{ $title }}</h2>
                    @if ($sub)<p class="card-sub">{{ $sub }}</p>@endif
                </div>
                @isset($actions)<div class="card-actions">{{ $actions }}</div>@endisset
            </header>
        @endif
        <div class="card-body {{ $flush ? 'flush' : '' }}">{{ $slot }}</div>
    </section>
@endif
