"use client";

import { useActionState } from "react";

import { Button } from "@/components/ui/button";
import { register, type RegisterState } from "./actions";

// Plain client-side initial value for useActionState — kept here, not in
// actions.ts: a "use server" file may only export async functions, and a
// const object export breaks that (confirmed live: it took down the whole
// /register page with "A 'use server' file can only export async
// functions, found object").
const initialState: RegisterState = { error: null, fields: null, values: null };

export function RegisterForm() {
  const [state, formAction, pending] = useActionState(register, initialState);

  return (
    <form action={formAction} className="flex flex-col gap-4">
      <Field
        label="Your name"
        name="name"
        type="text"
        autoComplete="name"
        errors={state.fields?.name}
        defaultValue={state.values?.name}
      />
      <Field
        label="Business name"
        name="business_name"
        type="text"
        autoComplete="organization"
        errors={state.fields?.business_name}
        defaultValue={state.values?.business_name}
      />
      <Field
        label="Email"
        name="email"
        type="email"
        autoComplete="email"
        errors={state.fields?.email}
        defaultValue={state.values?.email}
      />
      {/* Password fields never get a defaultValue, even on error — see
          RegisterState's own docblock (actions.ts): never echo a password
          back. */}
      <Field
        label="Password"
        name="password"
        type="password"
        autoComplete="new-password"
        errors={state.fields?.password}
      />
      <Field
        label="Confirm password"
        name="password_confirmation"
        type="password"
        autoComplete="new-password"
      />

      {state.error && (
        <p role="alert" className="text-sm text-destructive">
          {state.error}
        </p>
      )}

      <Button type="submit" disabled={pending} className="mt-2">
        {pending ? "Creating your account…" : "Create account"}
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
  defaultValue,
}: {
  label: string;
  name: string;
  type: string;
  autoComplete: string;
  errors?: string[];
  defaultValue?: string;
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
        defaultValue={defaultValue}
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
