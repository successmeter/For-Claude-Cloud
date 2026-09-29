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
        // already be started on the request.
        //
        // SECURITY: the previous version of this block appended EncryptCookies,
        // AddQueuedCookiesToResponse, and StartSession to the 'api' group
        // unconditionally, for every request regardless of origin. That meant ANY
        // cross-site request (e.g. an attacker's auto-submitting HTML form posting to
        // /api/login) got a session cookie back, with no CSRF check anywhere in the
        // stack — a login-CSRF vulnerability: an attacker could silently log a victim's
        // browser into the attacker's own account. Sanctum's own
        // EnsureFrontendRequestsAreStateful middleware is the correct fix: it internally
        // pushes EncryptCookies + AddQueuedCookiesToResponse + StartSession + CSRF
        // verification onto the pipeline, but ONLY for requests whose Origin/Referer
        // matches a domain in config('sanctum.stateful') (see api/config/sanctum.php —
        // currently the Sanctum install defaults: localhost variants, sufficient for
        // local dev/testing; the real SPA origin is added when that frontend is built).
        // Any other request is treated as fully stateless: no cookie is issued at all,
        // so a cross-site form can no longer trigger a session cookie for the victim's
        // browser. This restores the stateful allowlist as an actual security boundary.
        //
        // IMPORTANT finding #5 (final whole-branch review): statefulApi() is Laravel's
        // own helper for this, and using it (instead of appendToGroup, as this block
        // previously did) matters for more than style: appendToGroup() places its
        // middleware AFTER the api group's default array, which ends with
        // SubstituteBindings. statefulApi() instead makes EnsureFrontendRequestsAreStateful
        // the FIRST entry Laravel puts in the 'api' group's base array (see
        // Illuminate\Foundation\Configuration\Middleware::getMiddlewareGroups()), i.e.
        // before SubstituteBindings, not after.
        $middleware->statefulApi();

        // Tenant resolution (Plan B Task 3) is the per-route `tenant` middleware, which needs
        // the authenticated user (membership decides the org) and so must run after auth.
        //
        // It must still run before SubstituteBindings (Plan A's finding, kept): Laravel
        // resolves route-model-bound parameters (e.g. /hub/v1/.../{set}) DURING
        // SubstituteBindings, and that lookup is the query Postgres RLS filters by
        // app.current_org_id. Run after it, the lookup has no tenant context and always
        // 404s. Route middleware normally runs after the group's SubstituteBindings, so the
        // priority list is what enforces the order: auth < tenant < SubstituteBindings.
        // ResolveTenantTest's bound-{venue} cases pin this down.
        $middleware->alias([
            'tenant' => \App\Http\Middleware\ResolveTenant::class,
            'mfa.owner' => \App\Http\Middleware\EnsureOwnerHasMfa::class,
        ]);
        $middleware->prependToPriorityList(
            before: \Illuminate\Routing\Middleware\SubstituteBindings::class,
            prepend: \App\Http\Middleware\ResolveTenant::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
