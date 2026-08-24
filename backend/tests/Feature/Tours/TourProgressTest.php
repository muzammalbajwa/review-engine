<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Product-tour progress (2026_08_10_120000_add_tour_progress_to_users_table).
 * Server-side, per-user — not localStorage, not per-tenant. The
 * "multiple team members" scenario (two users sharing one tenant, each
 * with independent tour state) needs a second user attached to an
 * *existing* tenant, which no public endpoint creates yet — built
 * directly, same pattern as QuickAddTest's seedCustomerAccountDirect.
 */
function addTeammateToTenant(string $tenantId, string $label): array
{
    $email = strtolower(str_replace(' ', '', $label)).'-'.uniqid().'@example.com';

    $token = DB::transaction(function () use ($tenantId, $label, $email) {
        DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

        $user = new User([
            'name' => $label,
            'email' => $email,
            'password' => Hash::make('correct-horse-battery-staple'),
        ]);
        $user->tenant_id = $tenantId;
        $user->role = 'member';
        $user->save();

        return $user->createToken('test')->plainTextToken;
    });

    return [$token];
}

test('tour status requires authentication', function () {
    $this->getJson('/api/v1/tours/status')->assertUnauthorized();
});

test('completing the welcome tour requires authentication', function () {
    $this->postJson('/api/v1/tours/welcome/complete')->assertUnauthorized();
});

test('completing a screen tour requires authentication', function () {
    $this->postJson('/api/v1/tours/screens/contacts_import/complete')->assertUnauthorized();
});

test('a freshly registered user has not completed the welcome tour and has no screen tours seen', function () {
    [$token] = seedCustomerAccount('Tour Fresh');

    $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/tours/status');

    $response->assertOk();
    expect($response->json('data.has_completed_welcome_tour'))->toBeFalse();
    expect($response->json('data.tours_seen'))->toBe([]);
});

test('completing the welcome tour persists and is reflected on the next status check', function () {
    [$token] = seedCustomerAccount('Tour Welcome Complete');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/tours/welcome/complete')
        ->assertOk()
        ->assertJsonPath('data.has_completed_welcome_tour', true);

    $status = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/tours/status');
    expect($status->json('data.has_completed_welcome_tour'))->toBeTrue();
});

test('completing a screen tour persists it in the tours_seen map without disturbing other keys', function () {
    [$token] = seedCustomerAccount('Tour Screen Complete');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/tours/screens/contacts_import/complete')
        ->assertOk()
        ->assertJsonPath('data.tours_seen.contacts_import', true);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/tours/screens/templates_editor/complete')
        ->assertOk();

    $status = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/tours/status');
    expect($status->json('data.tours_seen'))->toBe([
        'contacts_import' => true,
        'templates_editor' => true,
    ]);
    // The welcome tour is a separate field entirely — completing screen
    // tours never flips it.
    expect($status->json('data.has_completed_welcome_tour'))->toBeFalse();
});

test('completing the same screen tour twice is a harmless no-op', function () {
    [$token] = seedCustomerAccount('Tour Screen Idempotent');

    $auth = fn () => $this->withHeader('Authorization', "Bearer {$token}");

    $auth()->postJson('/api/v1/tours/screens/contacts_import/complete')->assertOk();
    $auth()->postJson('/api/v1/tours/screens/contacts_import/complete')->assertOk();

    $status = $auth()->getJson('/api/v1/tours/status');
    expect($status->json('data.tours_seen'))->toBe(['contacts_import' => true]);
});

test('a malformed screen tour key is rejected by the route constraint, never reaching the controller', function () {
    [$token] = seedCustomerAccount('Tour Screen Bad Key');

    $auth = fn () => $this->withHeader('Authorization', "Bearer {$token}");

    // Uppercase and spaces are outside [a-z0-9_-]{1,40} — 404, the same
    // clean not_found envelope every other route-constraint mismatch in
    // this app produces (e.g. the admin {tenant} UUID constraint), not a
    // 422 or a 500.
    $upper = $auth()->postJson('/api/v1/tours/screens/ContactsImport/complete');
    $upper->assertNotFound();
    expect($upper->json('error'))->toBe('not_found');

    $tooLong = $auth()->postJson('/api/v1/tours/screens/'.str_repeat('a', 41).'/complete');
    $tooLong->assertNotFound();
});

