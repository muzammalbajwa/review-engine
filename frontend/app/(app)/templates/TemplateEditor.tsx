"use client";

import { useEffect, useRef, useState } from "react";

import { SinglePathLine } from "@/components/SinglePathLine";
import { Button } from "@/components/ui/button";
import {
  checkTemplateCompliance,
  saveTemplate,
  type ComplianceResult,
  type TemplateData,
} from "./actions";

const STEPS = [1, 2, 3] as const;
const STEP_LABELS: Record<number, string> = {
  1: "Initial request",
  2: "Follow-up",
  3: "Reactivation",
};

// .claude/DESIGN.md "Signature": the compliance checker is one of the
// line's exactly two intended uses. "Check" is where a block stops it —
// there's no code path past that point, same as the product has none for
// gating a review request by sentiment.
const COMPLIANCE_STEPS = [{ label: "Draft" }, { label: "Check" }, { label: "Live" }];

type SaveState =
  | { status: "idle" }
  | { status: "saving" }
  | { status: "saved" }
  | { status: "blocked"; message: string; reasons: string[]; suggestedRewrite: string | null }
  | { status: "error"; message: string };

export function TemplateEditor({ initialTemplates }: { initialTemplates: TemplateData[] }) {
  const [activeStep, setActiveStep] = useState<number>(1);
  const [bodies, setBodies] = useState<Record<number, string>>(() =>
    Object.fromEntries(initialTemplates.map((t) => [t.step, t.body]))
  );
  const [checkResults, setCheckResults] = useState<Record<number, ComplianceResult>>(() =>
    Object.fromEntries(
      initialTemplates.map((t) => [
        t.step,
        { status: t.compliance_status, reasons: t.compliance_reasons, suggested_rewrite: t.suggested_rewrite },
      ])
    )
  );
  const [checking, setChecking] = useState(false);
  const [checkError, setCheckError] = useState<string | null>(null);
  const [saveState, setSaveState] = useState<SaveState>({ status: "idle" });

  // The body last known to pass on the server (a saved template, or a
  // compliant default) — re-checking it on every render/mount would waste
  // an API call on content that's already verified. Only edits away from
  // this trigger a fresh live check.
  const knownGoodBodiesRef = useRef<Record<number, string>>(
    Object.fromEntries(initialTemplates.map((t) => [t.step, t.body]))
  );
  const debounceRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  const requestIdRef = useRef(0);

  const body = bodies[activeStep] ?? "";

  useEffect(() => {
    if (debounceRef.current) {
      clearTimeout(debounceRef.current);
    }

    if (body === knownGoodBodiesRef.current[activeStep]) {
      setChecking(false);
      setCheckError(null);
      return;
    }

    const requestId = ++requestIdRef.current;
    setChecking(true);
    setCheckError(null);

    debounceRef.current = setTimeout(async () => {
      const state = await checkTemplateCompliance(body);

      if (requestIdRef.current !== requestId) {
        return; // a newer keystroke/step switch has already superseded this
      }

      setChecking(false);

      if (state.status === "success") {
        setCheckResults((prev) => ({ ...prev, [activeStep]: state.result }));
        setCheckError(null);
      } else {
        // Never leave the previous (possibly stale/misleading) pass/block
        // badge showing when the check itself failed — a tenant editing a
        // template that then fails to save must see why, not a leftover
        // "Compliant" from before their edit.
        setCheckError(state.message);
      }
    }, 600);

    return () => {
      if (debounceRef.current) {
        clearTimeout(debounceRef.current);
      }
    };
  }, [body, activeStep]);

  async function handleSave() {
    setSaveState({ status: "saving" });
    const state = await saveTemplate(activeStep, body);

    if (state.status === "success") {
      knownGoodBodiesRef.current[activeStep] = body;
      setCheckResults((prev) => ({ ...prev, [activeStep]: { status: "pass", reasons: [], suggested_rewrite: null } }));
      setSaveState({ status: "saved" });
      return;
    }

    setSaveState(state);
  }

  function applySuggestedRewrite(rewrite: string) {
    setBodies((prev) => ({ ...prev, [activeStep]: rewrite }));
    setSaveState({ status: "idle" });
  }

  const result = checkResults[activeStep];

  return (
    <div className="flex w-full max-w-2xl flex-col gap-6">
      {/* overflow-x-auto, not flex-wrap: these labels don't fit a 375px
          viewport on one line, and wrapping an underlined-tab row to a
          second line breaks the border-b metaphor — same tradeoff
          AdminSidebarNav's mobile nav already makes. */}
      <div
        aria-label="Template step"
        data-tour="template-steps"
        className="flex gap-2 overflow-x-auto border-b border-border"
      >
        {STEPS.map((step) => (
          <button
            key={step}
            type="button"
            aria-current={activeStep === step ? "step" : undefined}
            onClick={() => {
              setActiveStep(step);
              setSaveState({ status: "idle" });
            }}
            className={`shrink-0 rounded-t-lg px-3 py-2 text-sm font-medium whitespace-nowrap focus-visible:outline-none focus-visible:ring-3 focus-visible:ring-ring/50 ${
              activeStep === step
                ? "border-b-2 border-primary text-foreground"
                : "text-muted-foreground hover:text-foreground"
            }`}
          >
            Step {step}: {STEP_LABELS[step]}
          </button>
        ))}
      </div>

      <div data-tour="template-message" className="flex flex-col gap-1.5">
        <label htmlFor="template-body" className="text-sm font-medium">
          Message
        </label>
        <textarea
          id="template-body"
          rows={5}
          value={body}
          onChange={(e) => {
            setBodies((prev) => ({ ...prev, [activeStep]: e.target.value }));
            setSaveState({ status: "idle" });
          }}
          className="rounded-lg border border-border bg-background px-3 py-2 text-sm outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
        />
      </div>

      <div aria-live="polite" data-tour="compliance-check" className="min-h-10">
        {checking ? (
          <p className="text-sm text-muted-foreground">Checking compliance…</p>
        ) : checkError ? (
          <p role="alert" className="rounded-lg border border-destructive/30 bg-destructive/10 p-3 text-sm text-destructive">
            {checkError}
          </p>
        ) : result ? (
          <div className="flex flex-col gap-3">
            <SinglePathLine steps={COMPLIANCE_STEPS} blockedAtIndex={result.status === "block" ? 1 : undefined} />

            {result.status === "block" ? (
              <div className="rounded-lg border border-destructive/30 bg-destructive/10 p-3 text-sm">
                <p className="font-medium text-destructive">This message needs changes:</p>
                <ul className="mt-1 list-disc pl-5 text-foreground">
                  {result.reasons.map((reason) => (
                    <li key={reason}>{reason}</li>
                  ))}
                </ul>
                {result.suggested_rewrite && (
                  <div className="mt-3 flex flex-col gap-2">
                    <p className="text-muted-foreground">Suggested rewrite:</p>
                    <p className="rounded-lg border border-border bg-background p-2 italic text-foreground">
                      {result.suggested_rewrite}
                    </p>
                    <div>
                      <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() => applySuggestedRewrite(result.suggested_rewrite!)}
                      >
                        Use this rewrite
                      </Button>
                    </div>
                  </div>
                )}
              </div>
            ) : (
              <p className="text-sm text-foreground">
                <span className="font-medium">Compliant.</span> This message is ready to use.
              </p>
            )}
          </div>
        ) : null}
      </div>

      {saveState.status === "blocked" && (
        <div role="alert" className="rounded-lg border border-destructive/30 bg-destructive/10 p-3 text-sm text-destructive">
          <p className="font-medium">{saveState.message}</p>
          <ul className="mt-1 list-disc pl-5">
            {saveState.reasons.map((reason) => (
              <li key={reason}>{reason}</li>
            ))}
          </ul>
        </div>
      )}
      {saveState.status === "error" && (
        <p role="alert" className="rounded-lg border border-destructive/30 bg-destructive/10 p-3 text-sm text-destructive">
          {saveState.message}
        </p>
      )}
      {saveState.status === "saved" && (
        <p role="status" className="text-sm text-muted-foreground">
          Saved.
        </p>
      )}

      <div>
        <Button onClick={handleSave} disabled={saveState.status === "saving" || body.trim() === ""}>
          {saveState.status === "saving" ? "Saving…" : "Save"}
        </Button>
      </div>
    </div>
  );
}
