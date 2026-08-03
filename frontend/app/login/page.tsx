import { redirect } from "next/navigation";

import { getToken } from "@/lib/session";
import { LoginForm } from "./LoginForm";

export default async function LoginPage() {
  const token = await getToken();

  if (token !== null) {
    redirect("/contacts/import");
  }

  return (
    <main className="flex min-h-screen items-center justify-center p-8">
      <div className="w-full max-w-sm rounded-lg border p-6">
        <h1 className="mb-6 text-lg font-semibold">Log in</h1>
        <LoginForm />
      </div>
    </main>
  );
}
