<?php

namespace App\Services\Contacts;

use App\Models\Campaign;
use App\Models\Contact;

/**
 * The one contact-creation path shared by every non-CSV entry point:
 * quick-add (in-app + guest link) and now the webhook API. Same campaign
 * via Campaign::findOrCreateDefault(), same status='pending', same
 * consent_at=now() CsvImportService::import() uses directly — so a
 * contact is indistinguishable from any other except for `source` and,
 * when given, `external_id`. .claude/CLAUDE.md webhook API spec: "feeding
 * the identical existing drip pipeline... no special casing" — this is
 * that: one method, one shape, a $source string telling you how it got
 * here.
 *
 * Always runs with a real tenant context already active on the
 * connection (set by 'tenant' middleware for authenticated routes, or by
 * ResolveQuickAddTenant for the guest quick-add link) — BelongsToTenant's
 * auto-fill on Contact::save() and Campaign::findOrCreateDefault()'s own
 * implicit tenant scoping both depend on that already being true.
 */
class ContactEnrollmentService
{
    public function create(string $name, ?string $phone, ?string $email, string $source, ?string $externalId = null): Contact
    {
        $campaign = Campaign::findOrCreateDefault();

        $contact = new Contact([
            'campaign_id' => $campaign->id,
            'name' => $name,
            'phone' => $phone,
            'email' => $email,
            'status' => 'pending',
            'source' => $source,
            'external_id' => $externalId,
            // Same reasoning as CsvImportService's own consent_at: implied
            // consent from the relationship event (a completed job, an
            // inbound webhook from a tool the tenant already connected)
            // that triggered this contact's creation.
            'consent_at' => now(),
        ]);
        $contact->save();

        return $contact;
    }
}
