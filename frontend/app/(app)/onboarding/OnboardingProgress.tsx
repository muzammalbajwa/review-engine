const STEP_LABELS = ["Plan", "Connect Google", "Import customers", "Review templates", "Done"] as const;
const TOTAL_STEPS = STEP_LABELS.length;

export function OnboardingProgress({ step }: { step: number }) {
  return (
    <div className="mb-8 flex flex-col gap-2" aria-label={`Step ${step} of ${TOTAL_STEPS}`}>
      <div className="flex items-center justify-between text-sm">
        <span className="font-medium text-foreground">
          Step {step} of {TOTAL_STEPS}: {STEP_LABELS[step - 1]}
        </span>
      </div>
      <div className="flex gap-1.5" aria-hidden="true">
        {STEP_LABELS.map((label, i) => (
          <div
            key={label}
            className={`h-1.5 flex-1 rounded-full ${i < step ? "bg-primary" : "bg-muted"}`}
          />
        ))}
      </div>
    </div>
  );
}
