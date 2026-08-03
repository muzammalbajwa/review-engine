<?php

use App\Models\Template;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * .claude/COMPLIANCE.md: the compliance checker + "ship compliant
 * defaults". .claude/CLAUDE.md golden rule #4: "No message template can be
 * saved without passing the compliance checker." No real Anthropic
 * credentials exist — Claude is faked via Http::fake(); what's real here is
 * the auto-provisioning of compliant defaults, the block-blocks-the-write
 * behavior, the friendly-fix surfacing, and tenant isolation.
 */
function registerAndGetTokenForTemplates(string $label): array
{
    $response = test()->postJson('/api/v1/register', [
        'name' => "{$label} Owner",
        'business_name' => "{$label} Co",
        'email' => strtolower(str_replace(' ', '', $label)).'-'.uniqid().'@example.com',
        'password' => 'correct-horse-battery-staple',
        'password_confirmation' => 'correct-horse-battery-staple',
    ])->assertCreated();

    return [$response->json('data.token'), $response->json('data.tenant.id')];
}

function fakeComplianceResponse(array $body): void
{
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => json_encode($body)]],
        ], 200),
    ]);
}

function templatesForTenant(string $tenantId): Collection
{
    return DB::transaction(function () use ($tenantId) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        return Template::withoutGlobalScopes()->where('tenant_id', $tenantId)->get();
    });
}

test('GET /templates requires authentication', function () {
    $this->getJson('/api/v1/templates')->assertUnauthorized();
});

test('GET /templates auto-provisions three compliant default templates', function () {
    [$token] = registerAndGetTokenForTemplates('Defaults');

    $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/templates');

    $response->assertOk();
    $steps = collect($response->json('data'))->pluck('step')->sort()->values();

    expect($steps->all())->toBe([1, 2, 3]);
    expect(collect($response->json('data'))->every(fn ($t) => $t['compliance_status'] === 'pass'))->toBeTrue();
});

test('checking a compliant body passes and persists nothing', function () {
    [$token, $tenantId] = registerAndGetTokenForTemplates('Check Pass');

    fakeComplianceResponse(['status' => 'pass', 'reasons' => [], 'suggested_rewrite' => null]);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/templates/check', ['body' => 'Thanks for choosing us! Please leave a review: {review_link}']);

    $response->assertOk();
    expect($response->json('data.status'))->toBe('pass');
    expect(templatesForTenant($tenantId))->toHaveCount(0);
});

