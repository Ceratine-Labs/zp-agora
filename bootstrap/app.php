<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

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
        $middleware->encryptCookies(except: ['agora_theme']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
