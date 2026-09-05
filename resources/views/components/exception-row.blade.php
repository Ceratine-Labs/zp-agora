{{--
    One row of the exception register — the mockup's `ex` / `bar` / `body`.

    The mockup toggled a class on click and hid the detail with CSS. This uses
    <details> instead: the row opens on Enter and Space, announces itself as a
    disclosure, and works with no JavaScript. That is the only deviation from
    the mockup's markup, and it costs one selector — `.ex .body[open] .d`
    rather than `.ex.open .d`. Everything else, including the severity bar down
    the left edge, is the design sheet's.

    `severity` is the register's own vocabulary — critical, serious, warning,
    good — and is mapped to a chip tone here so that a screen never has to know
    that "warning" wears the "warn" tint.

    `value` and `unit` are rendered as given: the caller has already put the
    figure through App\Support\Format, because only it knows whether this
    exception is measured in rand, litres or rows.
--}}
@props([
    'severity' => 'warning',
    'title' => '',
    'detail' => null,
    'category' => null,
    'site' => null,
    'age' => null,
    'owner' => null,
    'value' => null,
    'unit' => null,
    'href' => null,
    'open' => false,
])

@php
    $tones = ['critical' => 'crit', 'serious' => 'serious', 'warning' => 'warn', 'good' => 'good'];
    $tone = $tones[$severity] ?? 'neutral';
    $meta = array_values(array_filter([$category, $site, $age], fn ($p) => $p !== null && $p !== ''));
@endphp

<div {{ $attributes->merge(['class' => 'ex '.$severity]) }}>
    <div class="bar" aria-hidden="true"></div>

    <details class="body" @if ($open) open @endif>
        <summary>
            <div class="m">
                <x-chip :tone="$tone">{{ strtoupper($severity) }}</x-chip>
                @if ($owner)<x-chip tone="neutral" :dot="false">{{ $owner }}</x-chip>@endif
                @if ($meta)<span class="asat">{{ implode(' · ', $meta) }}</span>@endif
            </div>
            <div class="t">{{ $title }}</div>
            @if ($value !== null)
                <div class="m">
                    <span class="v">{{ $value }}</span>
                    @if ($unit)<span class="asat">{{ $unit }}</span>@endif
                </div>
            @endif
        </summary>

        <div class="d">
            @if ($detail)<p>{{ $detail }}</p>@endif
            {{ $slot }}
            @if ($href)<a class="btn sm" href="{{ $href }}">Open the detail <span aria-hidden="true">→</span></a>@endif
        </div>
    </details>
</div>
