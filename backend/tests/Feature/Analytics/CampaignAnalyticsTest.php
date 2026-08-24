<?php

use App\Models\Campaign;
use App\Models\Contact;
use App\Models\Message;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\DB;

/**
 * .claude/FRONTEND.md screen 3: campaign dashboard. .claude/CLAUDE.md
 * golden rule #2: tenant_id enforced by the global scope AND RLS, always
 * — including in an aggregate, not just row-level reads.
 */
function registerAndGetTokenForAnalytics(string $label): array
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

/**
 * @param  list<array{step: int, sent: bool, clicked: bool, reviewed: bool}>  $messages
 */
function seedMessagesForTenant(string $tenantId, array $messages): void
{
    DB::transaction(function () use ($tenantId, $messages) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        $campaign = new Campaign(['type' => 'live', 'status' => 'active']);
        $campaign->tenant_id = $tenantId;
        $campaign->save();

        foreach ($messages as $spec) {
            $contact = new Contact([
                'campaign_id' => $campaign->id,
                'name' => 'Contact '.uniqid(),
                'email' => uniqid().'@example.com',
                'status' => 'pending',
            ]);
            $contact->tenant_id = $tenantId;
            $contact->save();

            $message = new Message([
                'contact_id' => $contact->id,
                'step' => $spec['step'],
                'status' => $spec['sent'] ? 'sent' : 'pending',
                'sent_at' => $spec['sent'] ? now() : null,
                'clicked_at' => $spec['clicked'] ? now() : null,
                'reviewed_at' => $spec['reviewed'] ? now() : null,
            ]);
            $message->tenant_id = $tenantId;
            $message->save();
        }
    });
}

test('campaign analytics requires authentication', function () {
    $this->getJson('/api/v1/analytics/campaign')->assertUnauthorized();
});

test('the aggregation returns correct totals, per-step breakdown, and rates', function () {
    [$token, $tenantId] = registerAndGetTokenForAnalytics('Analytics Basic');

    seedMessagesForTenant($tenantId, [
        ['step' => 1, 'sent' => true, 'clicked' => false, 'reviewed' => false],
        ['step' => 1, 'sent' => true, 'clicked' => false, 'reviewed' => false],
        ['step' => 2, 'sent' => true, 'clicked' => true, 'reviewed' => false],
        ['step' => 2, 'sent' => true, 'clicked' => true, 'reviewed' => true],
        ['step' => 3, 'sent' => true, 'clicked' => false, 'reviewed' => false],
        ['step' => 3, 'sent' => false, 'clicked' => false, 'reviewed' => false], // not yet sent — excluded from sends
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/analytics/campaign');

    $response->assertOk();
    expect($response->json('data.sends.total'))->toBe(5);
    expect($response->json('data.sends.by_step'))->toBe(['1' => 2, '2' => 2, '3' => 1]);
    expect($response->json('data.clicks.total'))->toBe(2);
    expect($response->json('data.clicks.rate'))->toBe(0.4);
    expect($response->json('data.reviews_landed.total'))->toBe(1);
    expect($response->json('data.reviews_landed.rate'))->toBe(0.2);
});

test('an empty campaign reports zero counts and zero rates without a division error', function () {
    [$token] = registerAndGetTokenForAnalytics('Analytics Empty');

    $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/analytics/campaign');

    $response->assertOk();
    expect($response->json('data.sends.total'))->toBe(0);
    expect($response->json('data.clicks.rate'))->toBe(0);
    expect($response->json('data.reviews_landed.rate'))->toBe(0);
});

test('a tenant\'s aggregate counts never include another tenant\'s messages', function () {
    [$tokenA, $tenantAId] = registerAndGetTokenForAnalytics('Analytics Isolation A');
    [$tokenB, $tenantBId] = registerAndGetTokenForAnalytics('Analytics Isolation B');

    seedMessagesForTenant($tenantAId, [
        ['step' => 1, 'sent' => true, 'clicked' => true, 'reviewed' => true],
    ]);
    // Tenant B has substantially more activity — if isolation ever broke,
    // tenant A's numbers would visibly balloon to include these.
    seedMessagesForTenant($tenantBId, [
        ['step' => 1, 'sent' => true, 'clicked' => true, 'reviewed' => true],
        ['step' => 1, 'sent' => true, 'clicked' => true, 'reviewed' => true],
        ['step' => 2, 'sent' => true, 'clicked' => true, 'reviewed' => true],
        ['step' => 2, 'sent' => true, 'clicked' => true, 'reviewed' => true],
        ['step' => 3, 'sent' => true, 'clicked' => true, 'reviewed' => true],
    ]);

    $responseA = $this->withHeader('Authorization', "Bearer {$tokenA}")->getJson('/api/v1/analytics/campaign');
    $responseA->assertOk();
    expect($responseA->json('data.sends.total'))->toBe(1);
    expect($responseA->json('data.clicks.total'))->toBe(1);
    expect($responseA->json('data.reviews_landed.total'))->toBe(1);

    $responseB = $this->withHeader('Authorization', "Bearer {$tokenB}")->getJson('/api/v1/analytics/campaign');
    $responseB->assertOk();
    expect($responseB->json('data.sends.total'))->toBe(5);
});

test('RLS alone (app-layer scope bypassed) still constrains the aggregate to one tenant', function () {
    [, $tenantAId] = registerAndGetTokenForAnalytics('Analytics RLS A');
    [, $tenantBId] = registerAndGetTokenForAnalytics('Analytics RLS B');

    seedMessagesForTenant($tenantAId, [
        ['step' => 1, 'sent' => true, 'clicked' => false, 'reviewed' => false],
    ]);
    seedMessagesForTenant($tenantBId, [
        ['step' => 1, 'sent' => true, 'clicked' => false, 'reviewed' => false],
        ['step' => 1, 'sent' => true, 'clicked' => false, 'reviewed' => false],
        ['step' => 1, 'sent' => true, 'clicked' => false, 'reviewed' => false],
    ]);

    DB::transaction(function () use ($tenantAId) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantAId]);
        app(CurrentTenant::class)->set($tenantAId);

        // withoutGlobalScopes() removes ONLY the Eloquent-layer scope
        // (Layer A) — this is deliberately simulating "the app scope was
        // forgotten" to prove Postgres RLS (Layer B) alone still
        // constrains what COUNT(*) FILTER can even see, the same
        // dual-layer proof every other tenant table in this suite has.
        $row = Message::withoutGlobalScopes()
            ->selectRaw('COUNT(*) FILTER (WHERE sent_at IS NOT NULL) AS sends_total')
            ->first();

        expect((int) $row->sends_total)->toBe(1);

        app(CurrentTenant::class)->clear();
    });
});
