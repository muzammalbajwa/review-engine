import type { Metadata } from "next";
import Link from "next/link";

import { LegalReviewNote } from "@/components/legal/LegalReviewNote";

export const metadata: Metadata = {
  title: "Terms of Service",
  description: "The terms governing your use of ReviewEngine, including subscription billing and acceptable use.",
};

const LAST_UPDATED = "2026-08-19";

const NAV = [
  { href: "#acceptance", label: "Acceptance" },
  { href: "#the-service", label: "The service" },
  { href: "#eligibility", label: "Eligibility" },
  { href: "#accounts", label: "Accounts" },
  { href: "#billing", label: "Subscription & billing" },
  { href: "#acceptable-use", label: "Acceptable use" },
  { href: "#your-data", label: "Your content & data" },
  { href: "#third-party", label: "Third-party services" },
  { href: "#ip", label: "Intellectual property" },
  { href: "#warranty", label: "Disclaimers" },
  { href: "#liability", label: "Limitation of liability" },
  { href: "#termination", label: "Termination" },
  { href: "#law", label: "Governing law" },
  { href: "#changes", label: "Changes" },
  { href: "#contact", label: "Contact" },
] as const;

/**
 * Grounded against .claude/CLAUDE.md, BILLING.md, COMPLIANCE.md, and the
 * real state machine/pricing in config/plans.php and
 * app/Http/Middleware/RequireSendingAccess.php — not generic SaaS terms.
 * The no-gating clause under Acceptable Use is written as a condition of
 * use a tenant agrees to, not merely a description of how the product
 * happens to be built, per the brief.
 */
