"use client";

import { useState } from "react";

import { Button } from "@/components/ui/button";
import { submitGuestQuickAdd } from "./actions";

export function GuestQuickAddForm({ token }: { token: string }) {
  const [name, setName] = useState("");
  const [phone, setPhone] = useState("");
  const [email, setEmail] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [addedName, setAddedName] = useState<string | null>(null);

  const hasContactMethod = phone.trim() !== "" || email.trim() !== "";

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setSubmitting(true);
    setError(null);

    const result = await submitGuestQuickAdd(token, name.trim(), phone.trim(), email.trim());
    setSubmitting(false);

    if (result.status === "error") {
      setError(result.message);
      return;
    }

    setAddedName(name.trim());
    setName("");
    setPhone("");
    setEmail("");
  }

  if (addedName) {
    return (
      <div className="flex flex-col items-start gap-4 rounded-lg border border-success/40 bg-success/10 p-6">
        <div>
          <p className="font-heading text-base font-semibold text-foreground">
            {addedName} is on their way to a review request
          </p>
          <p className="mt-1 text-sm text-muted-foreground">You can close this page, or add another customer.</p>
        </div>
        <Button type="button" onClick={() => setAddedName(null)}>
          Add another customer
        </Button>
      </div>
    );
  }

  return (
    <form onSubmit={handleSubmit} className="flex flex-col gap-5">
      <div className="flex flex-col gap-1.5">
        <label htmlFor="quick-add-name" className="text-sm font-medium">
          Customer name
        </label>
        <input
          id="quick-add-name"
          type="text"
          autoFocus
          autoComplete="name"
          required
          value={name}
          onChange={(e) => setName(e.target.value)}
          className="h-12 rounded-lg border border-border bg-background px-4 text-base outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
        />
      </div>

      <div className="flex flex-col gap-1.5">
        <label htmlFor="quick-add-phone" className="text-sm font-medium">
          Phone
        </label>
        <input
          id="quick-add-phone"
          type="tel"
          inputMode="tel"
          autoComplete="tel"
          value={phone}
          onChange={(e) => setPhone(e.target.value)}
          className="h-12 rounded-lg border border-border bg-background px-4 text-base outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
        />
      </div>

      <p className="text-center text-xs text-muted-foreground">or</p>

      <div className="flex flex-col gap-1.5">
        <label htmlFor="quick-add-email" className="text-sm font-medium">
          Email
        </label>
        <input
          id="quick-add-email"
          type="email"
          inputMode="email"
          autoComplete="email"
          value={email}
          onChange={(e) => setEmail(e.target.value)}
          className="h-12 rounded-lg border border-border bg-background px-4 text-base outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
        />
      </div>

      {error && (
        <p role="alert" className="text-sm text-destructive">
          {error}
        </p>
      )}

      <Button
        type="submit"
        size="lg"
        disabled={submitting || name.trim() === "" || !hasContactMethod}
        className="h-14 text-base"
      >
        {submitting ? "Adding…" : "Job completed — start review request"}
      </Button>
    </form>
  );
}
