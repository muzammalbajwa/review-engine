<?php

namespace App\Providers;

use App\Models\Customer;
use App\Models\Subscription;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use LemonSqueezy\Laravel\LemonSqueezy;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(CurrentTenant::class);

        LemonSqueezy::useSubscriptionModel(Subscription::class);
        LemonSqueezy::useCustomerModel(Customer::class);

        // Must happen in register(), not boot(): every provider's
        // register() runs before ANY provider's boot(), but provider boot
        // ORDER isn't guaranteed — LemonSqueezyServiceProvider::boot()
        // (which reads LemonSqueezy::$registersRoutes to decide whether to
        // register its own /lemon-squeezy/webhook route) could run before
        // this provider's boot() does. Confirmed by `php artisan
        // route:list`: calling this from boot() left the package's own
        // route registered anyway. The package auto-registers that route
        // with no tenant-context resolution at all —
        // LemonSqueezyWebhookController is the one enforced entry point
        // instead (routes/api.php), which resolves tenant_id from the
        // checkout's custom_data and sets RLS context *before* delegating
        // to the package's own webhook processing. A second, unprotected
        // route to the same underlying logic would be a real
        // cross-tenant-write risk if ever hit directly, so it's disabled
        // outright rather than left dormant.
        LemonSqueezy::ignoreRoutes();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // .claude/CLAUDE.md quick-add: "Rate-limit this endpoint and tie
        // it to a per-tenant token... can't be abused for enumeration or
        // spam." Two limits, not one: per-token caps how fast a single
        // (even leaked) link can be spammed with fake contacts; per-ip
        // caps how fast one source can cycle through guessed tokens. The
        // token's own entropy (Str::random(48)) is the real defense
        // against enumeration — this is defense in depth, not the only
        // layer.
        RateLimiter::for('quick-add', function (Request $request) {
            $token = (string) $request->route('token');

            return [
                Limit::perMinute(5)->by('quick-add-token:'.$token),
                Limit::perMinute(20)->by('quick-add-ip:'.$request->ip()),
            ];
        });

        // Same two-limit shape as quick-add above, same reasoning: the
        // per-token limit covers a single link being hit repeatedly (a
        // real customer's browser retry, or an email client's own link
        // scanner following it ahead of the person) generously enough
        // that a legitimate click is never the one that gets throttled —
        // clicked_at only needs to be set once regardless, so a throttled
        // repeat click costs nothing real. The per-ip limit guards against
        // one source cycling through guessed tokens; the token's own
        // entropy (Str::random(48), same as quick_add_token) is the real
        // defense against enumeration, this is defense in depth.
        RateLimiter::for('click', function (Request $request) {
            $token = (string) $request->route('token');

            return [
                Limit::perMinute(10)->by('click-token:'.$token),
                Limit::perMinute(30)->by('click-ip:'.$request->ip()),
            ];
        });

        // .claude/CLAUDE.md webhook API spec: "Rate limiting tied to the
        // tenant's actual subscription plan... not one global limit for
        // everyone." Keyed by tenant (never ip — these are server-to-server
        // integration calls, often from a shared IP across many different
        // tenants' Zapier/Make.com accounts, so ip-keying would be both
        // wrong and fragile here unlike quick-add's browser-facing case
        // above).
        //
        // There's only one plan now (.claude/BILLING.md's single-plan
        // pricing change) — no per-plan lookup left to do, so this is just
        // a flat, tenant-keyed limit. requests_per_minute keeps living in
        // config/plans.php rather than being inlined here so it stays the
        // one place billing-adjacent numbers live.
        RateLimiter::for('webhook-api', function (Request $request) {
            $tenantId = app(CurrentTenant::class)->id();

            return Limit::perMinute(config('plans.standard.requests_per_minute'))->by('webhook-api:'.$tenantId);
        });
    }
}
