{{--
    The sign-in page frame: the mockup's two columns, hero on the left and the
    form on the right.

    MODULE-SCOPED ON PURPOSE, AND FLAGGED FOR REVIEW. The shared layer owns
    `resources/views/components` and `resources/scss`, and this lane does not
    touch either. `_components.scss` already carries the single-card sign-in
    (.signin-card, .signin-form, .signin-error and the rest) but not the
    mockup's hero column, its state panel or the status dot, so the rules for
    those live in the <style> block below rather than being written into a file
    another lane is rewriting this week.

    Every declaration is copied from docs/reference/agoraretailconsole.html
    lines 129-144 and 252, verbatim, and every colour is a token — there is no
    hex here and no new palette. When the shared layer is stable these rules
    should move into `_components.scss` beside .signin-card and this component
    should become <x-signin-layout>. That is a five-minute change and it is the
    reviewer's call, not mine.

    Props: `title` for the tab, `hero` and default slot for the two columns.
--}}
@props(['title' => 'Sign in'])

<!doctype html>
@php
    // Signed out, so there is no stored preference to read — the cookie is all
    // there is, and an unstamped document follows the operating system.
    $agoraTheme = request()->cookie('agora_theme');
    $agoraTheme = in_array($agoraTheme, ['light', 'dark'], true) ? $agoraTheme : null;
@endphp
<html lang="en" @if($agoraTheme) data-theme="{{ $agoraTheme }}" @endif>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} · Agora</title>
    @vite(['resources/scss/app.scss', 'resources/js/app.js'])
    <style>
        /* docs/reference/agoraretailconsole.html:129-144, 252 */
        .signin-wrap { min-height: 100vh; display: grid;
                       grid-template-columns: minmax(0, 1.15fr) minmax(360px, .85fr); }
        @media (max-width: 900px) { .signin-wrap { grid-template-columns: 1fr; } }

        .signin-hero { background: var(--chrome); color: var(--chrome-ink);
                       padding: 56px 48px; display: flex; flex-direction: column;
                       justify-content: center; gap: 22px; }
        .signin-mark .wm { font-family: var(--font-display); font-weight: 700;
                           letter-spacing: .06em; line-height: 1; color: #fff;
                           font-size: 44px; margin: 0; }
        .signin-mark .wm em { font-style: normal; color: var(--sand); }
        .signin-mark .sub { text-transform: uppercase; color: var(--chrome-muted);
                            margin: 8px 0 0; font-size: 11px; letter-spacing: .18em; }
        .signin-exp { font-size: 12px; letter-spacing: .10em; text-transform: uppercase;
                      opacity: .72; margin: 10px 0 0; }
        .signin-tag { font-family: var(--font-display); font-size: 27px; font-weight: 500;
                      color: var(--sand); margin: 0; letter-spacing: .01em; }
        .signin-why { margin: 0; max-width: 56ch; color: var(--chrome-ink); opacity: .85;
                      font-size: 13.5px; line-height: 1.6; }

        .signin-state { margin-top: 10px; border-top: 1px solid var(--chrome-line); padding-top: 18px; }
        .signin-state .eyebrow { color: var(--chrome-muted); margin: 0 0 10px; }
        .signin-state-row { display: flex; align-items: center; gap: 10px; padding: 5px 0;
                            font-size: 13px; color: var(--chrome-ink); }
        .signin-state-row a { color: inherit; }
        .sdot { width: 8px; height: 8px; border-radius: 50%; flex: none; background: var(--chrome-muted); }
        .sdot.good { background: var(--good); }
        .sdot.warn { background: var(--warn); }
        .sdot.serious { background: var(--serious); }
        .sdot.crit { background: var(--crit); }

        .signin-panel { background: var(--surface); color: var(--ink); padding: 52px 44px;
                        display: flex; flex-direction: column; justify-content: center; }
        .signin-panel > * { width: 100%; max-width: 360px; margin-left: auto; margin-right: auto; }
        .signin-panel h1 { font-family: var(--font-display); font-size: 26px; margin: 0 0 2px; }
        .signin-panel .signin-blurb { margin-bottom: 22px; }
        .signin-links { display: flex; align-items: baseline; justify-content: space-between;
                        gap: 12px; margin: 16px 0 0; font-size: 12.5px; }
        .signin-links .db { font-family: var(--font-mono); font-size: 11px; color: var(--muted); }
    </style>
</head>
<body class="signin-body">

<main class="signin-wrap">
    <div class="signin-hero">
        {{ $hero }}
    </div>

    <div class="signin-panel">
        {{ $slot }}
    </div>
</main>

</body>
</html>
