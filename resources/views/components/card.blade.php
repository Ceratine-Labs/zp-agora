{{--
    A bordered panel — the mockup's `card`.

    The class names are the mockup's (`card-h`, `card-b`, `flush`, `.sub`,
    `.right`) rather than a set of our own, so the design sheet's CSS ports
    across without a translation table in the middle. The older `card-head` /
    `card-body` names are kept alive as aliases in the stylesheet, because
    views written against them are still in flight elsewhere.

    `collapsible` turns the head into the toggle for the body, using <details>
    rather than a click handler — the browser then supplies the keyboard
    behaviour, the disclosure state and the correct semantics for a screen
    reader, and the panel still works with no JavaScript at all.

    `open` says which way it starts. A card that carries provenance rather than
    the answer — the arguments a run was given, the raw response behind a
    figure — should start closed: it has to be there, and it should not be the
    first thing between the reader and the work.

    `remember` gives the disclosure a key, and disclosure.js keeps that state
    for this person on this browser. Native <details> forgets on every reload;
    a card the reader closed on purpose should stay closed.
--}}
@props([
    'title' => null,
    'sub' => null,
    'infoWide' => false,
    'flush' => false,
    'collapsible' => false,
    'open' => false,
    'remember' => null,
])

@php($body = 'card-b'.($flush ? ' flush' : ''))

@if ($collapsible && $title)
    <details {{ $attributes->merge(['class' => 'card card-collapsible']) }}
             @if ($open) open @endif
             @if ($remember) data-remember="card:{{ $remember }}" @endif>
        <summary class="card-h">
            <div>
                <h3>{{ $title }}</h3>
                @if ($sub)<div class="sub">{{ $sub }}</div>@endif
            </div>
            @isset($actions)<div class="right">{{ $actions }}</div>@endisset
        </summary>
        {{-- A button inside a <summary> would fold the card as well, so on a
             collapsible card the explanation leads the body instead. --}}
        @isset($info)<div class="field-help" style="padding:10px 14px 0">{{ $info }}</div>@endisset
        <div class="{{ $body }}">{{ $slot }}</div>
        @isset($foot){{ $foot }}@endisset
    </details>
@else
    <section {{ $attributes->merge(['class' => 'card']) }}>
        @if ($title)
            <header class="card-h">
                <div>
                    @isset($info)
                        {{-- `info`: standing explanation behind a button
                             beside the title (<x-info>). --}}
                        <div class="card-t">
                            <h3>{{ $title }}</h3>
                            <x-info :class="$infoWide ? 'info-wide' : ''" :label="'About '.Str::lower($title)">{{ $info }}</x-info>
                        </div>
                    @else
                        <h3>{{ $title }}</h3>
                    @endisset
                    @if ($sub)<div class="sub">{{ $sub }}</div>@endif
                </div>
                @isset($actions)<div class="right">{{ $actions }}</div>@endisset
            </header>
        @endif
        <div class="{{ $body }}">{{ $slot }}</div>
        @isset($foot){{ $foot }}@endisset
    </section>
@endif
