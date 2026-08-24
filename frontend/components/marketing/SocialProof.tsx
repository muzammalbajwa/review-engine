import { Card } from "@/components/ui/card";

export type Testimonial = {
  quote: string;
  name: string;
  role: string;
};

/**
 * Real pilot-customer testimonials, once they exist. There are none yet,
 * so this renders nothing — the `testimonials.length === 0` check below
 * IS the safety mechanism, not a comment standing in for one. The layout
 * is finished and ready; only real data is missing.
 *
 * Never call this with placeholder names/quotes to "preview" the design,
 * here or anywhere else. This product's entire pitch is that it doesn't
 * fake things (see DESIGN.md's compliance framing, COMPLIANCE.md) — a
 * landing page that opens with fabricated testimonials would undercut
 * that on the first page a visitor sees. An empty section is honest.
 * Fill `TESTIMONIALS` in app/page.tsx in once real quotes exist; nothing
 * else here needs to change.
 */
export function SocialProof({ testimonials }: { testimonials: Testimonial[] }) {
  if (testimonials.length === 0) return null;

  return (
    <section className="border-t border-border px-5 py-14 sm:px-8 sm:py-20">
      <div className="mx-auto flex max-w-4xl flex-col gap-10">
        <h2 className="text-center font-heading text-2xl font-semibold text-foreground sm:text-3xl">
          What businesses like yours are saying
        </h2>
        <div className="grid grid-cols-1 gap-5 sm:grid-cols-3">
          {testimonials.map((t) => (
            <Card key={t.name} className="gap-4 bg-card">
              <p className="text-sm text-foreground">&ldquo;{t.quote}&rdquo;</p>
              <div className="flex flex-col">
                <span className="text-sm font-medium text-foreground">{t.name}</span>
                <span className="text-xs text-muted-foreground">{t.role}</span>
              </div>
            </Card>
          ))}
        </div>
      </div>
    </section>
  );
}
