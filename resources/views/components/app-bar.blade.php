@props(['sections' => collect(), 'workspace' => 'ho', 'workspaces' => []])

{{--
    The app bar and the mega panel it opens.

    Each section button owns one panel. The panel is a full-width div that
    expands downward under the bar — the customer's stated shape — and its
    contents are the section's item tree at whatever depth the data goes.
    Rendering is recursive (see menu-branch), so a third or fourth level needs
    no change here.
--}}
<header class="appbar" id="appbar">
    <a class="brand" href="{{ route('app.dashboard') }}">
        <svg class="mark" viewBox="0 0 24 24" aria-hidden="true">
            <path d="M3 21V5a2 2 0 0 1 2-2h7a2 2 0 0 1 2 2v16" fill="none" stroke="#e6c15c" stroke-width="1.8" stroke-linejoin="round"/>
            <path d="M2 21h13" stroke="#e6c15c" stroke-width="1.8" stroke-linecap="round"/>
            <path d="M5.5 6.5h6v3.5h-6z" fill="#e6c15c" opacity=".55"/>
            <path d="M14 9h3.2a2 2 0 0 1 2 2v6.2a1.8 1.8 0 0 0 3.6 0V12l-2.2-2.6" fill="none" stroke="#8aabb4" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
        <span class="brand-text">
            <span class="wm">AG<em>O</em>RA</span>
            <span class="sub">Zululand Retail &amp; Petroleum</span>
        </span>
    </a>

    <div class="wsw" role="group" aria-label="Workspace">
        @foreach ($workspaces as $code => $label)
            <a href="{{ request()->fullUrlWithQuery(['ws' => $code]) }}"
               class="{{ $workspace === $code ? 'on' : '' }}">{{ $label }}</a>
        @endforeach
    </div>

    <nav class="primary" id="nav" aria-label="Primary">
        @foreach ($sections as $section)
            <button type="button"
                    class="navbtn"
                    id="navbtn-{{ $section->Code }}"
                    data-menu="{{ $section->Code }}"
                    aria-expanded="false"
                    aria-controls="mega-{{ $section->Code }}">
                {{ $section->Label }}
                <svg class="caret" viewBox="0 0 8 8" aria-hidden="true"><path d="M1 2.5 4 5.5 7 2.5" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg>
            </button>
        @endforeach
    </nav>

    <div class="tools">
        {{-- The way across to ZP-NQL (Ryan, 2026-09-09). Its twin lives in
             ZP's app bar; the two systems sit under one brand on one box and
             increasingly the same people use both, so each carries a door to
             the other.

             Renders whether or not single sign-on is switched on — the URL is
             config('sso.peer.url'), which has a real default, while sign-on
             additionally needs a shared secret. With sign-on on you arrive
             already authenticated; without it you arrive at ZP's login page,
             which still beats typing the address.

             New tab on purpose: the two are used side by side, and somebody
             mid-way through a reconciliation does not want it replaced. --}}
        @if($peerUrl = config('sso.peer.url'))
        <a class="peerlink" href="{{ $peerUrl }}" target="_blank" rel="noopener"
           title="Open {{ config('sso.peer.name', 'ZP-NQL') }} in a new tab">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 010 5.656l-3 3a4 4 0 01-5.656-5.656l1.5-1.5m4.5-4.5l1.5-1.5a4 4 0 015.656 5.656l-3 3a4 4 0 01-5.656 0"/></svg>
            <span>{{ config('sso.peer.name', 'ZP-NQL') }}</span>
        </a>
        @endif
        <button class="iconbtn" id="themebtn" aria-label="Switch light / dark" title="Switch light / dark">
            <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M13.2 9.6A5.6 5.6 0 0 1 6.4 2.8a5.6 5.6 0 1 0 6.8 6.8Z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/></svg>
        </button>
        <form method="POST" action="{{ route('logout') }}" class="signout">
            @csrf
            <button type="submit" class="iconbtn" aria-label="Sign out" title="Sign out">
                <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M6 2H3.5A1.5 1.5 0 0 0 2 3.5v9A1.5 1.5 0 0 0 3.5 14H6" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/><path d="M10 11l3-3-3-3M13 8H6" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
        </form>
        <span class="avatar" title="{{ auth()->user()?->UserName }}">{{ Str::of(auth()->user()?->UserName ?? 'AG')->explode(' ')->map(fn ($w) => Str::substr($w, 0, 1))->take(2)->implode('') }}</span>
    </div>
</header>

@foreach ($sections as $section)
    <div class="mega" id="mega-{{ $section->Code }}" data-panel="{{ $section->Code }}" hidden>
        <div class="mega-in">
            @forelse ($section->items as $column)
                <x-menu-branch :item="$column" :depth="1" />
            @empty
                <p class="mega-empty">No entries in {{ $section->Label }} yet.</p>
            @endforelse
        </div>
    </div>
@endforeach

<div class="scrim" id="scrim" hidden></div>
