import { AppNav } from "@/components/AppNav";
import { requireToken } from "@/lib/session";
import { ImportWizard } from "./ImportWizard";

export default async function ImportContactsPage() {
  // .claude/FRONTEND.md: "Protected routes check session server-side
  // before render." Redirects to /login on its own if there's no session.
  await requireToken();

  return (
    <main className="flex min-h-screen justify-center p-8">
      <div className="w-full max-w-3xl">
        <AppNav />
        <h1 className="mb-6 text-lg font-semibold">Import contacts</h1>
        <ImportWizard />
      </div>
    </main>
  );
}
