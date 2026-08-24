"use client";

import { useState } from "react";

import { Button } from "@/components/ui/button";
import { TemplateEditor } from "../templates/TemplateEditor";
import type { TemplateData } from "../templates/actions";
import { completeOnboarding } from "./actions";

export function TemplatesStep({ initialTemplates }: { initialTemplates: TemplateData[] }) {
  const [finishing, setFinishing] = useState(false);

  async function handleFinish() {
    setFinishing(true);
    await completeOnboarding();
  }

  return (
    <div className="flex flex-col gap-6">
      <p className="text-sm text-muted-foreground">
        These are already written to pass the compliance check — most businesses never need to
        change them. Edit any step below if you want to, or just continue.
      </p>
      <TemplateEditor initialTemplates={initialTemplates} />
      <div className="border-t border-border pt-5">
        <Button onClick={handleFinish} disabled={finishing}>
          {finishing ? "Finishing up…" : "Looks good, continue"}
        </Button>
      </div>
    </div>
  );
}
