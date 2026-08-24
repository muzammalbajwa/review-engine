import { ChevronDown, HardHat, PaintRoller, Scissors, Sparkles, Wrench, Zap } from "lucide-react";
import type { Metadata } from "next";
import Link from "next/link";

import { DashboardPreview } from "@/components/marketing/DashboardPreview";
import { PricingCard } from "@/components/marketing/PricingCard";
import { RevealOnScroll } from "@/components/marketing/RevealOnScroll";
import { SocialProof, type Testimonial } from "@/components/marketing/SocialProof";
import { staggerReveal } from "@/components/marketing/staggerReveal";
import { SinglePathLine } from "@/components/SinglePathLine";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { logout } from "@/app/(app)/settings/actions";
import { getToken } from "@/lib/session";

// Copy approved in the Step 1 review pass (SEO meta section) — not
// independently rewritten here. `title.absolute` bypasses the root
// layout's "%s | ReviewEngine" template: this string is already the
// complete, final title, not a page name to be suffixed.
const TITLE = "ReviewEngine — Get Google reviews without risking your profile";
const DESCRIPTION =
  "Automatic Google review requests with no gating — every customer gets the same link. Built-in compliance checker keeps your Business Profile safe from suspension.";

export const metadata: Metadata = {
  title: { absolute: TITLE },
  description: DESCRIPTION,
  openGraph: {
    title: TITLE,
    description: DESCRIPTION,
    url: "/",
    siteName: "ReviewEngine",
    type: "website",
  },
  twitter: {
    card: "summary_large_image",
    title: TITLE,
    description: DESCRIPTION,
  },
};

const TRADES = [
  { icon: HardHat, label: "Roofers" },
  { icon: Wrench, label: "Plumbers" },
  { icon: Zap, label: "Electricians" },
  { icon: Scissors, label: "Salons" },
  { icon: PaintRoller, label: "Painters" },
  { icon: Sparkles, label: "Cleaners" },
] as const;

// No pilot customers yet. Do NOT fill this with placeholder names/quotes
// to preview the design — SocialProof (components/marketing/SocialProof.tsx)
// renders nothing until this is non-empty, and that emptiness is the
// honest, correct state right now. Fill it in once real testimonials
// exist; nothing else needs to change.
const TESTIMONIALS: Testimonial[] = [];

const FAQS = [
  {
    question: "Is this against Google's rules?",
    answer:
      "No — the opposite. Every customer gets the exact same review request, with no way to filter who sees it based on how happy they are. That's not a setting you turn on. It's how the product works, and it's the reason your profile stays safe.",
  },
  {
    question: "Do my customers see anything unusual?",
    answer:
      "No. They get a normal text or email with a link to leave a Google review — the same link every other customer gets, whether the job went perfectly or not.",
  },
  {
    question: "What happens when a bad review comes in?",
    answer:
      "You see it right away, and we draft a reply for you to review before it goes out. Nothing about the review process treats an unhappy customer differently — the reply is the only place a human touch comes in, and that's your choice, not automatic.",
  },
  {
    question: "Do I need to be technical to set this up?",
    answer:
      "No. Connect your Google Business Profile, upload a spreadsheet of past customers, and you're running. Most businesses never touch the advanced settings.",
  },
] as const;

export default async function HomePage() {
  // Session cookie is httpOnly, read server-side only (.claude/SECURITY.md
  // #3) — same "presence is enough, no extra verification round-trip"
  // treatment the app already gives getIsAdmin()'s cookie elsewhere. This
  // is a UI hint (which header CTA to show), not an access gate: /login
  // and /register both already redirect to /dashboard themselves if a
  // token exists, so this was never a security gap — just a static
  // header that never checked session state at all, showing "Log in" to
  // an already-logged-in visitor.
  const token = await getToken();
  const isLoggedIn = token !== null;

  return (
    <main className="flex flex-col">
      <TopBar isLoggedIn={isLoggedIn} />
      <Hero />
      <TradesStrip />
      <NoGatingSection />
      <HowItWorks />
      <SocialProof testimonials={TESTIMONIALS} />
      <Pricing />
      <Faq />
      <FinalCta />
      <Footer />
    </main>
  );
}

const NAV_LINK_CLASS =
  "hidden rounded-sm text-sm font-medium text-foreground outline-none hover:underline focus-visible:ring-2 focus-visible:ring-ring/50 focus-visible:ring-offset-2 sm:inline";

