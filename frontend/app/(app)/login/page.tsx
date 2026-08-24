import { ArrowLeft } from "lucide-react";
import Link from "next/link";
import { redirect } from "next/navigation";

import { getToken } from "@/lib/session";
import { LoginForm } from "./LoginForm";

export default async function LoginPage() {
  const token = await getToken();

  if (token !== null) {
    redirect("/dashboard");
  }

  return (
    <main className="relative flex min-h-screen items-center justify-center p-8">
      <Link
        href="/"
        className="absolute top-6 left-6 flex items-center gap-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground"
      >
        <ArrowLeft className="size-4" aria-hidden="true" />
        Back to home
      </Link>
      <div className="w-full max-w-sm rounded-lg border border-border p-6">
        <h1 className="mb-6 text-lg font-semibold">Log in</h1>
        <LoginForm />
        <p className="mt-6 text-center text-sm text-muted-foreground">
          New here?{" "}
          <Link href="/register" className="font-medium text-primary underline-offset-4 hover:underline">
            Create an account
          </Link>
        </p>
      </div>
    </main>
  );
}
