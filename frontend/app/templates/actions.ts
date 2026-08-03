"use server";

import { apiFetch } from "@/lib/api";
import { requireToken } from "@/lib/session";

export type ComplianceResult = {
  status: "pass" | "block";
  reasons: string[];
  suggested_rewrite: string | null;
};

export type TemplateData = {
  id: number;
  step: number;
  body: string;
  compliance_status: "pass" | "block";
  compliance_reasons: string[];
  suggested_rewrite: string | null;
};

export type CheckActionState =
  | { status: "error"; message: string }
  | { status: "success"; result: ComplianceResult };

/**
 * .claude/FRONTEND.md screen 2: "live compliance check with inline
 * pass/block". Non-persisting — calls POST /templates/check, the same
 * checker saveTemplate() uses, without writing anything.
 */
export async function checkTemplateCompliance(body: string): Promise<CheckActionState> {
  await requireToken();

  const result = await apiFetch<ComplianceResult>("/templates/check", {
    method: "POST",
    body: { body },
  });

  if (!result.ok) {
    return { status: "error", message: result.message };
  }

  return { status: "success", result: result.data };
}

export type SaveActionState =
  | { status: "success"; template: TemplateData }
  | { status: "blocked"; message: string; reasons: string[]; suggestedRewrite: string | null }
  | { status: "error"; message: string };

type ComplianceBlockData = { reasons: string[]; suggested_rewrite: string | null };

export async function saveTemplate(step: number, body: string): Promise<SaveActionState> {
  await requireToken();

  const result = await apiFetch<TemplateData, ComplianceBlockData>(`/templates/${step}`, {
    method: "PUT",
    body: { body },
  });

  if (!result.ok) {
    if (result.error === "compliance_block" && result.data) {
      return {
        status: "blocked",
        message: result.message,
        reasons: result.data.reasons,
        suggestedRewrite: result.data.suggested_rewrite,
      };
    }

    return { status: "error", message: result.message };
  }

  return { status: "success", template: result.data };
}
