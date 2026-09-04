import type { Metadata } from "next";
import Link from "next/link";

import { LegalReviewNote } from "@/components/legal/LegalReviewNote";

export const metadata: Metadata = {
  title: "Privacy Policy",
  description: "What ReviewEngine collects, why, who we share it with, and how to exercise your rights over it.",
};

const LAST_UPDATED = "2026-08-19";

const NAV = [
  { href: "#who-this-covers", label: "Who this covers" },
  { href: "#what-we-collect", label: "What we collect" },
  { href: "#how-we-use-it", label: "How we use it" },
  { href: "#who-we-share-with", label: "Who we share it with" },
  { href: "#retention", label: "Retention" },
  { href: "#your-rights", label: "Your rights" },
  { href: "#cookies", label: "Cookies" },
  { href: "#international-transfer", label: "International transfer" },
  { href: "#security", label: "Security" },
  { href: "#children", label: "Children" },
  { href: "#changes", label: "Changes" },
  { href: "#contact", label: "Contact" },
] as const;

/**
 * Grounded against .claude/CLAUDE.md, SECURITY.md, DATABASE.md, BILLING.md
 * and the real code paths that actually move data (SendReviewRequest,
 * ClaudeReplyDrafter, ComplianceChecker, GbpController, session.ts) — not
 * generic SaaS boilerplate. Every LegalReviewNote below marks a real gap
 * (no verified hosting region/jurisdiction, no self-service deletion flow
 * exists yet) rather than a confident guess standing in for one.
 */