test('CRITICAL: tour progress is tracked per-user, not per-tenant — two teammates on the same tenant have independent state', function () {
    [$ownerToken, $tenantId] = seedCustomerAccount('Tour Teammates');
    [$teammateToken] = addTeammateToTenant($tenantId, 'Teammate');

    $this->withHeader('Authorization', "Bearer {$ownerToken}")
        ->postJson('/api/v1/tours/welcome/complete')
        ->assertOk();
    $this->withHeader('Authorization', "Bearer {$ownerToken}")
        ->postJson('/api/v1/tours/screens/contacts_import/complete')
        ->assertOk();

    $ownerStatus = $this->withHeader('Authorization', "Bearer {$ownerToken}")->getJson('/api/v1/tours/status');
    $teammateStatus = $this->withHeader('Authorization', "Bearer {$teammateToken}")->getJson('/api/v1/tours/status');

    expect($ownerStatus->json('data.has_completed_welcome_tour'))->toBeTrue();
    expect($ownerStatus->json('data.tours_seen'))->toBe(['contacts_import' => true]);

    // The owner completing tours never touches the teammate's own,
    // separate row on the same tenant.
    expect($teammateStatus->json('data.has_completed_welcome_tour'))->toBeFalse();
    expect($teammateStatus->json('data.tours_seen'))->toBe([]);
});

test('CRITICAL: replaying a tour (completing it again after it was already seen) never re-triggers or resets it for a teammate on the same tenant', function () {
    // The exact scenario "Take the tour again" / a screen's "?" icon
    // produces: a tour that's already marked seen gets marked seen
    // again. Both users complete everything once first (their own
    // independent, real completion — not the replay under test), then
    // only the owner replays. If replaying ever touched anything beyond
    // the caller's own row, the teammate's welcome tour would flip back
    // to needing a re-trigger, or contacts_import's on/off state would
    // bleed across users; it does neither.
    [$ownerToken, $tenantId] = seedCustomerAccount('Tour Replay Teammates');
    [$teammateToken] = addTeammateToTenant($tenantId, 'Replay Teammate');

    $owner = fn () => $this->withHeader('Authorization', "Bearer {$ownerToken}");
    $teammate = fn () => $this->withHeader('Authorization', "Bearer {$teammateToken}");

    // Both users genuinely complete the welcome tour and one screen tour
    // once — the teammate deliberately does NOT complete templates_editor,
    // so its false value has to survive the owner's later replay untouched.
    $owner()->postJson('/api/v1/tours/welcome/complete')->assertOk();
    $owner()->postJson('/api/v1/tours/screens/contacts_import/complete')->assertOk();
    $teammate()->postJson('/api/v1/tours/welcome/complete')->assertOk();
    $teammate()->postJson('/api/v1/tours/screens/contacts_import/complete')->assertOk();

    // The owner now replays both — the real request shape "Take the tour
    // again" / a screen's "?" icon produces once the tour finishes again.
    $owner()->postJson('/api/v1/tours/welcome/complete')->assertOk();
    $owner()->postJson('/api/v1/tours/screens/contacts_import/complete')->assertOk();

    $ownerStatus = $owner()->getJson('/api/v1/tours/status');
    $teammateStatus = $teammate()->getJson('/api/v1/tours/status');

    // The owner's own state: unchanged by the replay (still exactly what
    // it was before — replaying is a no-op write, not a reset).
    expect($ownerStatus->json('data.has_completed_welcome_tour'))->toBeTrue();
    expect($ownerStatus->json('data.tours_seen'))->toBe(['contacts_import' => true]);

    // The teammate's own state: untouched by the owner's replay. Still
    // true from their own real completion — not flipped back to false,
    // and not re-triggered — and templates_editor, which the teammate
    // never touched at all, is still simply absent from their map.
    expect($teammateStatus->json('data.has_completed_welcome_tour'))->toBeTrue();
    expect($teammateStatus->json('data.tours_seen'))->toBe(['contacts_import' => true]);
    expect($teammateStatus->json('data.tours_seen.templates_editor'))->toBeNull();
});

test('tour progress never leaks across tenants', function () {
    [$tokenA] = seedCustomerAccount('Tour Isolation A');
    [$tokenB] = seedCustomerAccount('Tour Isolation B');

    $this->withHeader('Authorization', "Bearer {$tokenA}")
        ->postJson('/api/v1/tours/welcome/complete')
        ->assertOk();

    $statusB = $this->withHeader('Authorization', "Bearer {$tokenB}")->getJson('/api/v1/tours/status');
    expect($statusB->json('data.has_completed_welcome_tour'))->toBeFalse();
});
