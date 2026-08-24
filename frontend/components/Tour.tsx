"use client";

import { useEffect } from "react";
import { EVENTS, Joyride, STATUS, type EventData, type Step } from "react-joyride";

/**
 * The one reusable tour primitive — every guided tour in this app (the
 * dashboard/nav welcome tour today, contextual per-screen tours later)
 * renders through this, never a bare <Joyride> with its own one-off
 * styling. Content (the `steps` array) is the caller's job; this is
 * purely the shared chrome: styling, keyboard/focus behavior, and the
 * finish/skip -> onFinish callback every tour needs regardless of what
 * it's actually explaining.
 *
 * react-joyride v3 (verified against the installed package's own
 * .d.mts, not assumed from the older v2 API — v3 is a full rewrite):
 * named export, `onEvent` instead of a `callback` prop, and per-tour
 * styling/behavior defaults live under the flat `options` prop
 * (primaryColor, textColor, showProgress, buttons, skipBeacon, etc.)
 * rather than a nested `styles.options.*`/top-level `showSkipButton`
 * shape. v3's own README lists "Focus trapping, keyboard navigation,
 * and ARIA support" as a first-class feature (`disableFocusTrap`
 * defaults to `false` — the trap is on by default, confirmed live: Tab
 * cycles only through the tooltip's own Skip/Back/Next buttons, never
 * leaking focus to the dimmed page behind the overlay). The tooltip
 * itself renders as a proper `role="alertdialog"` with `aria-modal`,
 * `aria-labelledby`/`aria-describedby` pointing at the title/content
 * (confirmed live via the rendered DOM, not assumed from docs) — real
 * screen-reader-correct markup, not just a visual approximation.
 *
 * ESCAPE IS DELIBERATELY NOT THE LIBRARY DEFAULT: `dismissKeyAction`'s
 * options are only `'close' | 'next' | 'replay' | false` — verified live
 * that the default (`'close'`) actually ADVANCES to the next step in
 * continuous mode, it does not exit the tour. That fails this app's own
 * "Escape exits" requirement, so it's disabled here (`dismissKeyAction:
 * false`) and reimplemented below as a real document-level listener that
 * calls the same onFinish() the Skip button uses — Escape and Skip are
 * the same action, not two different ones that happen to look similar.
 *
 * `options` colors read entirely through .claude/DESIGN.md's CSS custom
 * properties (globals.css) rather than hardcoded hex — same "never
 * hardcode a color" rule every other component in this app follows, so
 * every tour matches light/dark mode automatically and reads as part of
 * this product rather than a bolted-on third-party widget.
 */
export function Tour({ steps, run, onFinish }: { steps: Step[]; run: boolean; onFinish: () => void }) {
  useEffect(() => {
    if (!run) {
      return;
    }

    function handleKeyDown(event: KeyboardEvent) {
      if (event.key === "Escape") {
        onFinish();
      }
    }

    document.addEventListener("keydown", handleKeyDown);

    return () => document.removeEventListener("keydown", handleKeyDown);
  }, [run, onFinish]);

  function handleEvent(data: EventData) {
    if (data.type === EVENTS.TOUR_END && (data.status === STATUS.FINISHED || data.status === STATUS.SKIPPED)) {
      onFinish();
    }
  }

  return (
    <Joyride
      steps={steps}
      run={run}
      continuous
      scrollToFirstStep
      onEvent={handleEvent}
      locale={{ last: "Done" }}
      styles={{
        // .claude/DESIGN.md Type: font-heading (Space Grotesk) for
        // titles, font-sans (Inter) for body — the same three-role
        // system every other screen in this app uses, not the library's
        // own default sans-serif. `tooltip` sets the body font on the
        // tooltip's outer wrapper so it cascades to the footer buttons
        // too (Tailwind's preflight already makes <button> inherit
        // font-family; it just needs an ancestor to inherit from).
        tooltip: {
          borderRadius: "var(--radius-lg)",
          fontFamily: "var(--font-sans)",
        },
        tooltipTitle: {
          fontFamily: "var(--font-heading)",
        },
      }}
      options={{
        // Default buttons are ['back', 'close', 'primary'] — 'skip' isn't
        // included unless added explicitly. A visible, labeled way out
        // matters more here than it would for a power-user tool: this
        // app's own audience (.claude/DESIGN.md: "checking this on a
        // phone between jobs") shouldn't feel cornered by a tour with no
        // obvious exit.
        buttons: ["back", "skip", "primary"],
        showProgress: true,
        skipBeacon: true,
        // See the docblock above — the library default advances rather
        // than exits; this app's own Escape handling (the effect above)
        // replaces it entirely.
        dismissKeyAction: false,
        primaryColor: "var(--primary)",
        textColor: "var(--foreground)",
        backgroundColor: "var(--card)",
        arrowColor: "var(--card)",
        overlayColor: "rgba(30, 42, 34, 0.5)",
        zIndex: 10000,
      }}
    />
  );
}
