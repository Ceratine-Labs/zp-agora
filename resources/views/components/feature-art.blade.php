@props(['art' => null, 'animated' => false])
@php
    $art ??= app(\Modules\Core\Services\NavigationArtwork::class)->current();
    $kind = in_array($art['kind'] ?? '', ['module', 'report', 'tool', 'logo'], true) ? $art['kind'] : 'module';
    $variant = $art['variant'] ?? 'cup';
    $loaderVariant = $variant === 'my-queue' ? 'my-queue' : 'reports';
@endphp
<span {{ $attributes->class('feature-art') }} data-feature-art-kind="{{ $kind }}" data-feature-art-variant="{{ $variant }}" aria-hidden="true">
    @if ($kind === 'logo')
        @if ($variant === 'zp')<x-zp-mark />@else<x-agora-mark />@endif
    @elseif ($kind === 'report')
        {{-- The shared loader can reuse this same SVG when its menu link opens. --}}
        <x-report-art :variant="$variant" class="module-art loader-art loader-{{ $loaderVariant }}" data-module-art="{{ $loaderVariant }}" data-module-art-animated="{{ $animated ? 'true' : 'false' }}" />
    @elseif ($kind === 'tool')
        <x-tool-art :variant="$variant" :animated="$animated" />
    @else
        <x-module-art :variant="$variant" :animated="$animated" />
    @endif
</span>
