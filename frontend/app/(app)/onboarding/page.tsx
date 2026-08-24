import { redirect } from "next/navigation";

import { apiFetch } from "@/lib/api";
import { requireToken } from "@/lib/session";
import type { GbpStatus } from "../gbp/actions";
import type { TemplateData } from "../templates/actions";
import { ContactsStep } from "./ContactsStep";
import { GbpStep } from "./GbpStep";
import { getOnboardingStatus } from "./actions";
import { OnboardingProgress } from "./OnboardingProgress";
import { PlanStep } from "./PlanStep";
import { TemplatesStep } from "./TemplatesStep";

/**
 * .claude/FRONTEND.md guided onboarding flow. The step to show is always
 * recomputed fresh from real backend state on every load — this is what
 * makes the flow resumable: closing the tab mid-import and coming back
 * lands here again, and this same computation puts the tenant back
 * wherever they actually left off, not step 1.
 */
export default async function OnboardingPage() {
  await requireToken();

  const status = await getOnboardingStatus();

  if (status === null) {
    return (
      <main className="flex min-h-screen items-center justify-center p-8">
        <p role="alert" className="rounded-lg border border-destructive/30 bg-destructive/10 p-4 text-sm text-destructive">
          Couldn&apos;t load your setup progress right now. Refresh the page to try again.
        </p>
      </main>
    );
  }

  if (status.completed) {
    redirect("/dashboard");
  }

  const step = !status.subscribed ? 1 : !status.gbp_step_done ? 2 : !status.contacts_step_done ? 3 : 4;

  return (
    <main className="flex min-h-screen justify-center p-8">
      <div className="w-full max-w-2xl">
        <h1 className="mb-1 text-lg font-semibold">Let&apos;s get you set up</h1>
        <OnboardingProgress step={step} />

        {step === 1 && <PlanStep />}
        {step === 2 && <GbpStepData />}
        {step === 3 && <ContactsStep />}
        {step === 4 && <TemplatesStepData />}
      </div>
    </main>
  );
}

async function GbpStepData() {
  const result = await apiFetch<GbpStatus>("/gbp/status");

  if (!result.ok) {
    return (
      <p role="alert" className="rounded-lg border border-destructive/30 bg-destructive/10 p-4 text-sm text-destructive">
        Couldn&apos;t load your Google Business Profile status right now. Refresh the page to try again.
      </p>
    );
  }

  return <GbpStep status={result.data} />;
}

async function TemplatesStepData() {
  const result = await apiFetch<TemplateData[]>("/templates");

  if (!result.ok) {
    return (
      <p role="alert" className="rounded-lg border border-destructive/30 bg-destructive/10 p-4 text-sm text-destructive">
        Couldn&apos;t load your message templates right now. Refresh the page to try again.
      </p>
    );
  }

  return <TemplatesStep initialTemplates={result.data} />;
}
