# AUTH — email verification

## Why Laravel's built-in MustVerifyEmail, not a custom token scheme

`App\Models\User` implements `Illuminate\Contracts\Auth\MustVerifyEmail`
and uses the `Illuminate\Auth\MustVerifyEmail` trait —
`hasVerifiedEmail()`/`markEmailAsVerified()`/`getEmailForVerification()`,
the signed `verification.verify` route (`id`+`hash`+expiry+signature),
the `Verified` event. Nothing about the actual verification mechanism is
reinvented — this app already has one bespoke, purpose-built verification
flow (`VerifySenderIdentity`, for proving control of a *sending* address)
and doesn't need a second, differently-shaped one for the same underlying
"prove you control this mailbox" problem applied to the account's own
email.

The one adaptation: `sendEmailVerificationNotification()` is overridden
(`App\Models\User`) to send `App\Notifications\VerifyEmailAddress` — a
one-line subclass of Laravel's own `VerifyEmail` that adds `ShouldQueue`,
matching every other outbound notification in this app. Laravel's stock
`VerifyEmail` sends synchronously by default, which nothing here does for
a real email.

## Why not `Illuminate\Foundation\Auth\EmailVerificationRequest`

That class's `authorize()` calls `$this->user()` — it assumes the web
session guard already knows who's asking, which fits Breeze/Jetstream's
session-based verification link. This app is Sanctum-token-only with no
session auth at all (`.claude/SECURITY.md`), and a Bearer token can't
ride along on a link clicked from an email client anyway.
`AuthController::verifyEmail()` is a small, un-authenticated, `signed`-only
route instead — the `{id}` route parameter plus the `hash` re-check
(`hash_equals(sha1($user->getEmailForVerification()), $hash)`) are the
same two checks `EmailVerificationRequest::authorize()` makes, just
without requiring an active session to make them. The `users` table's
existing `tenant_isolation_auth_lookup` RLS policy (built for
`AuthController::login()`'s own email lookup) is reused as-is for finding
the user by id before any tenant context exists.

## Register → login → verify, in order

1. `POST /register` creates the tenant + owner (unverified —
   `email_verified_at` starts null, same as any fresh Laravel user row)
   and calls `sendEmailVerificationNotification()` — after the DB
   transaction commits and the auth token is issued, not inside it (a
   queued notification firing before the write it depends on is
   committed would be a side effect racing ahead of its own dependency).
2. **Login is never gated on verification.** `AuthController::login()`
   doesn't check `hasVerifiedEmail()` at all — an unverified user gets a
   real token and full read access immediately. Only *sending* is gated
   (below).
3. Clicking the real signed link (`GET /email/verify/{id}/{hash}`)
   verifies and redirects to `{frontend}/settings?verified=1`. This never
   touches Sanctum tokens — `RequireSendingAccess` reads
   `hasVerifiedEmail()` fresh from the DB on every request, so an
   already-issued token starts passing the gate on its very next request.
   No new login, no new token.
4. `POST /email/verification-notification` (authenticated, throttled) is
   the "Resend verification email" action — a harmless
   "already verified" message if it's too late to matter, never a
   second email once verified.

## The gate: owner-scoped, not per-acting-user

`Tenant::sendingBlockedReason()` (extended from the trial-expiry/
subscription gate — `.claude/BILLING.md`'s "Auto-renew toggle"/"Renewal
reminders" sections) now also returns `'email_unverified'` when the
**tenant owner's** email isn't verified — not whichever user's Sanctum
token happens to be making the request. Two reasons, not one:

- **It has to work for `POST /quick/{token}`** — RequireSendingAccess's
  public, unauthenticated entry point. `ResolveQuickAddTenant` sets
  tenant context from the URL token alone; there's no Sanctum user at
  all on that route, so any acting-user-scoped check couldn't apply
  there uniformly regardless of what it did on the other three routes.
- **It's the same bucket billing already lives in.** Email verification
  here is an account-integrity fact about the tenant as a whole, the
  same "owner-scoped, not per-member" category `.claude/BILLING.md`
  already puts billing/subscription state in ("Billing is owner-only —
  never grantable to a member"). Team-invited members never go through
  their own verification step either — `TeamInviteController::accept()`
  never touches `email_verified_at` — consistent with verification being
  a registration-time, owner-level concept, not a per-member gate.

Practical effect, proven by `EmailVerificationTest.php`'s own
owner-scoped test: a verified member is still blocked while the owner is
unverified, and a member becomes unblocked the moment the *owner*
verifies — never the other way around. `RequireSendingAccess` returns
`error: 'email_unverified'` and `"Verify your email to start sending
review requests."`, the third branch alongside `trial_expired`/
`subscription_ended` — checked last in `sendingBlockedReason()`,
deliberately: a trial-expired or subscription-ended tenant sees that
message, not this one, since verifying email alone wouldn't unblock them
anyway.

## The banner

`GET /tenant`'s `sending_blocked_reason` field (the exact same source of
truth the backend gate enforces) drives `SidebarShell.tsx`'s
`EmailUnverifiedBanner` — destructive/red, not the gold/warning tint the
renewal-reminder banner uses, since sending is actually blocked right
now, not just an advance notice (`.claude/FRONTEND.md`'s design doc:
destructive red is reserved for "an actual compliance-blocked state or a
real error," same bucket `TrialExpiredBanner` is already in). The
"Resend verification email" action lives directly in the banner, not
just in Settings, so it's visible from every authenticated screen — not
something a blocked owner has to go hunting for.

## Test coverage that isn't obvious from reading the gate alone

`tests/Feature/Auth/EmailVerificationTest.php`'s CRITICAL test: register
→ confirm blocked with the original token → click the real signed link
(constructed via `URL::temporarySignedRoute`, same fidelity
`SenderIdentityVerificationTest.php` already established for sender
identities) → confirm unblocked using that *exact same* token, no new
login call anywhere in the test. `seedCustomerAccount()` (the shared test
helper nearly every other feature test in this suite uses) is verified
by default as of this change — the overwhelming majority of call sites
exist to test something else entirely and just need "a real account that
can send" as setup; `seedUnverifiedCustomerAccount()` is the explicit
opt-out for tests that exercise the gate itself.
