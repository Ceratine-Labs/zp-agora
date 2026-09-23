{{--
    The wait, drawn as a cup of coffee — ZP's ask through Ryan, 23 Sep 2026.

    The cup fills, the ZRP badge sits on its face, steam rises once it is full,
    and it drains and fills again until the thing being waited for finishes.
    It replaces the busy states on the screens where people wait: a preview,
    a recon centre tab loading, a match or an execute, the bulk runners.

    ONE INSTANCE PER PAGE. <x-app-shell> renders it once as the `overlay`,
    hidden; loader.js shows it for a navigation and clones its cup for a wait
    inside a pane. Nothing on a screen writes a second one — a screen asks
    window.Agora.loader, or puts `data-loader="What is happening…"` on a form.

    A wait under about 300 ms never shows it (loader.js): a cup that flashes on
    every quick press is noise, and teaches people to stop looking at it.

    Reduced motion gets a full, still cup and the words — the words are the
    part that carries the meaning; the animation only says "still going".

    The ids are per instance because clipPath is addressed by id, and a clone
    that pointed at another instance's clip would draw nothing the moment that
    one was hidden. loader.js renames them again on every clone.
--}}
@props([
    'label' => 'Working…',
    'overlay' => false,
])

@php($uid = 'ldr-'.\Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(6)))

<div {{ $attributes->merge(['class' => 'loader'.($overlay ? ' loader-overlay' : '')]) }}
     @if ($overlay) data-loader-overlay hidden @endif
     role="status" aria-live="polite">
    <div class="loader-box">
        <svg class="loader-cup" viewBox="0 0 160 160" aria-hidden="true" focusable="false">
            <defs>
                {{-- The inside of the cup: what the coffee is poured into. --}}
                <clipPath id="{{ $uid }}-in">
                    <path d="M38 56 H122 L115.5 126 Q114 134 106 134 H54 Q46 134 44.5 126 Z" />
                </clipPath>
                <clipPath id="{{ $uid }}-badge">
                    <circle cx="80" cy="96" r="21" />
                </clipPath>
            </defs>

            <ellipse class="loader-saucer" cx="80" cy="143" rx="60" ry="7" />
            <path class="loader-handle" d="M123 72 C 147 70, 147 112, 118 110" />
            <path class="loader-glass" d="M34 52 H126 L118.5 128 Q116.5 138 106 138 H54 Q43.5 138 41.5 128 Z" />

            <g clip-path="url(#{{ $uid }}-in)">
                <g class="loader-coffee">
                    <rect class="loader-brew" x="20" y="60" width="120" height="90" />
                    {{-- The surface, drawn twice as wide as the cup so it can
                         slide sideways and read as liquid. --}}
                    <path class="loader-crema"
                          d="M-60 60 q10 -5 20 0 t20 0 t20 0 t20 0 t20 0 t20 0 t20 0 t20 0 t20 0 t20 0 t20 0 t20 0 t20 0 t20 0 V68 H-60 Z" />
                </g>
            </g>

            <path class="loader-rim" d="M34 52 H126 L118.5 128 Q116.5 138 106 138 H54 Q43.5 138 41.5 128 Z" />

            <circle class="loader-badge-ring" cx="80" cy="96" r="22.5" />
            <image href="{{ asset('images/zrp-logo.png') }}" x="59" y="75" width="42" height="42"
                   clip-path="url(#{{ $uid }}-badge)" preserveAspectRatio="xMidYMid meet" />

            <g class="loader-steam">
                <path d="M62 44 C 54 36, 70 28, 62 18" />
                <path d="M80 42 C 72 32, 88 24, 80 12" />
                <path d="M98 44 C 90 36, 106 28, 98 18" />
            </g>
        </svg>
        <p class="loader-label" data-loader-label>{{ $label }}</p>
    </div>
</div>
