@props(['title' => 'Agora'])

<!doctype html>
<html lang="en" @if(request()->cookie('agora_theme')) data-theme="{{ request()->cookie('agora_theme') }}" @endif>
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
    :workspace="$shellWorkspace" />

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
