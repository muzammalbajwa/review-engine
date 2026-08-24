"use client";

import { useActionState } from "react";

import { Button } from "@/components/ui/button";
import { acceptInvite, type AcceptInviteState } from "./actions";

const initialState: AcceptInviteState = { error: null, fields: null };

export function AcceptInviteForm({ token, email }: { token: string; email: string }) {
  const acceptInviteWithToken = acceptInvite.bind(null, token);
  const [state, formAction, pending] = useActionState(acceptInviteWithToken, initialState);

  return (
    <form action={formAction} className="flex flex-col gap-4">
      <div className="flex flex-col gap-1.5">
        <span className="text-sm font-medium">Email</span>
        <p className="h-9 rounded-lg border border-border bg-muted px-3 text-sm leading-9 text-muted-foreground">
          {email}
        </p>
      </div>

      <Field label="Your name" name="name" type="text" autoComplete="name" errors={state.fields?.name} />
      <Field
        label="Password"
        name="password"
        type="password"
        autoComplete="new-password"
        errors={state.fields?.password}
      />
      <Field label="Confirm password" name="password_confirmation" type="password" autoComplete="new-password" />

      {state.error && (
        <p role="alert" className="text-sm text-destructive">
          {state.error}
        </p>
      )}

      <Button type="submit" disabled={pending} className="mt-2">
        {pending ? "Joining…" : "Join the team"}
      </Button>
    </form>
  );
}

function Field({
  label,
  name,
  type,
  autoComplete,
  errors,
}: {
  label: string;
  name: string;
  type: string;
  autoComplete: string;
  errors?: string[];
}) {
  return (
    <div className="flex flex-col gap-1.5">
      <label htmlFor={name} className="text-sm font-medium">
        {label}
      </label>
      <input
        id={name}
        name={name}
        type={type}
        required
        autoComplete={autoComplete}
        aria-invalid={errors ? true : undefined}
        className="h-9 rounded-lg border border-border bg-background px-3 text-sm outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
      />
      {errors?.map((message) => (
        <p key={message} role="alert" className="text-xs text-destructive">
          {message}
        </p>
      ))}
    </div>
  );
}
