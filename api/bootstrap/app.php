<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Sanctum SPA session-cookie auth (05-security.md §5.3): api/login and api/logout
        // call $request->session()->{regenerate,invalidate}(), which requires a session to
        // already be started on the request. The 'api' middleware group doesn't start one
        // by default (that's normally only in 'web'), and Sanctum's own
        // EnsureFrontendRequestsAreStateful only starts one conditionally, when the
        // request's Origin/Referer matches config('sanctum.stateful') — which same-origin
        // SPA requests will, but which isn't set up yet this early in the plan. Since this
        // API has no third-party bearer-token consumers, every api request gets a session
        // unconditionally. CSRF verification is deliberately NOT added here yet — no route
        // in this task performs a state change purely on a pre-existing authenticated
        // session without the caller already presenting fresh credentials (register creates
        // its own session; login requires the password), so there's nothing for CSRF to
        // protect yet. This must be revisited (VerifyCsrfToken + the sanctum/csrf-cookie
        // route) before any authenticated-session-only mutating endpoint is added.
        $middleware->appendToGroup('api', [
            \Illuminate\Cookie\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
        ]);
        $middleware->appendToGroup('api', \App\Http\Middleware\SetTenantContext::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
