"use client";

import { useRouter } from "next/navigation";
import { useState } from "react";

import { ImportWizard } from "../contacts/import/ImportWizard";
import { Button } from "@/components/ui/button";
import { markContactsStepDone } from "./actions";

export function ContactsStep() {
  const router = useRouter();
  const [pending, setPending] = useState(false);

  async function handleContinue() {
    setPending(true);
    await markContactsStepDone();
    router.refresh();
  }

  return (
    <div className="flex flex-col gap-6">
      <ImportWizard />
      <div className="border-t border-border pt-5">
        <Button variant="outline" onClick={handleContinue} disabled={pending}>
          {pending ? "One moment…" : "Continue"}
        </Button>
        <p className="mt-2 text-xs text-muted-foreground">
          You can skip this for now and import customers later — review requests just won&apos;t go
          out until you do.
        </p>
      </div>
    </div>
  );
}
