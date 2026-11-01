{{-- Small, still illustrations for report cards. The adjacent title supplies the label. --}}
@props(['variant' => 'reports'])

@php
    $variants = [
        'my-queue', 'day-close', 'overnight-loads', 'unallocated-zreads',
        'open-cashups', 'purchase-approvals', 'staff-shorts', 'drop-safe',
        'waste', 'pump-readings', 'fuel-input', 'daily-banking',
        'stock-count', 'utility-meters', 'import-pos', 'audit-trail',
    ];
    $variant = in_array($variant, $variants, true) ? $variant : 'reports';
@endphp

<svg {{ $attributes->class('report-art') }} data-report-art="{{ $variant }}"
     viewBox="0 0 128 128" aria-hidden="true" focusable="false">
    <ellipse class="report-art-shadow" cx="64" cy="113" rx="43" ry="5" />

    @switch($variant)
        @case('my-queue')
            <rect class="report-art-surface report-art-outline" x="29" y="23" width="71" height="85" rx="8" />
            <rect class="report-art-fill report-art-outline" x="45" y="14" width="39" height="18" rx="6" />
            <path class="report-art-gold-line" d="M40 50 L45 55 L54 44 M40 72 L45 77 L54 66" />
            <path class="report-art-line" d="M65 50 H86 M65 72 H86 M65 94 H86" />
            <rect class="report-art-fill" x="40" y="88" width="12" height="12" rx="3" />
            @break
        @case('day-close')
            <rect class="report-art-surface report-art-outline" x="20" y="27" width="86" height="78" rx="8" />
            <path class="report-art-fill report-art-outline" d="M28 27 H98 Q106 27 106 35 V49 H20 V35 Q20 27 28 27 Z" />
            <path class="report-art-line" d="M41 18 V35 M85 18 V35 M35 65 H46 M60 65 H71 M35 85 H46" />
            <circle class="report-art-gold report-art-outline" cx="88" cy="89" r="24" />
            <path class="report-art-line" d="M76 89 L85 98 L102 80" />
            @break
        @case('overnight-loads')
            <path class="report-art-gold report-art-outline" d="M95 15 A26 26 0 1 0 114 52 A26 26 0 0 1 95 15 Z" />
            <path class="report-art-fill report-art-outline" d="M23 61 H86 V99 C86 113 23 113 23 99 Z" />
            <ellipse class="report-art-surface report-art-outline" cx="54.5" cy="61" rx="31.5" ry="12" />
            <path class="report-art-line" d="M23 79 C23 94 86 94 86 79" />
            <path class="report-art-gold-line" d="M98 73 V99 M90 91 L98 99 L106 91" />
            <circle class="report-art-fill" cx="35" cy="27" r="4" />
            <path class="report-art-line" d="M53 14 V24 M48 19 H58" />
            @break
        @case('unallocated-zreads')
            <path class="report-art-surface report-art-outline" d="M28 17 H90 V108 L80 102 L69 108 L59 102 L48 108 L38 102 L28 108 Z" />
            <path class="report-art-fill report-art-outline" d="M28 17 H90 V36 H28 Z" />
            <path class="report-art-line" d="M41 49 H77 M41 63 H62 M41 79 H51" />
            <circle class="report-art-gold report-art-outline" cx="90" cy="83" r="24" />
            <path class="report-art-line" d="M83 76 C83 66 99 66 99 76 C99 82 90 82 90 88" />
            <circle class="report-art-ink" cx="90" cy="96" r="2.5" />
            @break
        @case('open-cashups')
            <path class="report-art-surface report-art-outline" d="M75 13 H100 V43 H75 Z" />
            <path class="report-art-gold-line" d="M82 22 H93 M82 31 H91" />
            <rect class="report-art-fill report-art-outline" x="31" y="34" width="66" height="35" rx="5" />
            <rect class="report-art-gold" x="39" y="42" width="50" height="19" rx="2" />
            <path class="report-art-line" d="M48 52 H64 M78 48 V55" />
            <path class="report-art-fill report-art-outline" d="M29 69 H99 L111 96 H17 Z" />
            <path class="report-art-light-line" d="M38 79 H47 M59 79 H68 M80 79 H89" />
            <rect class="report-art-surface report-art-outline" x="18" y="96" width="92" height="15" rx="3" />
            <path class="report-art-line" d="M52 103 H76" />
            @break
        @case('purchase-approvals')
            <path class="report-art-surface report-art-outline" d="M27 17 H74 L94 37 V108 H27 Z" />
            <path class="report-art-fill report-art-outline" d="M74 17 V37 H94" />
            <path class="report-art-line" d="M40 50 H78 M40 65 H66 M40 80 H53" />
            <circle class="report-art-gold report-art-outline" cx="88" cy="88" r="25" />
            <path class="report-art-line" d="M75 88 L84 97 L102 79" />
            @break
        @case('staff-shorts')
            <path class="report-art-surface report-art-outline" d="M23 48 V36 L91 23 V50 Z" />
            <rect class="report-art-fill report-art-outline" x="20" y="45" width="88" height="62" rx="9" />
            <path class="report-art-line" d="M29 55 H95" />
            <rect class="report-art-surface report-art-outline" x="79" y="67" width="33" height="23" rx="5" />
            <circle class="report-art-fill" cx="90" cy="78.5" r="3" />
            <circle class="report-art-gold report-art-outline" cx="91" cy="30" r="20" />
            <path class="report-art-line" d="M82 30 H100" />
            @break
        @case('drop-safe')
            <rect class="report-art-fill report-art-outline" x="23" y="20" width="82" height="87" rx="8" />
            <path class="report-art-gold-line" d="M47 31 H81" />
            <rect class="report-art-surface report-art-outline" x="33" y="43" width="62" height="54" rx="5" />
            <circle class="report-art-gold report-art-outline" cx="64" cy="70" r="17" />
            <path class="report-art-line" d="M64 58 V82 M52 70 H76 M32 108 V113 M96 108 V113" />
            <circle class="report-art-ink" cx="85" cy="70" r="3" />
            @break
        @case('waste')
            <path class="report-art-fill report-art-outline" d="M31 42 H97 L91 106 H37 Z" />
            <path class="report-art-line" d="M51 27 V18 H77 V27" />
            <rect class="report-art-gold report-art-outline" x="24" y="28" width="80" height="14" rx="4" />
            <path class="report-art-light-line" d="M47 57 L50 92 M64 57 V92 M81 57 L78 92" />
            @break
        @case('pump-readings')
            <rect class="report-art-fill report-art-outline" x="25" y="22" width="53" height="86" rx="7" />
            <rect class="report-art-surface report-art-outline" x="33" y="31" width="37" height="26" rx="3" />
            <path class="report-art-line" d="M42 44 H61 M19 110 H85 M78 64 H86 V92 C86 108 108 108 108 91 V53" />
            <path class="report-art-gold report-art-outline" d="M94 33 L104 24 L116 40 V58 H105 V46 Z" />
            <path class="report-art-gold" d="M51 66 C47 74 41 78 41 84 A10 10 0 0 0 61 84 C61 78 55 74 51 66 Z" />
            @break
        @case('fuel-input')
            <path class="report-art-fill report-art-outline" d="M23 47 H105 V94 C105 114 23 114 23 94 Z" />
            <ellipse class="report-art-gold report-art-outline" cx="64" cy="47" rx="41" ry="14" />
            <path class="report-art-line" d="M23 83 C23 102 105 102 105 83" />
            <path class="report-art-line" d="M64 12 V42 M54 32 L64 42 L74 32" />
            <path class="report-art-surface" d="M64 61 C61 67 55 73 55 78 A9 9 0 0 0 73 78 C73 73 67 67 64 61 Z" />
            @break
        @case('daily-banking')
            <path class="report-art-fill report-art-outline" d="M18 46 L64 18 L110 46 Z" />
            <circle class="report-art-gold" cx="64" cy="35" r="8" />
            <path class="report-art-line" d="M25 54 H103 M17 111 H111" />
            <path class="report-art-surface report-art-outline" d="M28 55 H41 V96 H28 Z M57 55 H71 V96 H57 Z M87 55 H100 V96 H87 Z" />
            <rect class="report-art-gold report-art-outline" x="23" y="97" width="82" height="10" rx="2" />
            @break
        @case('stock-count')
            <path class="report-art-fill report-art-outline" d="M15 73 L41 59 L67 73 V101 L41 115 L15 101 Z" />
            <path class="report-art-gold report-art-outline" d="M61 73 L87 59 L113 73 V101 L87 115 L61 101 Z" />
            <path class="report-art-surface report-art-outline" d="M38 33 L64 19 L90 33 V61 L64 75 L38 61 Z" />
            <path class="report-art-line" d="M15 73 L41 87 L67 73 M41 87 V115 M61 73 L87 87 L113 73 M87 87 V115 M38 33 L64 47 L90 33 M64 47 V75" />
            <path class="report-art-gold-line" d="M51 28 L77 42" />
            @break
        @case('utility-meters')
            <rect class="report-art-fill report-art-outline" x="29" y="22" width="70" height="85" rx="11" />
            <circle class="report-art-surface report-art-outline" cx="64" cy="58" r="25" />
            <path class="report-art-line" d="M47 58 V54 M52 43 L55 47 M64 39 V44 M76 43 L73 47 M81 54 V58" />
            <path class="report-art-gold-line" d="M64 58 L77 49" />
            <circle class="report-art-gold" cx="64" cy="58" r="4" />
            <rect class="report-art-surface" x="44" y="89" width="40" height="10" rx="2" />
            <path class="report-art-line" d="M56 92 V96 M67 92 V96 M39 108 V113 M89 108 V113" />
            @break
        @case('import-pos')
            <path class="report-art-gold report-art-outline" d="M17 40 Q17 34 23 34 H49 L60 45 H105 V105 H17 Z" />
            <path class="report-art-surface report-art-outline" d="M53 18 H87 L103 34 V83 H53 Z" />
            <path class="report-art-gold report-art-outline" d="M87 18 V34 H103" />
            <path class="report-art-line" d="M65 45 H90 M65 56 H81" />
            <path class="report-art-fill report-art-outline" d="M17 64 H111 L101 107 H27 Z" />
            <path class="report-art-light-line" d="M64 72 V96 M55 87 L64 96 L73 87" />
            @break
        @case('audit-trail')
            <path class="report-art-surface report-art-outline" d="M26 18 H75 L94 37 V107 H26 Z" />
            <path class="report-art-fill report-art-outline" d="M75 18 V37 H94" />
            <path class="report-art-line" d="M39 50 H76 M39 65 H59 M39 80 H49" />
            <path class="report-art-gold report-art-outline" d="M70 69 A25 25 0 1 1 64 91" />
            <path class="report-art-line" d="M65 59 V74 H80 M88 74 V87 L98 93" />
            @break
        @default
            <path class="report-art-surface report-art-outline" d="M30 17 H79 L99 37 V108 H30 Z" />
            <path class="report-art-fill report-art-outline" d="M79 17 V37 H99" />
            <path class="report-art-line" d="M43 49 H84 M43 99 H86" />
            <rect class="report-art-fill" x="44" y="78" width="10" height="14" rx="2" />
            <rect class="report-art-gold" x="60" y="68" width="10" height="24" rx="2" />
            <rect class="report-art-fill" x="76" y="58" width="10" height="34" rx="2" />
    @endswitch
</svg>
