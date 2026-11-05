<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Access required</title>
    <style>
        :root { color-scheme: light dark; font-family: system-ui, sans-serif; }
        body { min-height: 100vh; margin: 0; display: grid; place-items: center; background: #eef1f5; color: #172033; }
        main { box-sizing: border-box; width: min(32rem, calc(100% - 2rem)); padding: 2rem; border: 1px solid #d5dbe5; border-radius: 1rem; background: #fff; }
        h1 { margin: 0 0 .75rem; font-size: 1.5rem; }
        p { line-height: 1.5; }
        button { padding: .65rem 1rem; border: 0; border-radius: .5rem; background: #4d3dbb; color: #fff; font: inherit; cursor: pointer; }
        @media (prefers-color-scheme: dark) { body { background: #101827; color: #f2f4f8; } main { background: #1b2535; border-color: #39455a; } }
    </style>
</head>
<body>
    <main>
        <h1>Access required</h1>
        <p>You have no permissions in Agora. Please contact your administrator.</p>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit">Sign out</button>
        </form>
    </main>
</body>
</html>
