import Link from "next/link";

import { Button } from "@/components/ui/button";

export default function NotFound() {
  return (
    <main className="flex min-h-screen items-center justify-center p-8">
      <div className="flex max-w-sm flex-col items-start gap-3 text-center sm:text-left">
        <p className="font-heading text-base font-semibold text-foreground">Page not found</p>
        <p className="text-sm text-muted-foreground">
          There&apos;s nothing at this address. Check the link, or head back to your dashboard.
        </p>
        <Button render={<Link href="/dashboard" />} nativeButton={false}>
          Go to dashboard
        </Button>
      </div>
    </main>
  );
}
