import type { Metadata } from "next";
import Link from "next/link";

import { ContactForm } from "./ContactForm";
import { getContactPrefill } from "./actions";

export const metadata: Metadata = {
  title: "Contact",
  description: "Get in touch with ReviewEngine — no account needed.",
};

/**
 * Public marketing-site contact form (frontend/DESIGN.md's "Marketing:
 * generous whitespace, single-column reading width for copy sections").
 * No login required — reachable by a prospect who hasn't signed up
 * (.claude/CLAUDE.md's own class of unauthenticated public route:
 * /quick/{token}, /team/invite/{token}, etc.). A logged-in tenant visiting
 * anyway gets their name/email pre-filled (getContactPrefill) — a
 * convenience, not a requirement; the page never checks or redirects on
 * auth state otherwise.
 *
 * Deliberately no physical address anywhere on this page — email/form
 * only, same "don't fabricate a trust signal that doesn't reflect
 * reality" standard the rest of the marketing site holds to (see
 * SocialProof.tsx's empty-testimonials comment).
 */
export default async function ContactPage() {
  const prefill = await getContactPrefill();

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

      <main className="mx-auto max-w-md px-6 py-16">
        <h1 className="font-heading text-2xl font-semibold text-foreground">Contact us</h1>
        <p className="mt-2 text-sm text-muted-foreground">
          Questions about ReviewEngine, or need help with your account? Send us a message and we&apos;ll reply by
          email.
        </p>

        <div className="mt-8">
          <ContactForm initialName={prefill?.name ?? ""} initialEmail={prefill?.email ?? ""} />
        </div>
      </main>
    </div>
  );
}
