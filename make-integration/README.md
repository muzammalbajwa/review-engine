# ReviewEngine on Make

A Make.com custom app exposing Step 2's webhook API (`POST /api/v1/contacts`)
as one module: **Add Review Request Contact**. Same shape as Step 4's
Zapier integration — same auth, same idempotency behavior, same fields —
built on Make's own app framework (Forman Schema fields, IML expressions,
JSON component files) rather than ported wholesale from Zapier's Node.js
model, since the two platforms don't share a runtime.

## Why this isn't structured like `zapier-integration/`

Zapier's CLI builds a real local Node.js project you can `npm test` and
`zapier-platform validate` entirely offline before ever touching an
account. Make's custom-app framework doesn't have an equivalent — an app
is a set of JSON/IML **components** (a base, a connection, one or more
modules), normally edited directly in Make's web IDE or a VS Code
extension that syncs to Make's servers over an authenticated API session.
There's no local "build & fully validate offline" step in the platform
itself.

What's here instead: the real component files, written to match Make's
actual current schema (verified against Make's own documentation and a
real installable package — see below, not guessed at), organized the way
Make's own local-development file convention lays them out
(`<component>.<block>.json`), ready to paste into the Make Apps Editor or
sync via their VS Code extension once you have an account.

## What's in here

```
base.json                                    Shared config: API host, auth
                                              header, default error handling
                                              for every module.
connections/
  webhook-api-key.json                       Connection metadata (name/type).
  webhook-api-key.parameters.json            The one field a user fills in
                                              (their API key).
  webhook-api-key.communication.json         How Make tests that key works.
modules/create-contact/
  create-contact.json                        Module metadata.
  create-contact.expect.json                 Mappable input fields.
  create-contact.communication.json          The actual POST request.
  create-contact.interface.json              Output fields (for mapping
                                              into later scenario steps).
  create-contact.samples.json                Sample output (real Step 3 data).
scripts/validate.mjs                         Real offline validation — see below.
```

### Authentication — a Basic (API key) connection, not OAuth2