export default function PrivacyPage() {
  return (
    <div className="min-h-screen bg-background">
      <header className="border-b border-border">
        <div className="mx-auto flex max-w-6xl items-center justify-between px-6 py-4">
          <Link href="/" className="font-heading text-base font-semibold text-foreground">
            ReviewEngine
          </Link>
          <Link href="/" className="text-sm font-medium text-muted-foreground hover:text-foreground">
            Back to ReviewEngine
          </Link>
        </div>
      </header>

      <div className="mx-auto flex max-w-6xl gap-10 px-6 py-10">
        <nav aria-label="On this page" className="hidden w-52 shrink-0 lg:block">
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

        <main className="min-w-0 max-w-2xl flex-1">
          <h1 className="font-heading text-3xl font-semibold text-foreground">Privacy Policy</h1>
          <p className="mt-2 text-sm text-muted-foreground">Last updated: {LAST_UPDATED}</p>

          <p className="mt-6 text-sm leading-relaxed text-foreground">
            ReviewEngine (&quot;we,&quot; &quot;us&quot;) helps local service businesses (&quot;tenants,&quot;
            &quot;you,&quot; if you&apos;re a customer of ours) collect Google reviews from their own
            customers (&quot;end-customers&quot;) without gating requests by sentiment. This policy
            explains what data moves through the product, why, and what rights you have over it —
            drafted against what the product actually does, not a generic template.
          </p>

          <LegalReviewNote>
            this draft was written by an engineering session grounded in the real codebase, not by a
            lawyer. Every clause below should be reviewed by counsel qualified in your jurisdiction
            before this page is relied on as your actual privacy policy.
          </LegalReviewNote>

          <Section id="who-this-covers" title="Who this covers">
            <p>
              Two different kinds of people&apos;s data pass through ReviewEngine, and this policy covers
              both:
            </p>
            <ul className="list-disc pl-5">
              <li>
                <strong className="text-foreground">Tenants</strong> — the business that signs up, pays
                for, and logs into ReviewEngine. If you&apos;re reading this as someone with a
                ReviewEngine account, you&apos;re a tenant.
              </li>
              <li>
                <strong className="text-foreground">End-customers</strong> — the tenant&apos;s own
                customers, whose name/phone/email a tenant sends us so we can send them a review request
                on the tenant&apos;s behalf. An end-customer never creates a ReviewEngine account and
                never logs in.
              </li>
            </ul>
            <p>
              For end-customer data, the tenant is the <strong className="text-foreground">data
              controller</strong> (they decide whose data to send us and why) and ReviewEngine acts as a{" "}
              <strong className="text-foreground">data processor</strong> on the tenant&apos;s behalf. See{" "}
              <a href="#your-rights" className="text-primary underline-offset-4 hover:underline">
                Your rights
              </a>{" "}
              for what that means practically if you&apos;re an end-customer trying to exercise a right
              over your own data.
            </p>
          </Section>

          <Section id="what-we-collect" title="What we collect">
            <h3 className="mt-2 font-heading text-lg font-semibold text-foreground">
              End-customer data
            </h3>
            <p>Name, phone number, and/or email address. Collected however a tenant chooses to add you:</p>
            <ul className="list-disc pl-5">
              <li>a CSV spreadsheet the tenant uploads,</li>
              <li>the tenant (or their team) typing you in one at a time after a job,</li>
              <li>a public &quot;quick-add&quot; link or QR code the tenant shares with staff, or</li>
              <li>
                an automated integration the tenant connects — our webhook API, or a Zapier/Make
                connector built on top of it.
              </li>
            </ul>
            <p>
              We also record a consent timestamp at the moment you&apos;re added, and engagement state on
              the review-request messages we send you (whether a message was sent, whether you clicked
              the link, whether you left a review) — this exists purely so we don&apos;t message you twice
              for the same step.
            </p>
            <p>
              <strong className="text-foreground">
                We currently only ever message you by email.
              </strong>{" "}
              A phone number may be collected (a tenant might have it for their own records, or it may
              be required by their CRM export), but as of this writing ReviewEngine has no SMS sending
              capability — a phone-only contact with no email on file is never actually messaged.
            </p>

            <h3 className="mt-6 font-heading text-lg font-semibold text-foreground">Tenant data</h3>
            <ul className="list-disc pl-5">
              <li>Business name.</li>
              <li>
                Team member accounts: name, email, a bcrypt-hashed password (we never see or store your
                plaintext password), role (owner or member), and permissions.
              </li>
              <li>Sender identity — the &quot;from&quot; email address your review requests send from.</li>
              <li>
                Billing status (plan, subscription status, trial dates, billing interval) — see{" "}
                <a href="#who-we-share-with" className="text-primary underline-offset-4 hover:underline">
                  Who we share it with
                </a>{" "}
                for what we do and don&apos;t receive from our payment processor.
              </li>
              <li>
                If you connect a Google Business Profile: an OAuth access/refresh token (encrypted at
                rest), your GBP location ID, and the review link we use to build review requests.
              </li>
              <li>
                If you generate a webhook API key: we store only a hash of it, never the plaintext key
                itself, after the moment it&apos;s shown to you once.
              </li>
            </ul>
          </Section>

          <Section id="how-we-use-it" title="How we use it">
            <p>
              End-customer data is used for exactly one purpose: sending you the review request(s) a
              tenant&apos;s campaign is configured to send, and knowing not to send you a step you&apos;ve
              already been sent or already acted on. We do not use it for our own marketing, we do not
              sell it, and — this is a permanent product constraint, not a preference —{" "}
              <strong className="text-foreground">
                there is no code path that sends you a different message or link based on how happy you
                are
              </strong>
              . Every end-customer of a given tenant gets the same review link.
            </p>
            <p>
              Tenant data is used to operate the product for you: authenticate you, run your review
              campaigns, process billing, check your message templates for compliance with Google&apos;s
              review policies before they can be sent, provide support, and maintain security/audit logs
              of account activity.
            </p>
          </Section>

          <Section id="who-we-share-with" title="Who we share it with">
            <p>
              We don&apos;t sell your data or anyone else&apos;s. We share data with a small set of
              sub-processors, each doing one specific job:
            </p>
            <ProcessorTable
              rows={[
                {
                  name: "Resend",
                  purpose: "Delivers the review-request emails your end-customers receive, and our own transactional emails to you.",
                  data: "The recipient email address and the message content for a given send.",
                },
                {
                  name: "Google",
                  purpose: "Only if you connect your Google Business Profile — reads/replies to your reviews and provides the review link we send end-customers.",
                  data: "OAuth access to your Business Profile (scope: business.manage), your business's public reviews.",
                },
                {
                  name: "Anthropic (Claude API)",
                  purpose: "Two uses, both in service of keeping you compliant with Google's review policies: (1) checking your message templates against Google's rules before they can be saved, and (2) drafting a suggested reply to an incoming review for you to review before posting.",
                  data: "For (1): the template text you write, which never contains end-customer PII. For (2): the review's star rating, the reviewer's public display name (as shown on Google), and the review text — all already public on Google. We never send Claude an end-customer's phone number or email.",
                },
                {
                  name: "Zapier / Make.com",
                  purpose: "Only for tenants who explicitly connect one of our Zapier or Make integrations to their own account.",
                  data: "Whatever contact fields (name/phone/email/external ID) the tenant's own Zap or Scenario is configured to send us — this flows through Zapier's/Make's own infrastructure under the tenant's own account with them, governed by their own privacy policies.",
                },
              ]}
            />
            <LegalReviewNote>
              this table previously listed Lemon Squeezy as our payment sub-processor. That integration has
              been removed while we switch payment providers — no payment processor is currently live. Add
              the new processor&apos;s row back here (with an accurate purpose/data description for however
              it actually collects and stores payment info) before re-enabling paid subscriptions.
            </LegalReviewNote>
            <p>
              We may also disclose data if legally required to (a court order or valid legal process), or
              to protect the rights, property, or safety of ReviewEngine, our tenants, or the public.
            </p>
          </Section>

          <Section id="retention" title="How long we keep it">
            <p>
              Plainly, and matching what the product actually does today: we don&apos;t automatically
              delete data when a trial ends or a subscription lapses. If your 7-day trial expires (or a
              subscription is canceled), your account and all its data — contacts, templates, campaign
              history, reviews — stay fully visible to you. What changes is that you can&apos;t send{" "}
              <em>new</em> review requests until you subscribe; nothing already in your account is
              touched or removed.
            </p>
            <p>
              Beyond that, we don&apos;t currently run any automatic, scheduled deletion of tenant or
              end-customer data. Data is kept for as long as your account exists, unless you ask us to
              delete specific data or close your account (see{" "}
              <a href="#your-rights" className="text-primary underline-offset-4 hover:underline">
                Your rights
              </a>
              ).
            </p>
            <LegalReviewNote>
              there is no self-service &quot;delete my account&quot; or automated data-retention/purge
              pipeline built into the product yet — today, a deletion request is fulfilled manually by
              us on request, not by clicking a button in Settings. That&apos;s an honest description of
              the current product, not a hypothetical gap. Before this policy is relied on, counsel
              should confirm whether a stated retention period and/or a self-service deletion flow is
              required for the jurisdictions you operate in (GDPR&apos;s right to erasure expects this to
              be actionable &quot;without undue delay&quot;), and a commitment should only be made here
              that the product can actually keep.
            </LegalReviewNote>
          </Section>

          <Section id="your-rights" title="Your rights">
            <p>
              Depending on where you&apos;re located, you may have rights to access, correct, export, or
              delete your data, and to object to or restrict certain processing (this is standard under
              GDPR/UK GDPR for EU/UK residents, and under the CCPA/CPRA for California residents — see{" "}
              <a href="#international-transfer" className="text-primary underline-offset-4 hover:underline">
                International transfer
              </a>{" "}
              below).
            </p>
            <h3 className="mt-4 font-heading text-lg font-semibold text-foreground">If you&apos;re a tenant</h3>
            <p>
              You can update your business name and sender identity directly in Settings. For anything
              else — exporting your data, correcting something you can&apos;t edit yourself, or deleting
              your account and its data — email us (see{" "}
              <a href="#contact" className="text-primary underline-offset-4 hover:underline">
                Contact
              </a>
              ) and we&apos;ll act on it.
            </p>
            <h3 className="mt-4 font-heading text-lg font-semibold text-foreground">
              If you&apos;re an end-customer (you received a review request, but don&apos;t have a
              ReviewEngine account)
            </h3>
            <p>
              Because you never create an account with us, the fastest path is usually the business that
              sent you the request — they control what data about you gets sent to us and can ask us to
              correct or delete it. You&apos;re also welcome to email us directly at the address in{" "}
              <a href="#contact" className="text-primary underline-offset-4 hover:underline">
                Contact
              </a>{" "}
              and we&apos;ll either act on it ourselves or route it to the relevant tenant, whichever
              gets it resolved faster.
            </p>
          </Section>

          <Section id="cookies" title="Cookies and session data">
            <p>We use exactly two cookies, both set only when you&apos;re logged in as a tenant:</p>
            <ul className="list-disc pl-5">
              <li>
                <code className="rounded bg-muted px-1 py-0.5 font-mono text-xs">re_token</code> — your
                session token, so you stay logged in.
              </li>
              <li>
                <code className="rounded bg-muted px-1 py-0.5 font-mono text-xs">re_is_admin</code> — a
                UI hint for whether to show admin navigation. It has no security function on its own.
              </li>
            </ul>
            <p>
              Both are <code className="rounded bg-muted px-1 py-0.5 font-mono text-xs">httpOnly</code>,{" "}
              <code className="rounded bg-muted px-1 py-0.5 font-mono text-xs">Secure</code>, and{" "}
              <code className="rounded bg-muted px-1 py-0.5 font-mono text-xs">SameSite=Strict</code> —
              your session token is never readable by JavaScript and never stored in localStorage. We
              don&apos;t run any analytics, advertising, or third-party tracking cookies on this site.
            </p>
          </Section>

          <Section id="international-transfer" title="International data transfer">
            <p>
              ReviewEngine is built to serve tenants internationally, not just in one country — so this
              product expects EU, UK, and other international customers alongside US ones, meaning GDPR
              (and UK GDPR) applies to personal data from those tenants and their end-customers, and the
              CCPA/CPRA applies for California-based tenants and their end-customers.
            </p>
            <LegalReviewNote>
              this session doesn&apos;t have a verified answer for where ReviewEngine&apos;s
              infrastructure is physically hosted, where the company is legally domiciled, or what
              cross-border transfer mechanism (e.g. Standard Contractual Clauses) governs any transfer
              of EU/UK personal data outside the EEA/UK. Those are facts about your actual
              infrastructure and business entity, not something to infer from application code — they
              need to be supplied and reviewed by counsel before this section can make a concrete,
              accurate claim instead of this general statement.
            </LegalReviewNote>
          </Section>

          <Section id="security" title="Security">
            <p>We treat a breach of this data as an existential risk to the business, and build accordingly:</p>
            <ul className="list-disc pl-5">
              <li>Every tenant&apos;s data is isolated by two independent layers: application-level scoping and Postgres Row-Level Security enforced at the database itself, so even a bug in our application code can&apos;t leak one tenant&apos;s data into another&apos;s response.</li>
              <li>Google OAuth tokens are encrypted at rest.</li>
              <li>Passwords are hashed with bcrypt — we never store or log a plaintext password.</li>
              <li>All traffic runs over HTTPS with HSTS enabled.</li>
              <li>Every cross-tenant read by our own admin staff is logged in an audit trail.</li>
            </ul>
            <p>No system is perfectly secure, and we can&apos;t guarantee absolute security — but this is the real, built architecture, not an aspiration.</p>
          </Section>

          <Section id="children" title="Children's privacy">
            <p>
              ReviewEngine is a business-to-business product for local service businesses, and is not
              directed at or intended for use by children. We don&apos;t knowingly collect personal
              data from anyone under 16 (whether as a tenant&apos;s team member or an end-customer). If
              you believe a child&apos;s data has reached us, contact us and we&apos;ll delete it.
            </p>
          </Section>

          <Section id="changes" title="Changes to this policy">
            <p>
              If we make a material change to this policy, we&apos;ll update the &quot;Last updated&quot;
              date above and, for significant changes, notify tenants by email.
            </p>
          </Section>

          <Section id="contact" title="Contact">
            <p>
              Questions about this policy, or requests to access/correct/delete data, can be sent to{" "}
              <a href="mailto:support@reviewengine24.com" className="text-primary underline-offset-4 hover:underline">
                support@reviewengine24.com
              </a>
              .
            </p>
            <LegalReviewNote>
              consider whether GDPR requires naming a specific Data Protection Officer or EU/UK
              representative for your business.
            </LegalReviewNote>
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
      <div className="mt-4 flex flex-col gap-4 text-sm leading-relaxed text-foreground [&_ul]:flex [&_ul]:flex-col [&_ul]:gap-2 [&_p]:text-foreground">
        {children}
      </div>
    </section>
  );
}

function ProcessorTable({
  rows,
}: {
  rows: { name: string; purpose: string; data: string }[];
}) {
  return (
    <div className="mt-2 flex flex-col gap-4">
      {rows.map((row) => (
        <div key={row.name} className="rounded-lg border border-border p-4">
          <p className="font-heading text-base font-semibold text-foreground">{row.name}</p>
          <p className="mt-1 text-sm text-foreground">{row.purpose}</p>
          <p className="mt-2 text-xs text-muted-foreground">
            <span className="font-medium text-foreground">What they receive: </span>
            {row.data}
          </p>
        </div>
      ))}
    </div>
  );
}
