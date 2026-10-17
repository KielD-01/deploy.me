<?php

use App\Support\ViewerTimezone;
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
    ->withBroadcasting(__DIR__.'/../routes/channels.php')
    ->withMiddleware(function (Middleware $middleware): void {
        // TLS ends at the shared Caddy edge. Trust its X-Forwarded-* headers so
        // URLs, redirects and secure cookies come out as https.
        $middleware->trustProxies(at: '*');

        // The browser names its timezone in this cookie, in the clear (ViewerTimezone).
        $middleware->encryptCookies(except: [ViewerTimezone::COOKIE]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
