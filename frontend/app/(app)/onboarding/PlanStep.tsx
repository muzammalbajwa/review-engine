"use client";

import { useRouter } from "next/navigation";

import { PlanSelector } from "./PlanSelector";

export function PlanStep() {
  const router = useRouter();

  return (
    <div className="flex flex-col gap-5">
      <p className="text-sm text-muted-foreground">
        Pick a plan to get started. You can change this any time from billing settings.
      </p>
      <PlanSelector onStarted={() => router.refresh()} />
    </div>
  );
}
