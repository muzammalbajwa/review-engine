import { GuestQuickAddForm } from "./GuestQuickAddForm";
import { getQuickAddFormInfo } from "./actions";

/**
 * .claude/CLAUDE.md quick-add: the public, no-login link
 * (reviewengine.com/quick/{tenant_token}). Deliberately not wrapped in
 * AppShell — there's no session, no sidebar makes sense, and the person
 * scanning a QR code at a job site needs exactly one thing on screen: the
 * form. The business name (not the token) is the trust signal that
 * confirms they're on the right link.
 */
export default async function QuickAddLinkPage({
  params,
}: {
  params: Promise<{ token: string }>;
}) {
  const { token } = await params;
  const result = await getQuickAddFormInfo(token);

  return (
    <main className="flex min-h-screen items-center justify-center p-8">
      <div className="w-full max-w-sm">
        {!result.ok ? (
          <FormUnavailable status={result.status} message={result.message} />
        ) : (
          <>
            <h1 className="mb-1 text-lg font-semibold">Add a customer for {result.data.business_name}</h1>
            <p className="mb-6 text-sm text-muted-foreground">
              Just finished a job? Add them here and their review request goes out.
            </p>
            <GuestQuickAddForm token={token} />
          </>
        )}

        <p className="mt-10 text-center text-xs text-muted-foreground">ReviewEngine</p>
      </div>
    </main>
  );
}

function FormUnavailable({ status, message }: { status: number; message: string }) {
  const text =
    status === 404
      ? "This link isn't active. Double-check the URL, or ask the business for a fresh one."
      : status === 429
        ? message
        : "This form isn't available right now. Try again in a moment.";

  return (
    <p role="alert" className="rounded-lg border border-destructive/30 bg-destructive/10 p-4 text-sm text-destructive">
      {text}
    </p>
  );
}
