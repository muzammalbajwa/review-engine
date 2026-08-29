import Link from "next/link";

import { apiFetch } from "@/lib/api";
import { requireToken } from "@/lib/session";

type PaymentLogStatus = "succeeded" | "failed" | "refunded";

type PaymentLog = {
  paddle_transaction_id: string;
  amount: string;
  currency: string;
  status: PaymentLogStatus;
  failure_reason: string | null;
  billing_interval: "monthly" | "annual" | null;
  occurred_at: string;
};

type Tenant = {
  id: string;
  name: string;
  type: "admin" | "customer";
  created_at: string;
  payment_logs: PaymentLog[];
};

export default async function AdminTenantDetailPage({
  params,
}: {
  params: Promise<{ id: string }>;
}) {
  await requireToken();
  const { id } = await params;

  const result = await apiFetch<Tenant>(`/admin/tenants/${id}`);

  return (
    <main className="flex justify-center p-8">
      <div className="w-full max-w-4xl">
        <Link
          href="/admin"
          className="mb-6 inline-block rounded text-sm font-medium text-muted-foreground hover:underline focus-visible:outline-none focus-visible:ring-3 focus-visible:ring-ring/50"
        >
          &larr; All tenants
        </Link>

        {!result.ok ? (
          <p
            role="alert"
            className="rounded-lg border border-destructive/30 bg-destructive/10 p-4 text-sm text-destructive"
          >
            {result.status === 403
              ? "You don't have admin access to view this page."
              : result.status === 404
                ? "That tenant doesn't exist."
                : result.message}
          </p>
        ) : (
          <div className="flex flex-col gap-6">
            <div className="rounded-lg border border-border">
              <h1 className="border-b border-border px-6 py-4 text-lg font-semibold">
                {result.data.name}
              </h1>
              <dl className="grid grid-cols-1 gap-4 px-6 py-4 sm:grid-cols-2">
                <div>
                  <dt className="text-xs font-medium text-muted-foreground">Type</dt>
                  <dd className="mt-1 text-sm capitalize text-foreground">{result.data.type}</dd>
                </div>
                <div>
                  <dt className="text-xs font-medium text-muted-foreground">Created</dt>
                  <dd className="mt-1 text-sm text-foreground">
                    <time dateTime={result.data.created_at}>
                      {new Date(result.data.created_at).toLocaleString()}
                    </time>
                  </dd>
                </div>
                <div>
                  <dt className="text-xs font-medium text-muted-foreground">Tenant ID</dt>
                  <dd className="mt-1 font-mono text-xs text-foreground">{result.data.id}</dd>
                </div>
              </dl>
              <div className="border-t border-border px-6 py-4">
                <Link
                  href={`/admin/audit-log?tenant_id=${result.data.id}`}
                  className="rounded text-sm font-medium text-primary underline-offset-4 hover:underline focus-visible:outline-none focus-visible:ring-3 focus-visible:ring-ring/50"
                >
                  View audit log for this tenant &rarr;
                </Link>
              </div>
            </div>

            <PaymentHistory logs={result.data.payment_logs} />
          </div>
        )}
      </div>
    </main>
  );
}

// .claude/DESIGN.md: destructive red is reserved for a compliance-blocked
// state or a real error. A payment_logs 'failed' row is exactly that — a
// specific charge attempt that already, actually failed (distinct from a
// tenant's ongoing 'past_due' status elsewhere in /admin, which stays gold
// because Paddle is still retrying and nothing is broken yet — see
// TenantsTable.tsx's own note on that distinction). 'refunded' gets the
// same gold/warning treatment as past_due: worth an admin's attention,
// nothing wrong.
const STATUS_LABELS: Record<PaymentLogStatus, string> = {
  succeeded: "Succeeded",
  failed: "Failed",
  refunded: "Refunded",
};

