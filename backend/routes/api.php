<?php

use App\Http\Controllers\Api\V1\Admin\AdminAuditLogController;
use App\Http\Controllers\Api\V1\Admin\AdminSystemController;
use App\Http\Controllers\Api\V1\Admin\AdminTenantController;
use App\Http\Controllers\Api\V1\AnalyticsController;
use App\Http\Controllers\Api\V1\ApiKeyController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ContactController;
use App\Http\Controllers\Api\V1\ContactMessageController;
use App\Http\Controllers\Api\V1\GbpController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\MessageClickController;
use App\Http\Controllers\Api\V1\OnboardingController;
use App\Http\Controllers\Api\V1\PaddleWebhookController;
use App\Http\Controllers\Api\V1\QuickAddController;
use App\Http\Controllers\Api\V1\ReviewController;
use App\Http\Controllers\Api\V1\SenderIdentityController;
use App\Http\Controllers\Api\V1\SubscriptionController;
use App\Http\Controllers\Api\V1\TeamController;
use App\Http\Controllers\Api\V1\TeamInviteController;
use App\Http\Controllers\Api\V1\TemplateController;
use App\Http\Controllers\Api\V1\TenantController;
use App\Http\Controllers\Api\V1\TourController;
use App\Http\Controllers\Api\V1\WebhookContactController;
use Illuminate\Support\Facades\Route;
use Laravel\Paddle\Http\Middleware\VerifyWebhookSignature;

