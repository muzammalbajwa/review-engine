<?php

namespace Database\Seeders;

use App\Models\Campaign;
use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Templates\TemplateProvisioner;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Three ready-to-use demo accounts (super admin, tenant owner, limited
 * team member) for local/staging manual QA of Prompts 1-3's work — the
 * Lemon Squeezy billing/pricing change, the owner/member permissions
 * system, and the admin billing breakdown. Not wired into
 * DatabaseSeeder::run()'s default chain — only ever runs via an explicit
 * `php artisan db:seed --class=DemoAccountsSeeder`, so a bare `db:seed`
 * (or `migrate --seed`) never creates these by surprise.
 *
 * Deliberately NOT run from app/Console/Commands — "database seeder" per
 * the brief means a Seeder class, invoked the standard Laravel way.
 *
 * Idempotent, not additive: re-running this deletes and recreates its own
 * tenants (matched by the exact fake emails below, never anything else),
 * so repeated QA runs land on the same clean starting state instead of
 * accumulating duplicate contacts/templates or hitting a unique-email
 * conflict on the second run. Safe by construction — it only ever
 * touches tenants it can prove it created itself, by exact email match.
 */
class DemoAccountsSeeder extends Seeder
{
    private const ADMIN_EMAIL = 'admin@reviewengine.test';

    private const ADMIN_PASSWORD = 'DemoAdmin123!';

    private const OWNER_EMAIL = 'owner@riverside-roofing.test';

    private const OWNER_PASSWORD = 'DemoOwner123!';

    private const MEMBER_EMAIL = 'member@riverside-roofing.test';

    private const MEMBER_PASSWORD = 'DemoMember123!';

    public function run(): void
    {
        // .claude/SECURITY.md's whole threat model exists because this is
        // a real product with real tenant data — a seeder that creates
        // known, published credentials must never be reachable where a
        // real attacker could use them. app()->environment() reads
        // APP_ENV directly (config/app.php's 'env' key); throwing (not a
        // soft warning) makes `php artisan db:seed --class=...` fail
        // loudly with a non-zero exit if this is ever attempted against
        // production, rather than quietly no-op'ing in a way that could
        // be missed in a deploy script's output.
        if (! app()->environment(['local', 'staging'])) {
            throw new \RuntimeException(
                'DemoAccountsSeeder refuses to run outside local/staging. '.
                'Current APP_ENV='.app()->environment().' — this seeder creates known, '.
                'published test credentials and must never touch a production database.'
            );
        }

        $this->deleteExistingDemoTenants();

        $admin = $this->createSuperAdmin();
        [$ownerTenantId, $owner] = $this->createDemoCustomerTenant();
        $member = $this->createTeamMember($ownerTenantId);
        $this->seedContactsAndTemplates($ownerTenantId);

        $this->printCredentials($admin, $owner, $member);
    }

    /**
     * Bypasses RLS deliberately, scoped to exactly this one cleanup pass —
     * same is_admin pattern ExpireStaleTrials/ReleasePendingContacts use
     * to read/act across tenants with no request-scoped tenant context to
     * inherit. Deleting the Tenant row cascades (ON DELETE CASCADE) to
     * every user/campaign/contact/template/subscription row under it, so
     * this alone is enough to fully reset both demo tenants.
     */
    private function deleteExistingDemoTenants(): void
    {
        DB::transaction(function () {
            DB::statement("SELECT set_config('app.is_admin', 'true', true)");

            $tenantIds = User::query()
                ->withoutGlobalScopes()
                ->whereIn('email', [self::ADMIN_EMAIL, self::OWNER_EMAIL, self::MEMBER_EMAIL])
                ->pluck('tenant_id')
                ->unique();

            Tenant::query()->withoutGlobalScopes()->whereIn('id', $tenantIds)->delete();
        });
    }

    /**
     * Mirrors AuthController::register()'s own bootstrap exactly: the
     * tenant's UUID has to be known and set as the RLS session var
     * *before* the INSERT, since the tenant_isolation policy's WITH CHECK
     * (id = current_setting(...)) has to already agree with the row being
     * inserted — there's no prior context to inherit it from.
     */
    private function createSuperAdmin(): User
    {
        return DB::transaction(function () {
            $tenantId = (string) Str::uuid();
            DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

            $tenant = new Tenant(['name' => 'ReviewEngine HQ', 'type' => 'admin']);
            $tenant->id = $tenantId;
            $tenant->save();

            $user = new User([
                'name' => 'ReviewEngine Admin',
                'email' => self::ADMIN_EMAIL,
                'password' => Hash::make(self::ADMIN_PASSWORD),
            ]);
            $user->tenant_id = $tenantId;
            $user->role = 'owner';
            $user->save();

            return $user;
        });
    }

