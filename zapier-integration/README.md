# ReviewEngine on Zapier

A Zapier Platform CLI integration exposing Step 2's webhook API
(`POST /api/v1/contacts`) as a single Zapier Action: **Add Review Request
Contact**. Send a customer from any of the 8,000+ apps on Zapier — a CRM, a
form tool, a spreadsheet — straight into a ReviewEngine review-request
campaign.

Built against `zapier-platform-core`/`zapier-platform-cli` **19.1.0** (the
real current version — checked via `npm view` rather than guessed) and
verified with `zapier-platform validate` (0 errors, 0 publishing-blocking
warnings) and a real Jest suite that hits the live dev backend.

## What's in here

```
authentication.js      Custom auth: the tenant's API key, tested against
                        a real request, not just a format check.
index.js                Wires auth + the action together; attaches the
                        API key as `Authorization: Bearer …` to every
                        outgoing request.
creates/contact.js      The "Add Review Request Contact" action itself.
test/                   Jest tests — run against the real API (see below),
                        the same "no mocking" convention Step 2/3 used.
```

### Authentication — Custom, not OAuth2

ReviewEngine's webhook API is authenticated with a static per-tenant API
key (a Sanctum personal access token scoped to `contacts:create`), not a
login redirect — so this uses Zapier's **Custom auth** scheme, the one
their platform recognizes for exactly this shape of credential. One field
(`API Key`, masked as a password field), attached to every request by
`index.js`'s `beforeRequest` hook.

There's no dedicated "validate this key" endpoint in the API, so the
connection test calls `GET /api/v1/tenant` — real, read-only (a hard
requirement for an auth test — no side effects), and reachable by a
`contacts:create`-scoped key because `routes/api.php` only checks
abilities on the contacts-create route itself. A successful test shows
**"Connected as {tenant name}"** in Zapier's UI, pulled from that same
response.

### The action — Add Review Request Contact

Maps directly onto `WebhookCreateContactRequest`'s fields: `name`
(required), `phone` / `email` (one of the two required), and
`external_id` (optional). Field-level `helpText` in `creates/contact.js`
explains the idempotency behavior inline, in the Zap editor, right where a
user is deciding whether to map it.

**Idempotency, mapped straight through to Step 2's actual behavior:**
Zapier retries a failed step automatically, and users replay steps
manually from Zap history — both resend the exact same `bundle.inputData`.
Because `external_id` passes straight through to the same field the
webhook API already dedupes on (a 24-hour window, per Step 2), a Zapier
retry naturally lands on ReviewEngine's existing idempotency check and
gets back the original contact instead of creating a duplicate. Nothing
extra was built on the Zapier side to make retries safe — the action just
doesn't get in the way of behavior Step 2 already has.

**Error handling**, matching `.claude/API.md`'s `{ error, message, fields }`
envelope:
| ReviewEngine response | What happens in Zapier |
|---|---|
| 401 (bad/expired key) | `ExpiredAuthError` — Zapier prompts the user to reconnect the account, rather than just failing the task |
| 403 (wrong-ability key) | A clear error explaining every key from Settings already has this permission |
| 422 (validation failed) | The real field-level message from `fields`, not a generic "422" |
| 429 (rate limited) | Handled automatically by Zapier's platform — `zapier-platform-core` converts a 429 into a scheduled retry using ReviewEngine's own `Retry-After` header. Nothing custom needed; adding our own 429 handling here would fight the platform's built-in one. |

### Verified, not assumed

- `zapier-platform validate`: **0 structural errors, 0 publishing-blocking
  warnings**, 25 integration checks passed. 3 non-blocking "general
  warnings" remain, each addressed with a comment in the code explaining
  why (a false-positive ID-field heuristic on `external_id`, the default
  `cleanInputData` behavior being correct for this action, and a
  can't-fabricate-a-URL note on the auth field's help text — see below).
- `npm test`: a real Jest suite. Ran live against the actual dev backend
  (`http://127.0.0.1:8123`) with a real generated API key — confirmed the
  create action returns a real contact, a retried `external_id` returns
  the *original* contact (not a duplicate), and a malformed request
  surfaces a real validation error. Two real bugs were caught and fixed
  by actually running these rather than just writing them:
  1. `API_BASE_URL` was being read into a module-level constant, which
     captured `undefined` in tests (env loads *after* the module is
     required) — fixed by reading `process.env.API_BASE_URL` inside the
     functions instead.
  2. The auth test was returning the whole `{ data: {...} }` envelope
     instead of unwrapping it, so `connectionLabel` had nothing to
     resolve — fixed to return `response.data.data`.

