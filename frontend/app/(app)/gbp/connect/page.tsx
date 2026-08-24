import { AppShell } from "@/components/AppShell";
import { GbpConnectionCard } from "@/components/gbp/GbpConnectionCard";
import { apiFetch } from "@/lib/api";
import { requireToken } from "@/lib/session";
import type { GbpStatus } from "../actions";

/**
 * .claude/FRONTEND.md: Google Business Profile connection settings.
 * .claude/API.md: GBP connect/callback. Error codes here match exactly
 * what GbpController's callback redirects with — this page is the one
 * both the "connect" button and Google's own redirect land on.
 */
const ERROR_MESSAGES: Record<string, string> = {
  invalid_state:
    "That connection attempt expired or was already used. Please try connecting again.",
  oauth_failed: "Google couldn't complete the sign-in. Please try again.",
  no_refresh_token:
    "Google didn't grant lasting access to your account. Try connecting again and approve access when prompted.",
  no_location_found:
    "We couldn't find a Google Business Profile location on that account. Connect the Google account that manages your business listing.",
  connect_failed: "Something went wrong starting the connection. Please try again.",
};

export default async function GbpConnectPage({
  searchParams,
}: {
  searchParams: Promise<{ error?: string }>;
}) {
  await requireToken();
  const { error } = await searchParams;

  const result = await apiFetch<GbpStatus>("/gbp/status");

  return (
    <AppShell>
      <main className="flex justify-center p-8">
        <div className="w-full max-w-xl">
          <h1 className="mb-6 text-lg font-semibold">Google Business Profile</h1>

          {error && (
            <p
              role="alert"
              className="mb-4 rounded-lg border border-destructive/30 bg-destructive/10 p-3 text-sm text-destructive"
            >
              {ERROR_MESSAGES[error] ?? "Something went wrong connecting your Google account. Please try again."}
            </p>
          )}

          {!result.ok ? (
            <p
              role="alert"
              className="rounded-lg border border-destructive/30 bg-destructive/10 p-4 text-sm text-destructive"
            >
              {result.status === 401
                ? "Your session has expired. Log in again to continue."
                : result.message}
            </p>
          ) : (
            <GbpConnectionCard status={result.data} />
          )}
        </div>
      </main>
    </AppShell>
  );
}
