{{--
    One entry in the report library — the mockup's `libcard` / `libmeta`.

    The library is the screen where the customer finds out that the report they
    used to run is now called something else, so `was` is not a nicety: it is
    the only bridge between 161 legacy report names and the 97 that replace
    them. A card with no `was` line is a report that never existed before.

    `tags` are the library's own vocabulary — Runnable, Feeds a work queue,
    Merged, Renamed on purpose, New — passed as ['tone' => , 'label' => ].

    `data-s` carries the searchable text so the library's filter box can hide
    rows without a round trip. It is built here rather than by the page so that
    the search covers the same fields on every category.
--}}
@props([
    'name' => '',
    'desc' => null,
    'scope' => null,
    'was' => [],
    'tags' => [],
    'href' => null,
])

@php
    $was = array_values(array_filter((array) $was));
    $search = \Illuminate\Support\Str::lower(implode(' ', array_filter([
        $name, $desc, $scope, implode(' ', $was),
        implode(' ', array_column($tags, 'label')),
    ])));
    $tag = $href ? 'a' : 'div';
@endphp

<{{ $tag }} {{ $attributes->merge(['class' => 'libcard']) }}
    data-s="{{ $search }}"
    @if ($href) href="{{ $href }}" @endif>
    <div class="libcard-h">
        <div class="libcard-text">
            <div class="libcard-name">{{ $name }}</div>
            @if ($desc)<div class="libcard-desc">{{ $desc }}</div>@endif
        </div>
        @if ($tags)
            <div class="libcard-tags">
                @foreach ($tags as $chip)
                    <x-chip :tone="$chip['tone'] ?? 'neutral'">{{ $chip['label'] ?? '' }}</x-chip>
                @endforeach
            </div>
        @endif
    </div>

    @if ($scope || $was)
        <div class="libmeta">
            @if ($scope)<span>Scope: {{ $scope }}</span>@endif
            @if ($was)<span>Was: {{ implode(' · ', $was) }}</span>@endif
        </div>
    @endif
</{{ $tag }}>
