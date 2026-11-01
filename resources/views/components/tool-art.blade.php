{{-- Tool identity is shared by cards, navigation, page titles and loaders. --}}
@props(['variant' => 'reports', 'animated' => false])
@php
    $bankLogos = [
        'bank-absa' => ['path' => 'images/banks/absa.jpg', 'mime' => 'image/jpeg'],
        'bank-fnb' => ['path' => 'images/banks/fnb.svg', 'mime' => 'image/svg+xml'],
    ];
    $shared = [
        'cash-banking' => 'daily-banking', 'cashups' => 'open-cashups',
        'cash-safe' => 'drop-safe', 'cash-staff-shorts' => 'staff-shorts',
        'utility-readings' => 'utility-meters',
    ];
    $drawings = [
        'cash-allocation', 'cash-no-cashup', 'till-balance', 'pump-shortages',
        'cash-safe-bags', 'cash-shorts-list', 'cash-deductions', 'cash-stock-loss',
        'cash-non-integrated', 'cash-payroll', 'bank-reconcile', 'bank-dashboard',
        'bank-trace', 'bank-cash-bags', 'bank-cash-machine', 'bank-smart-atm',
        'stock-centre', 'stock-variance', 'stock-weekly', 'stock-areas',
        'stock-water', 'product-stock', 'product-critical', 'access-users',
        'access-roles', 'debtor-statements', 'asset-register', 'asset-movement',
        'asset-repairs', 'asset-masters',
    ];
    $variant = is_string($variant) && (isset($bankLogos[$variant]) || isset($shared[$variant]) || in_array($variant, $drawings, true)) ? $variant : 'reports';
    // Exact local brand artwork is embedded so first-paint loaders never wait
    // for another image request. Only these two fixed paths can be read.
    $bankLogo = isset($bankLogos[$variant]) ? 'data:'.$bankLogos[$variant]['mime'].';base64,'.base64_encode(file_get_contents(public_path($bankLogos[$variant]['path']))) : null;
