import Link from "next/link";

import { CodeBlock } from "./CodeBlock";
import { TryItPanel } from "./TryItPanel";

const NAV = [
  { href: "#authentication", label: "Authentication" },
  { href: "#idempotency", label: "Idempotency" },
  { href: "#rate-limits", label: "Rate limits" },
  { href: "#endpoint", label: "POST /contacts" },
  { href: "#errors", label: "Errors" },
  { href: "#try-it", label: "Try it" },
] as const;

/**
 * The real docs body — split out from page.tsx so the gating check there
 * can render this conditionally. Only ever imported/called from the
 * subscriber branch: since this is a plain Server Component (no "use
 * client"), its output only enters the RSC payload/HTML when this
 * function actually runs, so a non-subscriber's response never contains
 * any of this markup to inspect via view-source or the network tab —
 * the gate lives in what gets rendered, not in hiding it after the fact.
 */
export function ApiDocsContent() {
  const base = process.env.NEXT_PUBLIC_API_URL ?? "https://your-api-host";

  return (
    <div className="min-h-screen bg-background">
      <header className="border-b border-border">
        <div className="mx-auto flex max-w-6xl items-center justify-between px-6 py-4">
          <Link href="/" className="font-heading text-base font-semibold text-foreground">
            ReviewEngine
          </Link>
          <Link
            href="/dashboard"
            className="text-sm font-medium text-muted-foreground hover:text-foreground"
          >
            Back to dashboard
          </Link>
        </div>
      </header>

      <div className="mx-auto flex max-w-6xl gap-10 px-6 py-10">
        <nav aria-label="On this page" className="hidden w-44 shrink-0 lg:block">
          <div className="sticky top-10 flex flex-col gap-1 text-sm">
            {NAV.map((item) => (
              <a
                key={item.href}
                href={item.href}
                className="rounded-lg px-2 py-1 text-muted-foreground hover:bg-muted hover:text-foreground"
              >
                {item.label}
              </a>
            ))}
          </div>
        </nav>

        <main className="min-w-0 flex-1 max-w-3xl">
          <h1 className="font-heading text-3xl font-semibold text-foreground">Webhook API</h1>
          <p className="mt-3 text-base text-muted-foreground">
            One endpoint: send a lead or past-customer contact into ReviewEngine from wherever it already
            lives — a CRM, a form backend, a Zapier step — and it enters the same review-request drip
            campaign a CSV import or the quick-add form would use.
          </p>
          <p className="mt-3 rounded-lg border border-border bg-muted/50 p-3 font-mono text-xs text-muted-foreground">
            Base URL for this environment:{" "}
            <span className="font-semibold text-foreground">{base}</span>
          </p>

          {/* Authentication */}
          <Section id="authentication" title="Authentication">
            <p>
              Every request needs a bearer API key. Generate one from{" "}
              <Link href="/settings" className="font-medium text-primary underline-offset-4 hover:underline">
                Settings → API keys
              </Link>{" "}
              — the plaintext key is shown exactly once, right after you generate it. Store it somewhere
              your integration can read it (a secrets manager, an env var); ReviewEngine only ever stores
              its hash, so there&apos;s no way to retrieve it again later. If you lose it, generate a new one.
            </p>
            <p>
              Send it as a standard bearer token:
            </p>
            <CodeBlock
              label="header"
              code={`Authorization: Bearer <your-api-key>`}
            />
            <p>
              A generated key is scoped to <code className="rounded bg-muted px-1 py-0.5 font-mono text-xs">contacts:create</code> only
              — it can create contacts through this endpoint and nothing else in ReviewEngine (it can&apos;t
              read your reviews, change your templates, or touch billing). Don&apos;t use your dashboard login
              session for a server-to-server integration even though it happens to work here too — it carries
              far more access than this endpoint needs, and rotating it logs you out of the dashboard.
            </p>

            <h3 className="mt-6 font-heading text-lg font-semibold text-foreground">Rotating a key</h3>
            <p>
              Generating a new key from Settings doesn&apos;t delete the old one immediately — it keeps working
              for <strong className="text-foreground">24 hours</strong> after you rotate, so an in-flight
              integration doesn&apos;t break the moment you swap credentials. After 24 hours the old key stops
              authenticating and every request with it gets a <code className="rounded bg-muted px-1 py-0.5 font-mono text-xs">401</code>.
            </p>
          </Section>

          {/* Idempotency */}
          <Section id="idempotency" title="Idempotency">
            <p>
              Webhook senders retry — a timeout on their end doesn&apos;t mean ReviewEngine didn&apos;t receive
              the request, so blindly retrying without protection would create duplicate contacts (and
              duplicate review-request drip campaigns) for the same person.
            </p>
            <p>
              Pass an optional <code className="rounded bg-muted px-1 py-0.5 font-mono text-xs">external_id</code> —
              your own identifier for this record (a CRM lead ID, an order number, whatever your system
              already uses to name this record). If a second request arrives with the{" "}
              <strong className="text-foreground">same</strong> <code className="rounded bg-muted px-1 py-0.5 font-mono text-xs">external_id</code>{" "}
              within <strong className="text-foreground">24 hours</strong> of the first, ReviewEngine returns
              the <em>original</em> contact — with a <code className="rounded bg-muted px-1 py-0.5 font-mono text-xs">200</code>,
              not a <code className="rounded bg-muted px-1 py-0.5 font-mono text-xs">201</code> — instead of creating
              a second one, even if the rest of the payload has changed. It&apos;s safe to retry on any timeout
              or connection error without checking whether the first attempt actually landed.
            </p>
            <p>
              The 24-hour window isn&apos;t permanent: the same <code className="rounded bg-muted px-1 py-0.5 font-mono text-xs">external_id</code> sent
              again a week later creates a brand-new contact rather than being treated as a duplicate forever
              — it&apos;s meant to absorb retry storms, not to be a permanent unique key. If you omit{" "}
              <code className="rounded bg-muted px-1 py-0.5 font-mono text-xs">external_id</code> entirely, no
              deduplication happens at all and every request creates a new contact.
            </p>
          </Section>

          {/* Rate limits */}
          <Section id="rate-limits" title="Rate limits">
            <p>
              Limits are per-tenant (shared across every key on your account, not per-key) and scale with
              your plan:
            </p>
            <div className="mt-4 overflow-x-auto rounded-lg border border-border">
              <table className="w-full text-left text-sm">
                <thead className="bg-muted/60 font-mono text-xs uppercase tracking-wide text-muted-foreground">
                  <tr>
                    <th className="px-4 py-2 font-medium">Plan</th>
                    <th className="px-4 py-2 font-medium">Price</th>
                    <th className="px-4 py-2 font-medium">Requests / minute</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-border">
                  <tr>
                    <td className="px-4 py-2 text-foreground">Starter</td>
                    <td className="px-4 py-2 font-mono text-xs text-muted-foreground">$29/mo</td>
                    <td className="px-4 py-2 font-mono text-foreground">60</td>
                  </tr>
                  <tr>
                    <td className="px-4 py-2 text-foreground">Growth</td>
                    <td className="px-4 py-2 font-mono text-xs text-muted-foreground">$79/mo</td>
                    <td className="px-4 py-2 font-mono text-foreground">300</td>
                  </tr>
                  <tr>
                    <td className="px-4 py-2 text-foreground">Pro</td>
                    <td className="px-4 py-2 font-mono text-xs text-muted-foreground">$149/mo</td>
                    <td className="px-4 py-2 font-mono text-foreground">1,000</td>
                  </tr>
                </tbody>
              </table>
            </div>
            <p className="mt-3 text-xs text-muted-foreground">
              An account without an active subscription falls back to the Starter limit. These figures are
              the ones actually enforced today — flagged internally as provisional pending a full billing
              spec, so treat them as current, not contractually fixed.
            </p>
            <p className="mt-3">
              Every response — success or failure — carries{" "}
              <code className="rounded bg-muted px-1 py-0.5 font-mono text-xs">X-RateLimit-Limit</code> and{" "}
              <code className="rounded bg-muted px-1 py-0.5 font-mono text-xs">X-RateLimit-Remaining</code>{" "}
              headers. Going over the limit returns <code className="rounded bg-muted px-1 py-0.5 font-mono text-xs">429</code> with
              a <code className="rounded bg-muted px-1 py-0.5 font-mono text-xs">Retry-After</code> header (seconds
              until you can send another request) — never a silent drop.
            </p>
          </Section>

          {/* Endpoint */}
          <Section id="endpoint" title="POST /api/v1/contacts">
            <p>Creates a contact and enrolls it in the review-request drip campaign.</p>

            <h3 className="mt-6 font-heading text-lg font-semibold text-foreground">Request fields</h3>
            <SchemaTable
              rows={[
                { field: "name", type: "string", required: "Required", notes: "Up to 255 characters." },
                {
                  field: "phone",
                  type: "string",
                  required: "Required without email",
                  notes: "Up to 32 characters. At least one of phone or email must be present.",
                },
                {
                  field: "email",
                  type: "string",
                  required: "Required without phone",
                  notes: "Must be a valid email address, up to 255 characters.",
                },
                {
                  field: "external_id",
                  type: "string",
                  required: "Optional",
                  notes: "Up to 255 characters. See Idempotency above.",
                },
              ]}
            />

            <h3 className="mt-6 font-heading text-lg font-semibold text-foreground">Response fields</h3>
            <SchemaTable
              rows={[
                { field: "id", type: "integer", required: "—", notes: "The contact's ReviewEngine id." },
                { field: "tenant_id", type: "uuid", required: "—", notes: "Your account id." },
                { field: "campaign_id", type: "integer", required: "—", notes: "The drip campaign this contact was enrolled in — the same one CSV import and quick-add use." },
                { field: "name", type: "string", required: "—", notes: "" },
                { field: "phone", type: "string | null", required: "—", notes: "" },
                { field: "email", type: "string | null", required: "—", notes: "" },
                { field: "status", type: "string", required: "—", notes: "Starts as pending; advances as the drip campaign runs." },
                { field: "source", type: "string", required: "—", notes: "Always webhook for contacts created here." },
                { field: "external_id", type: "string | null", required: "—", notes: "Echoes what you sent, if anything." },
                { field: "consent_at", type: "timestamp", required: "—", notes: "When consent was recorded — set at creation for this source." },
                { field: "created_at / updated_at", type: "timestamp", required: "—", notes: "" },
              ]}
            />

            <h3 className="mt-8 font-heading text-lg font-semibold text-foreground">Example: creating a contact</h3>
            <p>A new lead, with an <code className="rounded bg-muted px-1 py-0.5 font-mono text-xs">external_id</code> tying it back to a CRM record.</p>
            <CodeBlock
              label="Request"
              code={curl(base, "58|your-api-key-here", {
                name: "Priya Shah",
                phone: "+14155550142",
                email: "priya@example.com",
                external_id: "crm-lead-48213",
              })}
            />
            <CodeBlock
              label="201 Created"
              code={pretty({
                data: {
                  campaign_id: 9,
                  name: "Priya Shah",
                  phone: "+14155550142",
                  email: "priya@example.com",
                  status: "pending",
                  source: "webhook",
                  external_id: "crm-lead-48213",
                  consent_at: "2026-08-05T07:39:13.000000Z",
                  tenant_id: "b8d076a9-601f-43f1-8b0c-78b1bb5849e0",
                  updated_at: "2026-08-05T07:39:13.000000Z",
                  created_at: "2026-08-05T07:39:13.000000Z",
                  id: 202,
                },
              })}
            />

            <h3 className="mt-8 font-heading text-lg font-semibold text-foreground">Example: a retried request</h3>
            <p>
              The same <code className="rounded bg-muted px-1 py-0.5 font-mono text-xs">external_id</code> sent
              again minutes later — even with a different name in the payload — returns the original contact
              untouched, with <code className="rounded bg-muted px-1 py-0.5 font-mono text-xs">200</code> instead
              of <code className="rounded bg-muted px-1 py-0.5 font-mono text-xs">201</code>.
            </p>
            <CodeBlock
              label="Request"
              code={curl(base, "58|your-api-key-here", {
                name: "Priya Shah (retry)",
                phone: "+14155550142",
                external_id: "crm-lead-48213",
              })}
            />
            <CodeBlock
              label="200 OK — id and name are the original request's, not this one's"
              code={pretty({
                data: {
                  id: 202,
                  tenant_id: "b8d076a9-601f-43f1-8b0c-78b1bb5849e0",
                  campaign_id: 9,
                  name: "Priya Shah",
                  phone: "+14155550142",
                  email: "priya@example.com",
                  status: "pending",
                  consent_at: "2026-08-05T07:39:13.000000Z",
                  created_at: "2026-08-05T07:39:13.000000Z",
                  updated_at: "2026-08-05T07:39:13.000000Z",
                  source: "webhook",
                  external_id: "crm-lead-48213",
                },
              })}
            />
          </Section>

          {/* Errors */}
          <Section id="errors" title="Errors">
            <p>
              Every failure — on this endpoint or anywhere else in the ReviewEngine API — uses the same
              envelope:
            </p>
            <CodeBlock label="Error shape" code={`{ "error": "<machine-readable code>", "message": "<human-readable>", "fields": { ... } | null }`} />

            <ErrorCase
              status="401 Unauthorized"
              when="The key is missing, misspelled, or its grace period has elapsed."
              request={curl(base, "not-a-real-key", { name: "Nobody", phone: "+14155550100" })}
              response={pretty({ error: "unauthenticated", message: "Unauthenticated.", fields: null })}
            />

            <ErrorCase
              status="403 Forbidden"
              when={
                <>
                  The key is valid but isn&apos;t scoped for{" "}
                  <code className="rounded bg-muted px-1 py-0.5 font-mono text-xs">contacts:create</code> — for
                  example, a key generated for a different purpose. Checked before the rate limit, so a
                  wrongly-scoped key never burns your request budget.
                </>
              }
              request={curl(base, "59|a-key-scoped-for-something-else", { name: "Wrong Scope Test", phone: "+14155550188" })}
              response={pretty({ error: "forbidden", message: "You are not authorized to perform this action.", fields: null })}
            />

            <ErrorCase
              status="422 Unprocessable Content"
              when="The payload fails validation — missing name, or neither phone nor email present."
              request={curl(base, "58|your-api-key-here", { name: "No Contact Method" })}
              response={pretty({
                error: "validation_failed",
                message: "The phone field is required when email is not present. (and 1 more error)",
                fields: {
                  phone: ["The phone field is required when email is not present."],
                  email: ["The email field is required when phone is not present."],
                },
              })}
            />

            <ErrorCase
              status="429 Too Many Requests"
              when="Your plan's per-minute limit is exceeded."
              request={curl(base, "58|your-api-key-here", { name: "One Too Many", phone: "+14155559999" })}
              response={pretty({ error: "rate_limited", message: "Too many attempts. Please try again later.", fields: null })}
              headers={`Retry-After: 57\nX-RateLimit-Limit: 60\nX-RateLimit-Remaining: 0`}
            />
          </Section>

          {/* Try it */}
          <Section id="try-it" title="Try it">
            <TryItPanel />
          </Section>
        </main>
      </div>
    </div>
  );
}

