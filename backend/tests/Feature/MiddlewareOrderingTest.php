<?php

use App\Http\Middleware\ResolveQuickAddTenant;
use Illuminate\Routing\Middleware\ThrottleRequests;

/**
 * bootstrap/app.php's two prependToPriorityList() calls fix a real class
 * of bug (Laravel's hardcoded $middlewarePriority list silently reorders
 * declared route middleware — already bit /contacts once for
 * abilities-vs-throttle). Auditing for the same pattern elsewhere found
 * an identical mismatch on /quick/{token} (quick-add.tenant vs
 * throttle:quick-add) — no real security consequence here (neither
 * middleware depends on the other having run), but the same latent trap,
 * closed the same way. This asserts the actual resolved order directly,
 * since — unlike the webhook route — there's no *observable behavioral*
 * difference to assert against instead.
 */
test('quick-add tenant resolution runs before its own throttle, matching declared route order', function () {
    $route = collect(app('router')->getRoutes())->first(
        fn ($r) => str_starts_with($r->uri(), 'api/v1/quick/') && in_array('POST', $r->methods())
    );

    $ref = new ReflectionMethod(app('router'), 'gatherRouteMiddleware');
    $ref->setAccessible(true);
    $resolved = $ref->invoke(app('router'), $route);

    $tenantIndex = array_search(ResolveQuickAddTenant::class, $resolved);
    $throttleIndex = collect($resolved)->search(fn ($m) => str_starts_with($m, ThrottleRequests::class));

    expect($tenantIndex)->not->toBeFalse();
    expect($throttleIndex)->not->toBeFalse();
    expect($tenantIndex)->toBeLessThan($throttleIndex);
});