test('checking a non-compliant body reports block with reasons and a suggested rewrite, and persists nothing', function () {
    [$token, $tenantId] = registerAndGetTokenForTemplates('Check Block');

    fakeComplianceResponse([
        'status' => 'block',
        'reasons' => ['Offers a discount in exchange for a review'],
        'suggested_rewrite' => 'Please leave us a review: {review_link}',
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/templates/check', ['body' => 'Leave a review and get 10% off your next visit!']);

    $response->assertOk();
    expect($response->json('data.status'))->toBe('block');
    expect($response->json('data.reasons'))->toBe(['Offers a discount in exchange for a review']);
    expect($response->json('data.suggested_rewrite'))->toBe('Please leave us a review: {review_link}');
    expect(templatesForTenant($tenantId))->toHaveCount(0);
});

test('saving a compliant body persists it as the live template for that step', function () {
    [$token, $tenantId] = registerAndGetTokenForTemplates('Save Pass');

    fakeComplianceResponse(['status' => 'pass', 'reasons' => [], 'suggested_rewrite' => null]);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->putJson('/api/v1/templates/1', ['body' => 'Thanks for choosing us! Please leave a review: {review_link}']);

    $response->assertOk();
    expect($response->json('data.compliance_status'))->toBe('pass');

    $templates = templatesForTenant($tenantId);
    expect($templates)->toHaveCount(1);
    expect($templates->first()->body)->toBe('Thanks for choosing us! Please leave a review: {review_link}');
});

test('saving a non-compliant body is rejected with 422, surfaces the reason, and is never persisted', function () {
    [$token, $tenantId] = registerAndGetTokenForTemplates('Save Block');

    fakeComplianceResponse([
        'status' => 'block',
        'reasons' => ['Asks for a specific star rating'],
        'suggested_rewrite' => 'Please leave us a review: {review_link}',
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->putJson('/api/v1/templates/1', ['body' => 'Please give us 5 stars!']);

    $response->assertStatus(422);
    expect($response->json('error'))->toBe('compliance_block');
    expect($response->json('data.reasons'))->toBe(['Asks for a specific star rating']);
    expect($response->json('data.suggested_rewrite'))->toBe('Please leave us a review: {review_link}');

    // Never persisted — not even as a blocked row (golden rule #4: a
    // template that fails the checker was never "saved" at all).
    expect(templatesForTenant($tenantId))->toHaveCount(0);
});

test('a blocked save does not overwrite an already-passing live template', function () {
    [$token, $tenantId] = registerAndGetTokenForTemplates('Save Block Preserve');

    // Http::fake() is additive within a test — a second fakeComplianceResponse()
    // call would NOT override this one (the first registered stub still
    // matches first), so a genuine two-different-responses case needs a
    // sequence, not two separate fake() calls.
    Http::fake([
        'api.anthropic.com/*' => Http::sequence()
            ->push(['content' => [['type' => 'text', 'text' => json_encode(['status' => 'pass', 'reasons' => [], 'suggested_rewrite' => null])]]], 200)
            ->push(['content' => [['type' => 'text', 'text' => json_encode(['status' => 'block', 'reasons' => ['Offers an incentive'], 'suggested_rewrite' => null])]]], 200),
    ]);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->putJson('/api/v1/templates/1', ['body' => 'A perfectly compliant message: {review_link}'])
        ->assertOk();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->putJson('/api/v1/templates/1', ['body' => 'Leave a review for a free gift!'])
        ->assertStatus(422);

    $templates = templatesForTenant($tenantId);
    expect($templates)->toHaveCount(1);
    expect($templates->first()->body)->toBe('A perfectly compliant message: {review_link}');
    expect($templates->first()->compliance_status)->toBe('pass');
});

test('saving an unknown step returns a clean 404', function () {
    [$token] = registerAndGetTokenForTemplates('Unknown Step');

    fakeComplianceResponse(['status' => 'pass', 'reasons' => [], 'suggested_rewrite' => null]);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->putJson('/api/v1/templates/9', ['body' => 'Anything']);

    $response->assertNotFound();
});

test('an unparseable compliance response surfaces as ai_unavailable, not a crash', function () {
    [$token] = registerAndGetTokenForTemplates('Unparseable');

    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => 'Sure, here is my opinion: looks fine!']],
        ], 200),
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/templates/check', ['body' => 'Anything']);

    $response->assertStatus(502);
    expect($response->json('error'))->toBe('ai_unavailable');
});

test('a tenant only ever sees and saves its own templates', function () {
    [$tokenA, $tenantAId] = registerAndGetTokenForTemplates('Isolation A');
    [$tokenB, $tenantBId] = registerAndGetTokenForTemplates('Isolation B');

    fakeComplianceResponse(['status' => 'pass', 'reasons' => [], 'suggested_rewrite' => null]);
    $this->withHeader('Authorization', "Bearer {$tokenA}")
        ->putJson('/api/v1/templates/1', ['body' => 'Tenant A message: {review_link}'])
        ->assertOk();

    $this->withHeader('Authorization', "Bearer {$tokenB}")->getJson('/api/v1/templates')->assertOk();

    $tenantATemplates = templatesForTenant($tenantAId);
    $tenantBTemplates = templatesForTenant($tenantBId);

    expect($tenantATemplates->firstWhere('step', 1)->body)->toBe('Tenant A message: {review_link}');
    expect($tenantBTemplates->firstWhere('step', 1)->body)->not->toBe('Tenant A message: {review_link}');
});
