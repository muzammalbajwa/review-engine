import { ArrowLeft } from "lucide-react";
import Link from "next/link";

import { apiFetch } from "@/lib/api";
import { AcceptInviteForm } from "./AcceptInviteForm";

type InviteLookup = { tenant_name: string; email: string };

/**
 * Public — the invited person has no account and no session yet (same
 * "no requireToken() here" shape as /register). Landed on from the link
 * in TeamInviteReceived's email.
 */
export default async function TeamInvitePage({ params }: { params: Promise<{ token: string }> }) {
  const { token } = await params;
  const result = await apiFetch<InviteLookup>(`/team/invite/${token}`, { skipAuth: true });

  return (
    <main className="relative flex min-h-screen items-center justify-center p-8">
      <Link
        href="/"
        className="absolute top-6 left-6 flex items-center gap-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground"
      >
        <ArrowLeft className="size-4" aria-hidden="true" />
        Back to home
      </Link>
      <div className="w-full max-w-sm rounded-lg border border-border p-6">
        {result.ok ? (
          <>
            <h1 className="mb-1 text-lg font-semibold">Join {result.data.tenant_name}</h1>
            <p className="mb-6 text-sm text-muted-foreground">
              You&apos;ve been invited to join their ReviewEngine team.
            </p>
            <AcceptInviteForm token={token} email={result.data.email} />
          </>
        ) : (
          <>
            <h1 className="mb-1 text-lg font-semibold">This invite isn&apos;t valid</h1>
            <p className="text-sm text-muted-foreground">
              {result.status === 404
                ? "This invite link is invalid or has expired. Ask whoever invited you to send a new one."
                : result.message}
            </p>
          </>
        )}
      </div>
    </main>
  );
}