### One honest gap: the API key field's help text

Zapier's validator (D002) wants a clickable link in the auth field's help
text, not just a navigation path like "Settings → API keys." Left as prose
on purpose: ReviewEngine has no fixed public domain yet (the same
constraint the Step 3 docs page flagged), so hardcoding a URL here would
mean guessing one. Once a real production domain exists, swap the prose
in `authentication.js`'s `helpText` for an actual link — a two-line change,
noted in a comment right there.

## Running it yourself

```bash
cd zapier-integration
npm install
cp .env.example .env      # fill in API_BASE_URL; TEST_API_KEY is optional,
                           # needed only to run the tests that hit the real API
npm test                  # Jest — the "no real key" tests always run;
                           # the rest skip cleanly if TEST_API_KEY is unset
npx zapier-platform validate   # needs Node >= 22
```

## What I can't do for you

Creating the Zapier developer account, logging the CLI in, and registering
/ pushing this app are all things I'm not able to do on your behalf — that
means creating an account and handling your login credentials, which I
won't do regardless of the go-ahead. You'll need to run these yourself,
using the code above.

### 1. Create a Zapier account (if you don't have one) and enable Developer Platform access

Sign up at zapier.com, then visit the Developer Platform (linked from your
account menu) — free, no separate application needed to start building.

### 2. Install the CLI and log in

```bash
npm install -g zapier-platform-cli   # or use npx from inside this folder
zapier-platform login                 # authenticates with your Zapier account — your credentials, not mine
                                       # (add --sso if your Zapier org uses single sign-on)
```

### 3. Register the app

```bash
cd zapier-integration
zapier-platform register "ReviewEngine"
```

This creates the app under your account and writes a `.zapierapprc` file
(already gitignored) recording its assigned integration ID.

### 4. Point it at your real API and push

Set `API_BASE_URL` in Zapier's own environment for this integration (their
dashboard, under your app → Environment) to your real production API host
— **not** `127.0.0.1`, which only works from this machine. Then:

```bash
zapier-platform push
```

This uploads the code as a new (unpublished) version, visible only to your
own account.

### 5. Test it privately first

In the Zapier dashboard for this integration, invite yourself (or your own
account) as a tester, or just build a private Zap: search for "ReviewEngine"
under your own apps (it won't show up in the public directory yet), add
the **Add Review Request Contact** action, connect using a real API key
from your own Settings → API keys, and run a real test. This is the same
path a tenant will eventually follow — you're just doing it before anyone
else can see the integration exists.

### 6. Submit for public listing (optional, and Zapier's call, not mine)

From the same dashboard: **Manage → submit for review**. Zapier's review
covers more than the code — branding, a description, category, and
sometimes UX review of the connect flow. It typically takes some days, and
I can't predict or guarantee the outcome; that determination is entirely
Zapier's. If they come back with requested changes, most are Action/auth
tweaks in this same codebase.

## How a tenant will actually connect it (once pushed)

1. Generate a ReviewEngine API key from **Settings → API keys** (built in
   Step 3) if they don't already have one.
2. In Zapier, create a new Zap. Pick any trigger app — a new row in a
   spreadsheet, a new lead in a CRM, a new form submission, anything.
3. For the action, search **"ReviewEngine"** (once listed — or find it
   under "your apps" during private testing) and choose **Add Review
   Request Contact**.
4. Connect their account: paste the API key from step 1 into the one
   field Zapier asks for. Zapier calls `GET /tenant` right away and shows
   **"Connected as {their business name}"** if it worked, or a clear
   "reconnect" prompt if the key was wrong.
5. Map fields: Name, Phone and/or Email from the trigger step's data, and
   — strongly recommended, explained right in the field's help text —
   External ID, mapped to whatever unique identifier the trigger step
   already provides (a CRM record ID, a form submission ID, a spreadsheet
   row ID). That's what makes a Zapier retry safe.
6. Test the step once for real — it creates one real contact in their
   ReviewEngine account (no sandbox mode exists, same as Step 3's docs
   page try-it panel), which they'll see immediately in Contacts.
7. Turn the Zap on. From then on, every new record from their trigger app
   flows into ReviewEngine's review-request campaign automatically.
