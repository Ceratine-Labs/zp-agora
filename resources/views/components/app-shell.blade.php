@props(['title' => 'Agora'])

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
    @vite(['resources/scss/app.scss', 'resources/js/app.js'])
</head>
<body>

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
