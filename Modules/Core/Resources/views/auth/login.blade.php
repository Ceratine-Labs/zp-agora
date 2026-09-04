<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign in · Agora</title>
    @vite(['resources/scss/app.scss', 'resources/js/app.js'])
</head>
<body class="signin-body">

<main class="signin">
    <div class="signin-card">
        <div class="signin-brand">
            <svg class="mark" viewBox="0 0 24 24" aria-hidden="true">
                <path d="M3 21V5a2 2 0 0 1 2-2h7a2 2 0 0 1 2 2v16" fill="none" stroke="#e6c15c" stroke-width="1.8" stroke-linejoin="round"/>
                <path d="M2 21h13" stroke="#e6c15c" stroke-width="1.8" stroke-linecap="round"/>
                <path d="M5.5 6.5h6v3.5h-6z" fill="#e6c15c" opacity=".55"/>
                <path d="M14 9h3.2a2 2 0 0 1 2 2v6.2a1.8 1.8 0 0 0 3.6 0V12l-2.2-2.6" fill="none" stroke="#8aabb4" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            <div>
                <p class="wm">AG<em>O</em>RA</p>
                <p class="sub">Zululand Retail &amp; Petroleum</p>
            </div>
        </div>

        <p class="signin-blurb">All Group Operations, Reconciliation &amp; Analysis — the whole estate, in one market square.</p>

        @if ($errors->any())
            <div class="signin-error" role="alert">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('login') }}" class="signin-form">
            @csrf
            <label>
                <span>Email address</span>
                <input type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username">
            </label>
            <label>
                <span>Password</span>
                <input type="password" name="password" required autocomplete="current-password">
            </label>
            <label class="signin-remember">
                <input type="checkbox" name="remember" value="1">
                <span>Keep me signed in on this device</span>
            </label>
            <button type="submit" class="btn-primary">Sign in</button>
        </form>
    </div>

    <p class="signin-foot">{{ config('database.connections.'.config('agora.connections.app').'.database') }} · Agora v1.0</p>
</main>

</body>
</html>
