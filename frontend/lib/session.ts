import "server-only";

import { cookies } from "next/headers";
import { redirect } from "next/navigation";

/**
 * .claude/SECURITY.md #3: "Next.js stores the token in an httpOnly, Secure,
 * SameSite=Strict cookie — never in localStorage." This is the ONLY place
 * that reads/writes it. The browser never sees the raw token: it's set here
 * (a Server Action) and read here (Server Components/Actions), never passed
 * to client code.
 */
const COOKIE_NAME = "re_token";
// Not sensitive on its own (the real authorization check always happens
// server-side again via TenantPolicy on every admin request) — this is
// purely a UI hint so protected pages can decide whether to render the
// Admin nav link without an extra round trip. Kept httpOnly anyway so it's
// never readable/spoofable from client-side JS.
const IS_ADMIN_COOKIE_NAME = "re_is_admin";

export async function setSession(token: string, isAdmin: boolean): Promise<void> {
  const cookieStore = await cookies();
  // Matches Sanctum's own token lifetime (config/sanctum.php expiration:
  // null — tokens don't expire server-side), so the cookie doesn't
  // silently drop a still-valid token.
  const maxAge = 60 * 60 * 24 * 30;

  cookieStore.set(COOKIE_NAME, token, {
    httpOnly: true,
    secure: true,
    sameSite: "strict",
    path: "/",
    maxAge,
  });

  cookieStore.set(IS_ADMIN_COOKIE_NAME, isAdmin ? "1" : "0", {
    httpOnly: true,
    secure: true,
    sameSite: "strict",
    path: "/",
    maxAge,
  });
}

export async function getToken(): Promise<string | null> {
  const cookieStore = await cookies();

  return cookieStore.get(COOKIE_NAME)?.value ?? null;
}

export async function getIsAdmin(): Promise<boolean> {
  const cookieStore = await cookies();

  return cookieStore.get(IS_ADMIN_COOKIE_NAME)?.value === "1";
}

export async function clearToken(): Promise<void> {
  const cookieStore = await cookies();

  cookieStore.delete(COOKIE_NAME);
  cookieStore.delete(IS_ADMIN_COOKIE_NAME);
}

/**
 * .claude/FRONTEND.md: "Protected routes check session server-side before
 * render." Call at the top of a protected Server Component page.
 */
export async function requireToken(): Promise<string> {
  const token = await getToken();

  if (token === null) {
    redirect("/login");
  }

  return token;
}
