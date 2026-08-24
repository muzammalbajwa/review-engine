<?php

use App\Http\Middleware\EnsureTenantOwner;
use App\Http\Middleware\RequirePermission;
use App\Http\Middleware\RequireSendingAccess;
use App\Http\Middleware\ResolveMessageClickTenant;
use App\Http\Middleware\ResolveQuickAddTenant;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetTenantContext;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;
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

        // Same class of bug as SetTenantContext above, found live while
        // gathering examples for the /docs/api page: Laravel's hardcoded
        // priority list includes ThrottleRequests but not Sanctum's
        // CheckAbilities, so despite the webhook route declaring
        // 'abilities:contacts:create' BEFORE 'throttle:webhook-api', the
        // priority list silently ran throttle first — confirmed via
        // Router::gatherRouteMiddleware() on the live route, and via a
        // clean curl request against a fresh tenant that consumed rate-limit
        // budget on a request whose token had the wrong ability entirely.
        // That's a real security gap, not just a mislabeled error: a
        // wrongly-scoped (or narrowly-scoped-but-leaked) token could burn a
        // tenant's whole rate-limit budget with requests that were always
        // going to be rejected, and a caller right at their limit would see
        // a misleading 429 instead of the 403 that would tell them their
        // key is wrong. Prepending here — rather than relying on declared
        // array order — makes the ability check run before the throttle
        // check regardless of where either falls in the framework's
        // default priority list.
        $middleware->prependToPriorityList(
            before: ThrottleRequests::class,
            prepend: CheckAbilities::class,
        );

        // Same structural pattern found on /quick/{token} while auditing
        // for this exact bug class elsewhere (confirmed via
        // Router::gatherRouteMiddleware(): declared
        // ['quick-add.tenant', 'throttle:quick-add'] actually ran throttle
        // first, tenant resolution last). Verified this one carries no
        // real security consequence — ResolveQuickAddTenant and the
        // 'quick-add' rate limiter both key off the raw {token} route
        // segment independently, neither depends on the other having run
        // — but it's the same known footgun class and the fix is one
        // line, so closed rather than left as a latent trap for the next
        // person who trusts the declared array order.
        $middleware->prependToPriorityList(
            before: ThrottleRequests::class,
            prepend: ResolveQuickAddTenant::class,
        );

        // Same class of bug, same fix, applied proactively this time
        // rather than found later by audit: the click-tracking route
        // declares ['click.tenant', 'throttle:click'] in that order for
        // the same reason quick-add does (resolve who this is before
        // deciding whether to rate-limit it), and Laravel's hardcoded
        // priority list would otherwise silently run throttle first here
        // too. No real security consequence either (ResolveMessageClickTenant
        // and the 'click' limiter both key off the raw {token} route segment
        // independently), but there's no reason to leave the same footgun
        // sitting in a third route now that the pattern is known.
        $middleware->prependToPriorityList(
            before: ThrottleRequests::class,
            prepend: ResolveMessageClickTenant::class,
        );

        $middleware->group('tenant', [SetTenantContext::class, 'auth:sanctum']);

        // A single alias() call, not one per concern: Middleware::alias()
        // assigns $this->customAliases = $aliases wholesale rather than
        // merging — a second call silently wipes out whatever an earlier
        // call registered. Confirmed live: splitting this into separate
        // calls (one per feature, added incrementally over several
        // sessions) had already made 'tenant.context' unreachable, and
        // adding a third call for 'abilities'/'ability' the same way took
        // 'quick-add.tenant' down with it — every /quick/{token} test
        // failed with "Target class [quick-add.tenant] does not exist"
        // until this was consolidated.
        $middleware->alias([
            'tenant.context' => SetTenantContext::class,
            // The guest quick-add link's own tenant-resolution — no
            // auth:sanctum involved at all, so (unlike 'tenant' above)
            // this doesn't need special priority-list placement: nothing
            // else on this route competes with it to read the
            // `users`/`tenants` tables before it runs.
            'quick-add.tenant' => ResolveQuickAddTenant::class,
            // The click-tracking redirect's own tenant-resolution — same
            // shape as quick-add.tenant above, against messages.click_token
            // instead of tenants.quick_add_token.
            'click.tenant' => ResolveMessageClickTenant::class,
            // Not auto-registered by this app's bootstrap/app.php-style
            // middleware config (Sanctum's own service provider only
            // auto-aliases these under the legacy Http/Kernel.php
            // $routeMiddleware convention, which this app doesn't use).
            // Reused as-is, not reimplemented: the webhook API key's
            // contacts:create-only scoping (.claude/CLAUDE.md) is
            // enforced by CheckAbilities throwing Laravel\Sanctum
            // \Exceptions\MissingAbilityException — which extends
            // AuthorizationException, so it already flows through the
            // AccessDeniedHttpException handler below with zero new
            // exception-handling code.
            'abilities' => CheckAbilities::class,
            'ability' => CheckForAnyAbility::class,
            // Trial-expiry gate — see RequireSendingAccess's own docblock
            // for exactly which routes this is applied to and why.
            'sending.access' => RequireSendingAccess::class,
            // Team permissions (contacts/templates/reviews/analytics) and
            // the separate owner-only gate (billing, team management) —
            // see RequirePermission/EnsureTenantOwner's own docblocks.
            'permission' => RequirePermission::class,
            'owner' => EnsureTenantOwner::class,
        ]);
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

            // $e->getHeaders() carries Retry-After (and X-RateLimit-*, when
            // the limiter reports a remaining-attempts count) — set by
            // Illuminate\Routing\Middleware\ThrottleRequests when it builds
            // this exception. Returning a bare response()->json(...) here
            // silently drops them: confirmed live, every throttled route in
            // this app (login/register included) was serving 429s with no
            // Retry-After header at all before this line existed. The
            // .claude/CLAUDE.md webhook-API spec calls this out explicitly
            // ("not a silent drop") for POST /contacts, but the fix belongs
            // here — the one place every throttled route in the app renders
            // its 429, not duplicated per-route.
            return response()->json([
                'error' => 'rate_limited',
                'message' => 'Too many attempts. Please try again later.',
                'fields' => null,
            ], 429, $e->getHeaders());
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
