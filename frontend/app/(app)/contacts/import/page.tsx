import Link from "next/link";

import { AppShell } from "@/components/AppShell";
import { ContactsImportTour } from "@/components/ContactsImportTour";
import { apiFetch } from "@/lib/api";
import { requireToken } from "@/lib/session";
import type { TourStatus } from "@/lib/tours";
import { ImportWizard } from "./ImportWizard";

export default async function ImportContactsPage() {
  // .claude/FRONTEND.md: "Protected routes check session server-side
  // before render." Redirects to /login on its own if there's no session.
  await requireToken();

  const tourResult = await apiFetch<TourStatus>("/tours/status");
  const alreadySeenTour = !tourResult.ok || tourResult.data.tours_seen.contacts_import === true;

  return (
    <AppShell>
      <main className="flex justify-center p-8">
        <div className="w-full max-w-3xl">
          <div className="mb-6 flex items-baseline justify-between">
            <div className="flex items-center gap-1.5">
              <h1 className="text-lg font-semibold">Import contacts</h1>
              <ContactsImportTour alreadySeen={alreadySeenTour} />
            </div>
            <Link
              href="/contacts/quick-add"
              data-tour="quick-add-link"
              className="text-sm font-medium text-primary underline-offset-4 hover:underline"
            >
              Just finished one job? Add them one at a time &rarr;
            </Link>
          </div>
          <ImportWizard />
        </div>
      </main>
    </AppShell>
  );
}