function Section({ id, title, children }: { id: string; title: string; children: React.ReactNode }) {
  return (
    <section id={id} className="mt-12 scroll-mt-10 border-t border-border pt-10 first:mt-10 first:border-t-0">
      <h2 className="font-heading text-xl font-semibold text-foreground">{title}</h2>
      <div className="mt-4 flex flex-col gap-4 text-sm leading-relaxed text-foreground [&_p]:text-foreground">
        {children}
      </div>
    </section>
  );
}

function SchemaTable({
  rows,
}: {
  rows: { field: string; type: string; required: string; notes: string }[];
}) {
  return (
    <div className="mt-3 overflow-x-auto rounded-lg border border-border">
      <table className="w-full text-left text-sm">
        <thead className="bg-muted/60 font-mono text-xs uppercase tracking-wide text-muted-foreground">
          <tr>
            <th className="px-4 py-2 font-medium">Field</th>
            <th className="px-4 py-2 font-medium">Type</th>
            <th className="px-4 py-2 font-medium">Required</th>
            <th className="px-4 py-2 font-medium">Notes</th>
          </tr>
        </thead>
        <tbody className="divide-y divide-border">
          {rows.map((row) => (
            <tr key={row.field}>
              <td className="px-4 py-2 font-mono text-xs text-foreground">{row.field}</td>
              <td className="px-4 py-2 font-mono text-xs text-muted-foreground">{row.type}</td>
              <td className="px-4 py-2 text-xs text-muted-foreground">{row.required}</td>
              <td className="px-4 py-2 text-xs text-muted-foreground">{row.notes}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function ErrorCase({
  status,
  when,
  request,
  response,
  headers,
}: {
  status: string;
  when: React.ReactNode;
  request: string;
  response: string;
  headers?: string;
}) {
  return (
    <div className="mt-6 flex flex-col gap-3 rounded-lg border border-border p-4">
      <div className="flex items-center gap-2">
        <span className="rounded-full bg-destructive/10 px-2 py-0.5 font-mono text-xs font-medium text-destructive">
          {status}
        </span>
      </div>
      <p className="text-sm text-muted-foreground">{when}</p>
      <CodeBlock label="Request" code={request} />
      {headers && <CodeBlock label="Response headers" code={headers} />}
      <CodeBlock label="Response body" code={response} />
    </div>
  );
}

function curl(base: string, apiKey: string, body: Record<string, string>): string {
  return [
    `curl -X POST ${base}/api/v1/contacts \\`,
    `  -H "Authorization: Bearer ${apiKey}" \\`,
    `  -H "Content-Type: application/json" \\`,
    `  -d '${JSON.stringify(body)}'`,
  ].join("\n");
}

function pretty(value: unknown): string {
  return JSON.stringify(value, null, 2);
}
