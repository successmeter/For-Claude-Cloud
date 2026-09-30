<?php

use App\Hub\Http\Problem;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\Exceptions\MissingScopeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            Route::group([], __DIR__.'/../routes/oidc.php');
            Route::group([], __DIR__.'/../routes/hub.php');
        },
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

        // Behind the AWS load balancer (the only thing that can reach the containers), trust its
        // X-Forwarded-* headers so the app knows requests arrived over HTTPS. TRUSTED_PROXIES='*'
        // there; unset locally (nothing trusted).
        if ($proxies = env('TRUSTED_PROXIES')) {
            $middleware->trustProxies(at: $proxies === '*' ? '*' : explode(',', $proxies));
        }

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
            'auth.hub' => \App\Hub\Http\Middleware\AuthenticateHubCaller::class,
            'hub.user' => \App\Hub\Http\Middleware\RequireUserCaller::class,
        ]);
        $middleware->prependToPriorityList(
            before: \Illuminate\Routing\Middleware\SubstituteBindings::class,
            prepend: \App\Http\Middleware\ResolveTenant::class,
        );
        // /hub/v1 authenticates by bearer token (auth.hub) and needs the caller before `tenant`.
        $middleware->prependToPriorityList(
            before: \App\Http\Middleware\ResolveTenant::class,
            prepend: \App\Hub\Http\Middleware\AuthenticateHubCaller::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Hub contract v1 answers every error as RFC 9457 problem+json (contract/v1/README.md),
        // whatever the caller's Accept header.
        //
        // Render callbacks run AFTER Laravel's prepareException(), which has already turned
        // AuthorizationException (incl. Passport's MissingScopeException) into
        // AccessDeniedHttpException and ModelNotFoundException into NotFoundHttpException, keeping
        // the original as the previous exception. So match on the HTTP status, and look at the
        // previous exception only to tell a missing scope apart from other 403s.
        $exceptions->render(function (Throwable $e, Request $request) {
            // Plan C's first-party routes answer problems too; Plan A's auth routes keep Laravel's shape.
            if (! $request->is('hub/*', 'api/venues', 'api/venues/*', 'api/uploads', 'api/uploads/*')) {
                return null;
            }

            if ($e instanceof AuthenticationException) {
                return Problem::response(401, 'unauthenticated', 'A valid bearer token is required.');
            }
            if ($e instanceof ValidationException) {
                return Problem::response(422, 'validation_failed', 'The request is invalid.', ['errors' => $e->errors()]);
            }
            if (! $e instanceof HttpExceptionInterface) {
                return null; // a real 500: leave it to the default handler (and reporting)
            }

            return match ($e->getStatusCode()) {
                403 => $e->getPrevious() instanceof MissingScopeException
                    ? Problem::response(403, 'insufficient_scope', 'The token lacks a required scope.')
                    : Problem::response(403, 'forbidden', 'Not allowed.'),
                404 => Problem::response(404, 'not_found', 'Not found.'),
                405 => Problem::response(405, 'method_not_allowed', 'Method not allowed.'),
                429 => Problem::response(429, 'too_many_requests', 'Too many requests.'),
                default => Problem::response($e->getStatusCode(), 'http_error', 'Request failed.'),
            };
        });
    })->create();