function TopBar({ isLoggedIn }: { isLoggedIn: boolean }) {
  return (
    <header className="sticky top-0 z-40 flex items-center justify-between border-b border-border bg-background/95 px-5 py-4 backdrop-blur-sm sm:px-8">
      <span className="font-heading text-lg font-semibold text-foreground">ReviewEngine</span>
      <nav className="flex items-center gap-4 sm:gap-6">
        <a href="#pricing" className={NAV_LINK_CLASS}>
          Pricing
        </a>
        <a href="#faq" className={NAV_LINK_CLASS}>
          FAQ
        </a>
        <Link href="/docs/api" className={NAV_LINK_CLASS}>
          API Docs
        </Link>
        {isLoggedIn ? (
          <form action={logout}>
            <button type="submit" className={NAV_LINK_CLASS}>
              Log out
            </button>
          </form>
        ) : (
          <Link href="/login" className={NAV_LINK_CLASS}>
            Log in
          </Link>
        )}
        <Button size="sm" className="max-sm:min-h-11" render={<Link href="/register" />} nativeButton={false}>
          Start free
        </Button>
      </nav>
    </header>
  );
}

function Hero() {
  return (
    <section className="flex flex-col items-center gap-10 px-5 py-14 text-center sm:px-8 sm:py-20">
      <RevealOnScroll className="flex flex-col items-center gap-10">
        <div className="flex max-w-2xl flex-col items-center gap-5">
          <h1 className="text-balance font-heading text-4xl leading-tight font-semibold text-foreground sm:text-5xl">
            Get more Google reviews.
            <br />
            Never risk your profile to get them.
          </h1>
          <p className="max-w-lg text-lg text-muted-foreground">
            Every customer gets the exact same review request — happy or not. No gating, no
            cherry-picking who gets asked. That&apos;s not a setting you can turn off. It&apos;s how
            the product works.
          </p>
          {/* hover:scale/active:scale scoped to this one CTA via className,
              not buttonVariants itself — that's a shared primitive used on
              every screen in the product; the marketing-page-specific
              "deliberate" hover/press feel belongs here, not globally. */}
          <Button
            size="lg"
            className="mt-2 transition-transform duration-150 hover:scale-[1.03] active:scale-[0.97]"
            render={<Link href="/register" />}
            nativeButton={false}
          >
            Start free
          </Button>
          <p className="text-xs text-muted-foreground">No credit card to try it.</p>
        </div>

        <DashboardPreview />
      </RevealOnScroll>
    </section>
  );
}

/**
 * The "built for the trades" identity moment: a quiet, understated
 * recognition beat, not a features grid or a stock-photo hero. Built
 * from the app's existing icon vocabulary (lucide-react, already used
 * throughout the product — Button, DashboardPreview, SinglePathLine, the
 * FAQ chevron) rather than a new illustration pipeline: an honest,
 * purpose-curated set for this specific moment, not a claim of
 * bespoke-drawn icons. Chosen to name the actual audience (roofer,
 * plumber, electrician, salon, painter, cleaner) instead of generic
 * "teams" or "businesses" SaaS language.
 */
function TradesStrip() {
  return (
    <section className="border-t border-border bg-card px-5 py-10 sm:px-8">
      <RevealOnScroll className="mx-auto flex max-w-3xl flex-col items-center gap-6">
        <p className="font-mono text-xs font-medium tracking-wide text-muted-foreground uppercase">
          Built for the trades
        </p>
        <ul className="flex flex-wrap items-center justify-center gap-x-8 gap-y-5">
          {TRADES.map((trade) => (
            <li key={trade.label} className="flex flex-col items-center gap-2">
              <span className="flex size-10 items-center justify-center rounded-full bg-muted text-foreground">
                <trade.icon className="size-5" strokeWidth={1.75} aria-hidden="true" />
              </span>
              <span className="text-xs text-muted-foreground">{trade.label}</span>
            </li>
          ))}
        </ul>
      </RevealOnScroll>
    </section>
  );
}

