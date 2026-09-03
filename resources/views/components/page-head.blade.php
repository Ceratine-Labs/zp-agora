@props(['eyebrow' => null, 'title' => '', 'blurb' => null])

<div class="page-head">
    <div>
        @if ($eyebrow)<p class="eyebrow">{{ $eyebrow }}</p>@endif
        <h1>{{ $title }}</h1>
        @if ($blurb)<p class="blurb">{{ $blurb }}</p>@endif
    </div>
    @isset($actions)
        <div class="page-actions">{{ $actions }}</div>
    @endisset
</div>
