<?php

use App\Http\Middleware\ResolveMessageClickTenant;
use App\Models\Campaign;
use App\Models\Contact;
use App\Models\GbpConnection;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * .claude/ROADMAP.md Phase 2: "click tracking" — GET /api/v1/click/{token},
 * embedded in the review-request link itself (SendReviewRequest). No auth,
 * so the same discipline as the quick-add public route: high-entropy
 * unguessable token, format-validated before ever touching the database,
 * rate-limited, 404 on anything invalid (never reveals a "close" guess).
 */
function seedMessageWithClickToken(string $label, array $overrides = []): array
{
    return DB::transaction(function () use ($label, $overrides) {
        $tenantId = (string) Str::uuid();
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        $tenant = new Tenant(['name' => "{$label} Co", 'type' => 'customer']);
        $tenant->id = $tenantId;
        $tenant->save();

        $user = new User([
            'name' => "{$label} Owner",
            'email' => Str::lower(Str::slug($label)).'-'.Str::random(6).'@example.com',
            'password' => Hash::make('correct-horse-battery-staple'),
        ]);
        $user->tenant_id = $tenantId;
        $user->role = 'owner';
        $user->save();

        $campaign = new Campaign(['type' => 'live', 'status' => 'active']);
        $campaign->tenant_id = $tenantId;
        $campaign->save();

        $contact = new Contact([
            'campaign_id' => $campaign->id,
            'name' => "{$label} Customer",
            'email' => Str::lower(Str::slug($label)).'-customer@example.com',
            'status' => 'sent',
        ]);
        $contact->tenant_id = $tenantId;
        $contact->consent_at = now();
        $contact->source = 'quick_add';
        $contact->save();

        $message = new Message(array_merge([
            'contact_id' => $contact->id,
            'step' => 1,
            'status' => 'sent',
            'sent_at' => now(),
            'click_token' => Str::random(48),
        ], $overrides));
        $message->tenant_id = $tenantId;
        $message->save();

        return [$tenant, $contact, $message];
    });
}

function makeConnectedGbpConnectionForClick(string $tenantId, string $reviewLink): void
{
    DB::transaction(function () use ($tenantId, $reviewLink) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        $connection = new GbpConnection([
            'oauth_token' => 'fake-oauth-token',
            'refresh_token' => 'fake-refresh-token',
            'token_expires_at' => now()->addHour(),
            'location_id' => 'locations/click-test',
            'review_link' => $reviewLink,
            'status' => 'connected',
        ]);
        $connection->tenant_id = $tenantId;
        $connection->save();
    });
}

test('a valid click_token resolves, sets clicked_at, and redirects to the real review link', function () {
    [$tenant, , $message] = seedMessageWithClickToken('Click Basic');
    makeConnectedGbpConnectionForClick($tenant->id, 'https://g.page/r/click-basic/review');

    $response = $this->get("/api/v1/click/{$message->click_token}");

    $response->assertRedirect('https://g.page/r/click-basic/review');

    $updated = DB::transaction(function () use ($tenant, $message) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenant->id]);

        return Message::find($message->id);
    });
    expect($updated->clicked_at)->not->toBeNull();
});

test('a repeat click does not overwrite the original clicked_at timestamp', function () {
    [$tenant, , $message] = seedMessageWithClickToken('Click Repeat');
    makeConnectedGbpConnectionForClick($tenant->id, 'https://g.page/r/click-repeat/review');

    $this->get("/api/v1/click/{$message->click_token}")->assertRedirect();

    $firstClickedAt = DB::transaction(function () use ($tenant, $message) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenant->id]);

        return Message::find($message->id)->clicked_at;
    });
    expect($firstClickedAt)->not->toBeNull();

    $this->travel(5)->minutes();

    $this->get("/api/v1/click/{$message->click_token}")->assertRedirect();

    $secondClickedAt = DB::transaction(function () use ($tenant, $message) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenant->id]);

        return Message::find($message->id)->clicked_at;
    });

    expect($secondClickedAt->equalTo($firstClickedAt))->toBeTrue();
});

test('a malformed token is rejected with 404 before ever reaching the database', function () {
    // Too short, wrong charset, path-traversal-ish, SQLi-ish — all must
    // die at the route/middleware's own format check, never reach a query.
    foreach (['short', 'has spaces in it 1234567890123456789012345678', '../../etc/passwd', "'; DROP TABLE messages; --"] as $badToken) {
        $this->get('/api/v1/click/'.rawurlencode($badToken))->assertNotFound();
    }
});

test('a well-formed but unknown token is rejected with 404, never a different error', function () {
    $this->get('/api/v1/click/'.Str::random(48))->assertNotFound();
});

test('a click on a message whose tenant no longer has a connected GBP link gets a clean 410, not a broken redirect', function () {
    [, , $message] = seedMessageWithClickToken('Click No Connection');
    // Deliberately no makeConnectedGbpConnectionForClick() call.

    $response = $this->get("/api/v1/click/{$message->click_token}");

    $response->assertStatus(410);
    expect($response->json('error'))->toBe('review_link_unavailable');
});

test('the click route requires no authentication at all', function () {
    [$tenant, , $message] = seedMessageWithClickToken('Click No Auth');
    makeConnectedGbpConnectionForClick($tenant->id, 'https://g.page/r/click-no-auth/review');

    // No Authorization header anywhere in this request.
    $response = $this->get("/api/v1/click/{$message->click_token}");

    $response->assertRedirect('https://g.page/r/click-no-auth/review');
});

test('two tenants clicking their own links never cross-contaminate clicked_at', function () {
    [$tenantA, , $messageA] = seedMessageWithClickToken('Click Tenant A');
    [$tenantB, , $messageB] = seedMessageWithClickToken('Click Tenant B');
    makeConnectedGbpConnectionForClick($tenantA->id, 'https://g.page/r/tenant-a/review');
    makeConnectedGbpConnectionForClick($tenantB->id, 'https://g.page/r/tenant-b/review');

    $this->get("/api/v1/click/{$messageA->click_token}")->assertRedirect('https://g.page/r/tenant-a/review');

    $messageBAfter = DB::transaction(function () use ($tenantB, $messageB) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantB->id]);

        return Message::find($messageB->id);
    });
    expect($messageBAfter->clicked_at)->toBeNull();
});

/**
 * Same structural pattern already fixed on /quick/{token} and
 * /contacts (bootstrap/app.php's prependToPriorityList() calls) —
 * applied proactively here rather than found later by audit.
 */
test('click tenant resolution runs before its own throttle, matching declared route order', function () {
    $route = collect(app('router')->getRoutes())->first(
        fn ($r) => $r->uri() === 'api/v1/click/{token}' && in_array('GET', $r->methods())
    );

    $ref = new ReflectionMethod(app('router'), 'gatherRouteMiddleware');
    $ref->setAccessible(true);
    $resolved = $ref->invoke(app('router'), $route);

    $tenantIndex = array_search(ResolveMessageClickTenant::class, $resolved);
    $throttleIndex = collect($resolved)->search(fn ($m) => str_starts_with($m, ThrottleRequests::class));

    expect($tenantIndex)->not->toBeFalse();
    expect($throttleIndex)->not->toBeFalse();
    expect($tenantIndex)->toBeLessThan($throttleIndex);
});
