import "server-only";

import { getToken } from "@/lib/session";

/**
 * .claude/CLAUDE.md: "Next.js NEVER connects to Postgres. Next.js calls
 * Laravel's REST API only." This is the one place that happens from server
 * code — every Server Action/Component talks to Laravel through this, never
 * fetch() directly, so the Bearer-token attachment can't be forgotten.
 */
function apiUrl(path: string): string {
  const base = process.env.NEXT_PUBLIC_API_URL;

  if (!base) {
    throw new Error("NEXT_PUBLIC_API_URL is not set");
  }

  return `${base}/api/v1${path}`;
}

export type ApiResult<T, E = never> =
  | { ok: true; status: number; data: T }
  | {
      ok: false;
      status: number;
      error: string;
      message: string;
      fields: Record<string, string[]> | null;
      // Most failure responses carry only {error, message, fields}. A few
      // (e.g. the templates compliance-block response) also attach a `data`
      // payload alongside the error envelope — surfaced here, typed per call
      // site via the E generic, rather than widening the common case.
      data: E | null;
    };

async function toApiResult<T, E = never>(res: Response): Promise<ApiResult<T, E>> {
  const body = await res.json().catch(() => null);

  if (res.ok) {
    return { ok: true, status: res.status, data: (body?.data ?? null) as T };
  }

  return {
    ok: false,
    status: res.status,
    error: body?.error ?? "unknown_error",
    message: body?.message ?? "Something went wrong.",
    fields: body?.fields ?? null,
    data: (body?.data ?? null) as E | null,
  };
}

/**
 * JSON requests (register/login/subscribe/etc). Attaches the session's
 * Bearer token automatically when present — callers never handle it.
 */
export async function apiFetch<T, E = never>(
  path: string,
  options: { method?: string; body?: unknown; skipAuth?: boolean } = {}
): Promise<ApiResult<T, E>> {
  const headers: Record<string, string> = {
    Accept: "application/json",
    "Content-Type": "application/json",
  };

  if (!options.skipAuth) {
    const token = await getToken();
    if (token) {
      headers.Authorization = `Bearer ${token}`;
    }
  }

  const res = await fetch(apiUrl(path), {
    method: options.method ?? "GET",
    headers,
    body: options.body !== undefined ? JSON.stringify(options.body) : undefined,
    cache: "no-store",
  });

  return toApiResult<T, E>(res);
}

/**
 * multipart/form-data requests (CSV upload). Kept separate from apiFetch
 * because a JSON Content-Type header would corrupt a file upload — fetch
 * needs to set its own multipart boundary from the FormData object.
 */
export async function apiFetchForm<T>(path: string, formData: FormData): Promise<ApiResult<T>> {
  const headers: Record<string, string> = { Accept: "application/json" };

  const token = await getToken();
  if (token) {
    headers.Authorization = `Bearer ${token}`;
  }

  const res = await fetch(apiUrl(path), {
    method: "POST",
    headers,
    body: formData,
    cache: "no-store",
  });

  return toApiResult<T>(res);
}
