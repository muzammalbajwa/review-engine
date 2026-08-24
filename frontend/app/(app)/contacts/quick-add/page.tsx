import Link from "next/link";

import { AppShell } from "@/components/AppShell";
import { requireToken } from "@/lib/session";
import { QuickAddForm } from "./QuickAddForm";

/**
 * .claude/CLAUDE.md quick-add: "meant to be used standing at a job site
 * on a phone, not at a desk." Narrower than the other authenticated
 * pages' usual max-w-2xl on purpose — this form should feel just as
 * compact on a laptop as it does on a phone, not stretch to fill space
 * it doesn't need.
 */
export default async function QuickAddContactPage() {
  await requireToken();

  return (
    <AppShell>
      <main className="flex justify-center p-8">
        <div className="w-full max-w-sm">
          <h1 className="mb-1 text-lg font-semibold">Add a customer</h1>
          <p className="mb-6 text-sm text-muted-foreground">
            Just finished a job? Add them here and their review request goes out.
          </p>

          <QuickAddForm />

          <p className="mt-6 text-xs text-muted-foreground">
            Adding a whole list instead?{" "}
            <Link href="/contacts/import" className="font-medium text-primary underline-offset-4 hover:underline">
              Import a CSV
            </Link>
          </p>
        </div>
      </main>
    </AppShell>
  );
}
