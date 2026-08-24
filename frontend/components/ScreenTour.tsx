"use client";

import { CircleHelp } from "lucide-react";
import { useState } from "react";
import { useRouter } from "next/navigation";
import type { Step } from "react-joyride";

import { markScreenTourSeen } from "@/lib/tours";
import { Tour } from "./Tour";

/**
 * The shared orchestrator every contextual, per-screen tour (Contacts
 * import, Templates, Reviews, Analytics — see their own *Tour.tsx
 * wrappers) renders through. Same "no duplicated logic, no per-screen
 * one-offs" reasoning WelcomeTour already established for the shared
 * <Tour /> primitive itself — this is that same principle one level up:
 * the run-state/mark-seen/refresh/replay mechanics are written once
 * here, not copy-pasted into each screen's own tour component. A
 * screen's own *Tour.tsx only ever supplies content (its `steps`, built
 * from real page state) and its `tourKey`.
 *
 * Also renders the "?" replay affordance itself — each screen's page.tsx
 * places this component inline next to its own <h1> (Tour's actual
 * overlay still renders through react-joyride's own portal regardless of
 * where in the tree this sits, so moving it next to the heading only
 * changes where the *button* appears, not the tour's behavior). One
 * component, one place the button and the tour instance share state,
 * rather than wiring a separate context just to connect a button
 * somewhere else in the page to this run state.
 *
 * No cross-page trigger complexity here unlike WelcomeTour/SidebarShell
 * — a contextual tour is always rendered directly on the one screen it
 * describes, so auto-start only ever needs to check "not already seen"
 * on mount; a manual replay just sets `run` true directly, same tab,
 * same mount, no navigation involved.
 *
 * `alreadySeen` comes from the page's own GET /tours/status read
 * (tours_seen[tourKey]) — server-side, per-user, same
 * has_completed_welcome_tour/tours_seen table the welcome tour uses, not
 * a separate tracking mechanism. Replaying (auto or manual) always marks
 * seen against $request->user() — the caller's own authenticated
 * session — never anything address-able by another user, so one
 * person's replay can never re-trigger anything for a teammate on the
 * same tenant (see TourController::completeScreen, and
 * tests/Feature/Tours/TourProgressTest.php's own CRITICAL per-user
 * isolation test).
 */
export function ScreenTour({
  tourKey,
  steps,
  alreadySeen,
}: {
  tourKey: string;
  steps: Step[];
  alreadySeen: boolean;
}) {
  const router = useRouter();
  // Lazy initializer, not a useEffect: unlike SidebarShell's welcome-tour
  // trigger (which has to react to isDashboard/hasCompletedWelcomeTour
  // changing *within* an already-mounted instance, since navigating
  // between pages there doesn't remount it), a contextual tour's page is
  // a fresh mount every time it's visited — "should this run on mount"
  // really is initial state, computed once, not something that needs to
  // re-derive later. Also sidesteps the react-hooks/set-state-in-effect
  // lint rule entirely rather than working around it.
  const [run, setRun] = useState(() => !alreadySeen && steps.length > 0);

  async function handleFinish() {
    setRun(false);
    // A replay that's already marked seen (the common case — you only
    // replay something you've already been through) still calls this:
    // harmless, same idempotent write TourProgressTest.php's own "twice
    // is a no-op" test already covers, and it's what makes finishing a
    // fresh, never-seen run behave identically to finishing a replay —
    // one code path, not two.
    await markScreenTourSeen(tourKey);
    router.refresh();
  }

  if (steps.length === 0) {
    return null;
  }

  return (
    <>
      <button
        type="button"
        onClick={() => setRun(true)}
        aria-label="Show me around this screen"
        title="Show me around this screen"
        className="rounded-full p-1 text-muted-foreground outline-none transition-colors hover:bg-muted hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring/50"
      >
        <CircleHelp className="size-4" aria-hidden="true" />
      </button>
      <Tour steps={steps} run={run} onFinish={handleFinish} />
    </>
  );
}