function NoGatingSection() {
  return (
    <section className="border-t border-border bg-card px-5 py-14 sm:px-8 sm:py-20">
      <div className="mx-auto flex max-w-3xl flex-col gap-8">
        <RevealOnScroll className="flex flex-col gap-4">
          <p className="font-mono text-xs font-medium tracking-wide text-primary uppercase">
            The part everyone else retrofits, we never had
          </p>
          <h2 className="font-heading text-2xl font-semibold text-foreground sm:text-3xl">
            Why this can&apos;t get your profile banned
          </h2>
          <p className="max-w-prose text-foreground">
            Some review tools quietly let a business send happy customers a review link — and steer
            unhappy ones somewhere else instead, like a private feedback form. That&apos;s called{" "}
            <strong className="font-semibold">review gating</strong>, and it&apos;s against Google&apos;s
            policies. Google and the FTC have started enforcing against it directly: a profile caught
            doing it can be suspended.
          </p>
          <p className="max-w-prose text-foreground">
            This product doesn&apos;t have a setting for that. Every customer who finishes a job gets
            the exact same review request, sent the exact same way, no matter what they thought of the
            work. There&apos;s no code path that reads how happy someone is and routes them
            differently — nothing to turn off, because there&apos;s nothing there to turn off.
          </p>
        </RevealOnScroll>

        <Card className="gap-6 bg-background p-6 sm:p-8">
          <SinglePathLine
            revealOnScroll
            steps={[
              { label: "Job finished", icon: "check" },
              { label: "Review request sent", icon: "send" },
              { label: "Review posted", icon: "star" },
            ]}
          />
          <p className="text-center font-mono text-xs text-muted-foreground">
            One path. Every customer. No fork.
          </p>
        </Card>
      </div>
    </section>
  );
}

function HowItWorks() {
  const steps = [
    {
      title: "Connect your Google Business Profile",
      body: "Sign in with Google once. We only ever post to the profile you connect.",
    },
    {
      title: "Import your customers",
      body: "Upload a spreadsheet of past customers, or add new ones as jobs finish.",
    },
    {
      title: "We handle the rest",
      body: "Requests go out on a compliant schedule, and replies get drafted for you when reviews come in.",
    },
  ];

  return (
    <section className="px-5 py-14 sm:px-8 sm:py-20">
      <RevealOnScroll className="mx-auto flex max-w-3xl flex-col gap-10">
        <h2 className="text-center font-heading text-2xl font-semibold text-foreground sm:text-3xl">
          How it works
        </h2>
        <ol className="flex flex-col gap-8 sm:flex-row sm:gap-6">
          {steps.map((step, i) => {
            const stagger = staggerReveal(i);
            return (
              <li
                key={step.title}
                style={stagger.style}
                className={`flex flex-1 flex-col gap-2 ${stagger.className}`}
              >
                <span className="font-heading text-2xl font-semibold text-primary">{i + 1}</span>
                <h3 className="font-heading text-lg font-semibold text-foreground">{step.title}</h3>
                <p className="text-sm text-muted-foreground">{step.body}</p>
              </li>
            );
          })}
        </ol>
      </RevealOnScroll>
    </section>
  );
}

function Pricing() {
  return (
    <section id="pricing" className="scroll-mt-20 border-t border-border bg-card px-5 py-14 sm:px-8 sm:py-20">
      <RevealOnScroll className="mx-auto flex max-w-4xl flex-col gap-10">
        <div className="flex flex-col items-center gap-2 text-center">
          <h2 className="font-heading text-2xl font-semibold text-foreground sm:text-3xl">Pricing</h2>
          <p className="max-w-md text-muted-foreground">
            One plan, everything included — automatic requests, AI-drafted replies, and the
            compliance checker built in.
          </p>
        </div>

        <PricingCard />
      </RevealOnScroll>
    </section>
  );
}

