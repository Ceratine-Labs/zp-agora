@props(['item', 'depth' => 1])

{{--
    One node of the menu tree, drawn at whatever depth it sits.

    Depth 1 is a column heading in the panel. Depth 2 is a link. Depth 3 and
    deeper is a nested group that expands in place, which is why this component
    calls itself: the customer asked for children and sub-children, and the
    schema puts no ceiling on how many levels they add later.
--}}
@php
    $children = $item->childItems ?? collect();
    $hasChildren = $children->isNotEmpty();
@endphp

@if ($depth === 1)
    <div class="mega-col">
        <h4>{{ $item->Label }}</h4>
        @if ($item->isLink())
            <a class="mega-link mega-col-link" href="{{ $item->href() }}">{{ __('Overview') }}</a>
        @endif
        <ul>
            @foreach ($children as $child)
                <li><x-menu-branch :item="$child" :depth="2" /></li>
            @endforeach
        </ul>
    </div>
@elseif ($hasChildren)
    {{-- A link that also has children: the label opens the group in place. --}}
    <details class="mega-group" style="--depth: {{ $depth }}">
        <summary>
            <svg class="twist" viewBox="0 0 8 8" aria-hidden="true"><path d="M2.5 1 5.5 4 2.5 7" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg>
            <span>{{ $item->Label }}</span>
            @if ($item->Hint)<span class="hint">{{ $item->Hint }}</span>@endif
        </summary>
        <ul>
            @if ($item->isLink())
                <li><a class="mega-link" href="{{ $item->href() }}">{{ $item->Label }} — {{ __('open') }}</a></li>
            @endif
            @foreach ($children as $child)
                <li><x-menu-branch :item="$child" :depth="$depth + 1" /></li>
            @endforeach
        </ul>
    </details>
@elseif ($item->isLink())
    <a class="mega-link" href="{{ $item->href() }}" style="--depth: {{ $depth }}">
        <span>{{ $item->Label }}</span>
        @if ($item->Hint)<span class="hint">{{ $item->Hint }}</span>@endif
    </a>
@else
    <span class="mega-link is-dead" style="--depth: {{ $depth }}" title="Not built yet">
        <span>{{ $item->Label }}</span>
        @if ($item->Hint)<span class="hint">{{ $item->Hint }}</span>@endif
    </span>
@endif
