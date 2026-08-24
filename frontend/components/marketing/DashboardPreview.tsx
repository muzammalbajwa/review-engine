import { MessageCircle, Reply, Star, type LucideIcon } from "lucide-react";

import { Card } from "@/components/ui/card";
import { cn } from "@/lib/utils";

/**
 * The homepage hero's "product above the fold" visual — a high-fidelity
 * rendering of the real /dashboard screen (app/dashboard/page.tsx), not
 * an invented approximation of it. StatTile below reproduces that
 * screen's own StatTile component's exact classes (rounded-lg border
 * p-5, uppercase tracked-wide label, text-2xl tabular-nums value,
 * success-tinted emphasis).
 *
 * The activity rows are close to RecentActivity's real markup but not a
 * literal copy: the real component (app/dashboard/page.tsx) has no per-row
 * icons and only ever shows two entry shapes ("left a review" / "left a
 * review — needs a reply"). The icons here, and the third "replied" row,
 * illustrate the reply-drafting feature — which is real (backend:
 * app/Services/Ai/ClaudeReplyDrafter.php) — but that exact feed-entry
 * shape doesn't exist in the dashboard's activity list today.
 * Marketing craft, not a claim that this is a pixel copy of that list.
 *
 * "Example account", not a real business name or real numbers: this is
 * illustrative data in the real UI's shape, and says so outright — no
 * implied-real customer data on the marketing site.
 */
export function DashboardPreview() {
  return (
    <div className="flex w-full max-w-sm flex-col gap-3">
      <Card className="gap-5 p-5 shadow-sm">
        <div className="flex items-center justify-between">
          <p className="font-heading text-sm font-semibold text-foreground">This week</p>
          <p className="font-mono text-xs text-muted-foreground">Example account</p>
        </div>

        <div className="grid grid-cols-2 gap-3">
          <StatTile label="Requests sent" value="142" />
          <StatTile label="Click rate" value="34%" sublabel="48 clicks" />
          <div className="relative">
            <StatTile label="Reviews landed" value="28%" sublabel="17 reviews" emphasis />
            <Callout number={1} className="-top-2 -right-2" />
          </div>
          <StatTile label="Average rating" value="4.8★" sublabel="17 ratings" />
        </div>

        <div className="flex flex-col gap-3 border-t border-border pt-4">
          <p className="text-xs font-medium text-muted-foreground">Recent activity</p>
          <div className="relative">
            <ActivityRow index={0} icon={Star} tone="gold" rating={5} name="Dana K." detail="left a review" time="2h ago" />
          </div>
          <div className="relative">
            <ActivityRow index={1} icon={MessageCircle} tone="gold" name="Marco T." detail="left a review — needs a reply" time="Yesterday" />
            <Callout number={2} className="top-1/2 -right-2 -translate-y-1/2" />
          </div>
          <div className="relative">
            <ActivityRow index={2} icon={Reply} tone="moss" name="You" detail="replied to Priya S.'s review" time="2d ago" />
          </div>
        </div>
      </Card>

      <div className="flex flex-col gap-1 px-1 text-xs text-muted-foreground">
        <p>
          <span className="mr-1.5 inline-flex size-4 items-center justify-center rounded-full bg-primary text-[0.65rem] font-medium text-primary-foreground">
            1
          </span>
          Counts real posted reviews, not just clicks on the link.
        </p>
        <p>
          <span className="mr-1.5 inline-flex size-4 items-center justify-center rounded-full bg-primary text-[0.65rem] font-medium text-primary-foreground">
            2
          </span>
          One click drafts and posts a reply — no separate writing step.
        </p>
      </div>
    </div>
  );
}

function Callout({ number, className }: { number: number; className: string }) {
  return (
    <span
      aria-hidden="true"
      className={`absolute flex size-5 items-center justify-center rounded-full border-2 border-background bg-primary text-[0.65rem] font-medium text-primary-foreground shadow-sm ${className}`}
    >
      {number}
    </span>
  );
}

function StatTile({
  label,
  value,
  sublabel,
  emphasis,
}: {
  label: string;
  value: string;
  sublabel?: string;
  emphasis?: boolean;
}) {
  return (
    <div className="rounded-lg border border-border p-3">
      <p className="text-[0.65rem] font-medium tracking-wide text-muted-foreground uppercase">{label}</p>
      <p className={`mt-1 text-xl font-semibold tabular-nums ${emphasis ? "text-success" : "text-foreground"}`}>
        {value}
      </p>
      {sublabel && <p className="mt-0.5 text-[0.7rem] text-muted-foreground">{sublabel}</p>}
    </div>
  );
}

/**
 * Icon + tone are per activity type, not per row: a review landing (gold —
 * "worth a look," same convention as the star-rating glyphs and the
 * needs-a-reply state below) reads differently at a glance from a reply
 * going out (moss — DESIGN.md's "compliant/done" color, reused here for
 * "handled," not just the brand accent).
 *
 * `index` staggers the entrance against the SAME reveal trigger as the
 * rest of the hero (see RevealOnScroll's `group`/`data-reveal-pending`)
 * rather than its own scroll observer — this card only ever mounts
 * inside that one reveal group, so a second observer would be redundant.
 */
function ActivityRow({
  index,
  icon: Icon,
  tone,
  rating,
  name,
  detail,
  time,
}: {
  index: number;
  icon: LucideIcon;
  tone: "gold" | "moss";
  rating?: number;
  name: string;
  detail: string;
  time: string;
}) {
  return (
    <div
      style={{ transitionDelay: `${500 + index * 110}ms` }}
      className="flex items-start justify-between gap-2 text-sm opacity-100 translate-y-0 motion-safe:transition-all motion-safe:duration-500 motion-safe:ease-out motion-safe:group-data-[reveal-pending=true]:translate-y-1.5 motion-safe:group-data-[reveal-pending=true]:opacity-0"
    >
      <span className="flex min-w-0 items-start gap-2 text-foreground">
        <span
          className={cn(
            "mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full",
            tone === "gold" ? "bg-secondary/15 text-secondary" : "bg-primary/15 text-primary"
          )}
        >
          <Icon className="size-3" strokeWidth={2.5} aria-hidden="true" />
        </span>
        <span className="min-w-0">
          {rating && <span className="mr-1 text-secondary">{"★".repeat(rating)}</span>}
          <span className="font-medium whitespace-nowrap">{name}</span>{" "}
          <span className="text-muted-foreground">{detail}</span>
        </span>
      </span>
      <span className="shrink-0 font-mono text-xs text-muted-foreground">{time}</span>
    </div>
  );
}
