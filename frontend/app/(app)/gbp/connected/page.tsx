import Link from "next/link";
import { redirect } from "next/navigation";

import { AppShell } from "@/components/AppShell";
import { requireToken } from "@/lib/session";
import { getOnboardingStatus, markGbpStepDone } from "../../onboarding/actions";

/**
 * Landing page for GbpController::callback's success redirect
 * (`{$frontendUrl}/gbp/connected`) — fixed by the backend, not something
 * this step's onboarding integration can change. When this lands mid
 * guided-onboarding, mark the GBP step done and send the tenant back into
 * the wizard automatically rather than stranding them on a standalone
 * page they'd have to navigate away from manually.
 */
export default async function GbpConnectedPage() {
  await requireToken();

  const status = await getOnboardingStatus();

  if (status !== null && !status.completed && !status.gbp_step_done) {
    await markGbpStepDone();
    redirect("/onboarding");
  }

  return (
    <AppShell>
      <main className="flex justify-center p-8">
        <div className="w-full max-w-xl">
          <div className="rounded-lg border border-success/40 bg-success/10 p-6">
            <h1 className="text-lg font-semibold text-foreground">Google Business Profile connected</h1>
            <p className="mt-2 text-sm text-foreground">
              Your reviews will start syncing shortly.
            </p>
            <div className="mt-4 flex gap-4 text-sm">
              <Link
                href="/gbp/connect"
                className="rounded font-medium text-primary underline-offset-4 hover:underline focus-visible:outline-none focus-visible:ring-3 focus-visible:ring-ring/50"
              >
                View connection status
              </Link>
              <Link
                href="/reviews"
                className="rounded font-medium text-primary underline-offset-4 hover:underline focus-visible:outline-none focus-visible:ring-3 focus-visible:ring-ring/50"
              >
                Go to reviews
              </Link>
            </div>
          </div>
        </div>
      </main>
    </AppShell>
  );
}
