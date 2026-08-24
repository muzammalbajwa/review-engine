import { SinglePathLine } from "@/components/SinglePathLine";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from "@/components/ui/card";

/**
 * Isolated design-system preview — see /DESIGN.md. Public on purpose (no
 * tenant data here); check this page after any token change in
 * globals.css, before touching a real screen.
 */
export default function StyleGuidePage() {
  return (
    <main className="mx-auto flex max-w-4xl flex-col gap-14 p-8 pb-20">
      <header className="flex flex-col gap-2">
        <p className="font-mono text-xs tracking-wide text-muted-foreground uppercase">Design system preview</p>
        <h1 className="text-3xl font-semibold">ReviewEngine style guide</h1>
        <p className="max-w-prose text-muted-foreground">
          The tokens, type, and two shared components every later screen builds on. Full brief in{" "}
          <code className="rounded bg-muted px-1.5 py-0.5 font-mono text-sm">/DESIGN.md</code>.
        </p>
      </header>

      <Section title="Color">
        <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
          <Swatch name="background" hex="#F5F6F2" className="border border-border bg-background text-foreground" />
          <Swatch name="foreground" hex="#1E2A22" className="bg-foreground text-background" />
          <Swatch name="primary" hex="#2F6F4E" className="bg-primary text-primary-foreground" note="= success" />
          <Swatch name="secondary" hex="#C99A3E" className="bg-secondary text-secondary-foreground" note="= warning" />
          <Swatch
            name="destructive"
            hex="#B3432B"
            className="bg-destructive text-destructive-foreground"
            note="errors only"
          />
          <Swatch name="card" hex="#FFFFFF" className="border border-border bg-card text-card-foreground" />
          <Swatch name="muted" hex="#EDEFEA" className="bg-muted text-muted-foreground" />
          <Swatch name="border" hex="#EDEFEA" className="border-2 border-border bg-background text-foreground" />
        </div>
        <p className="mt-4 rounded-lg border border-destructive/30 bg-destructive/10 p-3 text-sm text-destructive">
          <strong className="font-medium">Rule:</strong> destructive/red appears nowhere except an actual
          compliance-blocked state or a real error — never decorative, never a generic warning.
        </p>
      </Section>

      <Section title="Type">
        <div className="flex flex-col gap-6">
          <div>
            <Eyebrow>Display — Space Grotesk (font-heading)</Eyebrow>
            <p className="font-heading text-4xl font-semibold">Get more Google reviews.</p>
            <p className="font-heading text-2xl font-semibold">Without any risk to your profile.</p>
            <p className="font-heading text-lg font-semibold">Section header size</p>
          </div>
          <div>
            <Eyebrow>Body — Inter (font-sans, the default)</Eyebrow>
            <p className="max-w-prose text-base">
              This review request will go out at 6pm. Every customer gets the same link — there&apos;s no
              step in this product that routes people differently based on how happy they are.
            </p>
            <p className="text-sm text-muted-foreground">Smaller body text, e.g. helper copy or a timestamp label.</p>
          </div>
          <div>
            <Eyebrow>Data / utility — IBM Plex Mono (font-mono)</Eyebrow>
            <p className="font-mono text-sm text-foreground">
              2026-08-03 14:32:07 · contact_id=4821 · step=2 · status=sent
            </p>
            <p className="font-mono text-sm text-muted-foreground">audit_log #10432 · actor=owner · read</p>
          </div>
        </div>
      </Section>

      <Section title="Buttons">
        <div className="flex flex-wrap items-center gap-3">
          <Button>Import contacts</Button>
          <Button variant="secondary">Highlight action</Button>
          <Button variant="outline">Secondary action</Button>
          <Button variant="ghost">Ghost</Button>
          <Button variant="destructive">Delete template</Button>
          <Button variant="link">Link style</Button>
        </div>
      </Section>

      <Section title="Card">
        <Card className="max-w-sm">
          <CardHeader>
            <CardTitle>Google Business Profile</CardTitle>
            <CardDescription>Connected · syncing reviews</CardDescription>
          </CardHeader>
          <CardContent>
            <p className="text-sm text-foreground">Last synced 12 minutes ago.</p>
          </CardContent>
          <CardFooter>
            <Button size="sm" variant="outline">
              Reconnect
            </Button>
          </CardFooter>
        </Card>
      </Section>

      <Section title="Signature — the single path">
        <p className="mb-6 max-w-prose text-sm text-muted-foreground">
          One line, two states, used in exactly two places (the marketing hero and the template
          compliance checker) — wired into the compliance checker; the marketing hero still to come. See{" "}
          <code className="rounded bg-muted px-1.5 py-0.5 font-mono text-xs">components/SinglePathLine.tsx</code>.
        </p>
        <div className="flex flex-col gap-10">
          <div>
            <Eyebrow>Passing — unbroken</Eyebrow>
            <SinglePathLine steps={[{ label: "Job finished" }, { label: "Review request sent" }, { label: "Review posted" }]} />
          </div>
          <div>
            <Eyebrow>Blocked — stops at the reason</Eyebrow>
            <SinglePathLine
              steps={[{ label: "Draft" }, { label: "Compliance check" }, { label: "Live" }]}
              blockedAtIndex={1}
              blockedReason="Asks for a specific star rating."
            />
          </div>
        </div>
      </Section>
    </main>
  );
}

function Section({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <section>
      <h2 className="mb-4 border-b border-border pb-2 text-xl font-semibold">{title}</h2>
      {children}
    </section>
  );
}

function Eyebrow({ children }: { children: React.ReactNode }) {
  return <p className="mb-2 font-mono text-xs tracking-wide text-muted-foreground uppercase">{children}</p>;
}

function Swatch({
  name,
  hex,
  className,
  note,
}: {
  name: string;
  hex: string;
  className: string;
  note?: string;
}) {
  return (
    <div className={`flex h-24 flex-col justify-between rounded-lg p-3 ${className}`}>
      <span className="font-mono text-xs opacity-70">{hex}</span>
      <div>
        <p className="text-sm font-medium">{name}</p>
        {note && <p className="text-xs opacity-70">{note}</p>}
      </div>
    </div>
  );
}