Same reasoning as Step 4: the credential is a static per-tenant API key
(a Sanctum personal access token scoped to `contacts:create`), not a
login redirect, so this uses Make's **Basic connection** type — the one
built for exactly this shape of credential. One field, `apiKey`
(`type: "password"`, masked in Make's UI).

The connection test calls `GET /api/v1/tenant` — same choice as Step 4's
Zapier app, same reasoning: no dedicated "validate this key" endpoint
exists, `/tenant` is read-only (required for a connection test — no side
effects), and it's reachable by a `contacts:create`-scoped key because
`routes/api.php` only checks abilities on the contacts-create route
itself. A successful test sets the connection's label to the tenant's
business name, pulled from that response (`metadata.value`, Make's
equivalent of Zapier's `connectionLabel`).

### The module — Add Review Request Contact

Maps directly onto `WebhookCreateContactRequest`'s fields: `name`
(required), `phone` / `email` (one of the two required — Make can't
express "one of two required" declaratively any more than Zapier could,
so this is stated in both fields' help text, same as Step 4), and
`external_id` (optional). The help text on `external_id` explains the
idempotency behavior inline, in the scenario editor, the same way Step
4's Zapier field does.

**Idempotency, mapped straight through:** confirmed via Make's own docs
that an unmapped optional parameter renders as `undefined` in IML and is
**omitted from the request body entirely** by Make's platform (not sent
as `""` or `null`) — so a blank `external_id` never accidentally collides
with another blank one inside Step 2's 24-hour dedup window. When a value
*is* mapped, Make's own automatic retry (after a rate limit or a
5xx/timeout) resends the identical bundle, landing on the same
`external_id` and getting back the original contact instead of a
duplicate — exactly Step 4's reasoning, ported over because the mechanism
(same field, same backend, same dedup window) is identical.

**Error handling**, centralized once in `base.json` rather than
per-module (all of it — inherited automatically by every module, per
Make's own docs):

| ReviewEngine response | Make's `error.type` | Effect |
|---|---|---|
| 401 (bad/expired key) | `InvalidAccessTokenError` | Surfaces clearly as an authentication problem, not a generic failure |
| 403 (wrong-ability key) | `InvalidConfigurationError` | Explains every key from Settings already has this permission |
| 422 (validation failed) | `DataError` | Shows ReviewEngine's own real message |
| 429 (rate limited) | `RateLimitError` | **Make auto-assigns this type to any 429 with zero config** — confirmed from Make's own error-directive reference. Automatic retry with exponential backoff (roughly 1 min, then climbing) is Make's default behavior for this type; nothing custom was needed, same spirit as Step 4 deliberately not re-implementing Zapier's built-in 429 handling. |

### Verified, not assumed

Everything above was checked against Make's real, current documentation
and a real installable package — not written from memory and hoped
correct:

- **`@makehq/forman-schema`** (the actual npm package Make publishes for
  their field-schema format) is a real devDependency here.
  `npm run validate` (`scripts/validate.mjs`) loads the real
  `expect`/`interface`/connection-parameter JSON files straight from this
  repo and:
  - confirms every field definition is schema-valid,
  - validates a **real captured Step 3 request/response pair** (the same
    Priya Shah example from the docs page) against `expect` and
    `interface` respectively — passes,
  - confirms a request missing the required `name` field is correctly
    rejected.

  Ran live: all 6 checks pass.
- The Basic-connection structure, the base/module JSON shapes, the
  `error.type` enum and its default status-code mapping, and the
  omitted-when-empty parameter behavior were each confirmed against
  Make's official developer documentation directly (not paraphrased from
  general familiarity) — cited inline in the files' own comments where it
  mattered most (e.g. `create-contact.json`'s note on module-type
  metadata).

### One structural difference from Step 4, worth knowing

Zapier's app read `API_BASE_URL` from an environment variable per
Zapier account — the same published app worked against dev or prod
without a code change. Make's `baseUrl` is normally a **fixed literal**
baked into the app definition itself; there's no per-installation
environment-variable equivalent for a fixed-host SaaS integration like
this one. `base.json` and `connections/webhook-api-key.communication.json`
both currently say `https://YOUR-REVIEWENGINE-API-HOST` — a placeholder,
not a guess at a real domain, since (same as Steps 3 and 4) no fixed
production host exists yet for this project. **Both** occurrences need
the real host once one exists; they're independent files, so a search for
`YOUR-REVIEWENGINE-API-HOST` is the reliable way to catch both.

### What I didn't fabricate

Make's local-development workflow (VS Code extension) generates a
`makecomapp.json` manifest that ties these components into one deployable
app. I could not find a verified, concrete example of its exact schema in
Make's public documentation, and getting it wrong would be worse than not
providing it — a plausible-looking but incorrect manifest is more
misleading than an honest gap. It's gitignored here in anticipation of
being generated for real once you clone this app into a local workspace
from an actual Make account (see below) — at that point Make's own
tooling writes the authoritative version, not a guess from me.

## Running the validation yourself

```bash
cd make-integration
npm install
npm run validate
```

## What I can't do for you

Same as Step 4: creating a Make account, logging in, and creating the
actual app/connection/module resources on Make's platform are all things
I won't do on your behalf — that means creating an account and handling
your login credentials, regardless of going ahead. You'll need to do
this yourself, using the files above.

### 1. Create a Make account (if you don't have one) and open the Apps Editor

Sign up at make.com. Custom app development (the "Apps Editor") is
available from your organization's settings — free to build and test
privately, same as Zapier.

### 2. Create the app, connection, and module

Either paste each file's content into the corresponding section of the
web-based Apps Editor (Base / Connections / Modules — matching the file
names above 1:1), or install the VS Code extension
(`Integromat.apps-sdk`) and use its "Clone Make app to local workspace"
flow against a new empty app, then copy these files into the workspace it
creates. Either path needs you to be logged into your own Make account —
not something I can do from here.

### 3. Replace the placeholder host

Before testing anything, replace `https://YOUR-REVIEWENGINE-API-HOST` in
both `base.json` and `connections/webhook-api-key.communication.json`
with your real, deployed ReviewEngine API host.

### 4. Test it privately first

Make apps are private to your account by default — no separate "submit
for review" step is required to use it yourself or share it with your
team. Create a connection using a real API key from your own
Settings → API keys, add the Add Review Request Contact module to a test
scenario, and run it once for real.

### 5. Submit for public listing (optional, and Make's call, not mine)

If you want this listed in Make's public app directory: from the Apps
Editor, there's a submission/review flow (branding, description,
category — similar scope to Zapier's review). Timeline and outcome are
entirely Make's decision, not something I can predict or guarantee.

## How a tenant will actually connect it (once set up)

1. Generate a ReviewEngine API key from **Settings → API keys** (Step 3)
   if they don't already have one.
2. In Make, create a new scenario. Add any trigger module — a new row in
   a spreadsheet, a new lead in a CRM, a new form submission.
3. Search **"ReviewEngine"** for the action module (or find it under your
   own private apps during testing) and choose **Add Review Request
   Contact**.
4. Add a connection: paste the API key from step 1 into the one field
   Make asks for. Make calls `GET /tenant` immediately and labels the
   connection with the tenant's business name if it worked, or shows a
   clear error if the key was wrong.
5. Map fields: Name, Phone and/or Email from the trigger module's output,
   and — strongly recommended, explained in the field's own help text —
   External ID, mapped to whatever unique identifier the trigger module
   already provides.
6. Run the scenario once to test — it creates one real contact in their
   ReviewEngine account (no sandbox mode, same disclosure as Step 3's
   docs page and Step 4's Zapier app), visible immediately in Contacts.
7. Turn scheduling on. From then on, every new record from their trigger
   app flows into ReviewEngine's review-request campaign automatically.
