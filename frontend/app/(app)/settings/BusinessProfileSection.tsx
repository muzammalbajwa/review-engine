"use client";

import { useState } from "react";

import { Button } from "@/components/ui/button";
import { updateTenant, type Tenant } from "./actions";

export function BusinessProfileSection({ tenant }: { tenant: Tenant }) {
  const [name, setName] = useState(tenant.name);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [saved, setSaved] = useState(false);

  async function handleSave() {
    setSaving(true);
    setError(null);
    setSaved(false);

    const result = await updateTenant(name);
    setSaving(false);

    if (result.status === "error") {
      setError(result.message);
      return;
    }

    setSaved(true);
  }

  return (
    <div className="flex max-w-md flex-col gap-6">
      <p className="text-sm text-muted-foreground">
        This is the name your customers see on review request messages.
      </p>

      <div className="flex flex-col gap-1.5">
        <label htmlFor="business-name" className="text-sm font-medium">
          Business name
        </label>
        <input
          id="business-name"
          type="text"
          value={name}
          onChange={(e) => {
            setName(e.target.value);
            setSaved(false);
          }}
          className="h-9 rounded-lg border border-border bg-background px-3 text-sm outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
        />
      </div>

      {error && (
        <p role="alert" className="text-sm text-destructive">
          {error}
        </p>
      )}
      {saved && (
        <p role="status" className="text-sm text-success">
          Saved.
        </p>
      )}

      <div>
        <Button onClick={handleSave} disabled={saving || name.trim() === ""}>
          {saving ? "Saving…" : "Save"}
        </Button>
      </div>

      <dl className="flex flex-col gap-3 border-t border-border pt-5 text-sm">
        <div>
          <dt className="text-xs font-medium text-muted-foreground">Account type</dt>
          <dd className="mt-1 capitalize text-foreground">{tenant.type}</dd>
        </div>
        <div>
          <dt className="text-xs font-medium text-muted-foreground">Member since</dt>
          <dd className="mt-1 text-foreground">
            <time dateTime={tenant.created_at}>{new Date(tenant.created_at).toLocaleDateString()}</time>
          </dd>
        </div>
      </dl>
    </div>
  );
}
