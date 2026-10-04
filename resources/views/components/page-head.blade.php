{{--
    `blurb` is a line that stays on the page — use it for facts about this
    page (the window, the site). `info` is a slot for standing explanation,
    behind an <x-info> button beside the title.
--}}
@props(['eyebrow' => null, 'title' => '', 'blurb' => null])

<div class="page-head">
    <div>
        @if ($eyebrow)<p class="eyebrow">{{ $eyebrow }}</p>@endif
        @isset($info)
            <div class="ph-title">
                <h1>{{ $title }}</h1>
                <x-info class="info-wide" label="About this page">{{ $info }}</x-info>
            </div>
        @else
            <h1>{{ $title }}</h1>
        @endisset
        @if ($blurb)<p class="blurb">{{ $blurb }}</p>@endif
    </div>
    @isset($actions)
        <div class="page-actions">{{ $actions }}</div>
    @endisset
</div>
