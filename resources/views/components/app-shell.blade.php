{{--
    `wide` lets a screen use the whole window rather than the reading measure.

    1560px is right for a page somebody READS — prose past about that width is
    harder to follow, and every form and report on the site wants it. A grid is
    not read, it is scanned across: the configuration table carries fourteen
    columns, and at 1560 the site name — the column that says which row you are
    looking at — was pushed off the left into a horizontal scroll. Ryan hit
    that on live on 8 September 2026.

    So it is opt-in per screen rather than a new default: the pages that want
    it are the ones whose content is a wide table.
--}}
@props(['title' => 'Agora', 'wide' => false])

<!doctype html>
@php
    // The stored preference wins, because it is the person's choice wherever
    // they signed in; the cookie is the fallback that makes it instant on this
    // browser and covers a signed-out page.
    $agoraTheme = auth()->check()
        ? \Modules\Core\Models\UserPreference::get(auth()->user()->Id, \Modules\Core\Models\UserPreference::THEME)
        : null;
    $agoraTheme ??= request()->cookie('agora_theme');
    $agoraTheme = in_array($agoraTheme, ['light', 'dark'], true) ? $agoraTheme : null;
@endphp
<html lang="en" @if($agoraTheme) data-theme="{{ $agoraTheme }}" @endif>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title }} · Agora</title>
    <x-page-startup />
    @vite(['resources/scss/app.scss', 'resources/js/app.js'])
</head>
{{-- The wide mode belongs on the BODY, not on <main>.

     Put on <main> alone it widened the content and left the app bar and the
     scope bar on the reading measure, so the page head began about 150px to
     the LEFT of the "As at" control directly above it. Ryan read that as the
     content sitting too close to the edge, and he was right — a left edge that
     moves down the page is worse than either width on its own. --}}
<body class="{{ $wide ? 'is-wide' : '' }}">

{{-- The matching loader is first in the body on every view. The startup guard
     keeps it opaque until initial controls, tables, charts and panes are ready.
     Later waits still use the same overlay through Agora.loader. --}}
@php
    $routeLoader = collect(config('agora.route_loaders', []))->first(fn ($loader) =>
        request()->routeIs($loader['route']) && collect($loader['parameters'])->every(fn ($value, $parameter) =>
            (string) request()->route($parameter) === $value));
    $loaderVariant = $routeLoader['art'] ?? collect(config('agora.loaders', []))->first(fn ($variant, $pattern) => request()->routeIs($pattern), 'cup');
    $currentArt = app(\Modules\Core\Services\NavigationArtwork::class)->current();
    $loaderTool = $currentArt['kind'] === 'tool' ? $currentArt['variant'] : null;
    $loaderReport = $currentArt['kind'] === 'report' ? $currentArt['variant'] : null;
    if (request()->routeIs('app.modules.show') && $currentArt['kind'] === 'module') {
        $loaderVariant = $currentArt['variant'];
    }
@endphp
<x-loader overlay startup :variant="$loaderVariant" :tool="$loaderTool" :report="$loaderReport"
          :label="'Opening '.$title.'…'" :hold="request()->attributes->get('loader_hold')" />

<x-app-bar
    :sections="$shellSections"
    :workspace="$shellWorkspace"
    :workspaces="$shellWorkspaces" />

<x-scope-bar
    :branches="$shellBranches"
    :branch-id="$shellBranchId"
    :workspace="$shellWorkspace"
    :granted="$shellBranchesGranted" />

<main class="shell-main">
    <div class="shell-in">
        {{ $slot }}
    </div>
</main>

<footer class="shell-foot">
    <div class="shell-in">
        <span>{{ config('database.connections.'.config('agora.connections.primary').'.database') }}</span>
        <span>·</span>
        <span>{{ auth()->user()?->UserName }}</span>
        <span>·</span>
        <span>{{ auth()->user()?->role?->Name }}</span>
        <span class="grow"></span>
        <span>Agora v1.0</span>
    </div>
</footer>

</body>
</html>