const STATUS_PILL_CLASSES: Record<PaymentLogStatus, string> = {
  succeeded: "bg-primary/10 text-primary",
  failed: "bg-destructive/10 text-destructive",
  refunded: "bg-warning/15 text-warning-foreground border border-warning/40",
};

function PaymentHistory({ logs }: { logs: PaymentLog[] }) {
  const failedCount = logs.filter((log) => log.status === "failed").length;

  return (
    <div className="rounded-lg border border-border">
      <div className="flex items-center justify-between gap-4 border-b border-border px-6 py-4">
        <h2 className="text-base font-semibold text-foreground">Payment history</h2>
        {failedCount > 0 && (
          <span className="inline-flex items-center gap-1.5 rounded-full bg-destructive/10 px-2.5 py-1 text-xs font-medium text-destructive">
            <span className="inline-block h-1.5 w-1.5 shrink-0 rounded-full bg-destructive" aria-hidden="true" />
            {failedCount} failed {failedCount === 1 ? "payment" : "payments"}
          </span>
        )}
      </div>

      {logs.length === 0 ? (
        <p className="px-6 py-4 text-sm text-muted-foreground">No payment attempts recorded for this tenant yet.</p>
      ) : (
        <div className="overflow-x-auto">
          <table className="w-full text-left text-sm">
            <caption className="sr-only">
              Every payment attempt for {logs.length > 0 ? "this tenant" : ""}, most recent first
            </caption>
            <thead>
              <tr className="border-b border-border bg-muted/50 text-muted-foreground">
                <th scope="col" className="px-6 py-2 font-medium">
                  Date
                </th>
                <th scope="col" className="px-4 py-2 font-medium">
                  Amount
                </th>
                <th scope="col" className="px-4 py-2 font-medium">
                  Status
                </th>
                <th scope="col" className="px-4 py-2 font-medium">
                  Failure reason
                </th>
              </tr>
            </thead>
            <tbody>
              {logs.map((log) => {
                const isFailed = log.status === "failed";

                return (
                  <tr
                    key={`${log.paddle_transaction_id}-${log.status}`}
                    className={`border-b border-border last:border-0 ${
                      isFailed ? "border-l-4 border-l-destructive bg-destructive/5" : ""
                    }`}
                  >
                    <td className="px-6 py-2 text-muted-foreground">
                      <time dateTime={log.occurred_at} className="font-mono text-xs">
                        {new Date(log.occurred_at).toLocaleString()}
                      </time>
                    </td>
                    <td className="px-4 py-2 font-mono text-xs text-foreground">
                      {formatAmount(log.amount, log.currency)}
                    </td>
                    <td className="px-4 py-2">
                      <span
                        className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${STATUS_PILL_CLASSES[log.status]}`}
                      >
                        {STATUS_LABELS[log.status]}
                      </span>
                    </td>
                    <td className={`px-4 py-2 ${isFailed ? "font-medium text-destructive" : "text-muted-foreground"}`}>
                      {log.failure_reason ? humanizeFailureReason(log.failure_reason) : "—"}
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}

// amount is Paddle's own minor-unit string ("2000" = 20.00) — same
// convention Cashier's own `transactions` table stores it in.
function formatAmount(amount: string, currency: string): string {
  const cents = Number(amount);
  if (!Number.isFinite(cents)) return `${amount} ${currency}`;

  try {
    return new Intl.NumberFormat(undefined, { style: "currency", currency }).format(cents / 100);
  } catch {
    return `${(cents / 100).toFixed(2)} ${currency}`;
  }
}

// Paddle's own error_code vocabulary ("expired_card", "insufficient_funds")
// is precise but not written for a human skim — this is display-only, the
// raw code is still what's stored and returned by the API.
function humanizeFailureReason(reason: string): string {
  const words = reason.split("_");
  return words.map((word, i) => (i === 0 ? word.charAt(0).toUpperCase() + word.slice(1) : word)).join(" ");
}
