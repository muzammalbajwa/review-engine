"use client";

import { useRouter } from "next/navigation";
import { useState } from "react";

import { GbpConnectionCard } from "@/components/gbp/GbpConnectionCard";
import { Button } from "@/components/ui/button";
import type { GbpStatus } from "../gbp/actions";
import { markGbpStepDone } from "./actions";

export function GbpStep({ status }: { status: GbpStatus }) {
  const router = useRouter();
  const [pending, setPending] = useState(false);

  async function handleContinue() {
    setPending(true);
    await markGbpStepDone();
    router.refresh();
  }

  return (
    <div className="flex flex-col gap-5">
      <p className="text-sm text-muted-foreground">
        Connect the Google account that manages your business listing. We only ever post to the
        profile you connect here.
      </p>
      <GbpConnectionCard status={status} />
      <div>
        <Button variant={status.status === "connected" ? "default" : "outline"} onClick={handleContinue} disabled={pending}>
          {pending ? "One moment…" : status.status === "connected" ? "Continue" : "Skip for now"}
        </Button>
      </div>
    </div>
  );
}