    /**
     * @return array{0: string, 1: User} [tenant id, owner]
     */
    private function createDemoCustomerTenant(): array
    {
        return DB::transaction(function () {
            $tenantId = (string) Str::uuid();
            DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

            $tenant = new Tenant(['name' => 'Riverside Roofing', 'type' => 'customer']);
            $tenant->id = $tenantId;
            $tenant->save();

            // Trialing + onboarding already marked complete — "isn't empty
            // on first login" means landing straight on a populated
            // /dashboard, not being routed back into the onboarding
            // wizard the moment they sign in (DashboardPage redirects to
            // /onboarding whenever onboarding_completed_at is null, per
            // OnboardingController::status()'s own `completed` field).
            $tenant->startTrial('standard');
            $tenant->gbp_step_done = true;
            $tenant->contacts_step_done = true;
            $tenant->onboarding_completed_at = now();
            $tenant->save();

            $owner = new User([
                'name' => 'Jordan Rivera',
                'email' => self::OWNER_EMAIL,
                'password' => Hash::make(self::OWNER_PASSWORD),
            ]);
            $owner->tenant_id = $tenantId;
            $owner->role = 'owner';
            $owner->save();

            return [$tenantId, $owner];
        });
    }

    /**
     * Deliberately limited: reviews + analytics only, matching the
     * prompt's own example — contacts/templates left unset (defaults to
     * false via User::hasPermission()'s "absent key = not granted" rule)
     * so both the granted and withheld sides of the permission system are
     * visibly, immediately testable after seeding — /reviews and
     * /analytics work on first login, /contacts and /templates 403
     * (RequirePermission), and Settings shows no Team/Billing tab at all
     * (owner-only, .claude decision doc — a member can't reach those
     * under any permission combination).
     */
    private function createTeamMember(string $tenantId): User
    {
        return DB::transaction(function () use ($tenantId) {
            DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

            $member = new User([
                'name' => 'Sam Torres',
                'email' => self::MEMBER_EMAIL,
                'password' => Hash::make(self::MEMBER_PASSWORD),
            ]);
            $member->tenant_id = $tenantId;
            $member->role = 'member';
            $member->permissions = [
                'contacts' => false,
                'templates' => false,
                'reviews' => true,
                'analytics' => true,
            ];
            $member->save();

            return $member;
        });
    }

    /**
     * Reuses the app's own default-provisioning services rather than
     * inventing separate seed copy — TemplateProvisioner::ensureDefaults()
     * is exactly what GET /templates calls on a tenant's first real visit
     * (.claude/COMPLIANCE.md: "ship compliant defaults"), so the owner
     * sees precisely what a real new tenant would, just already there
     * instead of provisioned on first page load. Two contacts, not one —
     * enough to make the Contacts list visibly non-empty/plural without
     * pretending to be a realistic-sized customer list.
     */
    private function seedContactsAndTemplates(string $tenantId): void
    {
        DB::transaction(function () use ($tenantId) {
            DB::statement('SELECT set_config(?, ?, true)', ['app.current_tenant_id', $tenantId]);

            // Campaign::findOrCreateDefault()'s own tenant_id relies on
            // BelongsToTenant's creating() hook, which reads the
            // CurrentTenant *PHP singleton* — a separate mechanism from
            // the RLS session var set above (the same distinction
            // AdminBillingController's withoutGlobalScopes() docblock
            // covers from the opposite direction). A real request gets
            // both together from SetTenantContext; here both have to be
            // set explicitly. Confirmed live: omitting this line throws a
            // NOT NULL violation on campaigns.tenant_id. Cleared in
            // finally, same "never leave a stale tenant context sitting
            // around" reasoning SetTenantContext's own docblock gives,
            // even though this is a one-shot seeder process, not a
            // persistent worker.
            app(CurrentTenant::class)->set($tenantId);

            try {
                $campaign = Campaign::findOrCreateDefault();
                app(TemplateProvisioner::class)->ensureDefaults($campaign);

                $demoContacts = [
                    ['name' => 'Alicia Martinez', 'phone' => '555-0142', 'email' => 'alicia.martinez@example.com'],
                    ['name' => 'Devon Walker', 'phone' => '555-0198', 'email' => 'devon.walker@example.com'],
                ];

                foreach ($demoContacts as $data) {
                    $contact = new Contact([
                        'campaign_id' => $campaign->id,
                        'name' => $data['name'],
                        'phone' => $data['phone'],
                        'email' => $data['email'],
                        'status' => 'sent',
                        'consent_at' => now()->subDays(3),
                    ]);
                    $contact->tenant_id = $tenantId;
                    $contact->save();
                }
            } finally {
                app(CurrentTenant::class)->clear();
            }
        });
    }

    private function printCredentials(User $admin, User $owner, User $member): void
    {
        $rows = [
            ['Super admin (cross-tenant)', $admin->email, self::ADMIN_PASSWORD],
            ['Tenant owner — Riverside Roofing', $owner->email, self::OWNER_PASSWORD],
            ['Team member — reviews + analytics only', $member->email, self::MEMBER_PASSWORD],
        ];

        if ($this->command !== null) {
            $this->command->newLine();
            $this->command->info('Demo accounts created (local/staging only — never real credentials):');
            $this->command->table(['Role', 'Email', 'Password'], $rows);
        }
    }
}