@endphp
<svg {{ $attributes->class('tool-art module-art loader-art loader-tool') }}
     data-tool-art="{{ $variant }}" data-module-art="{{ $variant }}" data-module-art-animated="{{ $animated ? 'true' : 'false' }}"
     viewBox="0 0 128 128" aria-hidden="true" focusable="false">
    <g class="tool-art-scene">
    @if ($bankLogo !== null)
        <rect x="4" y="4" width="120" height="120" rx="18" fill="var(--badge)" />
        <image href="{{ $bankLogo }}" x="12" y="12" width="104" height="104" preserveAspectRatio="xMidYMid meet" />
    @elseif (isset($shared[$variant]) || $variant === 'reports')
        <x-report-art :variant="$shared[$variant] ?? 'reports'" />
    @else
        <ellipse class="report-art-shadow" cx="64" cy="114" rx="45" ry="5" />
        @switch($variant)
            @case('cash-allocation')
                <path class="report-art-surface report-art-outline" d="M19 17 H77 V106 L67 100 L57 106 L48 100 L38 106 L29 100 L19 106 Z" />
                <path class="report-art-fill" d="M22 20 H74 V35 H22 Z" />
                <path class="report-art-line" d="M31 48 H62 M31 61 H52 M31 79 H43" />
                <circle class="report-art-gold report-art-outline" cx="97" cy="55" r="13" />
                <path class="report-art-fill report-art-outline" d="M77 94 V87 C77 66 117 66 117 87 V94 Z" />
                <path class="report-art-gold-line" d="M51 82 H73 M65 74 L73 82 L65 90" />
                @break
            @case('cash-no-cashup')
                <path class="report-art-surface report-art-outline" d="M20 16 H77 V104 L67 98 L58 104 L48 98 L39 104 L29 98 L20 104 Z" />
                <path class="report-art-fill" d="M23 19 H74 V34 H23 Z" />
                <path class="report-art-line" d="M32 47 H63 M32 61 H55 M32 75 H50" />
                <rect class="report-art-surface report-art-outline" x="72" y="65" width="39" height="38" rx="5" stroke-dasharray="6 6" />
                <path class="report-art-gold-line" d="M84 84 H101" />
                <path class="report-art-line" d="M80 104 V109 M104 104 V109" />
                @break
            @case('till-balance')
                <path class="report-art-fill report-art-outline" d="M39 105 L48 94 H80 L89 105 Z" />
                <path class="report-art-line" d="M64 24 V94 M26 43 H102 M34 44 L21 78 M34 44 L47 78 M94 44 L81 78 M94 44 L107 78" />
                <path class="report-art-gold report-art-outline" d="M17 78 H51 C50 101 18 101 17 78 Z M77 78 H111 C110 101 78 101 77 78 Z" />
                <circle class="report-art-fill report-art-outline" cx="64" cy="27" r="10" />
                <path class="report-art-line" d="M24 106 H104" />
                @break
            @case('pump-shortages')
                <rect class="report-art-fill report-art-outline" x="24" y="20" width="53" height="87" rx="6" />
                <rect class="report-art-surface report-art-outline" x="33" y="31" width="35" height="26" rx="3" />
                <path class="report-art-line" d="M43 44 H57 M77 45 H87 V87 C87 99 106 99 106 87 V43 L97 31 M96 30 V44 H106 M19 108 H83" />
                <circle class="report-art-gold report-art-outline" cx="91" cy="91" r="23" />
                <path class="report-art-line" d="M80 91 H102" />
                @break
            @case('cash-safe-bags')
                <path class="report-art-fill report-art-outline" d="M34 48 L27 33 H51 L44 48 C65 69 69 102 40 106 C10 102 13 69 34 48 Z" />
                <path class="report-art-gold report-art-outline" d="M80 35 L73 19 H100 L92 35 C119 61 124 105 87 108 C49 105 55 61 80 35 Z" />
                <path class="report-art-line" d="M31 50 H48 M76 37 H97 M28 79 H45 M77 73 H96 M77 84 H90" />
                @break
            @case('cash-shorts-list')
                <rect class="report-art-surface report-art-outline" x="29" y="16" width="73" height="92" rx="6" />
                <path class="report-art-fill" d="M33 20 H98 V38 H33 Z" />
                <circle class="report-art-gold" cx="44" cy="54" r="6" />
                <circle class="report-art-gold" cx="44" cy="78" r="6" />
                <path class="report-art-line" d="M59 53 H86 M59 64 H77 M59 78 H86 M59 89 H77" />
                <path class="report-art-fill report-art-outline" d="M13 80 H39 V108 H13 Z" />
                <path class="report-art-light-line" d="M20 94 H31" />
                @break
            @case('cash-deductions')
                <rect class="report-art-fill report-art-outline" x="22" y="17" width="69" height="91" rx="7" />
                <rect class="report-art-surface" x="31" y="27" width="51" height="70" rx="3" />
                <path class="report-art-line" d="M41 43 H70 M41 59 H64 M41 75 H58" />
                <circle class="report-art-gold report-art-outline" cx="94" cy="85" r="24" />
                <path class="report-art-line" d="M82 85 H106" />
                @break
            @case('cash-stock-loss')
                <path class="report-art-fill report-art-outline" d="M18 44 L62 23 L105 44 V92 L62 113 L18 92 Z" />
                <path class="report-art-line" d="M18 44 L62 65 L105 44 M62 65 V112 M40 34 L84 55 V74" />
                <circle class="report-art-gold report-art-outline" cx="96" cy="89" r="24" />
                <path class="report-art-line" d="M84 89 H108" />
                @break
            @case('cash-non-integrated')
                <rect class="report-art-fill report-art-outline" x="12" y="26" width="44" height="73" rx="5" />
                <rect class="report-art-surface report-art-outline" x="73" y="26" width="43" height="73" rx="5" />
                <path class="report-art-light-line" d="M24 42 H43 M24 56 H39 M24 79 H36" />
                <path class="report-art-line" d="M84 42 H105 M84 56 H103 M84 79 H99" />
                <path class="report-art-gold-line" d="M50 69 H60 M69 69 H79 M62 57 L66 64 L62 74 L66 81" />
                @break
            @case('cash-payroll')
                <path class="report-art-surface report-art-outline" d="M35 17 H93 V79 H35 Z" />
                <path class="report-art-line" d="M46 31 H79 M46 44 H71" />
                <circle class="report-art-gold report-art-outline" cx="66" cy="65" r="15" />
                <path class="report-art-fill report-art-outline" d="M15 60 L64 90 L113 60 V108 H15 Z" />
                <path class="report-art-line" d="M15 108 L48 81 M113 108 L80 81" />
                @break
            @case('bank-reconcile')
                <rect class="report-art-surface report-art-outline" x="11" y="20" width="43" height="78" rx="5" />
                <rect class="report-art-fill report-art-outline" x="74" y="29" width="43" height="78" rx="5" />
                <path class="report-art-line" d="M21 35 H43 M21 49 H36 M21 75 H36" />
                <path class="report-art-light-line" d="M85 45 H106 M85 59 H101 M85 86 H100" />
                <path class="report-art-gold-line" d="M44 59 H80 M71 51 L80 59 L71 67 M83 80 H47 M56 72 L47 80 L56 88" />
                @break
            @case('bank-dashboard')
                <rect class="report-art-surface report-art-outline" x="14" y="23" width="100" height="75" rx="7" />
                <path class="report-art-fill" d="M18 27 H110 V40 H18 Z" />
                <path class="report-art-line" d="M51 99 V109 M77 99 V109 M41 110 H87" />
                <rect class="report-art-fill" x="26" y="68" width="11" height="18" rx="2" />
                <rect class="report-art-gold" x="43" y="58" width="11" height="28" rx="2" />
                <path class="report-art-line" d="M24 87 H59" />
                <circle class="report-art-fill report-art-outline" cx="86" cy="66" r="18" />
                <path class="report-art-gold report-art-outline" d="M86 48 A18 18 0 0 1 104 66 H86 Z" />
                @break
            @case('bank-trace')
                <rect class="report-art-surface report-art-outline" x="22" y="16" width="65" height="87" rx="5" />
                <path class="report-art-fill" d="M26 20 H83 V35 H26 Z" />
                <path class="report-art-line" d="M34 48 H71 M34 62 H61 M34 76 H49" />
                <circle class="report-art-gold report-art-outline" cx="84" cy="79" r="23" />
                <path class="report-art-line" d="M100 96 L115 112 M74 79 H94 M84 69 V89" />
                @break
            @case('bank-cash-bags')
                <g transform="rotate(-16 52 33)">
                    <rect class="report-art-fill report-art-outline" x="32" y="17" width="43" height="31" rx="3" />
                    <circle class="report-art-surface" cx="53" cy="32" r="7" />
                </g>
                <g transform="rotate(13 77 36)">
                    <rect class="report-art-surface report-art-outline" x="56" y="21" width="42" height="31" rx="3" />
                    <circle class="report-art-gold report-art-outline" cx="77" cy="36" r="7" />
                </g>
                <path class="report-art-gold report-art-outline" d="M39 43 Q64 50 89 43 L83 56 C101 70 111 91 98 104 C87 115 41 115 30 104 C17 91 27 70 45 56 Z" />
                <path class="report-art-line" d="M43 55 Q64 60 85 55 M82 57 L94 66 M82 57 L85 71" />
                <circle class="report-art-fill report-art-outline" cx="63" cy="84" r="16" />
                <path class="report-art-light-line" d="M58 93 V75 H64 C74 75 74 85 64 85 H58 M65 85 L72 93" />
                <circle class="report-art-gold report-art-outline" cx="103" cy="101" r="11" />
                <path class="report-art-line" d="M103 96 V106" />
                @break
            @case('bank-cash-machine')
                <rect class="report-art-surface report-art-outline" x="38" y="16" width="51" height="33" rx="3" />
                <path class="report-art-line" d="M44 22 H83" />
                <rect class="report-art-gold report-art-outline" x="34" y="28" width="59" height="32" rx="3" />
                <circle class="report-art-surface report-art-outline" cx="64" cy="43" r="8" />
                <path class="report-art-fill report-art-outline" d="M27 43 H101 L114 61 V105 H14 V61 Z" />
                <path class="report-art-line" d="M28 44 L34 53 H94 L100 44" />
                <rect class="report-art-surface" x="25" y="63" width="38" height="16" rx="3" />
                <path class="report-art-line" d="M33 71 H54" />
                <circle class="report-art-gold" cx="79" cy="70" r="5" />
                <circle class="report-art-gold" cx="97" cy="70" r="5" />
                <path class="report-art-light-line" d="M29 89 H99" />
                <path class="report-art-surface report-art-outline" d="M38 89 H89 L96 110 H31 Z" />
                <ellipse class="report-art-gold report-art-outline" cx="64" cy="100" rx="9" ry="5" />
                @break
            @case('bank-smart-atm')
                <rect class="report-art-fill report-art-outline" x="24" y="14" width="72" height="96" rx="7" />
                <rect class="report-art-surface report-art-outline" x="35" y="27" width="49" height="29" rx="3" />
                <path class="report-art-gold-line" d="M45 42 L55 48 L73 35" />
                <path class="report-art-light-line" d="M36 68 H47 M36 78 H47 M36 88 H47 M60 68 H81" />
                <path class="report-art-surface report-art-outline" d="M60 69 H78 V92 L69 97 L60 92 Z" />
                <path class="report-art-line" d="M66 79 H72" />
                <circle class="report-art-gold report-art-outline" cx="99" cy="89" r="23" />
                <circle class="report-art-ink" cx="99" cy="78" r="3" />
                <path class="report-art-line" d="M95 87 H99 V100 M94 100 H104" />
                @break
            @case('stock-centre')
                <path class="report-art-fill report-art-outline" d="M17 62 L43 48 L69 62 V94 L43 108 L17 94 Z M62 31 L88 17 L114 31 V63 L88 77 L62 63 Z" />
                <path class="report-art-line" d="M17 62 L43 76 L69 62 M43 76 V108 M62 31 L88 45 L114 31 M88 45 V77" />
                <path class="report-art-gold-line" d="M25 39 V25 H51 M43 17 L51 25 L43 33 M104 87 V101 H78 M86 93 L78 101 L86 109" />
                @break
            @case('stock-variance')
                <path class="report-art-line" d="M18 22 V107 H111" />
                <rect class="report-art-fill report-art-outline" x="33" y="52" width="23" height="55" rx="3" />
                <rect class="report-art-gold report-art-outline" x="68" y="28" width="23" height="79" rx="3" />
                <path class="report-art-line" d="M98 28 H109 M98 52 H109 M104 29 V51 M98 36 L104 29 L110 36 M98 44 L104 51 L110 44" />
                @break
            @case('stock-weekly')
                <rect class="report-art-surface report-art-outline" x="17" y="26" width="91" height="79" rx="7" />
                <path class="report-art-fill report-art-outline" d="M24 26 H101 Q108 26 108 33 V47 H17 V33 Q17 26 24 26 Z" />
                <path class="report-art-line" d="M37 17 V33 M87 17 V33 M31 61 H41 M49 61 H59 M31 78 H41" />
                <path class="report-art-gold report-art-outline" d="M64 72 L88 60 L112 72 V99 L88 111 L64 99 Z" />
                <path class="report-art-line" d="M64 72 L88 84 L112 72 M88 84 V111" />
                @break
            @case('stock-areas')
                <path class="report-art-line" d="M19 20 V110 M109 20 V110 M19 59 H109 M19 103 H109" />
                <rect class="report-art-fill report-art-outline" x="28" y="29" width="29" height="29" rx="2" />
                <rect class="report-art-gold report-art-outline" x="69" y="29" width="29" height="29" rx="2" />
                <rect class="report-art-gold report-art-outline" x="28" y="73" width="29" height="29" rx="2" />
                <rect class="report-art-fill report-art-outline" x="69" y="73" width="29" height="29" rx="2" />
                <path class="report-art-line" d="M39 29 V39 M80 29 V39 M39 73 V83 M80 73 V83" />
                @break
            @case('stock-water')
                <path class="report-art-fill report-art-outline" d="M64 14 C52 32 28 56 28 79 A36 36 0 0 0 100 79 C100 56 76 32 64 14 Z" />
                <circle class="report-art-surface report-art-outline" cx="64" cy="77" r="25" />
                <path class="report-art-gold-line" d="M64 77 L78 64" />
                <path class="report-art-line" d="M47 77 V72 M64 59 V64 M82 77 H77 M54 91 H74" />
                @break
            @case('product-stock')
                <path class="report-art-fill report-art-outline" d="M18 39 L64 18 L110 39 V91 L64 112 L18 91 Z" />
                <path class="report-art-line" d="M18 39 L64 60 L110 39 M64 60 V112 M42 28 L87 49" />
                <path class="report-art-surface report-art-outline" d="M30 59 L55 71 V95 L30 83 Z" />
                <path class="report-art-line" d="M36 66 V82 M43 70 V85 M49 73 V88" />
                @break
            @case('product-critical')
                <path class="report-art-surface report-art-outline" d="M21 27 H67 L109 69 L67 111 L21 65 Z" />
                <circle class="report-art-fill report-art-outline" cx="37" cy="43" r="6" />
                <path class="report-art-line" d="M32 39 L17 21" />
                <path class="report-art-gold report-art-outline" d="M73 44 L81 61 L100 64 L86 77 L90 96 L73 87 L56 96 L60 77 L46 64 L65 61 Z" />
                @break
            @case('access-users')
                <circle class="report-art-surface report-art-outline" cx="31" cy="45" r="14" />
                <circle class="report-art-surface report-art-outline" cx="97" cy="45" r="14" />
                <path class="report-art-fill report-art-outline" d="M10 99 V84 C10 61 52 61 52 84 V99 Z M76 99 V84 C76 61 118 61 118 84 V99 Z" />
                <circle class="report-art-gold report-art-outline" cx="64" cy="35" r="18" />
                <path class="report-art-fill report-art-outline" d="M36 108 V85 C36 54 92 54 92 85 V108 Z" />
                <path class="report-art-light-line" d="M52 83 H76" />
                @break
            @case('access-roles')
                <path class="report-art-fill report-art-outline" d="M64 15 L104 29 V66 C104 88 83 103 64 113 C45 103 24 88 24 66 V29 Z" />
                <circle class="report-art-gold report-art-outline" cx="57" cy="54" r="16" />
                <path class="report-art-gold-line" d="M68 66 L89 87 M80 78 L87 71 M86 84 L93 77" />
                <circle class="report-art-ink" cx="54" cy="51" r="5" />
                @break
            @case('debtor-statements')
                <rect class="report-art-surface report-art-outline" x="23" y="16" width="64" height="91" rx="5" />
                <path class="report-art-fill" d="M27 20 H83 V35 H27 Z" />
                <path class="report-art-line" d="M35 47 H74 M35 61 H63 M35 75 H52" />
                <rect class="report-art-gold report-art-outline" x="55" y="74" width="61" height="36" rx="4" />
                <path class="report-art-line" d="M57 77 L85 96 L113 77" />
                @break
            @case('asset-register')
                <rect class="report-art-fill report-art-outline" x="15" y="25" width="86" height="62" rx="7" />
                <rect class="report-art-surface" x="24" y="34" width="68" height="43" rx="2" />
                <path class="report-art-line" d="M45 88 V104 M70 88 V104 M33 105 H82" />
                <path class="report-art-gold report-art-outline" d="M79 62 H108 L119 75 L99 100 L79 84 Z" />
                <circle class="report-art-ink" cx="89" cy="73" r="3" />
                @break
            @case('asset-movement')
                <path class="report-art-fill report-art-outline" d="M13 51 L36 40 L59 51 V78 L36 90 L13 78 Z M70 72 L93 61 L116 72 V100 L93 112 L70 100 Z" />
                <path class="report-art-line" d="M13 51 L36 63 L59 51 M36 63 V90 M70 72 L93 84 L116 72 M93 84 V112" />
                <path class="report-art-gold-line" d="M39 28 H93 V49 M85 41 L93 49 L101 41" />
                @break
            @case('asset-repairs')
                <rect class="report-art-fill report-art-outline" x="16" y="20" width="79" height="65" rx="6" />
                <rect class="report-art-surface" x="25" y="29" width="61" height="46" rx="2" />
                <path class="report-art-line" d="M43 86 V103 M66 86 V103 M29 104 H77" />
                <path class="report-art-gold report-art-outline" d="M108 57 A19 19 0 0 0 83 80 L59 104 A8 8 0 0 0 70 115 L94 91 A19 19 0 0 0 117 66 L104 78 L96 70 Z" />
                @break
            @case('asset-masters')
                <rect class="report-art-fill report-art-outline" x="23" y="17" width="82" height="92" rx="6" />
                <rect class="report-art-surface report-art-outline" x="33" y="29" width="62" height="29" rx="3" />
                <rect class="report-art-gold report-art-outline" x="33" y="69" width="62" height="29" rx="3" />
                <path class="report-art-line" d="M54 43 H74 M54 83 H74 M32 109 V114 M96 109 V114" />
                @break
        @endswitch
    @endif
    </g>
</svg>
