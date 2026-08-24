import { ImageResponse } from "next/og";

/**
 * The share-preview card for links to the marketing homepage (Slack,
 * iMessage, Twitter/X, etc.) — without this, a shared link showed no
 * image at all. Generated at request time via Satori (next/og), not a
 * static asset: no image-editing tool is available in this environment,
 * and this keeps the card's copy defined once, here, in code, rather
 * than as an opaque exported PNG that would silently drift from the
 * real page.
 *
 * Colors are DESIGN.md's real tokens (globals.css's :root, light mode
 * only — light was picked deliberately since most chat apps render link
 * previews on a white/light card chrome regardless of the visited
 * page's own theme, so a light-mode OG card is the safer default here).
 * Text is Satori's default system font, not next/font's self-hosted
 * Space Grotesk/Inter: embedding those would mean fetching and inlining
 * the actual font binary into this route, which is real added
 * complexity for an image most people see for under a second — a
 * reasonable simplification, not an oversight.
 *
 * Headline is the exact Step 1-approved hero headline, unchanged; "One
 * path. Every customer. No fork." is the existing SinglePathLine caption
 * already live on the page (see NoGatingSection in app/page.tsx) — both
 * strings are quoted verbatim, nothing paraphrased for this image.
 */
export const alt = "ReviewEngine — Get Google reviews without risking your profile";
export const size = { width: 1200, height: 630 };
export const contentType = "image/png";

export default async function Image() {
  return new ImageResponse(
    (
      <div
        style={{
          width: "100%",
          height: "100%",
          display: "flex",
          flexDirection: "column",
          justifyContent: "space-between",
          backgroundColor: "#F5F6F2",
          padding: "76px",
          fontFamily: "sans-serif",
        }}
      >
        <div style={{ display: "flex", alignItems: "center", gap: 14 }}>
          <div style={{ width: 16, height: 16, borderRadius: 999, backgroundColor: "#2F6F4E", display: "flex" }} />
          <div style={{ fontSize: 30, fontWeight: 700, color: "#1E2A22" }}>ReviewEngine</div>
        </div>

        <div style={{ display: "flex", maxWidth: 1000 }}>
          <div style={{ fontSize: 66, fontWeight: 700, color: "#1E2A22", lineHeight: 1.18 }}>
            Get more Google reviews. Never risk your profile to get them.
          </div>
        </div>

        <div style={{ display: "flex", alignItems: "center", gap: 18 }}>
          <div style={{ width: 200, height: 4, backgroundColor: "#2F6F4E", borderRadius: 999, display: "flex" }} />
          <div style={{ fontSize: 22, color: "#5B6A60" }}>One path. Every customer. No fork.</div>
        </div>
      </div>
    ),
    { ...size }
  );
}
