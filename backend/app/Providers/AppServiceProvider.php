<?php

namespace App\Providers;

use App\Support\Tenancy\CurrentTenant;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Paddle\Cashier;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(CurrentTenant::class);

        // Must happen in register(), not boot(): every provider's
        // register() runs before ANY provider's boot(), but provider boot
        // ORDER isn't guaranteed — Cashier's own CashierServiceProvider::
        // boot() (which reads Cashier::$registersRoutes to decide whether
        // to register its own POST /paddle/webhook route) could run
        // before this provider's boot() does. Same reasoning
        // LemonSqueezy::ignoreRoutes() was called for here previously
        // (git history) — Cashier's default webhook route has no
        // tenant-context resolution at all, and every cashier-paddle
        // table (customers/subscriptions/subscription_items/transactions
        // — see the FORCE ROW LEVEL SECURITY migration) would silently
        // match zero rows for it. No tenant-aware webhook controller
        // exists yet (deliberately not built this step — package
        // installation and config only) — ignoreRoutes() here means
        // there is currently NO working /paddle/webhook endpoint at all
        // until that controller is built and wired in its place, rather
        // than a live-but-broken one.
        Cashier::ignoreRoutes();

        // useCustomerModel()/useSubscriptionModel() intentionally not
        // called here yet — no App\Models\Subscription/Customer exist
        // (next step, alongside the webhook controller and the Billable
        // trait on whichever model becomes billable). Cashier's own
        // default Laravel\Paddle\Customer/Subscription models work
        // against the customers/subscriptions tables as published; they
        // just don't have BelongsToTenant on them yet.
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
