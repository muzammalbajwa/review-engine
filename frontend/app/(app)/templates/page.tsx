import { AppShell } from "@/components/AppShell";
import { TemplatesTour } from "@/components/TemplatesTour";
import { apiFetch } from "@/lib/api";
import { requireToken } from "@/lib/session";
import type { TourStatus } from "@/lib/tours";
import { TemplateEditor } from "./TemplateEditor";
import type { TemplateData } from "./actions";

/**
 * .claude/FRONTEND.md screen 2: "Template editor — 3 steps, live
 * compliance check with inline pass/block." Server Component: fetches the
 * tenant's (auto-provisioned-if-new) templates from the Laravel API only,
 * then hands them to the client-side editor for the interactive part.
 */
export default async function TemplatesPage() {
  await requireToken();

  const [result, tourResult] = await Promise.all([
    apiFetch<TemplateData[]>("/templates"),
    apiFetch<TourStatus>("/tours/status"),
  ]);
  const alreadySeenTour = !tourResult.ok || tourResult.data.tours_seen.templates_editor === true;

  return (
    <AppShell>
      <main className="flex justify-center p-8">
        <div className="w-full max-w-2xl">
          <div className="mb-6 flex items-center gap-1.5">
            <h1 className="text-lg font-semibold">Message templates</h1>
            <TemplatesTour alreadySeen={alreadySeenTour} />
          </div>

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
            <TemplateEditor initialTemplates={result.data} />
          )}
        </div>
      </main>
    </AppShell>
  );
}
