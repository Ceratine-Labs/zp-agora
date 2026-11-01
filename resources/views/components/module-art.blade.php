{{-- Shared module artwork: still on a menu tile, animated only during a wait.
     Cup/cart shapes and their established loader classes are preserved. --}}
@props(['variant' => 'cup', 'animated' => false])

@php
    $variants = ['cup', 'cart', 'bank', 'utilities', 'cashup', 'reports', 'operations', 'product', 'assets', 'debtors', 'settings', 'my-queue'];
    $variant = in_array($variant, $variants, true) ? $variant : 'cup';
    $uid = 'ldr-'.\Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(8));
    $artClasses = 'module-art loader-art loader-'.$variant;
    [$badgeX, $badgeY, $badgeR] = match ($variant) {
        'bank' => [80, 93, 18],
        'utilities' => [80, 110, 18],
        'cashup' => [80, 119, 15],
        'reports', 'debtors' => [116, 119, 18],
        'operations' => [119, 116, 18],
        'settings' => [80, 77, 23],
        default => [80, 100, 20],
    };
@endphp

        @if ($variant === 'my-queue')
        <svg {{ $attributes->class($artClasses) }} data-module-art="{{ $variant }}" data-module-art-animated="{{ $animated ? 'true' : 'false' }}"
             viewBox="0 0 128 128" aria-hidden="true" focusable="false">
            <x-report-art variant="my-queue" />
        </svg>
        @elseif ($variant === 'cart')
        {{-- One cycle, on the cup's 2.8 s clock: three cartons drop into the
             cart one after another, each landing with a small bounce and the
             cart dipping under it; it holds full, then they are gone and the
             next load starts. The wheels turn throughout. --}}
        <svg {{ $attributes->class($artClasses) }} data-module-art="{{ $variant }}" data-module-art-animated="{{ $animated ? 'true' : 'false' }}" viewBox="0 0 160 160" aria-hidden="true" focusable="false">
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
        @elseif ($variant === 'cup')
        <svg {{ $attributes->class($artClasses) }} data-module-art="{{ $variant }}" data-module-art-animated="{{ $animated ? 'true' : 'false' }}" viewBox="0 0 160 160" aria-hidden="true" focusable="false">
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
        @else
        <svg {{ $attributes->class($artClasses) }} data-module-art="{{ $variant }}" data-module-art-animated="{{ $animated ? 'true' : 'false' }}"
             viewBox="0 0 160 160" aria-hidden="true" focusable="false">
            <defs>
                <clipPath id="{{ $uid }}-badge">
                    <circle cx="{{ $badgeX }}" cy="{{ $badgeY }}" r="{{ $badgeR }}" />
                </clipPath>
            </defs>
            <ellipse class="module-art-shadow" cx="80" cy="145" rx="58" ry="6" />

            @switch($variant)
                @case('bank')
                    <path class="module-art-fill module-art-outline" d="M24 58 L80 25 L136 58 Z" />
                    <path class="module-art-gold module-art-outline" d="M29 121 H131 V133 H29 Z" />
                    <path class="module-art-line" d="M23 139 H137 M34 65 H126" />
                    <rect class="module-art-surface module-art-outline" x="37" y="66" width="17" height="54" rx="2" />
                    <rect class="module-art-surface module-art-outline" x="72" y="66" width="16" height="54" rx="2" />
                    <rect class="module-art-surface module-art-outline" x="106" y="66" width="17" height="54" rx="2" />
                    <g class="module-art-pulse">
                        <circle class="module-art-gold module-art-outline" cx="80" cy="45" r="10" />
                        <path class="module-art-line" d="M75 45 L79 49 L86 41" />
                    </g>
                    @break
                @case('utilities')
                    <rect class="module-art-fill module-art-outline" x="40" y="36" width="80" height="98" rx="12" />
                    <circle class="module-art-surface module-art-outline" cx="80" cy="69" r="24" />
                    <path class="module-art-line" d="M63 69 V65 M68 54 L71 58 M80 50 V55 M92 54 L89 58 M98 65 V69" />
                    <g class="module-art-needle">
                        <path class="module-art-gold-line" d="M80 69 L93 59" />
                        <circle class="module-art-gold" cx="80" cy="69" r="4" />
                    </g>
                    <path class="module-art-line" d="M52 134 V139 M108 134 V139" />
                    <path class="module-art-gold module-art-drop" d="M80 8 C77 14 70 21 70 26 A10 10 0 0 0 90 26 C90 21 83 14 80 8 Z" />
                    @break
                @case('cashup')
                    <g class="module-art-paper">
                        <path class="module-art-surface module-art-outline" d="M86 10 H117 V49 H86 Z" />
                        <path class="module-art-gold-line" d="M94 20 H108 M94 28 H108 M94 36 H103" />
                    </g>
                    <rect class="module-art-fill module-art-outline" x="38" y="35" width="79" height="39" rx="5" />
                    <rect class="module-art-gold" x="46" y="43" width="63" height="22" rx="2" />
                    <path class="module-art-line" d="M55 56 H72 M92 51 V58 M100 48 V58" />
                    <path class="module-art-fill module-art-outline" d="M36 75 H123 L138 113 H22 Z" />
                    <g class="module-art-surface">
                        <rect x="45" y="83" width="13" height="8" rx="2" /><rect x="65" y="83" width="13" height="8" rx="2" /><rect x="85" y="83" width="13" height="8" rx="2" />
                        <rect x="41" y="98" width="17" height="7" rx="2" /><rect x="65" y="98" width="13" height="7" rx="2" /><rect x="85" y="98" width="17" height="7" rx="2" />
                    </g>
                    <rect class="module-art-surface module-art-outline" x="25" y="112" width="110" height="23" rx="4" />
                    <path class="module-art-line" d="M35 123 H49 M111 123 H125" />
                    @break
                @case('reports')
                    <path class="module-art-surface module-art-outline" d="M42 21 H96 L118 43 V133 H42 Z" />
                    <path class="module-art-fill module-art-outline" d="M96 21 V43 H118" />
                    <path class="module-art-line" d="M54 55 H94 M54 64 H80 M54 116 H95" />
                    <rect class="module-art-fill module-art-bar module-art-bar-1" x="55" y="91" width="11" height="17" rx="2" />
                    <rect class="module-art-gold module-art-bar module-art-bar-2" x="72" y="79" width="11" height="29" rx="2" />
                    <rect class="module-art-fill module-art-bar module-art-bar-3" x="89" y="68" width="11" height="40" rx="2" />
                    @break
                @case('operations')
                    <rect class="module-art-fill module-art-outline" x="21" y="28" width="118" height="87" rx="8" />
                    <rect class="module-art-surface" x="29" y="37" width="102" height="66" rx="3" />
                    <path class="module-art-line" d="M66 116 V134 M94 116 V134 M53 136 H108" />
                    <path class="module-art-line" d="M40 50 H88 M40 58 H66" />
                    <path class="module-art-gold-line module-art-trace" d="M40 85 H55 L63 70 L74 94 L84 78 H118" />
                    <circle class="module-art-gold module-art-pulse" cx="113" cy="51" r="6" />
                    @break
                @case('product')
                    <path class="module-art-fill module-art-outline" d="M30 55 L80 31 L131 55 V117 L80 140 L30 117 Z" />
                    <path class="module-art-gold module-art-outline" d="M30 55 L80 78 L131 55 L80 31 Z" />
                    <path class="module-art-line" d="M80 78 V140 M55 44 L106 67" />
                    <g class="module-art-tag">
                        <path class="module-art-surface module-art-outline" d="M108 13 H139 V48 L124 60 L108 48 Z" />
                        <circle class="module-art-gold" cx="124" cy="22" r="3" />
                        <path class="module-art-line" d="M116 33 V44 M123 33 V48 M131 33 V44" />
                    </g>
                    @break
                @case('assets')
                    <path class="module-art-line" d="M59 51 V36 Q59 30 65 30 H95 Q101 30 101 36 V51" />
                    <rect class="module-art-fill module-art-outline" x="25" y="51" width="110" height="80" rx="7" />
                    <path class="module-art-gold module-art-outline" d="M25 56 Q25 51 32 51 H128 Q135 51 135 58 V78 H25 Z" />
                    <path class="module-art-line" d="M44 78 V87 M116 78 V87" />
                    <g class="module-art-tool">
                        <path class="module-art-surface module-art-outline" d="M113 11 A14 14 0 0 0 100 31 L81 50 L91 60 L110 41 A14 14 0 0 0 130 28 L122 34 L113 25 Z" />
                    </g>
                    @break
                @case('debtors')
                    <path class="module-art-surface module-art-outline" d="M39 21 H111 V133 L102 127 L93 133 L84 127 L75 133 L66 127 L57 133 L48 127 L39 133 Z" />
                    <path class="module-art-line" d="M51 38 H97 M51 49 H79 M51 70 H72 M51 84 H72 M51 98 H72" />
                    <path class="module-art-gold-line" d="M87 70 H99 M87 84 H99 M87 98 H99" />
                    <g class="module-art-coin">
                        <circle class="module-art-gold module-art-outline" cx="117" cy="37" r="18" />
                        <path class="module-art-line" d="M117 26 V48 M123 29 H115 Q109 29 109 34 Q109 39 117 39 Q125 39 125 44 Q125 48 115 48 H111" />
                    </g>
                    @break
                @case('settings')
                    <g class="module-art-gear">
                        <path class="module-art-fill module-art-outline" d="M68 21 H92 L96 36 L107 42 L122 38 L134 59 L123 70 V82 L134 94 L122 115 L107 111 L96 118 L92 133 H68 L64 118 L53 111 L38 115 L26 94 L37 82 V70 L26 59 L38 38 L53 42 L64 36 Z" />
                        <circle class="module-art-gold module-art-outline" cx="80" cy="77" r="34" />
                    </g>
                    @break
            @endswitch

            <circle class="loader-badge-ring" cx="{{ $badgeX }}" cy="{{ $badgeY }}" r="{{ $badgeR + 1.5 }}" />
            <image href="{{ asset('images/zrp-logo.png') }}" x="{{ $badgeX - $badgeR }}" y="{{ $badgeY - $badgeR }}"
                   width="{{ $badgeR * 2 }}" height="{{ $badgeR * 2 }}" clip-path="url(#{{ $uid }}-badge)" preserveAspectRatio="xMidYMid meet" />
        </svg>
        @endif