export default function TermsPage() {
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
          <h1 className="font-heading text-3xl font-semibold text-foreground">Terms of Service</h1>
          <p className="mt-2 text-sm text-muted-foreground">Last updated: {LAST_UPDATED}</p>

          <p className="mt-6 text-sm leading-relaxed text-foreground">
            These terms govern your use of ReviewEngine (&quot;the Service&quot;), operated by us
            (&quot;ReviewEngine,&quot; &quot;we,&quot; &quot;us&quot;). By creating an account or using the
            Service, you (&quot;you,&quot; the &quot;tenant&quot;) agree to these terms.
          </p>

          <LegalReviewNote>
            this draft was written by an engineering session grounded in the real product and pricing,
            not by a lawyer. It should be reviewed by counsel qualified in your jurisdiction before it
            is used as a binding contract with real customers.
          </LegalReviewNote>

          <Section id="acceptance" title="Acceptance of these terms">
            <p>
              You must read and agree to these terms, and our{" "}
              <Link href="/privacy" className="text-primary underline-offset-4 hover:underline">
                Privacy Policy
              </Link>
              , before using the Service. If you&apos;re agreeing on behalf of a business, you&apos;re
              confirming you have the authority to bind that business to these terms.
            </p>
          </Section>

          <Section id="the-service" title="What the Service does">
            <p>
              ReviewEngine helps a local service business collect Google reviews from its own past and
              current customers. You connect your Google Business Profile, import or send us your
              customers&apos; contact details, and the Service sends them a review request by email on a
              compliant schedule, drafts suggested replies to reviews that come in, and checks your
              message templates against Google&apos;s review policies before they can be used.
            </p>
          </Section>

          <Section id="eligibility" title="Eligibility">
            <p>
              The Service is offered to businesses and professionals for business use, not to
              individual consumers acting in a personal capacity. You must be at least 18 and able to
              form a binding contract to create an account.
            </p>
          </Section>

          <Section id="accounts" title="Accounts and security">
            <p>
              You&apos;re responsible for the security of your account credentials and for all activity
              under your account, including that of any team member you invite. Tell us immediately if
              you suspect unauthorized access. An account owner can invite team members with a limited
              (&quot;member&quot;) role that can&apos;t access billing, team management, or certain other
              owner-only areas — you&apos;re responsible for who you grant access to and at what level.
            </p>
          </Section>

          <Section id="billing" title="Subscription & billing">
            <p>
              ReviewEngine is offered as a single paid plan, billed either monthly or annually:{" "}
              <strong className="text-foreground">$20/month</strong>, or{" "}
              <strong className="text-foreground">$200/year</strong> (equivalent to two months free
              versus paying monthly). Prices are subject to change with reasonable advance notice to
              existing subscribers; a price change never applies retroactively to a period you&apos;ve
              already paid for.
            </p>
            <h3 className="mt-4 font-heading text-lg font-semibold text-foreground">Free trial</h3>
            <p>
              New accounts get a 7-day free trial with no credit card required. During the trial, and if
              it lapses without you subscribing, you retain full access to everything already in your
              account — your contacts, templates, campaign history, and reviews stay visible. What&apos;s
              paused is the ability to start sending <em>new</em> review requests until you subscribe.
              Nothing is deleted when a trial ends.
            </p>
            <h3 className="mt-4 font-heading text-lg font-semibold text-foreground">Payment processing</h3>
            <LegalReviewNote>
              this section previously named Lemon Squeezy as our merchant of record. That integration has
              been removed while we switch payment providers — paid subscriptions cannot currently be
              purchased or renewed through the product. Replace this section with the new processor&apos;s
              actual name and role (merchant of record vs. payment facilitator changes what we can honestly
              claim about who handles sales tax/VAT) before re-enabling paid subscriptions.
            </LegalReviewNote>
            <h3 className="mt-4 font-heading text-lg font-semibold text-foreground">
              Renewal & cancellation
            </h3>
            <p>
              Subscriptions renew automatically at the end of each billing period until canceled. You
              can cancel at any time from Settings → Billing; cancellation stops future charges but
              (consistent with the trial-expiry behavior above) does not delete your existing data —
              your account moves to a non-sending state, the same as a lapsed trial, until you
              resubscribe.
            </p>
            <LegalReviewNote>
              this draft doesn&apos;t state a refund policy (e.g., prorated refunds on cancellation,
              or a money-back window) because none is implemented in the product or decided on yet —
              add one deliberately rather than defaulting to &quot;no refunds&quot; here without that
              being an actual business decision.
            </LegalReviewNote>
          </Section>

          <Section id="acceptable-use" title="Acceptable use">
            <p>
              You agree to use the Service only for its intended purpose — collecting genuine reviews
              from your own real customers — and specifically agree NOT to:
            </p>
            <ul className="list-disc pl-5">
              <li>
                <strong className="text-foreground">
                  Attempt to gate, filter, or selectively route review requests based on a customer&apos;s
                  sentiment or expected rating (&quot;review gating&quot;).
                </strong>{" "}
                This is a material condition of your use of the Service, not merely a description of
                how it&apos;s built: every customer you add must receive the same review request and the
                same review link as every other customer, regardless of how you expect them to feel
                about your business. Review gating violates Google&apos;s review policies and, in the
                United States, the FTC&apos;s rule on fake and deceptive reviews — using the Service to
                attempt it, work around its absence of a gating feature, or ask us to build one is a
                breach of these terms and grounds for immediate termination.
              </li>
              <li>Ask a reviewer to name a specific staff member, or request a specific star rating, in a message template.</li>
              <li>Offer a discount, gift, refund, or other incentive in exchange for a review.</li>
              <li>Collect review requests via an on-premises kiosk or point-of-sale prompt.</li>
              <li>Upload contact data you don&apos;t have a legitimate basis to send a review request to.</li>
              <li>Use the Service to send spam, or any message unrelated to requesting a review of a real transaction.</li>
              <li>Attempt to bypass rate limits, reverse-engineer the Service, or interfere with its normal operation.</li>
              <li>Use another tenant&apos;s data, account, or API key without authorization.</li>
              <li>Use the Service in any way that violates applicable law.</li>
            </ul>
          </Section>

          <Section id="your-data" title="Your content & data">
            <p>
              You retain ownership of your business data and the end-customer contact data you send us.
              You grant us a limited license to process that data solely to provide the Service to you
              (see our{" "}
              <Link href="/privacy" className="text-primary underline-offset-4 hover:underline">
                Privacy Policy
              </Link>{" "}
              for the specifics of how and with whom). You&apos;re responsible for having the right to
              share your end-customers&apos; contact details with us for this purpose — for example, that
              they&apos;re real recent or past customers of your business, not a purchased or scraped
              list.
            </p>
          </Section>

          <Section id="third-party" title="Third-party services">
            <p>
              The Service integrates with third parties we don&apos;t control, and we&apos;re not
              responsible for their availability, accuracy, or policy changes:
            </p>
            <ul className="list-disc pl-5">
              <li>
                <strong className="text-foreground">Google Business Profile</strong> — connecting your
                profile requires you to comply with Google&apos;s own terms and review policies. If
                Google changes or restricts its API, or suspends your Business Profile for reasons
                outside our control, review syncing/posting may stop working through no fault of ours.
              </li>
              <li>
                <strong className="text-foreground">Zapier / Make.com</strong> — if you connect one of
                our integrations, your use of their platform is governed by their own terms, separate
                from these.
              </li>
              <li>
                <strong className="text-foreground">Anthropic (Claude API)</strong> — powers our
                compliance checker and AI-drafted reply suggestions. Suggested replies are exactly
                that — suggestions for you to review and approve before anything is posted publicly to
                your Business Profile; we don&apos;t post an AI-drafted reply without your action.
              </li>
            </ul>
          </Section>

          <Section id="ip" title="Intellectual property">
            <p>
              We own the Service, including its software, design, and default message templates. You
              may use compliant default templates as-is or edit them for your own account; nothing here
              grants you rights to our brand, code, or design beyond using the Service as intended.
            </p>
          </Section>

          <Section id="warranty" title="Disclaimers">
            <p>
              The Service is provided &quot;as is&quot; and &quot;as available.&quot; We don&apos;t
              warrant that it will be uninterrupted or error-free, that a specific number of reviews will
              result from using it, or that Google will never change a policy or API in a way that
              affects the Service. To the maximum extent permitted by law, we disclaim all implied
              warranties, including merchantability, fitness for a particular purpose, and
              non-infringement.
            </p>
          </Section>

          <Section id="liability" title="Limitation of liability">
            <p>
              To the maximum extent permitted by law, ReviewEngine won&apos;t be liable for any
              indirect, incidental, special, consequential, or punitive damages, or for lost profits or
              lost data, arising from your use of the Service. Our total liability for any claim
              relating to the Service is limited to the amount you paid us in the 12 months before the
              claim arose.
            </p>
            <LegalReviewNote>
              a liability cap and exclusion of consequential damages is standard, but the specific
              numbers/carve-outs above are a reasonable starting draft, not a number chosen for your
              actual risk profile or insurance coverage — counsel should confirm this against your
              actual liability insurance and jurisdiction&apos;s enforceability rules for this kind of
              clause (some jurisdictions don&apos;t allow disclaiming certain warranties at all).
            </LegalReviewNote>
          </Section>

          <Section id="termination" title="Termination">
            <p>
              You can cancel your subscription and stop using the Service at any time from Settings.
              We may suspend or terminate your account if you breach these terms — including the
              review-gating prohibition above — or if required by law.
            </p>
            <p>
              Consistent with our actual data-retention behavior (see{" "}
              <Link href="/privacy#retention" className="text-primary underline-offset-4 hover:underline">
                Privacy Policy → How long we keep it
              </Link>
              ), closing your account does not automatically and immediately erase your data — deletion
              is handled on request. If you want your data deleted on account closure, tell us and
              we&apos;ll act on it.
            </p>
          </Section>

          <Section id="law" title="Governing law">
            <LegalReviewNote>
              this session has no verified answer for what jurisdiction/governing law and dispute
              resolution process should be named here (arbitration vs. courts, which country/state,
              venue). BILLING.md notes the payment processor was switched specifically because the
              previous one doesn&apos;t support Pakistan-domiciled businesses, which may be relevant to
              where the operating entity is domiciled — but that&apos;s an inference from a billing
              decision, not a confirmed legal fact, and shouldn&apos;t be treated as one. This section
              needs the real, confirmed entity/jurisdiction filled in by you and reviewed by counsel
              before publishing.
            </LegalReviewNote>
          </Section>

          <Section id="changes" title="Changes to these terms">
            <p>
              If we make a material change, we&apos;ll update the &quot;Last updated&quot; date above
              and notify active subscribers by email. Continuing to use the Service after a change takes
              effect means you accept the updated terms.
            </p>
          </Section>

          <Section id="contact" title="Contact">
            <p>
              Questions about these terms can be sent to{" "}
              <a href="mailto:support@reviewengine24.com" className="text-primary underline-offset-4 hover:underline">
                support@reviewengine24.com
              </a>
              .
            </p>
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
