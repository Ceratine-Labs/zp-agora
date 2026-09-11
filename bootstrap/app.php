<?php

use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Modules\Core\Http\Middleware\AttemptCrossAppSignIn;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // The theme toggle is written by JavaScript, so this cookie arrives as
        // plaintext. Laravel decrypts every cookie by default, fails on this
        // one and hands the view a null — which meant the server never stamped
        // <html data-theme>, and a reload silently reverted the theme the
        // person had chosen. Caught by tests/e2e/shell.spec.js.
        //
        // It carries no secret and no identity: the only values it ever holds
        // are "light" and "dark", and a forged one changes nothing but the
        // colour of the page for the person who forged it.
        //
        // `ceratine_sso_checked` joins it for the same reason from the other
        // direction: it is written by Agora and READ BY ZP-NQL, which cannot
        // decrypt this app's cookies. It carries no identity and grants
        // nothing — the worst a forged one does is suppress a silent sign-in
        // for the browser that forged it.
        $middleware->encryptCookies(except: ['agora_theme', 'ceratine_sso_checked']);

        // Cross-app sign-in with ZP-NQL (config/sso.php). Inert unless
        // SSO_ENABLED, a secret and a peer URL are all set.
        //
        // Position is the whole difficulty here, and BOTH obvious choices
        // are wrong:
        //
        //   prepend — sits ahead of StartSession, so $request->user() is null
        //             on every request and the app asks ZP who you are
        //             immediately after ZP just told it. A redirect loop;
        //             curl calls it 50 hops.
        //   append  — sits at the end of the group, which is NOT before the
        //             route's `auth`. Laravel sorts the pipeline by
        //             $middlewarePriority, and `auth` implements
        //             Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests,
        //             which is slot 5 — ahead of SubstituteBindings, and so
        //             ahead of anything appended here. The handshake simply
        //             never ran, and every visitor went to the login page.
        //
        // Membership of the group is therefore not enough: it needs a
        // PRIORITY slot as well, which is what puts it between "the session
        // has started" and "you are not signed in, go and sign in".
        //
        // Anchored on the CONTRACT rather than on Authenticate::class because
        // that is what the framework's own list holds; anchoring on the
        // concrete class would silently no-op.
        $middleware->web(append: [
            AttemptCrossAppSignIn::class,
        ]);

        $middleware->prependToPriorityList(
            before: AuthenticatesRequests::class,
            prepend: AttemptCrossAppSignIn::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