Route::prefix('v1')->group(function () {
    Route::get('/health', HealthController::class);

    // Rate-limited per .claude/SECURITY.md #3 ("Rate-limit login ... and
    // all public endpoints"); throttle:5,1 is the exact figure specified
    // for login, reused for register since no different figure was given.
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:5,1');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');

    Route::post('/logout', [AuthController::class, 'logout'])->middleware('tenant');

    // Public, signed — clicked from an email, carries no Sanctum bearer
    // token (same class of exception as /gbp/callback,
    // sender-identities.verify). throttle:6,1 matches Laravel's own
    // default verification-route rate limit.
    Route::get('/email/verify/{id}/{hash}', [AuthController::class, 'verifyEmail'])
        ->name('verification.verify')
        ->middleware(['signed', 'throttle:6,1'])
        ->where('id', '[0-9]+');

    Route::post('/email/verification-notification', [AuthController::class, 'resendVerificationEmail'])
        ->middleware(['tenant', 'throttle:6,1']);

    // Billing is owner-only — never grantable to a member under any
    // permission combination (see EnsureTenantOwner's own docblock).
    // GET /subscription/portal (Paddle's customer portal) isn't rebuilt
    // yet.
    Route::post('/subscribe', [SubscriptionController::class, 'subscribe'])->middleware(['tenant', 'owner']);
    Route::get('/subscription', [SubscriptionController::class, 'show'])->middleware(['tenant', 'owner']);
    Route::patch('/subscription', [SubscriptionController::class, 'update'])->middleware(['tenant', 'owner']);

    Route::get('/tenant', [TenantController::class, 'show'])->middleware('tenant');
    Route::patch('/tenant', [TenantController::class, 'update'])->middleware('tenant');

    // Team management (owner/member two-tier structure — separate from
    // the cross-tenant admin system). Owner-only, same reasoning as
    // billing above.
    Route::prefix('team')->middleware(['tenant', 'owner'])->group(function () {
        Route::get('/', [TeamController::class, 'index']);
        Route::post('/invite', [TeamController::class, 'invite']);
        Route::delete('/invites/{invite}', [TeamController::class, 'revokeInvite']);
        Route::patch('/members/{member}/permissions', [TeamController::class, 'updatePermissions']);
    });

    // Public — the invited person has no account and no Sanctum bearer
    // token yet (same class of exception as /register). Token-scoped
    // lookup only, never a client-supplied tenant id — see
    // TeamInviteController's own docblock for the RLS bypass this needs.
    Route::get('/team/invite/{token}', [TeamInviteController::class, 'show'])
        ->middleware('throttle:20,1')
        ->where('token', '[A-Za-z0-9]{48}');
    Route::post('/team/invite/{token}/accept', [TeamInviteController::class, 'accept'])
        ->middleware('throttle:5,1')
        ->where('token', '[A-Za-z0-9]{48}');

    // Dashboard-authenticated key management for the webhook API below —
    // not part of the public webhook surface itself. Gated by
    // 'permission:contacts', not owner-only: a webhook API key is
    // fundamentally a contacts-creation capability (its only ability is
    // contacts:create) — without this, a member with no 'contacts'
    // permission could still mint a key and create contacts through
    // POST /api/v1/contacts, working around the dashboard gate entirely.
    Route::get('/api-keys', [ApiKeyController::class, 'show'])->middleware(['tenant', 'permission:contacts']);
    Route::post('/api-keys', [ApiKeyController::class, 'store'])->middleware(['tenant', 'permission:contacts']);

    Route::prefix('contacts')->middleware(['tenant', 'permission:contacts'])->group(function () {
        Route::get('/', [ContactController::class, 'index']);
        Route::post('/import/preview', [ContactController::class, 'previewImport']);
        // 'sending.access': these two create NEW outbound review requests
        // (.claude decision doc: a trial_expired tenant "cannot send new
        // review requests, use quick-add"). Preview above is exempt —
        // it persists nothing, same "let them see it, block the actual
        // send" spirit as everything else this gate touches.
        Route::post('/import', [ContactController::class, 'import'])->middleware('sending.access');
        Route::post('/quick-add', [ContactController::class, 'quickAdd'])->middleware('sending.access');
        Route::get('/webhook-activity', [ContactController::class, 'webhookActivity']);

        // The public webhook API (.claude/CLAUDE.md) — POST /api/v1/contacts.
        // Same 'tenant' middleware (Sanctum resolves tenant + user from any
        // valid bearer token, dashboard session or API key alike) plus two
        // additions specific to this route: 'abilities:contacts:create'
        // rejects a token that isn't scoped for this (a dashboard session's
        // own token has the wildcard '*' ability by default and is NOT
        // rejected here — that's correct, not a scope hole: a session that
        // can already do everything else in the product being able to also
        // hit this narrower endpoint isn't a privilege escalation), and
        // 'throttle:webhook-api' applies the tenant's plan-based limit.
        Route::post('/', [WebhookContactController::class, 'store'])
            ->middleware(['abilities:contacts:create', 'throttle:webhook-api', 'sending.access']);
    });

    // Public marketing contact form (reviewengine.com/contact) — no
    // Sanctum auth, no 'tenant' group: a prospect who hasn't signed up has
    // neither. throttle:contact rate-limits per-ip only (no token concept
    // here); ContactMessageController handles the honeypot field itself.
    Route::post('/contact', [ContactMessageController::class, 'store'])->middleware('throttle:contact');

    // Public quick-add link (reviewengine.com/quick/{token}) — no
    // Sanctum auth, no 'tenant' group (that bundles auth:sanctum).
    // quick-add.tenant resolves and activates tenant context from the
    // token itself; throttle:quick-add rate-limits per-token and per-ip.
    Route::prefix('quick')->middleware(['quick-add.tenant', 'throttle:quick-add'])->group(function () {
        Route::get('/{token}', [QuickAddController::class, 'show']);
        // 'sending.access' after quick-add.tenant, same as the rest of
        // this gate's routes — needs CurrentTenant already resolved.
        Route::post('/{token}', [QuickAddController::class, 'store'])->middleware('sending.access');
    });

    // Public click-tracking redirect (Phase 2 Step 4) — embedded in the
    // review-request email itself, so this carries no Sanctum auth, no
    // 'tenant' group. click.tenant resolves and activates tenant context
    // from the token itself; throttle:click rate-limits per-token and
    // per-ip, same shape as quick-add. Named so SendReviewRequest can
    // build the absolute URL via route('click.redirect', ...) rather than
    // a hand-assembled string. Constrained to exactly Str::random(48)'s
    // own charset/length at the route level too — a second, cheap layer
    // in front of the middleware's own format check, same defense-in-depth
    // spirit as the admin routes' {tenant} UUID constraint.
    Route::get('/click/{token}', [MessageClickController::class, 'redirect'])
        ->name('click.redirect')
        ->middleware(['click.tenant', 'throttle:click'])
        ->where('token', '[A-Za-z0-9]{48}');

    Route::get('/gbp/status', [GbpController::class, 'status'])->middleware('tenant');
    Route::get('/gbp/connect', [GbpController::class, 'connect'])->middleware('tenant');
    // Public — one of API.md's documented no-Sanctum-auth exceptions.
    // Google's redirect back here carries no Sanctum bearer token; tenant
    // identity comes from the signed state parameter instead
    // (App\Services\Gbp\GbpOAuthState), never from request input directly.
    Route::get('/gbp/callback', [GbpController::class, 'callback']);

    Route::prefix('templates')->middleware(['tenant', 'permission:templates'])->group(function () {
        Route::get('/', [TemplateController::class, 'index']);
        Route::post('/check', [TemplateController::class, 'check']);
        Route::put('/{step}', [TemplateController::class, 'save'])->where('step', '[0-9]+');
    });

    Route::prefix('reviews')->middleware(['tenant', 'permission:reviews'])->group(function () {
        Route::get('/', [ReviewController::class, 'index']);
        Route::post('/{review}/reply', [ReviewController::class, 'reply']);
    });

    Route::get('/analytics/campaign', [AnalyticsController::class, 'campaign'])
        ->middleware(['tenant', 'permission:analytics']);

    Route::prefix('onboarding')->middleware('tenant')->group(function () {
        Route::get('/status', [OnboardingController::class, 'status']);
        Route::post('/start-trial', [OnboardingController::class, 'startTrial']);
        Route::post('/gbp-step-done', [OnboardingController::class, 'markGbpStepDone']);
        Route::post('/contacts-step-done', [OnboardingController::class, 'markContactsStepDone']);
        Route::post('/complete', [OnboardingController::class, 'complete']);
    });

    // Product-tour progress (.claude/DATABASE.md's users table row) —
    // per-user, not per-tenant. {key} is constrained at the route level
    // (defense in depth, same convention as the admin routes' UUID
    // constraint below): lowercase alphanumeric/underscore/hyphen, capped
    // at 40 chars, no per-screen tour keys enumerated yet since no
    // per-screen tour content exists yet.
    Route::prefix('tours')->middleware('tenant')->group(function () {
        Route::get('/status', [TourController::class, 'status']);
        Route::post('/welcome/complete', [TourController::class, 'completeWelcome']);
        Route::post('/screens/{key}/complete', [TourController::class, 'completeScreen'])
            ->where('key', '[a-z0-9_-]{1,40}');
    });

    Route::prefix('sender-identities')->middleware('tenant')->group(function () {
        Route::get('/', [SenderIdentityController::class, 'index']);
        Route::post('/', [SenderIdentityController::class, 'store']);
    });

    // Public, signed — clicked from an email, carries no Sanctum bearer
    // token (same class of exception as /gbp/callback).
    // The signature covers the {tenant} parameter itself, so tampering
    // with it invalidates the link outright (verified by the `signed`
    // middleware before this route's controller ever runs).
    Route::get('/sender-identities/{tenant}/{sender}/verify', [SenderIdentityController::class, 'verify'])
        ->name('sender-identities.verify')
        ->middleware('signed')
        ->where('tenant', '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}')
        ->where('sender', '[0-9]+');

    // No Sanctum auth (API.md's one documented exception) — authenticated
    // instead by Paddle's own signature, applied unconditionally here
    // (not conditionally inside Cashier's own WebhookController
    // constructor, which only attaches VerifyWebhookSignature when
    // cashier.webhook_secret happens to be truthy — a missing/
    // misconfigured PADDLE_WEBHOOK_SECRET would otherwise silently accept
    // any payload unverified, same "fail closed, not open" reasoning
    // every processor's webhook route has used here). The only entry
    // point Paddle is ever told to call — see AppServiceProvider's
    // Cashier::ignoreRoutes() and PaddleWebhookController's own docblock
    // for why the package's auto-registered POST /paddle/webhook is
    // disabled.
    Route::post('/paddle/webhook', PaddleWebhookController::class)
        ->middleware(VerifyWebhookSignature::class);

    // UUID constraint on {tenant}: a malformed ID must never reach the
    // controller's ::uuid-casting RLS query (an invalid cast throws a raw
    // QueryException, not a clean 404 — same class of bug as the
    // pre-fix register() duplicate-email path). A route that doesn't match
    // falls through to the normal 404 JSON handler instead.
    Route::prefix('admin')->middleware('tenant')->group(function () {
        Route::get('/tenants', [AdminTenantController::class, 'index']);
        Route::get('/tenants/{tenant}', [AdminTenantController::class, 'show'])
            ->where('tenant', '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}');
        Route::get('/audit-log', [AdminAuditLogController::class, 'index']);
        Route::get('/system', [AdminSystemController::class, 'status']);
        // GET /billing-breakdown removed with AdminBillingController —
        // depended on the Lemon Squeezy-backed Subscription model.
        // Pending a Paddle-backed rebuild.
    });
});
