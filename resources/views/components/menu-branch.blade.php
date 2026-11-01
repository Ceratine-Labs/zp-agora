@props(['item', 'depth' => 1, 'artwork' => null, 'modules' => null])

{{-- The permitted tree stays in the DOM; search only changes its visibility. --}}
@php
    $children = $item->childItems ?? collect();
    $hasChildren = $children->isNotEmpty();
    $artwork ??= app(\Modules\Core\Services\NavigationArtwork::class);
    $art = $artwork->forItem($item);
    $modules ??= app(\Modules\Core\Services\NavigationModules::class);
    $ownModule = $item->availableHref !== null ? $modules->forDestination($item) : null;
    $hint = trim((string) $item->Hint);
    $hint = strcasecmp($hint, 'live') === 0 ? '' : $hint;
    $searchLabel = trim($item->Label.' '.$hint);
@endphp

@if ($depth === 1)
    <div class="mega-col" data-menu-node data-menu-label="{{ $searchLabel }}" data-menu-active="{{ $item->hasAvailableItems ? 'true' : 'false' }}">
        <h4>{{ $item->Label }}</h4>
        @if ($item->availableHref !== null)
            <a class="mega-link mega-col-link" href="{{ $item->availableHref }}" data-loader="Opening {{ $item->Label }}…" data-menu-entry data-menu-active="true" data-menu-label="{{ trim($searchLabel.' '.($ownModule['label'] ?? '')) }}">
                <x-feature-art :art="$art" class="mega-art" /><span>{{ __('Overview') }}</span>
                @if ($ownModule)<span class="mega-module-tag">{{ $ownModule['label'] }}</span>@endif
            </a>
        @endif
        <ul>
            <x-menu-children :items="$children" :depth="2" :artwork="$artwork" :modules="$modules" />
        </ul>
    </div>
@elseif ($hasChildren)
    <details class="mega-group" style="--depth: {{ $depth }}">
        <summary>
            <x-feature-art :art="$art" class="mega-art" />
            <span class="mega-label">{{ $item->Label }}</span>
            @if ($hint)<span class="hint">{{ $hint }}</span>@endif
            <svg class="twist" viewBox="0 0 8 8" aria-hidden="true"><path d="M2.5 1 5.5 4 2.5 7" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg>
        </summary>
        <ul>
            @if ($item->availableHref !== null)
                <li><a class="mega-link" href="{{ $item->availableHref }}" data-loader="Opening {{ $item->Label }}…" data-menu-entry data-menu-active="true" data-menu-label="{{ trim($searchLabel.' '.($ownModule['label'] ?? '')) }}">
                    <x-feature-art :art="$art" class="mega-art" /><span class="mega-label">{{ $item->Label }} — {{ __('open') }}</span>
                    @if ($ownModule)<span class="mega-module-tag">{{ $ownModule['label'] }}</span>@endif
                </a></li>
            @endif
            <x-menu-children :items="$children" :depth="$depth + 1" :artwork="$artwork" :modules="$modules" />
        </ul>
    </details>
@elseif ($item->availableHref !== null)
    <a class="mega-link" href="{{ $item->availableHref }}" style="--depth: {{ $depth }}" data-loader="Opening {{ $item->Label }}…" data-menu-entry data-menu-active="true" data-menu-label="{{ trim($searchLabel.' '.($ownModule['label'] ?? '')) }}">
        <x-feature-art :art="$art" class="mega-art" /><span class="mega-label">{{ $item->Label }}</span>
        @if ($hint)<span class="hint">{{ $hint }}</span>@endif
    </a>
@else
    <span class="mega-link is-dead" style="--depth: {{ $depth }}" title="Not currently available" data-menu-entry data-menu-active="false" data-menu-label="{{ $searchLabel }}">
        <x-feature-art :art="$art" class="mega-art" /><span class="mega-label">{{ $item->Label }}</span>
        @if ($hint)<span class="hint">{{ $hint }}</span>@endif
    </span>
@endif
