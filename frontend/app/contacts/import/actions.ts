"use server";

import { apiFetchForm } from "@/lib/api";
import { requireToken } from "@/lib/session";

export type CsvRow = {
  row_number: number;
  data: Record<string, string>;
  valid: boolean;
  errors: string[];
  warnings: string[];
};

export type PreviewResult = {
  headers: string[];
  rows: CsvRow[];
  total_row_count: number;
  row_cap_exceeded: boolean;
};

// No "idle" member: unlike a useActionState reducer, this is a plain async
// function called once per click — it only ever resolves to one of these
// two outcomes, never sits in an initial/idle state itself.
export type PreviewActionState =
  | { status: "error"; message: string }
  | { status: "success"; preview: PreviewResult };

export async function previewImport(formData: FormData): Promise<PreviewActionState> {
  // .claude/CLAUDE.md #2: tenant is resolved from the authenticated
  // session, never trusted from client input — requireToken() redirects to
  // /login on its own if the session is missing, same check the backend
  // makes again independently via the 'tenant' middleware.
  await requireToken();

  const file = formData.get("file");
  if (!(file instanceof File) || file.size === 0) {
    return { status: "error", message: "Choose a CSV file first." };
  }

  const body = new FormData();
  body.set("file", file);

  const result = await apiFetchForm<PreviewResult>("/contacts/import/preview", body);

  if (!result.ok) {
    return { status: "error", message: result.message };
  }

  return { status: "success", preview: result.data };
}

export type ImportResult = {
  imported: number;
  skipped: number;
  total_row_count: number;
  row_cap_exceeded: boolean;
};

export type ImportActionState =
  | { status: "error"; message: string }
  | { status: "success"; result: ImportResult };

export async function confirmImport(formData: FormData): Promise<ImportActionState> {
  await requireToken();

  const file = formData.get("file");
  if (!(file instanceof File) || file.size === 0) {
    return { status: "error", message: "Choose a CSV file first." };
  }

  const mapping = {
    name: String(formData.get("mapping_name") ?? ""),
    phone: String(formData.get("mapping_phone") ?? ""),
    email: String(formData.get("mapping_email") ?? ""),
  };

  if (mapping.name === "") {
    return { status: "error", message: "Choose which column is the contact's name." };
  }

  const body = new FormData();
  body.set("file", file);
  body.set("mapping[name]", mapping.name);
  if (mapping.phone) body.set("mapping[phone]", mapping.phone);
  if (mapping.email) body.set("mapping[email]", mapping.email);

  const result = await apiFetchForm<ImportResult>("/contacts/import", body);

  if (!result.ok) {
    return { status: "error", message: result.message };
  }

  return { status: "success", result: result.data };
}
