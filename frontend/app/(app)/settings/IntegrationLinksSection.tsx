/**
 * Links to the Zapier (Step 4) and Make.com (Step 5) listings — read from
 * env, never hardcoded. Neither integration has a real, guaranteed-stable
 * public URL yet: both apps (zapier-integration/, make-integration/) are
 * built and validated locally, but publishing either to its platform's
 * public directory needs a human with an account there, not this
 * codebase. Showing a fabricated link would be worse than showing "coming
 * soon" honestly — set NEXT_PUBLIC_ZAPIER_LISTING_URL /
 * NEXT_PUBLIC_MAKE_LISTING_URL once each is actually live and this
 * section picks it up with no code change.
 */
export function IntegrationLinksSection() {
  const zapierUrl = process.env.NEXT_PUBLIC_ZAPIER_LISTING_URL;
  const makeUrl = process.env.NEXT_PUBLIC_MAKE_LISTING_URL;

  return (
    <div className="flex max-w-xl flex-col gap-3">
      <div>
        <p className="text-sm font-medium text-foreground">Connect with your other tools</p>
        <p className="text-xs text-muted-foreground">
          Send contacts here from thousands of apps with a prebuilt connector — no code required.
        </p>
      </div>
      <div className="flex flex-col gap-2 sm:flex-row">
        <IntegrationLink name="Zapier" href={zapierUrl} />
        <IntegrationLink name="Make" href={makeUrl} />
      </div>
    </div>
  );
}

function IntegrationLink({ name, href }: { name: string; href?: string }) {
  if (!href) {
    return (
      <div className="flex flex-1 items-center justify-between rounded-lg border border-dashed border-border px-4 py-2.5 text-sm">
        <span className="text-muted-foreground">{name}</span>
        <span className="text-xs text-muted-foreground">Coming soon</span>
      </div>
    );
  }

  return (
    <a
      href={href}
      target="_blank"
      rel="noopener noreferrer"
      className="flex flex-1 items-center justify-between rounded-lg border border-border px-4 py-2.5 text-sm hover:bg-muted focus-visible:outline-none focus-visible:ring-3 focus-visible:ring-ring/50"
    >
      <span className="font-medium text-foreground">{name}</span>
      <span className="text-primary">Connect →</span>
    </a>
  );
}
