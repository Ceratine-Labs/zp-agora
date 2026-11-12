{{--
    `blurb` is a line that stays on the page — use it for facts about this
    page (the window, the site). `info` is a slot for standing explanation,
    behind an <x-info> button beside the title.

    `compact` is for a screen whose table needs the height: smaller art, and
    the blurb on the title's line instead of under it (stock recon run,
    12 Nov 2026).
--}}
@props(['eyebrow' => null, 'title' => '', 'blurb' => null, 'art' => null, 'compact' => false])

<div @class(['page-head', 'is-compact' => $compact])>
    <div class="page-heading">
        <x-feature-art class="page-head-art" :art="$art" />
        <div class="page-heading-copy">
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
    </div>
    @isset($actions)
        <div class="page-actions">{{ $actions }}</div>
    @endisset
</div>
