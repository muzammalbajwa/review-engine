"use client";

import { useSearchParams } from "next/navigation";
import { useState, type ReactNode } from "react";

const ALL_TABS = [
  { key: "business", label: "Business profile" },
  { key: "sender", label: "Sender identity" },
  { key: "gbp", label: "Google Business Profile" },
  { key: "billing", label: "Billing" },
  { key: "team", label: "Team" },
  { key: "apiKeys", label: "Webhooks & API" },
] as const;

type TabKey = (typeof ALL_TABS)[number]["key"];

/**
 * One screen, in-page tabs — not a sub-route per section — keeps
 * /settings at the same nav depth as every other sidebar destination
 * (Sidebar -> Settings, not Sidebar -> Settings -> Billing). Same
 * underlined-tab pattern as TemplateEditor's step switcher, reused for
 * visual consistency rather than inventing a second tab style.
 *
 * Billing and Team are owner-only tabs — hidden here for a member, not
 * just left visible-but-broken. The real enforcement is still server-side
 * (EnsureTenantOwner on every route those sections call) regardless of
 * what this hides; a member who somehow lands on ?tab=billing directly
 * just sees whatever page.tsx passed for that slot (undefined, since a
 * member's fetch there is never even attempted — see page.tsx).
 */
export function SettingsTabs({
  isOwner,
  business,
  sender,
  gbp,
  billing,
  team,
  apiKeys,
}: {
  isOwner: boolean;
  business: ReactNode;
  sender: ReactNode;
  gbp: ReactNode;
  billing?: ReactNode;
  team?: ReactNode;
  apiKeys: ReactNode;
}) {
  const TABS = ALL_TABS.filter((tab) => isOwner || (tab.key !== "billing" && tab.key !== "team"));

  // Lets the trial-expired banner's "Subscribe now" link (/settings?tab=
  // billing) land directly on the right tab instead of the business
  // profile default — read once at mount, same as any other
  // ?query-param-as-initial-state pattern (this is a plain in-page tab
  // switch afterward, not synced back to the URL on every click).
  const searchParams = useSearchParams();
  const requestedTab = searchParams.get("tab");
  const initialTab = TABS.some((tab) => tab.key === requestedTab) ? (requestedTab as TabKey) : "business";
  const [activeTab, setActiveTab] = useState<TabKey>(initialTab);

  const content: Record<TabKey, ReactNode> = { business, sender, gbp, billing, team, apiKeys };

  return (
    <div className="flex flex-col gap-6">
      <div aria-label="Settings section" className="flex flex-wrap gap-2 border-b border-border">
        {TABS.map((tab) => (
          <button
            key={tab.key}
            type="button"
            aria-current={activeTab === tab.key ? "page" : undefined}
            onClick={() => setActiveTab(tab.key)}
            className={`rounded-t-lg px-3 py-2 text-sm font-medium focus-visible:outline-none focus-visible:ring-3 focus-visible:ring-ring/50 ${
              activeTab === tab.key
                ? "border-b-2 border-primary text-foreground"
                : "text-muted-foreground hover:text-foreground"
            }`}
          >
            {tab.label}
          </button>
        ))}
      </div>

      {content[activeTab]}
    </div>
  );
}
