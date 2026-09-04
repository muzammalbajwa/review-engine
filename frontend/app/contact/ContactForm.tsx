"use client";

import { useState } from "react";

import { Button } from "@/components/ui/button";
import { submitContactMessage } from "./actions";

const FIELD_CLASS =
  "h-12 rounded-lg border border-border bg-background px-4 text-base outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50";

export function ContactForm({ initialName = "", initialEmail = "" }: { initialName?: string; initialEmail?: string }) {
  const [name, setName] = useState(initialName);
  const [email, setEmail] = useState(initialEmail);
  const [message, setMessage] = useState("");
  // Honeypot — never rendered visibly (see the hidden wrapper below). A
  // real visitor's browser never puts anything here; some bots fill every
  // input they find in the DOM regardless of how it's hidden.
  const [company, setCompany] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]> | null>(null);
  const [successMessage, setSuccessMessage] = useState<string | null>(null);

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setSubmitting(true);
    setError(null);
    setFieldErrors(null);

    const result = await submitContactMessage(name.trim(), email.trim(), message.trim(), company);
    setSubmitting(false);

    if (result.status === "error") {
      // A real send failure (ContactMessageController's 502) and a
      // validation failure (422) both land here, in the same shape — the
      // point is this branch only ever renders when the message did NOT
      // go out, never a generic "thanks" regardless of what happened.
      setError(result.message);
      setFieldErrors(result.fields);
      return;
    }

    setSuccessMessage(result.message);
  }

  if (successMessage) {
    return (
      <div role="status" className="flex flex-col items-start gap-4 rounded-lg border border-success/40 bg-success/10 p-6">
        <div>
          <p className="font-heading text-base font-semibold text-foreground">{successMessage}</p>
          <p className="mt-1 text-sm text-muted-foreground">We read every message ourselves and reply by email.</p>
        </div>
      </div>
    );
  }

  return (
    <form onSubmit={handleSubmit} className="flex flex-col gap-5">
      <div className="flex flex-col gap-1.5">
        <label htmlFor="contact-name" className="text-sm font-medium">
          Name
        </label>
        <input
          id="contact-name"
          type="text"
          autoComplete="name"
          required
          value={name}
          onChange={(e) => setName(e.target.value)}
          className={FIELD_CLASS}
        />
        {fieldErrors?.name && <p className="text-sm text-destructive">{fieldErrors.name[0]}</p>}
      </div>

      <div className="flex flex-col gap-1.5">
        <label htmlFor="contact-email" className="text-sm font-medium">
          Email
        </label>
        <input
          id="contact-email"
          type="email"
          inputMode="email"
          autoComplete="email"
          required
          value={email}
          onChange={(e) => setEmail(e.target.value)}
          className={FIELD_CLASS}
        />
        {fieldErrors?.email && <p className="text-sm text-destructive">{fieldErrors.email[0]}</p>}
      </div>

      <div className="flex flex-col gap-1.5">
        <label htmlFor="contact-message" className="text-sm font-medium">
          Message
        </label>
        <textarea
          id="contact-message"
          required
          rows={6}
          value={message}
          onChange={(e) => setMessage(e.target.value)}
          className="rounded-lg border border-border bg-background px-4 py-3 text-base outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
        />
        {fieldErrors?.message && <p className="text-sm text-destructive">{fieldErrors.message[0]}</p>}
      </div>

      {/* Honeypot — invisible and unreachable for a real visitor (off-screen,
          aria-hidden, out of tab order), left in the DOM for a bot that
          fills every input it finds. Never display:none — some bots skip
          display:none fields specifically to dodge this trick. */}
      <div aria-hidden="true" className="absolute -left-[9999px] top-auto h-0 w-0 overflow-hidden">
        <label htmlFor="contact-company">Company</label>
        <input
          id="contact-company"
          name="company"
          tabIndex={-1}
          autoComplete="off"
          value={company}
          onChange={(e) => setCompany(e.target.value)}
        />
      </div>

      {error && (
        <p role="alert" className="text-sm text-destructive">
          {error}
        </p>
      )}

      <Button type="submit" size="lg" disabled={submitting} className="h-14 text-base">
        {submitting ? "Sending…" : "Send message"}
      </Button>
    </form>
  );
}
