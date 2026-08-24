import QRCode from "qrcode";

import { CopyLinkButton } from "./CopyLinkButton";

/**
 * .claude/CLAUDE.md quick-add: "A shareable per-tenant link/QR code...
 * that opens the same form without requiring login." Generated server-side
 * (qrcode's Node API, not a client bundle) — the token itself is already
 * server-fetched (GET /tenant), so there's no reason to ship QR-encoding
 * JS to the browser just to draw a code from data the server already has.
 */
export async function QuickAddLinkSection({ token }: { token: string }) {
  const appUrl = process.env.NEXT_PUBLIC_APP_URL ?? "";
  const url = `${appUrl}/quick/${token}`;
  const qrDataUrl = await QRCode.toDataURL(url, { margin: 1, width: 240 });

  return (
    <div className="flex flex-col gap-4 border-t border-border pt-5">
      <div>
        <p className="text-sm font-medium text-foreground">Quick-add link</p>
        <p className="mt-1 text-sm text-muted-foreground">
          Share this with anyone on your team. It opens a one-field form to add a customer right after a
          job — no login needed.
        </p>
      </div>

      <div className="flex flex-col items-start gap-4 sm:flex-row sm:items-center">
        {/* eslint-disable-next-line @next/next/no-img-element -- a data: URI generated server-side, never a remote src next/image would optimize */}
        <img
          src={qrDataUrl}
          alt={`QR code linking to the quick-add form at ${url}`}
          width={120}
          height={120}
          className="rounded-lg border border-border"
        />
        <div className="flex flex-col gap-2">
          <code className="rounded-lg border border-border bg-muted/40 px-3 py-2 font-mono text-xs break-all text-foreground">
            {url}
          </code>
          <div>
            <CopyLinkButton url={url} />
          </div>
        </div>
      </div>
    </div>
  );
}
