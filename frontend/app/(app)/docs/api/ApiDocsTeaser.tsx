import { CheckCircle2, Link2, RefreshCw, Send, Workflow } from "lucide-react";
import Link from "next/link";

import { RevealOnScroll } from "@/components/marketing/RevealOnScroll";
import { staggerReveal } from "@/components/marketing/staggerReveal";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";

const SCENARIOS = [
  {
    before: "Right now: once a month you export a spreadsheet of finished jobs and upload it as a CSV — hoping you didn't miss anyone since the last one.",
    after: "Connected: there's no spreadsheet. Every finished job flows over the moment it's marked done. Nothing to remember to export.",
  },
  {
    before: "Right now: a job wraps up on-site, so you or your crew stop and type the customer into the quick-add form on your phone before it slips your mind.",
    after: "Connected: your CRM or field-service tool already knows the job is done — it hands the customer straight to ReviewEngine. Nobody stops to type anything in.",
  },
  {
    before: "Right now: you finish a job, and later — if you remember — you log in and add the customer by hand.",
    after: "Connected: you mark the job complete in the tool you already use for that. That's the whole step. The review request is already on its way.",
  },
] as const;

const FLOW_STEPS = [
  { icon: CheckCircle2, label: "You mark the job done" },
  { icon: Workflow, label: "Your tool hands off the customer" },
  { icon: Send, label: "The review request goes out" },
] as const;

/**
 * What a non-subscriber sees at /docs/api instead of the real content —
 * see ApiDocsContent's own docblock for why this alone is what makes the
 * gate real (the full page never runs for this visitor, so there's
 * nothing sensitive in the response to find via view-source or the
 * network tab, not just something hidden by CSS/JS after the fact).
 *
 * Sells the outcome, not the implementation: no endpoint paths, no
 * request/response payloads, no code — those stay behind the gate in
 * ApiDocsContent. This page's job is to make a non-technical reader
 * understand what actually changes for their business, using only
 * capabilities that are real today (the webhook endpoint behind
 * ApiDocsContent, and the Zapier/Make connectors referenced in
 * settings/IntegrationLinksSection.tsx) — nothing hypothetical.
 *
 * One teaser, two CTA states: a visitor with no account yet gets sent to
 * start a free trial (which — per the Step 2 gate's own definition,
 * status = 'trialing' counts as a subscriber — unlocks this page
 * immediately, not after a separate upsell); a logged-in tenant whose
 * trial lapsed or subscription was canceled gets sent straight to
 * billing instead of being told to register again.
 */
