<?php

use App\Http\Controllers\Api\V1\Admin\AdminTenantController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ContactController;
use App\Http\Controllers\Api\V1\GbpController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\ReviewController;
use App\Http\Controllers\Api\V1\StripeWebhookController;
use App\Http\Controllers\Api\V1\SubscriptionController;
use App\Http\Controllers\Api\V1\TemplateController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('/health', HealthController::class);

    // Rate-limited per .claude/SECURITY.md #3 ("Rate-limit login ... and
    // all public endpoints"); throttle:5,1 is the exact figure specified
    // for login, reused for register since no different figure was given.
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:5,1');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');

    Route::post('/logout', [AuthController::class, 'logout'])->middleware('tenant');

    Route::post('/subscribe', [SubscriptionController::class, 'subscribe'])->middleware('tenant');
    Route::get('/subscription', [SubscriptionController::class, 'show'])->middleware('tenant');

    Route::prefix('contacts')->middleware('tenant')->group(function () {
        Route::get('/', [ContactController::class, 'index']);
        Route::post('/import/preview', [ContactController::class, 'previewImport']);
        Route::post('/import', [ContactController::class, 'import']);
    });

    Route::get('/gbp/connect', [GbpController::class, 'connect'])->middleware('tenant');
    // Public — API.md's other documented exception alongside stripe/webhook.
    // Google's redirect back here carries no Sanctum bearer token; tenant
    // identity comes from the signed state parameter instead
    // (App\Services\Gbp\GbpOAuthState), never from request input directly.
    Route::get('/gbp/callback', [GbpController::class, 'callback']);

    Route::prefix('templates')->middleware('tenant')->group(function () {
        Route::get('/', [TemplateController::class, 'index']);
        Route::post('/check', [TemplateController::class, 'check']);
        Route::put('/{step}', [TemplateController::class, 'save'])->where('step', '[0-9]+');
    });

    Route::prefix('reviews')->middleware('tenant')->group(function () {
        Route::get('/', [ReviewController::class, 'index']);
        Route::post('/{review}/reply', [ReviewController::class, 'reply']);
    });

    // No Sanctum auth (API.md's one documented exception) — authenticated
    // instead by Stripe's own signature, verified unconditionally inside
    // StripeWebhookController itself, not by route middleware here.
    Route::post('/stripe/webhook', [StripeWebhookController::class, 'handleWebhook']);

    // UUID constraint on {tenant}: a malformed ID must never reach the
    // controller's ::uuid-casting RLS query (an invalid cast throws a raw
    // QueryException, not a clean 404 — same class of bug as the
    // pre-fix register() duplicate-email path). A route that doesn't match
    // falls through to the normal 404 JSON handler instead.
    Route::prefix('admin')->middleware('tenant')->group(function () {
        Route::get('/tenants', [AdminTenantController::class, 'index']);
        Route::get('/tenants/{tenant}', [AdminTenantController::class, 'show'])
            ->where('tenant', '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}');
    });
});
