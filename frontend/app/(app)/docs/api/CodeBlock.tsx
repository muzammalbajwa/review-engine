"use client";

import { useState } from "react";

/**
 * A code block reads as a terminal, not a themed surface — bg-code/
 * text-code-foreground (globals.css) is deliberately the same dark
 * forest-charcoal in both light and dark mode, so it doesn't flip bright
 * when the page switches to dark mode.
 */
export function CodeBlock({ code, label }: { code: string; label: string }) {
  const [copied, setCopied] = useState(false);

  async function handleCopy() {
    await navigator.clipboard.writeText(code);
    setCopied(true);
    setTimeout(() => setCopied(false), 2000);
  }

  return (
    <div className="overflow-hidden rounded-lg border border-border">
      <div className="flex items-center justify-between border-b border-code-foreground/10 bg-code px-4 py-1.5">
        <span className="font-mono text-xs text-code-foreground/60">{label}</span>
        <button
          type="button"
          onClick={handleCopy}
          className="font-mono text-xs text-code-foreground/60 hover:text-code-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-code-foreground/50 rounded"
        >
          {copied ? "Copied" : "Copy"}
        </button>
      </div>
      <div className="overflow-x-auto bg-code">
        <pre className="p-4 font-mono text-[0.8rem] leading-relaxed text-code-foreground">
          <code>{code}</code>
        </pre>
      </div>
    </div>
  );
}