export function ApiDocsTeaser({ isLoggedIn }: { isLoggedIn: boolean }) {
  return (
    <div className="min-h-screen bg-background">
      <header className="border-b border-border">
        <div className="mx-auto flex max-w-6xl items-center justify-between px-6 py-4">
          <Link href="/" className="font-heading text-base font-semibold text-foreground">
            ReviewEngine
          </Link>
          <Link
            href={isLoggedIn ? "/dashboard" : "/login"}
            className="text-sm font-medium text-muted-foreground hover:text-foreground"
          >
            {isLoggedIn ? "Back to dashboard" : "Log in"}
          </Link>
        </div>
      </header>

      {/* Hero */}
      <section className="px-5 py-14 sm:px-8 sm:py-20">
        <RevealOnScroll className="mx-auto flex max-w-2xl flex-col items-start gap-5">
          <h1 className="text-balance font-heading text-3xl font-semibold text-foreground sm:text-4xl">
            Connect the tools you already use. Review requests start themselves.
          </h1>
          <p className="max-w-xl text-lg text-muted-foreground">
            No more uploading a spreadsheet once a month, and no more stopping mid-job to type a customer
            in by hand. Connect your CRM, invoicing tool, or field-service software once — every completed
            job sends its customer straight into the same review-request drip campaign a CSV import or the
            quick-add form would use.
          </p>
          <CtaButtons isLoggedIn={isLoggedIn} />
        </RevealOnScroll>
      </section>

      {/* Real, not theoretical */}
      <section className="border-t border-border bg-card px-5 py-14 sm:px-8 sm:py-20">
        <RevealOnScroll className="mx-auto flex max-w-2xl flex-col gap-8">
          <div className="flex flex-col gap-4">
            <p className="font-mono text-xs font-medium tracking-wide text-primary uppercase">
              This already works
            </p>
            <h2 className="font-heading text-2xl font-semibold text-foreground sm:text-3xl">
              Not a webhook you have to build yourself
            </h2>
            <p className="max-w-prose text-foreground">
              We&apos;ve built and use real Zapier and Make.com integrations for this — ready-made connectors,
              not a generic API you&apos;re left to wire up alone. If the tool you run your business on connects
              to Zapier or Make (most CRMs, invoicing tools, and field-service software already do), you can
              point it at ReviewEngine with no code.
            </p>
            <p className="max-w-prose text-muted-foreground">
              Prefer to build it yourself, or your tool doesn&apos;t have a Zapier/Make connection? The same
              endpoint those integrations use is documented in full once you&apos;re in.
            </p>
          </div>

          <Card className="gap-6 bg-background p-6 sm:p-8">
            <div className="flex flex-col items-stretch gap-4 sm:flex-row sm:items-center sm:justify-between">
              {FLOW_STEPS.map((step, i) => {
                const stagger = staggerReveal(i);
                const Icon = step.icon;
                return (
                  <div key={step.label} className="contents">
                    <div style={stagger.style} className={`flex items-center gap-3 sm:flex-col sm:text-center ${stagger.className}`}>
                      <span className="flex size-10 shrink-0 items-center justify-center rounded-full bg-primary text-primary-foreground">
                        <Icon className="size-5" strokeWidth={2} aria-hidden="true" />
                      </span>
                      <span className="text-sm font-medium text-foreground sm:max-w-28">{step.label}</span>
                    </div>
                    {i < FLOW_STEPS.length - 1 && (
                      <div className="hidden h-px flex-1 bg-border sm:block" aria-hidden="true" />
                    )}
                  </div>
                );
              })}
            </div>
            <p className="flex items-center gap-2 text-center font-mono text-xs text-muted-foreground">
              <RefreshCw className="size-3.5 shrink-0" aria-hidden="true" />
              Safe to retry — if the same job ever gets marked done twice, ReviewEngine recognizes it and
              won&apos;t create a duplicate customer.
            </p>
          </Card>
        </RevealOnScroll>
      </section>

      {/* Before / after */}
      <section className="px-5 py-14 sm:px-8 sm:py-20">
        <RevealOnScroll className="mx-auto flex max-w-2xl flex-col gap-10">
          <h2 className="text-center font-heading text-2xl font-semibold text-foreground sm:text-3xl">
            What actually changes
          </h2>
          <div className="flex flex-col gap-6">
            {SCENARIOS.map((scenario, i) => {
              const stagger = staggerReveal(i);
              return (
                <div
                  key={scenario.before}
                  style={stagger.style}
                  className={`grid grid-cols-1 gap-3 sm:grid-cols-2 ${stagger.className}`}
                >
                  <div className="flex flex-col gap-1.5 rounded-lg border border-border p-4">
                    <p className="font-mono text-xs font-medium tracking-wide text-muted-foreground uppercase">
                      Right now
                    </p>
                    <p className="text-sm text-foreground">{scenario.before}</p>
                  </div>
                  <div className="flex flex-col gap-1.5 rounded-lg border border-primary/30 bg-primary/5 p-4">
                    <p className="flex items-center gap-1.5 font-mono text-xs font-medium tracking-wide text-primary uppercase">
                      <Link2 className="size-3.5 shrink-0" aria-hidden="true" />
                      Connected
                    </p>
                    <p className="text-sm text-foreground">{scenario.after}</p>
                  </div>
                </div>
              );
            })}
          </div>
        </RevealOnScroll>
      </section>

      {/* Subscriber note + final CTA */}
      <section className="border-t border-border bg-card px-5 py-14 text-center sm:px-8 sm:py-20">
        <RevealOnScroll className="mx-auto flex max-w-lg flex-col items-center gap-5">
          <h2 className="font-heading text-2xl font-semibold text-foreground sm:text-3xl">
            {isLoggedIn ? "Full API access is part of every subscription" : "Full API access is part of every plan — including your trial"}
          </h2>
          <p className="text-muted-foreground">
            {isLoggedIn
              ? "Your account doesn't currently have an active subscription. Subscribe to unlock the full API reference, your API key, and the live try-it panel."
              : "There's no separate add-on or upgrade for this. The moment your free trial starts, the full API reference, your API key, and the live try-it panel unlock too."}
          </p>
          <CtaButtons isLoggedIn={isLoggedIn} size="lg" />
        </RevealOnScroll>
      </section>
    </div>
  );
}

function CtaButtons({ isLoggedIn, size = "default" }: { isLoggedIn: boolean; size?: "default" | "lg" }) {
  if (isLoggedIn) {
    return (
      <Button size={size} render={<Link href="/settings?tab=billing" />} nativeButton={false}>
        Subscribe to unlock full API access
      </Button>
    );
  }

  return (
    <div className="flex flex-wrap items-center gap-3">
      <Button size={size} render={<Link href="/register" />} nativeButton={false}>
        Start your free trial to unlock full API access
      </Button>
      <Link
        href="/login"
        className="rounded-sm text-sm font-medium text-foreground underline-offset-4 outline-none hover:underline focus-visible:ring-2 focus-visible:ring-ring/50"
      >
        Already have an account? Log in
      </Link>
    </div>
  );
}
