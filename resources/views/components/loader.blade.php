{{--
    The wait, drawn as a cup of coffee — ZP's ask through Ryan, 23 Sep 2026 —
    or, on the stock recon screens, as a shopping cart being loaded with
    cartons (`variant="cart"`, ZP's ask, 7 Oct 2026).

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
    // 'cup' everywhere; 'cart' on the stock recon screens — cartons dropping
    // into a shopping cart with the ZRP badge on its side (ZP's ask, 7 Oct
    // 2026: "a package or a shopping cart loader for the stock recon"). The
    // app shell picks it from config('agora.loaders') by route.
    'variant' => 'cup',
    // On the overlay only: show it from the first paint, saying this, until
    // the page's held tables are ready (loader.js). Null: hidden as usual.
    'hold' => null,
])

@php($uid = 'ldr-'.\Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(6)))

<div {{ $attributes->merge(['class' => 'loader'.($overlay ? ' loader-overlay' : '')]) }}
     @if ($overlay) data-loader-overlay @if ($hold) data-loader-hold @else hidden @endif @endif
     role="status" aria-live="polite">
    <div class="loader-box">
        @if ($variant === 'cart')
        {{-- One cycle, on the cup's 2.8 s clock: three cartons drop into the
             cart one after another, each landing with a small bounce and the
             cart dipping under it; it holds full, then they are gone and the
             next load starts. The wheels turn throughout. --}}
        <svg class="loader-art loader-cart" viewBox="0 0 160 160" aria-hidden="true" focusable="false">
            <defs>
                <clipPath id="{{ $uid }}-badge">
                    <circle cx="91" cy="91" r="15" />
                </clipPath>
            </defs>

            <ellipse class="loader-saucer" cx="86" cy="147" rx="58" ry="6" />

            <g class="loader-cart-body">
                <path class="loader-frame" d="M12 32 H28 L40 66" />

                <g class="loader-carton loader-carton-1">
                    <rect x="48" y="46" width="36" height="42" rx="2" />
                    <path class="loader-tape" d="M66 46 V60" />
                    <path class="loader-flap" d="M48 53 H84" />
                </g>
                <g class="loader-carton loader-carton-2">
                    <rect x="88" y="54" width="34" height="34" rx="2" />
                    <path class="loader-tape" d="M105 54 V66" />
                    <path class="loader-flap" d="M88 60 H122" />
                </g>
                <g class="loader-carton loader-carton-3">
                    <rect x="60" y="18" width="34" height="28" rx="2" />
                    <path class="loader-tape" d="M77 18 V30" />
                    <path class="loader-flap" d="M60 24 H94" />
                </g>

                <path class="loader-basket" d="M38 66 H142 L131 114 H51 Z" />
                <path class="loader-mesh" d="M44 90 H136 M68 66 V114 M114 66 V114" />
                <path class="loader-frame" d="M38 66 H142 L131 114 H51 Z M51 114 L55 124 H128 L131 114" />

                <circle class="loader-badge-ring" cx="91" cy="91" r="16.5" />
                <image href="{{ asset('images/zrp-logo.png') }}" x="76" y="76" width="30" height="30"
                       clip-path="url(#{{ $uid }}-badge)" preserveAspectRatio="xMidYMid meet" />

                <g class="loader-wheel">
                    <circle cx="64" cy="133" r="9" />
                    <path d="M64 124 V142 M55 133 H73" />
                </g>
                <g class="loader-wheel">
                    <circle cx="120" cy="133" r="9" />
                    <path d="M120 124 V142 M111 133 H129" />
                </g>
            </g>
        </svg>
        @else
        <svg class="loader-art loader-cup" viewBox="0 0 160 160" aria-hidden="true" focusable="false">
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
        @endif
        <p class="loader-label" data-loader-label>{{ $hold ?: $label }}</p>
    </div>
</div>
