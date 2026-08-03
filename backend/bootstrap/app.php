<?php

use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetTenantContext;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // The app only ever receives traffic through a TLS-terminating
        // reverse proxy (local: local-ssl-proxy, prod: nginx on Forge), so
        // it must trust that proxy's X-Forwarded-* headers — otherwise
        // isSecure()/HSTS, secure cookies, and url() generation all break.
        $middleware->trustProxies(at: '*');
        $middleware->append(SecurityHeaders::class);

        // This is an API-only app — there is no web 'login' route. Laravel's
        // withMiddleware() defaults every guest redirect to route('login')
        // unconditionally (Illuminate\Foundation\Configuration
        // \ApplicationBuilder::withMiddleware()), and Authenticate's
        // unauthenticated() only skips calling it when the request already
        // has $request->expectsJson() === true. Any client that doesn't send
        // Accept: application/json on an unauthenticated request — including
        // the frontend's own bare fetch() calls, which don't set that header
        // by default — hits RouteNotFoundException on 'login' and gets an
        // uncaught 500 instead of the 401 JSON registered below. Confirmed
        // live: a plain `curl -X POST /api/v1/logout` with no token and no
        // Accept header 500'd before this line was added.
        $middleware->redirectGuestsTo(fn () => null);

        // Protected routes should use 'tenant' instead of bare
        // 'auth:sanctum' — bundles the tenant-context middleware so it's
        // structurally impossible to protect a route with auth:sanctum and
        // forget to activate tenant scoping.
        //
        // SetTenantContext runs BEFORE auth:sanctum, not after: auth:sanctum
        // has to read the `users` row behind the token to authenticate, and
        // that row is hidden by RLS until a tenant context is active. See
        // SetTenantContext's docblock for the full reasoning.
        //
        // Declaring it first in the group array below is NOT enough on its
        // own — Laravel's Kernel has a hardcoded $middlewarePriority list
        // that includes the AuthenticatesRequests contract (which
        // Illuminate\Auth\Middleware\Authenticate implements), and that
        // priority list silently overrides declared array order within a
        // group. The "before" target has to be the contract interface, not
        // the concrete Authenticate class — the priority array's entries are
        // matched by literal string, and the concrete class never appears in
        // it, so targeting it here would silently no-op (confirmed live:
        // targeting Authenticate::class got silently appended to the *end*
        // of the priority list instead of inserted, and the RLS-ordering bug
        // was still reproducible afterward).
        $middleware->prependToPriorityList(
            before: AuthenticatesRequests::class,
            prepend: SetTenantContext::class,
        );

        $middleware->alias(['tenant.context' => SetTenantContext::class]);
        $middleware->group('tenant', [SetTenantContext::class, 'auth:sanctum']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // API.md's error envelope: { error, message, fields }. Scoped to
        // api/* so Horizon's own dashboard (an HTML surface with its own
        // auth-gate error handling) isn't affected — returning null falls
        // through to Laravel's default rendering for everything else.

        $exceptions->render(function (ValidationException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'error' => 'validation_failed',
                'message' => $e->getMessage(),
                'fields' => $e->errors(),
            ], 422);
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'error' => 'unauthenticated',
                'message' => $e->getMessage() ?: 'Authentication required.',
                'fields' => null,
            ], 401);
        });

        // Illuminate\Auth\Access\AuthorizationException, not this class, is
        // what Gate::authorize()/$this->authorize() actually throw — but
        // Handler::prepareException() unconditionally converts a
        // status-less AuthorizationException into this Symfony exception
        // *before* any custom render() callback is checked. A callback
        // type-hinted to the original AuthorizationException class silently
        // never matches and falls through to Laravel's default HTML 403
        // page. Confirmed live: the admin-only route's 403 for a non-admin
        // user rendered as HTML until this was corrected.
        $exceptions->render(function (AccessDeniedHttpException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'error' => 'forbidden',
                'message' => 'You are not authorized to perform this action.',
                'fields' => null,
            ], 403);
        });

        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'error' => 'rate_limited',
                'message' => 'Too many attempts. Please try again later.',
                'fields' => null,
            ], 429);
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'error' => 'not_found',
                'message' => 'The requested resource was not found.',
                'fields' => null,
            ], 404);
        });
    })->create();
