"use server";

/**
 * The try-it panel's own call path — deliberately NOT lib/api.ts's
 * apiFetch(). apiFetch always attaches the dashboard session token
 * (lib/session.ts's requireToken()); this endpoint is authenticated by a
 * key the visitor pastes in, which may not even belong to whoever is
 * viewing this public page. A raw fetch, scoped to this one file, keeps
 * that distinction explicit instead of teaching apiFetch a second auth
 * mode it should never otherwise have.
 */
export type TryItResult =
  | { ok: true; status: number; body: unknown }
  | { ok: false; message: string };

export async function tryContactsRequest(
  apiKey: string,
  fields: { name: string; phone: string; email: string; external_id: string }
): Promise<TryItResult> {
  const base = process.env.NEXT_PUBLIC_API_URL;

  if (!base) {
    return { ok: false, message: "API base URL is not configured on this deployment." };
  }

  if (apiKey.trim() === "") {
    return { ok: false, message: "Paste an API key first." };
  }

  const body: Record<string, string> = {};
  if (fields.name.trim() !== "") body.name = fields.name.trim();
  if (fields.phone.trim() !== "") body.phone = fields.phone.trim();
  if (fields.email.trim() !== "") body.email = fields.email.trim();
  if (fields.external_id.trim() !== "") body.external_id = fields.external_id.trim();

  const res = await fetch(`${base}/api/v1/contacts`, {
    method: "POST",
    headers: {
      Authorization: `Bearer ${apiKey.trim()}`,
      "Content-Type": "application/json",
      Accept: "application/json",
    },
    body: JSON.stringify(body),
    cache: "no-store",
  });

  const json = await res.json().catch(() => null);

  return { ok: true, status: res.status, body: json };
}
