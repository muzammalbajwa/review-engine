/**
 * Custom auth, not OAuth2 — the credential is a static Sanctum personal
 * access token issued from Settings → API keys (Step 2's ApiKeyController),
 * not a token obtained through a login redirect. Zapier's schema calls this
 * "custom" auth: a plain field Zapier stores encrypted, attached to every
 * request by app.js's beforeRequest hook.
 *
 * The test call hits GET /api/v1/tenant rather than a dedicated
 * "whoami"/validate endpoint — .claude/API.md has no such endpoint, and
 * /tenant is the one existing authenticated route that's both read-only (no
 * side effects, required for an auth test) and reachable by a
 * contacts:create-scoped key: routes/api.php only applies the 'abilities'
 * ability check to POST /contacts itself, so /tenant accepts any valid
 * token for the account, webhook key included.
 *
 * process.env.API_BASE_URL is read here, at call time, not hoisted into a
 * module-level constant — a top-level `const BASE_URL = process.env...`
 * would capture `undefined` in local testing, since test/*.test.js's
 * zapier.tools.env.inject() (which loads .env) runs *after* `require('../index')`
 * has already pulled this module in. Confirmed live: that was the exact bug
 * on the first test run ("Only absolute URLs are supported").
 */
const testAuth = async (z, bundle) => {
  const response = await z.request({
    url: `${process.env.API_BASE_URL}/api/v1/tenant`,
    skipThrowForStatus: true,
  });

  if (response.status === 401) {
    throw new z.errors.ExpiredAuthError(
      "That key isn't valid. Copy a fresh one from Settings → API keys — the plaintext key is only shown once, right after you generate it."
    );
  }

  response.throwForStatus();

  // Every ReviewEngine response wraps its payload in { data: ... }
  // (.claude/API.md) — unwrap it so connectionLabel's {{bundle.inputData.name}}
  // resolves against the tenant object itself, not the envelope.
  return response.data.data;
};

module.exports = {
  type: 'custom',

  fields: [
    {
      key: 'apiKey',
      label: 'API Key',
      type: 'password',
      required: true,
      // `zapier-platform validate` flags this as D002 ("consider a direct
      // link to information about this field") — Zapier wants a clickable
      // URL here, not just a navigation path in prose. Deliberately not
      // added: ReviewEngine has no fixed public domain yet (same
      // constraint noted in Step 3's docs page — this app is only ever
      // reachable at whatever host API_BASE_URL points to per environment,
      // dev or prod). Once a real production domain exists, replace the
      // "Settings → API keys" text below with an actual
      // https://<that-domain>/settings link and this warning clears for
      // real, instead of masking it with a URL that doesn't resolve yet.
      helpText:
        'From your ReviewEngine dashboard: **Settings → API keys → Generate API key**. ' +
        "The key is shown once, right when you generate it — copy it here before leaving that page. " +
        'This key can only create contacts; it cannot read or change anything else in your account.',
    },
  ],

  test: testAuth,

  // The account name from the /tenant test call above, e.g. "Rae Roofing".
  connectionLabel: '{{bundle.inputData.name}}',
};