function Faq() {
  return (
    <section id="faq" className="scroll-mt-20 px-5 py-14 sm:px-8 sm:py-20">
      <RevealOnScroll className="mx-auto flex max-w-2xl flex-col gap-8">
        <h2 className="text-center font-heading text-2xl font-semibold text-foreground sm:text-3xl">
          Questions
        </h2>
        {/* Native <details>/<summary>: full keyboard support and
            expanded/collapsed state announced to screen readers for free,
            no JS needed. The default marker triangle is suppressed in
            favor of the rotating chevron below, so there's exactly one
            open/closed indicator, not two.
            py-5 lives on <summary> itself, not the wrapping <details> —
            a real 375px Playwright check found the old layout's actual
            tap target was only ~24px tall (the padding sat on a parent
            that isn't part of <summary>'s own hit area, so it looked
            spacious but wasn't clickable); this makes the visible
            padding and the real tap target the same box.
            group/item (a NAMED group), not the plain `group` every other
            group-first:/group-last: usage on this page uses: this whole
            block already sits inside RevealOnScroll's own unnamed
            `.group` wrapper, and since that wrapper is the section's only
            child (so it's simultaneously :first-child AND :last-child of
            its own parent), a plain group-first:/group-last: matches
            THROUGH it via Tailwind's `:where(.group) *` selector — every
            row ended up with both pt-0 and pb-0, not just the real
            first/last one. Naming this group is what scopes first/last
            to "first/last among FAQS", not "first/last group ancestor
            found anywhere above". */}
        <div className="flex flex-col">
          {FAQS.map((faq) => (
            <details key={faq.question} className="group/item border-b border-border last:border-0">
              <summary className="flex cursor-pointer list-none items-center justify-between gap-4 rounded-sm py-5 font-heading text-base font-semibold text-foreground outline-none marker:content-none group-first/item:pt-0 group-last/item:pb-0 hover:text-primary focus-visible:ring-2 focus-visible:ring-ring/50 focus-visible:ring-offset-2 [&::-webkit-details-marker]:hidden">
                {/* h3, not a styled span: DESIGN.md's base layer applies
                    font-heading to every h1-h6 automatically, and Tailwind's
                    preflight resets a heading's font-size/weight/margin to
                    `inherit`/0 — so this inherits summary's own text-base
                    font-semibold with zero extra classes and zero visual
                    change. The actual win is structural: a screen-reader
                    user navigating by heading can now jump straight between
                    FAQ questions, not just between the page's five h2
                    sections. */}
                <h3>{faq.question}</h3>
                <ChevronDown
                  className="size-4 shrink-0 text-muted-foreground transition-transform duration-200 group-open/item:rotate-180 group-open/item:text-primary"
                  aria-hidden="true"
                />
              </summary>
              <p className="mt-3 pb-5 text-sm text-muted-foreground group-last/item:pb-0">{faq.answer}</p>
            </details>
          ))}
        </div>
      </RevealOnScroll>
    </section>
  );
}

function FinalCta() {
  return (
    <section className="border-t border-border bg-card px-5 py-14 text-center sm:px-8 sm:py-20">
      <RevealOnScroll className="mx-auto flex max-w-lg flex-col items-center gap-5">
        <h2 className="font-heading text-2xl font-semibold text-foreground sm:text-3xl">
          Get more reviews. Keep your profile safe.
        </h2>
        <Button size="lg" render={<Link href="/register" />} nativeButton={false}>
          Start free
        </Button>
      </RevealOnScroll>
    </section>
  );
}

const FOOTER_LINK_CLASS = "text-sm text-muted-foreground outline-none hover:text-foreground hover:underline focus-visible:ring-2 focus-visible:ring-ring/50 focus-visible:ring-offset-2 rounded-sm";
const FOOTER_HEADING_CLASS = "text-xs font-medium tracking-wide text-muted-foreground uppercase";

function Footer() {
  const year = new Date().getFullYear();

  return (
    <footer className="border-t border-border bg-card px-5 py-12 sm:px-8">
      <div className="mx-auto flex max-w-4xl flex-col gap-10 sm:flex-row sm:justify-between">
        <div className="flex max-w-xs flex-col gap-2">
          <span className="font-heading text-lg font-semibold text-foreground">ReviewEngine</span>
          <p className="text-sm text-muted-foreground">
            Compliant Google review collection for local businesses.
          </p>
        </div>

        <nav aria-label="Footer" className="grid grid-cols-2 gap-x-8 gap-y-8 sm:grid-cols-3">
          <div className="flex flex-col gap-3">
            <p className={FOOTER_HEADING_CLASS}>Product</p>
            <a href="#pricing" className={FOOTER_LINK_CLASS}>
              Pricing
            </a>
            <a href="#faq" className={FOOTER_LINK_CLASS}>
              FAQ
            </a>
            <Link href="/docs/api" className={FOOTER_LINK_CLASS}>
              API Docs
            </Link>
            <Link href="/login" className={FOOTER_LINK_CLASS}>
              Log in
            </Link>
          </div>

          <div className="flex flex-col gap-3">
            <p className={FOOTER_HEADING_CLASS}>Legal</p>
            <Link href="/privacy" className={FOOTER_LINK_CLASS}>
              Privacy Policy
            </Link>
            <Link href="/terms" className={FOOTER_LINK_CLASS}>
              Terms of Service
            </Link>
          </div>

          <div className="flex flex-col gap-3">
            <p className={FOOTER_HEADING_CLASS}>Contact</p>
            <a href="mailto:hello@example.com" className={FOOTER_LINK_CLASS}>
              hello@example.com
            </a>
          </div>
        </nav>
      </div>

      <div className="mx-auto mt-10 max-w-4xl border-t border-border pt-6 text-xs text-muted-foreground">
        © {year} ReviewEngine. All rights reserved.
      </div>
    </footer>
  );
}
