/**
 * POST /api/v1/contacts (Step 2's WebhookContactController). Every failure
 * mode here mirrors .claude/API.md's error envelope exactly:
 *   - 401 (bad/expired key)      -> ExpiredAuthError, so Zapier prompts a
 *                                    reconnect instead of just failing the task
 *   - 403 (wrong-ability key)    -> z.errors.Error with the real reason
 *   - 422 (validation failed)    -> z.errors.Error built from `fields`
 *   - 429 (plan rate limit)      -> NOT handled here on purpose. Core's
 *                                    default throwForThrottling middleware
 *                                    already converts a 429 into a
 *                                    ThrottledError using ReviewEngine's own
 *                                    Retry-After header, and Zapier
 *                                    reschedules the task automatically —
 *                                    handling it again here would just
 *                                    fight the platform's built-in retry.
 *
 * process.env.API_BASE_URL is read here, at call time — see
 * authentication.js's testAuth for why this can't be a module-level const.
 */
const perform = async (z, bundle) => {
  const response = await z.request({
    url: `${process.env.API_BASE_URL}/api/v1/contacts`,
    method: 'POST',
    skipThrowForStatus: true,
    body: {
      name: bundle.inputData.name,
      phone: bundle.inputData.phone,
      email: bundle.inputData.email,
      external_id: bundle.inputData.external_id,
    },
  });

  if (response.status === 401) {
    throw new z.errors.ExpiredAuthError(
      "This API key is invalid or its rotation grace period has ended. Reconnect this account with a fresh key from Settings → API keys."
    );
  }

  if (response.status === 403) {
    throw new z.errors.Error(
      "This API key isn't authorized to create contacts. Every key generated from Settings → API keys already has this permission — reconnect using a key from there rather than one issued for something else.",
      'AuthorizationError',
      403
    );
  }

  if (response.status === 422) {
    const fields = response.data && response.data.fields;
    const message = fields
      ? Object.values(fields).flat().join(' ')
      : (response.data && response.data.message) || 'ReviewEngine rejected this contact.';
    throw new z.errors.Error(message, 'InvalidData', 422);
  }

  // Anything else unexpected (5xx, etc.) — let Zapier show the real status.
  response.throwForStatus();

  return response.data.data;
};

module.exports = {
  key: 'contact',
  noun: 'Contact',

  display: {
    label: 'Add Review Request Contact',
    description:
      'Adds a customer to ReviewEngine and enrolls them in your review-request drip campaign — the same path a CSV import or the quick-add form uses.',
  },

  operation: {
    inputFields: [
      {
        key: 'name',
        label: 'Name',
        type: 'string',
        required: true,
        helpText: "The customer's full name.",
      },
      {
        key: 'phone',
        label: 'Phone',
        type: 'string',
        required: false,
        helpText:
          'Include the country code, e.g. +14155550142. Required if Email is left blank.',
      },
      {
        key: 'email',
        label: 'Email',
        type: 'string',
        required: false,
        helpText: 'Required if Phone is left blank.',
      },
      {
        // `zapier-platform validate` flags this as D004 ("looks like an ID
        // field but lacks a dynamic dropdown") — a heuristic meant for
        // fields that reference an existing record (e.g. "which Contact"),
        // which would need a dropdown populated by a search. This is the
        // opposite: free text the user maps in from an upstream step, never
        // selected from a ReviewEngine-side list. False positive, left as
        // a plain string field on purpose.
        key: 'external_id',
        label: 'External ID',
        type: 'string',
        required: false,
        helpText:
          "Strongly recommended: map a unique ID for this customer from whatever app triggered this Zap — a CRM record ID, an order number, a row ID. " +
          "If this step ever runs twice for the same customer (Zapier's own automatic retry after a timeout, or a manual replay from the Zap history), " +
          'sending the same External ID returns the contact ReviewEngine already created instead of creating a duplicate. Leave blank only if this Zap ' +
          'step will never plausibly run twice for the same person.',
      },
    ],

    perform,

    sample: {
      id: 202,
      tenant_id: 'b8d076a9-601f-43f1-8b0c-78b1bb5849e0',
      campaign_id: 9,
      name: 'Priya Shah',
      phone: '+14155550142',
      email: 'priya@example.com',
      status: 'pending',
      source: 'webhook',
      external_id: 'crm-lead-48213',
      consent_at: '2026-08-05T07:39:13.000000Z',
      created_at: '2026-08-05T07:39:13.000000Z',
      updated_at: '2026-08-05T07:39:13.000000Z',
    },

    outputFields: [
      { key: 'id', label: 'Contact ID', type: 'integer' },
      { key: 'campaign_id', label: 'Campaign ID', type: 'integer' },
      { key: 'name', label: 'Name' },
      { key: 'phone', label: 'Phone' },
      { key: 'email', label: 'Email' },
      { key: 'status', label: 'Status' },
      { key: 'source', label: 'Source' },
      { key: 'external_id', label: 'External ID' },
      { key: 'consent_at', label: 'Consent Recorded At', type: 'datetime' },
      { key: 'created_at', label: 'Created At', type: 'datetime' },
    ],
  },
};
